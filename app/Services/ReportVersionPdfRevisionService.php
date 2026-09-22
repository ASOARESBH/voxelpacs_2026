<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\SqlHelper;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Revisão operacional imutável do PDF de uma report_version.
 *
 * A revisão é uma identidade separada do snapshot clínico canônico. Ela só
 * pode ser criada a partir do contexto explícito da versão e nunca altera
 * report_versions, o snapshot original ou artifacts históricos.
 */
final class ReportVersionPdfRevisionService
{
    private const RENDERER = 'dompdf';
    private const SCHEMA_VERSION = 1;

    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    /**
     * Renderiza uma revisão a partir do conteúdo explícito da versão e
     * persiste somente a nova linha/storage privado.
     *
     * @return array{id:int,tenant_id:int,report_id:int,report_version:int,revision_number:int,revision_key:string,source_pdf_snapshot_sha256:string,pdf_snapshot_path:string,pdf_snapshot_sha256:string,pdf_snapshot_size_bytes:int,pdf_snapshot_renderer:string,pdf_snapshot_schema_version:int,reason_code:string,created_by:int|null,created_new_file:bool}
     */
    public function createForVersion(
        int $tenantId,
        int $reportId,
        int $version,
        string $reasonCode = 'visual_renderer_correction',
        ?int $createdBy = null
    ): array {
        return $this->createWithContextSource($tenantId, $reportId, $version, $reasonCode, $createdBy, false);
    }

    /**
     * Cria uma revisão operacional usando explicitamente o corpo atual do
     * report liberado. Não altera report_versions nem o snapshot canônico.
     *
     * @return array{id:int,tenant_id:int,report_id:int,report_version:int,revision_number:int,revision_key:string,source_pdf_snapshot_sha256:string,pdf_snapshot_path:string,pdf_snapshot_sha256:string,pdf_snapshot_size_bytes:int,pdf_snapshot_renderer:string,pdf_snapshot_schema_version:int,reason_code:string,created_by:int|null,created_new_file:bool}
     */
    public function createOperationalReplacementFromCurrentReport(
        int $tenantId,
        int $reportId,
        int $version,
        ?int $createdBy = null
    ): array {
        return $this->createWithContextSource(
            $tenantId,
            $reportId,
            $version,
            'operational_replacement',
            $createdBy,
            true
        );
    }

    /**
     * @return array{id:int,tenant_id:int,report_id:int,report_version:int,revision_number:int,revision_key:string,source_pdf_snapshot_sha256:string,pdf_snapshot_path:string,pdf_snapshot_sha256:string,pdf_snapshot_size_bytes:int,pdf_snapshot_renderer:string,pdf_snapshot_schema_version:int,reason_code:string,created_by:int|null,created_new_file:bool}
     */
    private function createWithContextSource(
        int $tenantId,
        int $reportId,
        int $version,
        string $reasonCode,
        ?int $createdBy,
        bool $useCurrentReportBody
    ): array {
        $reasonCode = trim($reasonCode);
        if (!in_array($reasonCode, ['visual_renderer_correction', 'operational_replacement'], true)) {
            throw new RuntimeException('Motivo de revisão PDF inválido.');
        }
        if ($tenantId <= 0 || $reportId <= 0 || $version <= 0 || ($createdBy !== null && $createdBy <= 0)) {
            throw new RuntimeException('Identidade inválida para revisão PDF.');
        }

        $identity = $this->loadVersionIdentity($tenantId, $reportId, $version);
        $source = (new ReportVersionPdfSnapshotService($this->pdo))->readForJob([
            'tenant_id' => $tenantId,
            'report_id' => $reportId,
            'report_version' => $version,
        ]);
        $contextBuilder = new ReportPdfDeliveryContextService($this->pdo);
        $context = $useCurrentReportBody
            ? $contextBuilder->buildFromCurrentReport([
                'tenant_id' => $tenantId,
                'report_id' => $reportId,
                'estudo_id' => (int) $identity['estudo_id'],
                'report_version' => $version,
            ])
            : $contextBuilder->build([
            'tenant_id' => $tenantId,
            'report_id' => $reportId,
            'estudo_id' => (int) $identity['estudo_id'],
            'report_version' => $version,
            ]);
        $binary = (new ReportPdfService())->renderSnapshotBinary($context);
        $this->assertPdf($binary);

        $pdfHash = strtolower(hash('sha256', $binary));
        $pdfSize = strlen($binary);
        $revisionKey = hash('sha256', DeliveryRequestIdentity::canonicalJson([
            'schema_version' => self::SCHEMA_VERSION,
            'tenant_id' => $tenantId,
            'report_id' => $reportId,
            'report_version' => $version,
            'report_version_row_id' => (int) $identity['report_version_row_id'],
            'source_pdf_snapshot_sha256' => strtolower((string) $source['sha256']),
            'pdf_snapshot_sha256' => $pdfHash,
            'pdf_snapshot_size_bytes' => $pdfSize,
            'renderer' => self::RENDERER,
            'reason_code' => $reasonCode,
        ]));

        $basePath = defined('BASE_PATH') ? (string) BASE_PATH : dirname(__DIR__, 2);
        $directory = sprintf(
            '%s/storage/report_version_pdf_revisions/%d/%d/v%d',
            rtrim($basePath, '/'),
            $tenantId,
            $reportId,
            $version
        );
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o storage privado da revisão PDF.');
        }
        @chmod($directory, 0700);
        $path = $directory . '/revision-' . $pdfHash . '.pdf';
        $createdNewFile = $this->writeAtomicallyIfAbsent($path, $binary, $pdfHash);

        $pdo = $this->pdo ?? Database::getInstance();
        try {
            $pdo->beginTransaction();
            $existing = $this->findByKey($tenantId, $revisionKey);
            if ($existing !== null) {
                $pdo->commit();
                if ($createdNewFile) {
                    @unlink($path);
                }
                return $this->readMetadata($existing, false);
            }

            $numberStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(revision_number), 0) + 1
                   FROM pacs_report_version_pdf_revisions
                  WHERE tenant_id = :tenant_id AND report_id = :report_id AND report_version = :report_version'
            );
            $numberStmt->execute([
                ':tenant_id' => $tenantId,
                ':report_id' => $reportId,
                ':report_version' => $version,
            ]);
            $revisionNumber = (int) $numberStmt->fetchColumn();
            if ($revisionNumber <= 0) {
                throw new RuntimeException('Número de revisão PDF inválido.');
            }

            $insertSql = 'INSERT INTO pacs_report_version_pdf_revisions
                    (tenant_id, report_id, report_version, report_version_row_id, revision_number,
                     revision_key, source_pdf_snapshot_sha256, pdf_snapshot_path, pdf_snapshot_sha256,
                     pdf_snapshot_size_bytes, pdf_snapshot_renderer, pdf_snapshot_schema_version,
                     reason_code, created_by)
                 VALUES
                    (:tenant_id, :report_id, :report_version, :report_version_row_id, :revision_number,
                     :revision_key, :source_pdf_snapshot_sha256, :pdf_snapshot_path, :pdf_snapshot_sha256,
                     :pdf_snapshot_size_bytes, :pdf_snapshot_renderer, :pdf_snapshot_schema_version,
                     :reason_code, :created_by)';
            $insertSql .= SqlHelper::isPostgres()
                ? ' ON CONFLICT DO NOTHING RETURNING id'
                : ' ON DUPLICATE KEY UPDATE revision_key = revision_key';
            $insert = $pdo->prepare($insertSql);
            $insert->execute([
                ':tenant_id' => $tenantId,
                ':report_id' => $reportId,
                ':report_version' => $version,
                ':report_version_row_id' => (int) $identity['report_version_row_id'],
                ':revision_number' => $revisionNumber,
                ':revision_key' => $revisionKey,
                ':source_pdf_snapshot_sha256' => strtolower((string) $source['sha256']),
                ':pdf_snapshot_path' => $path,
                ':pdf_snapshot_sha256' => $pdfHash,
                ':pdf_snapshot_size_bytes' => $pdfSize,
                ':pdf_snapshot_renderer' => self::RENDERER,
                ':pdf_snapshot_schema_version' => self::SCHEMA_VERSION,
                ':reason_code' => $reasonCode,
                ':created_by' => $createdBy,
            ]);
            $id = SqlHelper::isPostgres()
                ? (int) ($insert->fetchColumn() ?: 0)
                : (int) $pdo->lastInsertId();
            if ($id <= 0) {
                $existing = $this->findByKey($tenantId, $revisionKey);
                if ($existing !== null) {
                    $pdo->commit();
                    if ($createdNewFile) {
                        @unlink($path);
                    }
                    return $this->readMetadata($existing, false);
                }
                throw new RuntimeException('Revisão PDF não foi persistida após conflito concorrente.');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($createdNewFile) {
                @unlink($path);
            }
            throw $e;
        }

        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'report_id' => $reportId,
            'report_version' => $version,
            'revision_number' => $revisionNumber,
            'revision_key' => $revisionKey,
            'source_pdf_snapshot_sha256' => strtolower((string) $source['sha256']),
            'pdf_snapshot_path' => $path,
            'pdf_snapshot_sha256' => $pdfHash,
            'pdf_snapshot_size_bytes' => $pdfSize,
            'pdf_snapshot_renderer' => self::RENDERER,
            'pdf_snapshot_schema_version' => self::SCHEMA_VERSION,
            'reason_code' => $reasonCode,
            'created_by' => $createdBy,
            'created_new_file' => $createdNewFile,
        ];
    }

    /** @param array<string,mixed> $job @return array{content:string,sha256:string,size:int,path:string,revision_id:int,revision_number:int} */
    public function readForJob(array $job): array
    {
        $tenantId = (int) ($job['tenant_id'] ?? 0);
        $reportId = (int) ($job['report_id'] ?? 0);
        $version = (int) ($job['report_version'] ?? 0);
        $revisionId = (int) ($job['pdf_revision_id'] ?? 0);
        if ($revisionId <= 0 && is_string($job['payload_json'] ?? null)) {
            $payload = json_decode((string) $job['payload_json'], true);
            if (is_array($payload)) {
                $revisionId = (int) ($payload['pdf_revision_id'] ?? 0);
            }
        }
        if ($tenantId <= 0 || $reportId <= 0 || $version <= 0 || $revisionId <= 0) {
            throw new RuntimeException('Revisão PDF do job incompleta.');
        }

        $row = $this->findById($tenantId, $revisionId, $reportId, $version);
        if ($row === null) {
            throw new RuntimeException('Revisão PDF não localizada para o job.');
        }
        return $this->readContent($row, $tenantId, $reportId, $version);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $tenantId, int $revisionId, int $reportId, int $version): ?array
    {
        if ($tenantId <= 0 || $revisionId <= 0 || $reportId <= 0 || $version <= 0) {
            return null;
        }
        $pdo = $this->pdo ?? Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT rev.*
               FROM pacs_report_version_pdf_revisions rev
               INNER JOIN reports r ON r.id = rev.report_id AND r.tenant_id = rev.tenant_id
              WHERE rev.id = :revision_id
                AND rev.tenant_id = :tenant_id
                AND rev.report_id = :report_id
                AND rev.report_version = :report_version
              LIMIT 1'
        );
        $stmt->execute([
            ':revision_id' => $revisionId,
            ':tenant_id' => $tenantId,
            ':report_id' => $reportId,
            ':report_version' => $version,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function readContent(array $row, int $tenantId, int $reportId, int $version): array
    {
        if ((string) ($row['pdf_snapshot_renderer'] ?? '') !== self::RENDERER
            || (int) ($row['pdf_snapshot_schema_version'] ?? 0) !== self::SCHEMA_VERSION
        ) {
            throw new RuntimeException('Revisão PDF possui renderer ou schema inválido.');
        }
        $expectedHash = strtolower(trim((string) ($row['pdf_snapshot_sha256'] ?? '')));
        $expectedSize = (int) ($row['pdf_snapshot_size_bytes'] ?? 0);
        $basePath = defined('BASE_PATH') ? (string) BASE_PATH : dirname(__DIR__, 2);
        $storageRoot = realpath(sprintf(
            '%s/storage/report_version_pdf_revisions/%d/%d/v%d',
            rtrim($basePath, '/'), $tenantId, $reportId, $version
        ));
        $path = (string) ($row['pdf_snapshot_path'] ?? '');
        $pathReal = is_file($path) ? realpath($path) : false;
        if (!preg_match('/^[0-9a-f]{64}$/', $expectedHash)
            || $expectedSize < 100
            || $storageRoot === false
            || $pathReal === false
            || !str_starts_with($pathReal, $storageRoot . DIRECTORY_SEPARATOR)
            || !is_readable($pathReal)
        ) {
            throw new RuntimeException('Revisão PDF inválida ou inacessível.');
        }

        $content = file_get_contents($pathReal);
        $hash = hash_file('sha256', $pathReal);
        $size = (int) filesize($pathReal);
        if (!is_string($content) || !is_string($hash)
            || $size !== $expectedSize
            || !hash_equals($expectedHash, strtolower($hash))
            || !str_starts_with($content, '%PDF')
        ) {
            throw new RuntimeException('Integridade da revisão PDF não confirmada.');
        }

        return [
            'content' => $content,
            'sha256' => strtolower($hash),
            'size' => $size,
            'path' => $pathReal,
            'revision_id' => (int) $row['id'],
            'revision_number' => (int) $row['revision_number'],
        ];
    }

    /** @return array<string,mixed> */
    private function loadVersionIdentity(int $tenantId, int $reportId, int $version): array
    {
        $pdo = $this->pdo ?? Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT r.estudo_id, rv.id AS report_version_row_id
               FROM reports r
               INNER JOIN report_versions rv ON rv.report_id = r.id AND rv.versao = :version
              WHERE r.id = :report_id
                AND r.tenant_id = :tenant_id
                AND r.situacao = 'liberado'
                AND rv.acao IN ('assinado', 'liberado')
              LIMIT 2"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':report_id' => $reportId,
            ':version' => $version,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            throw new RuntimeException('Versão liberada não é única para revisão PDF.');
        }
        return $rows[0];
    }

    /** @return array<string,mixed>|null */
    private function findByKey(int $tenantId, string $revisionKey): ?array
    {
        $pdo = $this->pdo ?? Database::getInstance();
        $sql = 'SELECT * FROM pacs_report_version_pdf_revisions WHERE tenant_id = :tenant_id AND revision_key = :revision_key LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':tenant_id' => $tenantId, ':revision_key' => $revisionKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function readMetadata(array $row, bool $createdNewFile): array
    {
        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'report_id' => (int) $row['report_id'],
            'report_version' => (int) $row['report_version'],
            'revision_number' => (int) $row['revision_number'],
            'revision_key' => (string) $row['revision_key'],
            'source_pdf_snapshot_sha256' => (string) $row['source_pdf_snapshot_sha256'],
            'pdf_snapshot_path' => (string) $row['pdf_snapshot_path'],
            'pdf_snapshot_sha256' => (string) $row['pdf_snapshot_sha256'],
            'pdf_snapshot_size_bytes' => (int) $row['pdf_snapshot_size_bytes'],
            'pdf_snapshot_renderer' => (string) $row['pdf_snapshot_renderer'],
            'pdf_snapshot_schema_version' => (int) $row['pdf_snapshot_schema_version'],
            'reason_code' => (string) $row['reason_code'],
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_new_file' => $createdNewFile,
        ];
    }

    private function writeAtomicallyIfAbsent(string $path, string $binary, string $expectedHash): bool
    {
        if (is_file($path)) {
            $existingHash = hash_file('sha256', $path);
            if (!is_string($existingHash) || !hash_equals($expectedHash, strtolower($existingHash))) {
                throw new RuntimeException('Colisão no storage da revisão PDF.');
            }
            return false;
        }
        $temporaryPath = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporaryPath, $binary, LOCK_EX) === false) {
            @unlink($temporaryPath);
            throw new RuntimeException('Não foi possível persistir a revisão PDF.');
        }
        @chmod($temporaryPath, 0600);
        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Não foi possível publicar atomicamente a revisão PDF.');
        }
        @chmod($path, 0600);
        return true;
    }

    private function assertPdf(string $binary): void
    {
        if (strlen($binary) < 100 || !str_starts_with($binary, '%PDF')) {
            throw new RuntimeException('Renderer não produziu revisão PDF válida.');
        }
    }
}
