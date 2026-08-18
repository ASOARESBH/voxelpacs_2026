<?php
namespace App\Core;

use PDO;
use PDOException;

class Database {
    private static ?PDO $instance = null;

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            try {
                $driver  = strtolower((string) ($_ENV['DB_DRIVER'] ?? 'mysql'));
                $host    = $_ENV['DB_HOST']     ?? 'localhost';
                $db      = $_ENV['DB_DATABASE'] ?? 'voxel_bi';
                $user    = $_ENV['DB_USERNAME'] ?? 'root';
                $pass    = $_ENV['DB_PASSWORD'] ?? '';
                $port    = $_ENV['DB_PORT']     ?? ($driver === 'pgsql' ? '5432' : '3306');
                $charset = 'utf8mb4';
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ];

                if ($driver === 'pgsql') {
                    $schema = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_ENV['DB_SCHEMA'] ?? 'public')) ?: 'public';
                    $dsn = "pgsql:host={$host};port={$port};dbname={$db};options='--search_path={$schema},public'";
                    self::$instance = new PostgresPdo($dsn, $user, $pass, $options);
                } elseif ($driver === 'mysql') {
                    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
                    self::$instance = new PDO($dsn, $user, $pass, $options);
                } else {
                    throw new \InvalidArgumentException('Driver de banco não suportado.');
                }
            } catch (PDOException|\InvalidArgumentException $e) {
                Logger::error('Falha na conexão com o banco de dados', [
                    'driver' => $_ENV['DB_DRIVER'] ?? 'mysql',
                    'message' => $e->getMessage(),
                ]);
                throw new \RuntimeException('Erro de conexão com o banco de dados.', 500);
            }
        }

        return self::$instance;
    }

    /**
     * Executa uma query de escrita (INSERT, UPDATE, DELETE) com log de erro automático
     * Requisito: API Database Write Error Logging Requirement
     */
    public static function executeWrite(string $sql, array $params = []): bool {
        try {
            $stmt = self::getInstance()->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            Logger::error('Erro em operação de escrita no banco de dados', [
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
