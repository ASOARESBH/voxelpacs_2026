<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * Mantém um PDF clínico imutável por tenant, laudo e versão. O binário fica em
 * storage privado; o banco registra somente a referência e a integridade.
 */
final class ReportPdfSnapshotService
{
    private const MAX_BYTES = 100 * 1024 * 1024;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    /** @return array{storage_path:string,sha256:string,size:int,created:bool} */
    public function createForReleasedReport(
        int $tenantId,
        int $reportId,
        int $reportVersion,
        ?int $estabelecimentoId
    ): array {
        if ($tenantId <= 0 || $reportId <= 0 || $reportVersion <= 0) {
            throw new RuntimeException('Dados insuficientes para congelar o PDF do laudo.');
        }

        $existing = $this->findSnapshot($tenantId, $reportId, $reportVersion);
        if ($existing !== null) {
            $this->verifySnapshot($existing);
            return [
                'storage_path' => (string) $existing['storage_path'],
                'sha256' => (string) $existing['sha256'],
                'size' => (int) $existing['file_size_bytes'],
                'created' => false,
            ];
        }

        $context = (new ReportPdfRenderContextService($this->pdo))->loadForReport($reportId, $tenantId);
        if ($context === null || !ReportClinicalContentService::hasReportContent($context['report'])) {
            throw new RuntimeException('PDF clínico não pôde ser congelado para a versão liberada.');
        }

        $binary = (new ReportPdfService())->renderSnapshotBinary($context);
        $size = strlen($binary);
        if ($size < 100 || $size > self::MAX_BYTES || !str_starts_with($binary, '%PDF')) {
            throw new RuntimeException('Snapshot PDF inválido para a devolutiva.');
        }

        $hash = hash('sha256', $binary);
        $path = $this->storagePath($tenantId, $reportId, $reportVersion);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o armazenamento privado do PDF.');
        }
        @chmod($directory, 0700);

        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $binary, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível gravar o snapshot PDF privado.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Não foi possível finalizar o snapshot PDF privado.');
        }
        @chmod($path, 0600);

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO report_pdf_snapshots
                    (tenant_id, report_id, report_version, estabelecimento_id, storage_path, sha256, file_size_bytes)
                 VALUES
                    (:tenant_id, :report_id, :report_version, :estabelecimento_id, :storage_path, :sha256, :file_size_bytes)"
            );
            $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $stmt->bindValue(':report_id', $reportId, PDO::PARAM_INT);
            $stmt->bindValue(':report_version', $reportVersion, PDO::PARAM_INT);
            if ($estabelecimentoId === null) {
                $stmt->bindValue(':estabelecimento_id', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':estabelecimento_id', $estabelecimentoId, PDO::PARAM_INT);
            }
            $stmt->bindValue(':storage_path', $path, PDO::PARAM_STR);
            $stmt->bindValue(':sha256', $hash, PDO::PARAM_STR);
            $stmt->bindValue(':file_size_bytes', $size, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }

        return ['storage_path' => $path, 'sha256' => $hash, 'size' => $size, 'created' => true];
    }

    /** @return array{content:string,storage_path:string,sha256:string,size:int} */
    public function readForDelivery(int $outboxId, int $tenantId, int $reportId, int $reportVersion): array
    {
        if ($outboxId <= 0 || $tenantId <= 0 || $reportId <= 0 || $reportVersion <= 0) {
            throw new RuntimeException('Contexto de snapshot inválido para devolutiva.');
        }
        $stmt = $this->pdo->prepare(
            "SELECT s.storage_path, s.sha256, s.file_size_bytes
             FROM report_pdf_snapshots s
             INNER JOIN pacs_report_delivery_outbox o
                ON o.id = :outbox_id
               AND o.tenant_id = s.tenant_id
               AND o.report_id = s.report_id
               AND o.report_version = s.report_version
             WHERE s.tenant_id = :tenant_id
               AND s.report_id = :report_id
               AND s.report_version = :report_version
             LIMIT 1"
        );
        $stmt->execute([
            ':outbox_id' => $outboxId,
            ':tenant_id' => $tenantId,
            ':report_id' => $reportId,
            ':report_version' => $reportVersion,
        ]);
        $snapshot = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($snapshot)) {
            throw new RuntimeException('Snapshot PDF imutável não encontrado para este job.');
        }
        $this->verifySnapshot($snapshot);
        $content = file_get_contents((string) $snapshot['storage_path']);
        if (!is_string($content)) {
            throw new RuntimeException('Snapshot PDF indisponível para leitura privada.');
        }
        return [
            'content' => $content,
            'storage_path' => (string) $snapshot['storage_path'],
            'sha256' => (string) $snapshot['sha256'],
            'size' => (int) $snapshot['file_size_bytes'],
        ];
    }

    /** @return array<string,mixed>|null */
    private function findSnapshot(int $tenantId, int $reportId, int $reportVersion): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT storage_path, sha256, file_size_bytes
             FROM report_pdf_snapshots
             WHERE tenant_id = :tenant_id AND report_id = :report_id AND report_version = :report_version
             LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':report_id' => $reportId, ':report_version' => $reportVersion]);
        $snapshot = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($snapshot) ? $snapshot : null;
    }

    /** @param array<string,mixed> $snapshot */
    private function verifySnapshot(array $snapshot): void
    {
        $path = (string) ($snapshot['storage_path'] ?? '');
        $expectedHash = (string) ($snapshot['sha256'] ?? '');
        $expectedSize = (int) ($snapshot['file_size_bytes'] ?? 0);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Snapshot PDF privado não está disponível.');
        }
        $actualSize = filesize($path);
        if (!is_int($actualSize) || $actualSize < 100 || $actualSize > self::MAX_BYTES || $actualSize !== $expectedSize) {
            throw new RuntimeException('Tamanho do snapshot PDF não confere.');
        }
        $actualHash = hash_file('sha256', $path);
        if (!is_string($actualHash) || !hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException('Integridade do snapshot PDF não confere.');
        }
    }

    private function storagePath(int $tenantId, int $reportId, int $reportVersion): string
    {
        $basePath = defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/') : dirname(__DIR__, 2);
        return sprintf('%s/storage/report_pdf_snapshots/%d/%d/v%d.pdf', $basePath, $tenantId, $reportId, $reportVersion);
    }
}
