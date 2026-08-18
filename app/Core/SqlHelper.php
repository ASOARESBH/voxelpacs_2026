<?php
namespace App\Core;

use PDO;

/**
 * Gera fragmentos SQL para diferenças pontuais entre MySQL e PostgreSQL.
 *
 * As expressões recebidas devem ser constantes definidas pelo código. Nunca
 * passe dados de requisição para estes métodos; valores continuam devendo ser
 * enviados como parâmetros preparados do PDO.
 */
final class SqlHelper
{
    public static function isPostgres(): bool
    {
        return strtolower((string) ($_ENV['DB_DRIVER'] ?? 'mysql')) === 'pgsql';
    }

    /**
     * Formata uma expressão de data/hora nos formatos usados pelo VOXEL PACS.
     */
    public static function dateFormat(string $expression, string $mysqlFormat): string
    {
        if (!self::isPostgres()) {
            return "DATE_FORMAT({$expression}, " . self::quoteLiteral($mysqlFormat) . ')';
        }

        $formatos = [
            '%Y-%m' => 'YYYY-MM',
            '%Y-%m-%d' => 'YYYY-MM-DD',
            '%d/%m/%Y' => 'DD/MM/YYYY',
            '%H:%i' => 'HH24:MI',
            '%H:%i:%s' => 'HH24:MI:SS',
        ];

        if (!isset($formatos[$mysqlFormat])) {
            throw new \InvalidArgumentException('Formato de data SQL não mapeado para PostgreSQL.');
        }

        return "TO_CHAR({$expression}, " . self::quoteLiteral($formatos[$mysqlFormat]) . ')';
    }

    /**
     * Retorna a diferença entre duas expressões temporais na unidade solicitada.
     */
    public static function timestampDiff(string $unit, string $inicio, string $fim): string
    {
        $unit = strtoupper(trim($unit));
        $divisores = [
            'MINUTE' => 60,
            'HOUR' => 3600,
            'DAY' => 86400,
        ];

        if (!isset($divisores[$unit])) {
            throw new \InvalidArgumentException('Unidade de diferença temporal não suportada.');
        }

        if (!self::isPostgres()) {
            return "TIMESTAMPDIFF({$unit}, {$inicio}, {$fim})";
        }

        return "(EXTRACT(EPOCH FROM (({$fim}) - ({$inicio})))/{$divisores[$unit]})";
    }

    /**
     * Retorna agregação textual compatível, incluindo ordenação determinística.
     */
    public static function groupConcat(string $expression, string $separator, string $order = ''): string
    {
        if (!self::isPostgres()) {
            $orderSql = trim($order) !== '' ? ' ORDER BY ' . $order : '';
            return "GROUP_CONCAT({$expression}{$orderSql} SEPARATOR " . self::quoteLiteral($separator) . ')';
        }

        $orderSql = trim($order) !== '' ? ' ORDER BY ' . $order : '';
        return "STRING_AGG(({$expression})::text, " . self::quoteLiteral($separator) . "{$orderSql})";
    }

    /**
     * Compara textos sem diferenciar maiúsculas/minúsculas usando a semântica
     * historicamente adotada nas comparações de Institution Name.
     */
    public static function caseInsensitiveEquals(string $left, string $right): string
    {
        if (self::isPostgres()) {
            return "LOWER({$left}) = LOWER({$right})";
        }

        return "{$left} COLLATE utf8mb4_general_ci = {$right} COLLATE utf8mb4_general_ci";
    }

    /**
     * Lista colunas de tabela sem usar SHOW COLUMNS no PostgreSQL.
     *
     * @return list<string>
     */
    public static function tableColumns(PDO $pdo, string $table): array
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Nome de tabela inválido para introspecção.');
        }

        if (!self::isPostgres()) {
            return $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        }

        $stmt = $pdo->prepare(
            'SELECT column_name
               FROM information_schema.columns
              WHERE table_schema = current_schema()
                AND table_name = :table
              ORDER BY ordinal_position'
        );
        $stmt->execute([':table' => $table]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        return in_array($column, self::tableColumns($pdo, $table), true);
    }

    /**
     * Confirma se uma tabela está presente no schema ativo sem depender de
     * migrations opcionais. Útil para preservar telas de leitura em bancos
     * de homologação que ainda não receberam todos os módulos recentes.
     */
    public static function hasTable(PDO $pdo, string $table): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Nome de tabela inválido para introspecção.');
        }

        if (!self::isPostgres()) {
            return $pdo->query('SHOW TABLES LIKE ' . self::quoteLiteral($table))->fetchColumn() !== false;
        }

        $stmt = $pdo->prepare(
            'SELECT 1
               FROM information_schema.tables
              WHERE table_schema = current_schema()
                AND table_name = :table
              LIMIT 1'
        );
        $stmt->execute([':table' => $table]);
        return $stmt->fetchColumn() !== false;
    }

    private static function quoteLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
