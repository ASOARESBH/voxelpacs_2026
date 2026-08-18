<?php
/**
 * Regressão de compatibilidade SQL PostgreSQL.
 *
 * Execução exclusiva por CLI, com DB_DRIVER=pgsql. Todas as operações mutáveis
 * são realizadas em tabela temporária dentro de uma transação revertida.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$required = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SCHEMA'];
foreach ($required as $key) {
    $value = getenv($key);
    if ($value === false || $value === '') {
        fwrite(STDERR, "Variável obrigatória ausente: {$key}\n");
        exit(2);
    }
    $_ENV[$key] = $value;
}
$_ENV['DB_DRIVER'] = 'pgsql';

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Database;
use App\Core\SqlHelper;

function assertPgCompatibility(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FALHOU] {$message}\n");
        exit(1);
    }

    fwrite(STDOUT, "[OK] {$message}\n");
}

assertPgCompatibility(SqlHelper::isPostgres(), 'DB_DRIVER está configurado para PostgreSQL');

$pdo = Database::getInstance();
$pdo->beginTransaction();
try {
    $columns = SqlHelper::tableColumns($pdo, 'bi_tenants');
    assertPgCompatibility(in_array('id', $columns, true), 'Introspecção de colunas via information_schema');
    assertPgCompatibility(SqlHelper::hasTable($pdo, 'bi_tenants'), 'Introspecção de tabela existente via information_schema');
    assertPgCompatibility(!SqlHelper::hasTable($pdo, '__tabela_pg_inexistente__'), 'Introspecção de tabela inexistente via information_schema');

    $monthSql = SqlHelper::dateFormat('NOW()', '%Y-%m');
    $month = $pdo->query("SELECT {$monthSql}")->fetchColumn();
    assertPgCompatibility(is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1, 'Formatação mensal equivalente a DATE_FORMAT');

    $minutesSql = SqlHelper::timestampDiff('MINUTE', "NOW() - INTERVAL '5 minutes'", 'NOW()');
    $minutes = (float) $pdo->query("SELECT {$minutesSql}")->fetchColumn();
    assertPgCompatibility($minutes >= 4.9 && $minutes <= 5.1, 'Diferença temporal equivalente a TIMESTAMPDIFF');

    $namesSql = SqlHelper::groupConcat('v.nome', '|', 'v.nome');
    $names = $pdo->query("SELECT {$namesSql} FROM (VALUES ('B'), ('A')) AS v(nome)")->fetchColumn();
    assertPgCompatibility($names === 'A|B', 'Agregação textual equivalente a GROUP_CONCAT');

    $tenantCount = (int) $pdo->query('SELECT COUNT(*) FROM `bi_tenants`')->fetchColumn();
    assertPgCompatibility($tenantCount >= 0, 'Normalização segura de crases MySQL');

    $activeUnits = (int) $pdo->query('SELECT COUNT(*) FROM bi_unidades WHERE ativo = 1')->fetchColumn();
    assertPgCompatibility($activeUnits >= 0, 'Flags TINYINT(1) normalizadas para comparação numérica');

    $reportSituacao = $pdo->query("SELECT COALESCE((SELECT situacao::text FROM reports LIMIT 1), '')")->fetchColumn();
    assertPgCompatibility(is_string($reportSituacao), 'Enum de situação de laudo convertido para texto na Worklist');

    $assumiveis = (int) $pdo->query(
        "SELECT COUNT(*) FROM bi_pacs_estudos
         WHERE COALESCE(situacao::text, 'novo') IN ('novo', 'aberto')
           AND usuario_responsavel_id IS NULL"
    )->fetchColumn();
    assertPgCompatibility($assumiveis >= 0, 'Cláusula de elegibilidade para assumir estudo aceita ENUM PostgreSQL');

    $tenantId = (int) $pdo->query('SELECT id FROM bi_tenants ORDER BY id LIMIT 1')->fetchColumn();
    assertPgCompatibility($tenantId > 0, 'Tenant de referência disponível para validação de UPSERT');
    $probeKey = '__pg_compat_' . bin2hex(random_bytes(8));
    $configUpsert = $pdo->prepare(
        'INSERT INTO bi_configuracoes (tenant_id, chave, valor) VALUES (:tenant_id, :chave, :valor)
         ON CONFLICT (tenant_id, chave) DO UPDATE SET valor = EXCLUDED.valor'
    );
    $configUpsert->execute([':tenant_id' => $tenantId, ':chave' => $probeKey, ':valor' => 'primeiro']);
    $configUpsert->execute([':tenant_id' => $tenantId, ':chave' => $probeKey, ':valor' => 'segundo']);
    $storedValue = $pdo->prepare('SELECT valor FROM bi_configuracoes WHERE tenant_id = :tenant_id AND chave = :chave');
    $storedValue->execute([':tenant_id' => $tenantId, ':chave' => $probeKey]);
    assertPgCompatibility($storedValue->fetchColumn() === 'segundo', 'UPSERT real de configurações por tenant');

    $pdo->exec('CREATE TEMP TABLE pg_compat_upsert (tenant_id bigint NOT NULL, chave text NOT NULL, valor text NOT NULL, UNIQUE (tenant_id, chave))');
    $upsert = $pdo->prepare(
        'INSERT INTO pg_compat_upsert (tenant_id, chave, valor) VALUES (1, :chave, :valor)
         ON CONFLICT (tenant_id, chave) DO UPDATE SET valor = EXCLUDED.valor'
    );
    $upsert->execute([':chave' => 'teste', ':valor' => 'primeiro']);
    $upsert->execute([':chave' => 'teste', ':valor' => 'segundo']);
    $value = $pdo->query("SELECT valor FROM pg_compat_upsert WHERE tenant_id = 1 AND chave = 'teste'")->fetchColumn();
    assertPgCompatibility($value === 'segundo', 'UPSERT ON CONFLICT atualiza chave única corretamente');

    $pdo->rollBack();
    fwrite(STDOUT, "Regressão de consultas PostgreSQL aprovada.\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[FALHOU] ' . $e->getMessage() . "\n");
    exit(1);
}
