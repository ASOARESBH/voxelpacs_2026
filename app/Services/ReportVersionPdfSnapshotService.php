<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * Snapshot binário canônico da versão do laudo.
 *
 * O arquivo é privado, tenant-scoped pelo caminho e referenciado na
 * report_versions. Depois de assinado/liberado, a migration impede mutação
 * dos metadados e do conteúdo clínico da versão.
 */
final class ReportVersionPdfSnapshotService
{
    private const RENDERER = 'dompdf';
    private const SCHEMA_VERSION = 1;

    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    /** @param array<string,mixed> $job @return array{content:string,sha256:string,size:int,path:string,version:int} */
    public function readForJob(array $job): array
    {
        $tenantId = (int) ($job['tenant_id'] ?? 0);
        $reportId = (int) ($job['report_id'] ?? 0);
        $version = (int) ($job['report_version'] ?? 0);
        if ($tenantId <= 0 || $reportId <= 0 || $version <= 0) {
            throw new RuntimeException('Snapshot PDF do job incompleto.');
        }

        return $this->readRow($tenantId, $reportId, $version);
    }

    /** @return array{content:string,sha256:string,size:int,path:string,version:int}|null */
    public function readLatestForReport(int $tenantId, int $reportId): ?array
    {
        if ($tenantId <= 0 || $reportId <= 0) {
            return null;
        }
        $pdo = $this->pdo ?? Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT rv.versao
              FROM report_versions rv
               INNER JOIN reports r ON r.id = rv.report_id AND r.tenant_id = :tenant_id
              WHERE rv.report_id = :report_id
                AND r.situacao IN (\'assinado\', \'liberado\')
                AND rv.acao IN (\'assinado\', \'liberado\')
              ORDER BY rv.versao DESC
              LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':report_id' => $reportId]);
        $version = (int) $stmt->fetchColumn();
        return $version > 0 ? $this->readRow($tenantId, $reportId, $version) : null;
    }

    /**
     * Renderiza e persiste a versão recém-criada dentro da transação clínica.
     * O caller deve ter criado a report_version antes desta chamada.
     *
     * @param array<string,mixed> $jobContext
     * @return array{path:string,sha256:string,size:int,renderer:string,schema_version:int,created_new_file:bool}
     */
    public function createForVersion(
        int $tenantId,
        int $reportId,
        int $version,
        array $jobContext
    ): array {
        if ($tenantId <= 0 || $reportId <= 0 || $version <= 0) {
            throw new RuntimeException('Identidade da versão inválida para snapshot PDF.');
        }

        $binary = (new ReportPdfService())->renderSnapshotBinary($jobContext);
        if (strlen($binary) < 100 || !str_starts_with($binary, '%PDF')) {
            throw new RuntimeException('Renderer não produziu PDF canônico válido.');
        }

        $hash = strtolower(hash('sha256', $binary));
        $size = strlen($binary);
        $basePath = defined('BASE_PATH') ? (string) BASE_PATH : dirname(__DIR__, 2);
        $directory = sprintf('%s/storage/report_versions/%d/%d', rtrim($basePath, '/'), $tenantId, $reportId);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o storage privado do snapshot PDF.');
        }
        @chmod($directory, 0700);

        $path = $directory . '/v' . $version . '-' . $hash . '.pdf';
        $createdNewFile = false;
        if (is_file($path)) {
            $existingHash = hash_file('sha256', $path);
            if (!is_string($existingHash) || !hash_equals($hash, strtolower($existingHash))) {
                throw new RuntimeException('Colisão no storage do snapshot PDF.');
            }
        } else {
            $temporaryPath = $path . '.tmp-' . bin2hex(random_bytes(8));
            if (file_put_contents($temporaryPath, $binary, LOCK_EX) === false) {
                @unlink($temporaryPath);
                throw new RuntimeException('Não foi possível persistir o snapshot PDF.');
            }
            @chmod($temporaryPath, 0600);
            if (!rename($temporaryPath, $path)) {
                @unlink($temporaryPath);
                throw new RuntimeException('Não foi possível publicar atomicamente o snapshot PDF.');
            }
            $createdNewFile = true;
        }
        @chmod($path, 0600);

        $pdo = $this->pdo ?? Database::getInstance();
        $stmt = $pdo->prepare(
            'UPDATE report_versions
                SET pdf_snapshot_path = :path,
                    pdf_snapshot_sha256 = :sha256,
                    pdf_snapshot_size_bytes = :size,
                    pdf_snapshot_created_at = COALESCE(pdf_snapshot_created_at, NOW()),
                    pdf_snapshot_renderer = :renderer,
                    pdf_snapshot_schema_version = :schema_version
              WHERE report_id = :report_id AND versao = :version
                AND EXISTS (SELECT 1 FROM reports r WHERE r.id = report_versions.report_id AND r.tenant_id = :tenant_id)'
        );
        try {
            $stmt->execute([
                ':path' => $path,
                ':sha256' => $hash,
                ':size' => $size,
                ':renderer' => self::RENDERER,
                ':schema_version' => self::SCHEMA_VERSION,
                ':report_id' => $reportId,
                ':version' => $version,
                ':tenant_id' => $tenantId,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Versão clínica não localizada para persistir o snapshot PDF.');
            }
        } catch (\Throwable $e) {
            if ($createdNewFile) {
                @unlink($path);
            }
            throw $e;
        }

        return [
            'path' => $path,
            'sha256' => $hash,
            'size' => $size,
            'renderer' => self::RENDERER,
            'schema_version' => self::SCHEMA_VERSION,
            'created_new_file' => $createdNewFile,
        ];
    }

    /** @return array{content:string,sha256:string,size:int,path:string,version:int} */
    private function readRow(int $tenantId, int $reportId, int $version): array
    {
        $pdo = $this->pdo ?? Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT rv.pdf_snapshot_path, rv.pdf_snapshot_sha256, rv.pdf_snapshot_size_bytes,
                    rv.pdf_snapshot_renderer, rv.pdf_snapshot_schema_version
               FROM report_versions rv
               INNER JOIN reports r ON r.id = rv.report_id AND r.tenant_id = :tenant_id
              WHERE rv.report_id = :report_id AND rv.versao = :version
              LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':report_id' => $reportId,
            ':version' => $version,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || (string) ($row['pdf_snapshot_renderer'] ?? '') !== self::RENDERER
            || (int) ($row['pdf_snapshot_schema_version'] ?? 0) !== self::SCHEMA_VERSION
        ) {
            throw new RuntimeException('Snapshot PDF canônico não encontrado para a versão do job.');
        }

        $path = (string) ($row['pdf_snapshot_path'] ?? '');
        $expectedHash = strtolower(trim((string) ($row['pdf_snapshot_sha256'] ?? '')));
        $expectedSize = (int) ($row['pdf_snapshot_size_bytes'] ?? 0);
        $basePath = defined('BASE_PATH') ? (string) BASE_PATH : dirname(__DIR__, 2);
        $storageRoot = realpath(sprintf('%s/storage/report_versions/%d/%d', rtrim($basePath, '/'), $tenantId, $reportId));
        $pathReal = is_file($path) ? realpath($path) : false;
        if (!preg_match('/^[0-9a-f]{64}$/', $expectedHash)
            || $expectedSize < 100
            || $storageRoot === false
            || $pathReal === false
            || !str_starts_with($pathReal, $storageRoot . DIRECTORY_SEPARATOR)
            || !is_readable($pathReal)
        ) {
            throw new RuntimeException('Snapshot PDF canônico inválido ou inacessível.');
        }

        $path = $pathReal;
        $size = (int) filesize($path);
        $content = file_get_contents($path);
        $hash = hash_file('sha256', $path);
        if (!is_string($content) || !is_string($hash)
            || $size !== $expectedSize
            || !hash_equals($expectedHash, strtolower($hash))
            || !str_starts_with($content, '%PDF')
        ) {
            throw new RuntimeException('Integridade do snapshot PDF canônico não confirmada.');
        }

        return [
            'content' => $content,
            'sha256' => strtolower($hash),
            'size' => $size,
            'path' => $path,
            'version' => $version,
        ];
    }
}
