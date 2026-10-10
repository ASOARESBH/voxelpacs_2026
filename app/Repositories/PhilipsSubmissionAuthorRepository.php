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
     * @return array{id:int,tenant_id:int,nome:string,ativo:int}|null
     */
    public function findActiveBiMedico(int $tenantId, int $authorId): ?array
    {
        if ($tenantId <= 0 || $authorId <= 0) {
            return null;
        }

        try {
            $pdo = $this->pdo ??= Database::getInstance();
            $statement = $pdo->prepare(
                'SELECT id, tenant_id, nome, ativo\n'
                . 'FROM bi_medicos\n'
                . 'WHERE id = :author_id\n'
                . '  AND tenant_id = :tenant_id\n'
                . '  AND ativo = 1\n'
                . 'LIMIT 1'
            );
            $statement->execute([
                'author_id' => $authorId,
                'tenant_id' => $tenantId,
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'tenant_id' => (int) ($row['tenant_id'] ?? 0),
            'nome' => is_string($row['nome'] ?? null) ? trim($row['nome']) : '',
            'ativo' => (int) ($row['ativo'] ?? 0),
        ];
    }
}
