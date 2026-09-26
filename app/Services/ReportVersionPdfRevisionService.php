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
        return $this->createWithContextSource(
            $tenantId,
            $reportId,
            $version,
            $reasonCode,
            $createdBy,
            'canonical_snapshot'
        );
    }

    /**
     * Cria uma revisão operacional a partir exclusivamente dos campos da
     * report_versions histórica indicada. Nunca usa reports.corpo_laudo.
     *
     * @return array<string,mixed>
     */
    public function createFromHistoricalReportVersion(
        int $tenantId,
        int $reportId,
        int $version,
        ?int $createdBy = null
    ): array {
        return $this->createWithContextSource(
            $tenantId,
            $reportId,
            $version,
            'visual_renderer_correction',
            $createdBy,
            'historical_report_version'
        );
    }

    /**
     * Recupera uma revisão operacional do PDF já entregue, sem regenerá-lo e
     * sem promover o artifact a snapshot canônico.
     *
     * @return array<string,mixed>
     */
    public function createFromDeliveredPdfArtifact(
        int $tenantId,
        int $reportId,
        int $version,
        int $artifactId,
        ?int $createdBy = null
    ): array {
        if ($tenantId <= 0 || $reportId <= 0 || $version <= 0 || $artifactId <= 0) {
            throw new RuntimeException('Identidade inválida para recuperação do artifact PDF.');
        }

        $identity = $this->loadVersionIdentity($tenantId, $reportId, $version);
        $pdo = $this->pdo ?? Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT a.id, a.storage_path, a.sha256, a.file_size_bytes
               FROM pacs_report_delivery_artifacts a
               INNER JOIN pacs_report_delivery_outbox o
                       ON o.id = a.outbox_id
                      AND o.tenant_id = a.tenant_id
              WHERE a.id = :artifact_id
                AND a.tenant_id = :tenant_id
                AND lower(a.artifact_type) = :artifact_type
                AND o.report_id = :report_id
                AND o.report_version = :report_version
                AND o.tenant_id = :tenant_id_outbox
                AND EXISTS (
                    SELECT 1
                      FROM pacs_report_delivery_jobs j
                     WHERE j.outbox_id = o.id
                       AND j.tenant_id = o.tenant_id
                       AND j.status = \'delivered\'
                )
              LIMIT 1'
        );
        $stmt->execute([
            ':artifact_id' => $artifactId,
            ':tenant_id' => $tenantId,
            ':artifact_type' => 'pdf',
            ':report_id' => $reportId,
            ':report_version' => $version,
            ':tenant_id_outbox' => $tenantId,
        ]);
        $artifact = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($artifact)) {
            throw new RuntimeException('Artifact PDF entregue não localizado para a versão.');
        }

        $artifactPath = $this->resolveArtifactPath((string) ($artifact['storage_path'] ?? ''));
        if ($artifactPath === null || !is_readable($artifactPath)) {
            throw new RuntimeException('Artifact PDF entregue inacessível ou fora do storage.');
        }
        $binary = file_get_contents($artifactPath);
        $actualHash = hash_file('sha256', $artifactPath);
        $actualSize = (int) filesize($artifactPath);
        $expectedHash = strtolower(trim((string) ($artifact['sha256'] ?? '')));
        $expectedSize = (int) ($artifact['file_size_bytes'] ?? 0);
        if (!is_string($binary) || !is_string($actualHash)
            || !preg_match('/^[0-9a-f]{64}$/', $expectedHash)
            || !hash_equals($expectedHash, strtolower($actualHash))
            || $actualSize !== $expectedSize
        ) {
            throw new RuntimeException('Integridade do artifact PDF entregue não confirmada.');
        }
        $this->assertPdf($binary);

        $revisionKey = hash('sha256', DeliveryRequestIdentity::canonicalJson([
            'schema_version' => self::SCHEMA_VERSION,
            'tenant_id' => $tenantId,
            'report_id' => $reportId,
            'report_version' => $version,
            'report_version_row_id' => (int) $identity['report_version_row_id'],
            'source_kind' => 'delivery_artifact',
            'source_delivery_artifact_id' => $artifactId,
            'source_delivery_artifact_sha256' => $expectedHash,
            'source_delivery_artifact_size_bytes' => $expectedSize,
            'pdf_snapshot_sha256' => strtolower($actualHash),
            'pdf_snapshot_size_bytes' => $actualSize,
            'renderer' => self::RENDERER,
            'reason_code' => 'historical_artifact_recovery',
        ]));

        $storageBase = PdfSnapshotPathResolver::storageBasePath();
        $directory = sprintf('%s/report_version_pdf_revisions/%d/%d/v%d', $storageBase, $tenantId, $reportId, $version);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o storage privado da revisão PDF.');
        }
        @chmod($directory, 0700);
        $path = $directory . '/revision-' . strtolower($actualHash) . '.pdf';
        $createdNewFile = $this->writeAtomicallyIfAbsent($path, $binary, strtolower($actualHash));
        $relativePath = PdfSnapshotPathResolver::relativePathFor(
            $path,
            sprintf('report_version_pdf_revisions/%d/%d/v%d', $tenantId, $reportId, $version)
        );

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
            $insertSql = 'INSERT INTO pacs_report_version_pdf_revisions
                    (tenant_id, report_id, report_version, report_version_row_id, revision_number,
                     revision_key, source_kind, source_pdf_snapshot_sha256, source_content_sha256,
                     source_delivery_artifact_id, source_delivery_artifact_sha256, source_delivery_artifact_size_bytes,
                     pdf_snapshot_path, pdf_snapshot_sha256, pdf_snapshot_size_bytes,
                     pdf_snapshot_renderer, pdf_snapshot_schema_version, reason_code, created_by)
                 VALUES
                    (:tenant_id, :report_id, :report_version, :report_version_row_id, :revision_number,
                     :revision_key, :source_kind, NULL, NULL,
                     :source_delivery_artifact_id, :source_delivery_artifact_sha256, :source_delivery_artifact_size_bytes,
                     :pdf_snapshot_path, :pdf_snapshot_sha256, :pdf_snapshot_size_bytes,
                     :pdf_snapshot_renderer, :pdf_snapshot_schema_version, :reason_code, :created_by)
                 ON CONFLICT DO NOTHING RETURNING id';
            $insert = $pdo->prepare($insertSql);
            $insert->execute([
                ':tenant_id' => $tenantId,
                ':report_id' => $reportId,
                ':report_version' => $version,
                ':report_version_row_id' => (int) $identity['report_version_row_id'],
                ':revision_number' => $revisionNumber,
                ':revision_key' => $revisionKey,
                ':source_kind' => 'delivery_artifact',
                ':source_delivery_artifact_id' => $artifactId,
                ':source_delivery_artifact_sha256' => $expectedHash,
                ':source_delivery_artifact_size_bytes' => $expectedSize,
                ':pdf_snapshot_path' => $relativePath,
                ':pdf_snapshot_sha256' => strtolower($actualHash),
                ':pdf_snapshot_size_bytes' => $actualSize,
                ':pdf_snapshot_renderer' => self::RENDERER,
                ':pdf_snapshot_schema_version' => self::SCHEMA_VERSION,
                ':reason_code' => 'historical_artifact_recovery',
                ':created_by' => $createdBy,
            ]);
            $id = (int) ($insert->fetchColumn() ?: 0);
            if ($id <= 0) {
                $existing = $this->findByKey($tenantId, $revisionKey);
                if ($existing === null) {
                    throw new RuntimeException('Recuperação do artifact não foi persistida.');
                }
                $pdo->commit();
                if ($createdNewFile) {
                    @unlink($path);
                }
                return $this->readMetadata($existing, false);
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
            'source_kind' => 'delivery_artifact',
            'source_delivery_artifact_id' => $artifactId,
            'source_delivery_artifact_sha256' => $expectedHash,
            'source_delivery_artifact_size_bytes' => $expectedSize,
            'source_pdf_snapshot_sha256' => null,
            'source_content_sha256' => null,
            'pdf_snapshot_path' => $path,
            'pdf_snapshot_sha256' => strtolower($actualHash),
            'pdf_snapshot_size_bytes' => $actualSize,
            'pdf_snapshot_renderer' => self::RENDERER,
            'pdf_snapshot_schema_version' => self::SCHEMA_VERSION,
            'reason_code' => 'historical_artifact_recovery',
            'created_by' => $createdBy,
            'created_new_file' => $createdNewFile,
        ];
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
            'current_report_body'
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
        string $sourceKind
    ): array {
        $reasonCode = trim($reasonCode);
        if (!in_array($reasonCode, ['visual_renderer_correction', 'operational_replacement'], true)) {
            throw new RuntimeException('Motivo de revisão PDF inválido.');
        }
        if (!in_array($sourceKind, ['canonical_snapshot', 'current_report_body', 'historical_report_version'], true)) {
            throw new RuntimeException('Fonte da revisão PDF inválida.');
        }
        if ($tenantId <= 0 || $reportId <= 0 || $version <= 0 || ($createdBy !== null && $createdBy <= 0)) {
            throw new RuntimeException('Identidade inválida para revisão PDF.');
        }

        $identity = $this->loadVersionIdentity($tenantId, $reportId, $version);
        $sourcePdfSnapshotSha256 = null;
        if ($sourceKind === 'canonical_snapshot') {
            $source = (new ReportVersionPdfSnapshotService($this->pdo))->readForJob([
                'tenant_id' => $tenantId,
                'report_id' => $reportId,
                'report_version' => $version,
            ]);
            $sourcePdfSnapshotSha256 = strtolower((string) $source['sha256']);
        }
        $contextBuilder = new ReportPdfDeliveryContextService($this->pdo);
        $context = match ($sourceKind) {
            'current_report_body' => $contextBuilder->buildFromCurrentReport([
                'tenant_id' => $tenantId,
                'report_id' => $reportId,
                'estudo_id' => (int) $identity['estudo_id'],
                'report_version' => $version,
            ]),
            'historical_report_version' => $contextBuilder->buildFromHistoricalVersion([
                'tenant_id' => $tenantId,
                'report_id' => $reportId,
                'estudo_id' => (int) $identity['estudo_id'],
                'report_version' => $version,
            ]),
            default => $contextBuilder->build([
            'tenant_id' => $tenantId,
            'report_id' => $reportId,
            'estudo_id' => (int) $identity['estudo_id'],
            'report_version' => $version,
            ]),
        };
        $sourceContentSha256 = $sourceKind === 'historical_report_version'
            ? strtolower((string) ($context['source_content_sha256'] ?? ''))
            : null;
        $binary = (new ReportPdfService())->renderSnapshotBinary($context);
        $this->assertPdf($binary);

        $pdfHash = strtolower(hash('sha256', $binary));
        $pdfSize = strlen($binary);
        if ($sourceKind === 'canonical_snapshot' && !preg_match('/^[0-9a-f]{64}$/', (string) $sourcePdfSnapshotSha256)) {
            throw new RuntimeException('Hash do snapshot-fonte da revisão PDF inválido.');
        }
        if ($sourceKind === 'historical_report_version' && !preg_match('/^[0-9a-f]{64}$/', (string) $sourceContentSha256)) {
            throw new RuntimeException('Hash do conteúdo histórico da revisão PDF inválido.');
        }
        $revisionKey = hash('sha256', DeliveryRequestIdentity::canonicalJson([
            'schema_version' => self::SCHEMA_VERSION,
            'tenant_id' => $tenantId,
            'report_id' => $reportId,
            'report_version' => $version,
            'report_version_row_id' => (int) $identity['report_version_row_id'],
            'source_kind' => $sourceKind,
            'source_pdf_snapshot_sha256' => $sourcePdfSnapshotSha256,
            'source_content_sha256' => $sourceContentSha256,
            'pdf_snapshot_sha256' => $pdfHash,
            'pdf_snapshot_size_bytes' => $pdfSize,
            'renderer' => self::RENDERER,
            'reason_code' => $reasonCode,
        ]));

        $storageBase = PdfSnapshotPathResolver::storageBasePath();
        $directory = sprintf(
            '%s/report_version_pdf_revisions/%d/%d/v%d',
            $storageBase,
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
        $relativePath = PdfSnapshotPathResolver::relativePathFor(
            $path,
            sprintf('report_version_pdf_revisions/%d/%d/v%d', $tenantId, $reportId, $version)
        );

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
                     revision_key, source_kind, source_pdf_snapshot_sha256, source_content_sha256,
                     pdf_snapshot_path, pdf_snapshot_sha256,
                     pdf_snapshot_size_bytes, pdf_snapshot_renderer, pdf_snapshot_schema_version,
                     reason_code, created_by)
                 VALUES
                    (:tenant_id, :report_id, :report_version, :report_version_row_id, :revision_number,
                     :revision_key, :source_kind, :source_pdf_snapshot_sha256, :source_content_sha256,
                     :pdf_snapshot_path, :pdf_snapshot_sha256,
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
                ':source_kind' => $sourceKind,
                ':source_pdf_snapshot_sha256' => $sourcePdfSnapshotSha256,
                ':source_content_sha256' => $sourceContentSha256,
                     ':pdf_snapshot_path' => $relativePath,
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
            'source_kind' => $sourceKind,
            'source_pdf_snapshot_sha256' => $sourcePdfSnapshotSha256,
            'source_content_sha256' => $sourceContentSha256,
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

    /** @return array{content:string,sha256:string,size:int,path:string,revision_id:int,revision_number:int,report_version:int}|null */
    public function readLatestForViewer(int $tenantId, int $reportId): ?array
    {
        if ($tenantId <= 0 || $reportId <= 0) {
            return null;
        }

        $pdo = $this->pdo ?? Database::getInstance();
        try {
            $stmt = $pdo->prepare(
                "SELECT rev.*
                   FROM pacs_report_version_pdf_revisions rev
                   INNER JOIN reports r
                           ON r.id = rev.report_id
                          AND r.tenant_id = rev.tenant_id
                   INNER JOIN report_versions rv
                           ON rv.id = rev.report_version_row_id
                          AND rv.report_id = rev.report_id
                          AND rv.versao = rev.report_version
                  WHERE rev.tenant_id = :tenant_id
                    AND rev.report_id = :report_id
                    AND r.situacao IN ('assinado', 'liberado')
                    AND rv.acao IN ('assinado', 'liberado')
                  ORDER BY rev.report_version DESC,
                           rev.revision_number DESC,
                           rev.id DESC
                  LIMIT 1"
            );
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':report_id' => $reportId,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
            if (in_array($sqlState, ['42P01', '42703', '42S02', '42S22'], true)) {
                return null;
            }
            throw $e;
        }

        if (!is_array($row)) {
            return null;
        }

        return $this->readContent(
            $row,
            $tenantId,
            (int) $row['report_id'],
            (int) $row['report_version']
        );
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
        $path = (string) ($row['pdf_snapshot_path'] ?? '');
        $pathReal = PdfSnapshotPathResolver::resolve(
            $path,
            sprintf('report_version_pdf_revisions/%d/%d/v%d', $tenantId, $reportId, $version)
        );
        if (!preg_match('/^[0-9a-f]{64}$/', $expectedHash)
            || $expectedSize < 100
            || $pathReal === null
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
            'report_version' => $version,
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
            'source_kind' => (string) $row['source_kind'],
            'source_pdf_snapshot_sha256' => $row['source_pdf_snapshot_sha256'] !== null
                ? (string) $row['source_pdf_snapshot_sha256']
                : null,
            'source_content_sha256' => $row['source_content_sha256'] !== null
                ? (string) $row['source_content_sha256']
                : null,
            'source_delivery_artifact_id' => $row['source_delivery_artifact_id'] !== null
                ? (int) $row['source_delivery_artifact_id']
                : null,
            'source_delivery_artifact_sha256' => $row['source_delivery_artifact_sha256'] !== null
                ? (string) $row['source_delivery_artifact_sha256']
                : null,
            'source_delivery_artifact_size_bytes' => $row['source_delivery_artifact_size_bytes'] !== null
                ? (int) $row['source_delivery_artifact_size_bytes']
                : null,
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

    private function resolveArtifactPath(string $storedPath): ?string
    {
        $storageRoot = realpath(PdfSnapshotPathResolver::storageBasePath());
        if ($storageRoot === false || trim($storedPath) === '') {
            return null;
        }
        $candidate = str_starts_with($storedPath, '/')
            ? $storedPath
            : $storageRoot . '/' . ltrim(str_replace('\\', '/', $storedPath), '/');
        $pathReal = realpath($candidate);
        if ($pathReal === false || !is_file($pathReal)) {
            return null;
        }
        return $pathReal === $storageRoot || str_starts_with($pathReal, $storageRoot . DIRECTORY_SEPARATOR)
            ? $pathReal
            : null;
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
