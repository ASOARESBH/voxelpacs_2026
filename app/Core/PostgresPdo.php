<?php
namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Conexão PDO PostgreSQL com normalização conservadora de identificadores.
 *
 * O VOXEL PACS historicamente usa crases MySQL em consultas parametrizadas.
 * PostgreSQL exige aspas duplas para preservar os mesmos identificadores.
 * Transformações de semântica (UPSERT, DATE_FORMAT, SHOW COLUMNS etc.)
 * permanecem explícitas nos módulos correspondentes, evitando reescrita SQL
 * implícita e insegura em produção.
 */
final class PostgresPdo extends PDO {
    private function normalizar(string $sql): string {
        return str_replace('`', '"', $sql);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare($this->normalizar($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $query = $this->normalizar($query);
        if ($fetchMode === null) {
            return parent::query($query);
        }

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false {
        return parent::exec($this->normalizar($statement));
    }
}
