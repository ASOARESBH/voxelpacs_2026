<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Contracts\PhilipsSubmissionAuthorLookup;
use App\Core\Database;
use PDO;
final class PhilipsSubmissionAuthorRepository implements PhilipsSubmissionAuthorLookup
{
    private ?PDO $pdo;
    private ?string $lastFailureSqlState = null;
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    public function lastFailureSqlState(): ?string
    {
        return $this->lastFailureSqlState;
    }
    /**
     * Resolve o médico que assinou o laudo, nunca o usuário que apenas liberou.
     * Para uma versão `assinado`, usa a própria versão; para `liberado`, usa a
     * versão `assinado` mais recente com número menor ou igual ao Job.
     * O estado ativo não é reavaliado e a duplicidade falha fechado.
     * @return array{id:int,tenant_id:int,nome:string,ativo:int}|null
     */
    public function findSignerForReportVersion(
        int $tenantId,
        int $reportId,
        int $reportVersion,
        int $studyId
    ): ?array {
        if ($tenantId <= 0 || $reportId <= 0 || $reportVersion <= 0 || $studyId <= 0) {
            return null;
        }
        $this->lastFailureSqlState = null;
        try {
            $pdo = $this->pdo ??= Database::getInstance();
            $signerVersion = $this->resolveSignerVersion(
                $pdo,
                $tenantId,
                $reportId,
                $reportVersion,
                $studyId
            );
            if ($signerVersion === null) {
                return null;
            }
            $statement = $pdo->prepare(<<<'SQL'
SELECT m.id AS id,
       r.tenant_id AS tenant_id,
       m.nome AS nome,
       m.ativo AS ativo
FROM reports r
INNER JOIN bi_pacs_estudos e
        ON e.id = r.estudo_id
       AND e.tenant_id = r.tenant_id
INNER JOIN report_versions rv
        ON rv.report_id = r.id
       AND rv.versao = :signer_version
INNER JOIN bi_medicos m
        ON m.usuario_id = rv.usuario_id
       AND m.tenant_id = r.tenant_id
WHERE r.id = :report_id
  AND r.tenant_id = :tenant_id
  AND r.estudo_id = :study_id
  AND rv.usuario_id IS NOT NULL
  AND rv.usuario_id > 0
  AND rv.acao = 'assinado'
LIMIT 2
SQL);
            $statement->execute([
                'tenant_id' => $tenantId,
                'report_id' => $reportId,
                'signer_version' => $signerVersion,
                'study_id' => $studyId,
            ]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $error) {
            $code = $error instanceof \PDOException ? (string) $error->getCode() : '';
            $this->lastFailureSqlState = preg_match('/^[0-9A-Z]{5}$/', $code) === 1 ? $code : 'UNKNOWN';
            return null;
        }
        return count($rows) === 1 ? $this->normalizeRow($rows[0]) : null;
    }

    private function resolveSignerVersion(
        PDO $pdo,
        int $tenantId,
        int $reportId,
        int $reportVersion,
        int $studyId
    ): ?int {
        $targetStatement = $pdo->prepare(<<<'SQL'
SELECT rv.acao
FROM reports r
INNER JOIN bi_pacs_estudos e
        ON e.id = r.estudo_id
       AND e.tenant_id = r.tenant_id
INNER JOIN report_versions rv
        ON rv.report_id = r.id
       AND rv.versao = :report_version
WHERE r.id = :report_id
  AND r.tenant_id = :tenant_id
  AND r.estudo_id = :study_id
LIMIT 2
SQL);
        $targetStatement->execute([
            'tenant_id' => $tenantId,
            'report_id' => $reportId,
            'report_version' => $reportVersion,
            'study_id' => $studyId,
        ]);
        $targetRows = $targetStatement->fetchAll(PDO::FETCH_ASSOC);
        if (count($targetRows) !== 1) {
            return null;
        }

        $action = (string) ($targetRows[0]['acao'] ?? '');
        if ($action === 'assinado') {
            return $reportVersion;
        }
        if ($action !== 'liberado') {
            return null;
        }

        $signedStatement = $pdo->prepare(<<<'SQL'
SELECT rv.versao
FROM reports r
INNER JOIN bi_pacs_estudos e
        ON e.id = r.estudo_id
       AND e.tenant_id = r.tenant_id
INNER JOIN report_versions rv
        ON rv.report_id = r.id
WHERE r.id = :report_id
  AND r.tenant_id = :tenant_id
  AND r.estudo_id = :study_id
  AND rv.acao = 'assinado'
  AND rv.versao <= :report_version
  AND rv.usuario_id IS NOT NULL
  AND rv.usuario_id > 0
ORDER BY rv.versao DESC
LIMIT 1
SQL);
        $signedStatement->execute([
            'tenant_id' => $tenantId,
            'report_id' => $reportId,
            'report_version' => $reportVersion,
            'study_id' => $studyId,
        ]);
        $signedVersion = (int) $signedStatement->fetchColumn();
        return $signedVersion > 0 ? $signedVersion : null;
    }

    /** @return array{id:int,tenant_id:int,nome:string,ativo:int}|null */
    private function normalizeRow(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $id = (int) ($row['id'] ?? 0);
        $tenantId = (int) ($row['tenant_id'] ?? 0);
        $name = is_string($row['nome'] ?? null) ? trim($row['nome']) : '';
        $active = (int) ($row['ativo'] ?? 0);
        if ($id <= 0 || $tenantId <= 0 || $name === '') {
            return null;
        }
        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'nome' => $name,
            'ativo' => $active,
        ];
    }
}
