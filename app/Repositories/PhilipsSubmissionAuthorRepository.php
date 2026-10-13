<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Contracts\PhilipsSubmissionAuthorLookup;
use App\Core\Database;
use PDO;
final class PhilipsSubmissionAuthorRepository implements PhilipsSubmissionAuthorLookup
{
    private ?PDO $pdo;
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }
    /**
     * O usuário canônico é report_versions.usuario_id. A versão exata e o estudo são
     * joins obrigatórios; o XML recebe bi_medicos.id e o nome do cadastro resolvido.
     * O estado ativo não é reavaliado: desativar o cadastro depois da assinatura
     * não pode mudar a autoria histórica. Duplicidade continua falhando fechado.
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
        try {
            $pdo = $this->pdo ??= Database::getInstance();
            $statement = $pdo->prepare(
                'SELECT m.id AS id,\n'
                . '       r.tenant_id AS tenant_id,\n'
                . '       m.nome AS nome,\n'
                . '       m.ativo AS ativo\n'
                . 'FROM reports r\n'
                . 'INNER JOIN bi_pacs_estudos e\n'
                . '        ON e.id = r.estudo_id\n'
                . '       AND e.tenant_id = r.tenant_id\n'
                . 'INNER JOIN report_versions rv\n'
                . '        ON rv.report_id = r.id\n'
                . '       AND rv.versao = :report_version\n'
                . 'INNER JOIN bi_medicos m\n'
                . '        ON m.usuario_id = rv.usuario_id\n'
                . '       AND m.tenant_id = r.tenant_id\n'
                . 'WHERE r.id = :report_id\n'
                . '  AND r.tenant_id = :tenant_id\n'
                . '  AND r.estudo_id = :study_id\n'
                . '  AND rv.usuario_id IS NOT NULL\n'
                . '  AND rv.usuario_id > 0\n'
                . '  AND rv.acao IN (\'assinado\', \'liberado\')\n'
                . 'LIMIT 2'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'report_id' => $reportId,
                'report_version' => $reportVersion,
                'study_id' => $studyId,
            ]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }
        return count($rows) === 1 ? $this->normalizeRow($rows[0]) : null;
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
