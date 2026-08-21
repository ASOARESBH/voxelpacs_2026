<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use PDO;

/** Histórico global e sanitizado dos disparos de conectores da plataforma. */
class ConectorLog extends Model
{
    protected string $table = 'bi_conectores_log';
    protected bool $hasTenant = false;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $payload = $data['payload'] ?? [];
        if (!is_string($payload)) {
            $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        $sql = "INSERT INTO {$this->table}
                    (conector_tipo, evento, destino, mensagem, payload, status, resposta, http_code)
                VALUES (?, ?, ?, ?, CAST(? AS JSONB), ?, ?, ?)
                RETURNING id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $data['conector_tipo'],
            $data['evento'],
            $data['destino'] ?? null,
            $data['mensagem'] ?? null,
            $payload,
            $data['status'] ?? 'pendente',
            $data['resposta'] ?? null,
            $data['http_code'] ?? null,
        ]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<int, array<string, mixed>> */
    public function recentes(?string $tipo = null, int $limite = 100): array
    {
        $limite = max(1, min(100, $limite));
        $sql = "SELECT id, conector_tipo, evento, destino, mensagem, status, resposta, http_code, created_at
                FROM {$this->table}";
        $params = [];
        if (in_array($tipo, ['whatsapp', 'telegram'], true)) {
            $sql .= ' WHERE conector_tipo = ?';
            $params[] = $tipo;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ' . $limite;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, int> */
    public function contagensRecentes(): array
    {
        $rows = $this->pdo->query(
            "SELECT conector_tipo, COUNT(*)::int AS total
             FROM {$this->table}
             WHERE created_at >= NOW() - INTERVAL '30 days'
             GROUP BY conector_tipo"
        )->fetchAll(PDO::FETCH_ASSOC);

        $contagens = ['whatsapp' => 0, 'telegram' => 0];
        foreach ($rows as $row) {
            $contagens[$row['conector_tipo']] = (int) $row['total'];
        }
        return $contagens;
    }
}
