<?php
/**
 * VOXEL B.I — Instalador de Tabelas
 * Acesse: https://bi.voxelbi.com.br/install_tables.php
 * IMPORTANTE: Delete este arquivo após a instalação!
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/autoload.php';

// Carrega .env manualmente
$envFile = BASE_PATH . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val, " \t\n\r\0\x0B\"'");
    }
}

// Conecta ao banco
try {
    $pdo = new PDO(
        "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_DATABASE']};charset=utf8mb4",
        $_ENV['DB_USERNAME'],
        $_ENV['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    die("<h2 style='color:red'>Erro de conexão: " . htmlspecialchars($e->getMessage()) . "</h2>");
}

$results = [];

// ============================================================
// TABELAS A CRIAR
// ============================================================
$tables = [

    // Colunas na bi_tenants (executadas individualmente)
    'alter_bi_tenants' => [
        'type' => 'alter_columns',
        'table' => 'bi_tenants',
        'columns' => [
            'razao_social'       => 'VARCHAR(255) NULL',
            'nome_fantasia'      => 'VARCHAR(255) NULL',
            'cnpj'               => 'VARCHAR(18) NULL',
            'inscricao_estadual' => 'VARCHAR(30) NULL',
            'inscricao_municipal'=> 'VARCHAR(30) NULL',
            'cep'                => 'VARCHAR(9) NULL',
            'logradouro'         => 'VARCHAR(255) NULL',
            'numero'             => 'VARCHAR(20) NULL',
            'complemento'        => 'VARCHAR(100) NULL',
            'bairro'             => 'VARCHAR(100) NULL',
            'cidade'             => 'VARCHAR(100) NULL',
            'estado'             => 'CHAR(2) NULL',
            'pais'               => "VARCHAR(50) NULL DEFAULT 'Brasil'",
            'site'               => 'VARCHAR(255) NULL',
            'descricao'          => 'TEXT NULL',
            'porte'              => 'VARCHAR(20) NULL',
            'natureza_juridica'  => 'VARCHAR(100) NULL',
            'cnae_principal'     => 'VARCHAR(20) NULL',
            'cnae_descricao'     => 'VARCHAR(255) NULL',
            'data_abertura'      => 'DATE NULL',
            'situacao_cadastral' => 'VARCHAR(50) NULL',
            'observacoes'        => 'TEXT NULL',
        ]
    ],

    // bi_negocio_contatos
    'bi_negocio_contatos' => [
        'type' => 'create_table',
        'sql' => "CREATE TABLE IF NOT EXISTS `bi_negocio_contatos` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tenant_id`    INT UNSIGNED NOT NULL,
            `nome`         VARCHAR(150) NOT NULL,
            `cargo`        VARCHAR(100) NULL,
            `departamento` VARCHAR(100) NULL,
            `email`        VARCHAR(150) NULL,
            `telefone`     VARCHAR(20)  NULL,
            `celular`      VARCHAR(20)  NULL,
            `whatsapp`     VARCHAR(20)  NULL,
            `principal`    TINYINT(1)   NOT NULL DEFAULT 0,
            `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_tenant` (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ],

    // bi_negocio_institution_names
    'bi_negocio_institution_names' => [
        'type' => 'create_table',
        'sql' => "CREATE TABLE IF NOT EXISTS `bi_negocio_institution_names` (
            `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tenant_id`        INT UNSIGNED NOT NULL,
            `institution_name` VARCHAR(255) NOT NULL,
            `descricao`        VARCHAR(255) NULL,
            `ativo`            TINYINT(1)   NOT NULL DEFAULT 1,
            `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_tenant_name` (`tenant_id`, `institution_name`),
            INDEX `idx_tenant` (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ],

    // bi_negocio_plano_historico
    'bi_negocio_plano_historico' => [
        'type' => 'create_table',
        'sql' => "CREATE TABLE IF NOT EXISTS `bi_negocio_plano_historico` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tenant_id`    INT UNSIGNED NOT NULL,
            `plan_id`      INT UNSIGNED NULL,
            `plano_nome`   VARCHAR(100) NULL,
            `status`       VARCHAR(30)  NOT NULL DEFAULT 'ativo',
            `alterado_por` INT UNSIGNED NULL,
            `motivo`       TEXT NULL,
            `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_tenant` (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ],

    // bi_orthanc_servidores
    'bi_orthanc_servidores' => [
        'type' => 'create_table',
        'sql' => "CREATE TABLE IF NOT EXISTS `bi_orthanc_servidores` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tenant_id`   INT UNSIGNED NOT NULL,
            `nome`        VARCHAR(100) NOT NULL,
            `url`         VARCHAR(255) NOT NULL,
            `usuario`     VARCHAR(100) NULL,
            `senha`       VARCHAR(255) NULL,
            `timeout`     INT          NOT NULL DEFAULT 10,
            `ativo`       TINYINT(1)   NOT NULL DEFAULT 1,
            `ultimo_ping` TIMESTAMP    NULL,
            `ultimo_status` VARCHAR(20) NULL,
            `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_tenant` (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ],

    // bi_orthanc_sync_log
    'bi_orthanc_sync_log' => [
        'type' => 'create_table',
        'sql' => "CREATE TABLE IF NOT EXISTS `bi_orthanc_sync_log` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `servidor_id`  INT UNSIGNED NOT NULL,
            `tenant_id`    INT UNSIGNED NOT NULL,
            `tipo`         VARCHAR(30)  NOT NULL DEFAULT 'sync',
            `status`       VARCHAR(20)  NOT NULL DEFAULT 'ok',
            `total_estudos` INT         NULL,
            `novos`        INT          NULL DEFAULT 0,
            `erros`        INT          NULL DEFAULT 0,
            `mensagem`     TEXT         NULL,
            `duracao_ms`   INT          NULL,
            `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_servidor` (`servidor_id`),
            INDEX `idx_tenant` (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ],
];

// ============================================================
// EXECUTA
// ============================================================
foreach ($tables as $key => $def) {
    if ($def['type'] === 'create_table') {
        try {
            $pdo->exec($def['sql']);
            $results[] = ['status' => 'ok', 'msg' => "Tabela <strong>{$key}</strong> criada/verificada com sucesso."];
        } catch (Exception $e) {
            $results[] = ['status' => 'error', 'msg' => "Erro em <strong>{$key}</strong>: " . htmlspecialchars($e->getMessage())];
        }
    } elseif ($def['type'] === 'alter_columns') {
        $table = $def['table'];
        foreach ($def['columns'] as $col => $colDef) {
            // Verifica se a coluna já existe
            $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $check->execute([$_ENV['DB_DATABASE'], $table, $col]);
            if ($check->fetchColumn() > 0) {
                $results[] = ['status' => 'skip', 'msg' => "Coluna <strong>{$col}</strong> em <strong>{$table}</strong> já existe — ignorada."];
                continue;
            }
            try {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$colDef}");
                $results[] = ['status' => 'ok', 'msg' => "Coluna <strong>{$col}</strong> adicionada em <strong>{$table}</strong>."];
            } catch (Exception $e) {
                $results[] = ['status' => 'error', 'msg' => "Erro ao adicionar coluna <strong>{$col}</strong>: " . htmlspecialchars($e->getMessage())];
            }
        }
    }
}

$ok    = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
$skip  = count(array_filter($results, fn($r) => $r['status'] === 'skip'));
$error = count(array_filter($results, fn($r) => $r['status'] === 'error'));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>VOXEL B.I — Instalador de Tabelas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:800px">
    <div class="card shadow">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0"><i class="fa fa-database me-2"></i>VOXEL B.I — Instalador de Tabelas</h4>
        </div>
        <div class="card-body">
            <div class="alert <?= $error > 0 ? 'alert-warning' : 'alert-success' ?>">
                <strong>Resultado:</strong>
                <?= $ok ?> criadas/executadas &nbsp;|&nbsp;
                <?= $skip ?> ignoradas (já existiam) &nbsp;|&nbsp;
                <?= $error ?> erros
            </div>

            <table class="table table-sm table-bordered">
                <thead class="table-dark">
                    <tr><th>Status</th><th>Detalhe</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $r): ?>
                    <tr class="<?= $r['status'] === 'ok' ? 'table-success' : ($r['status'] === 'error' ? 'table-danger' : 'table-secondary') ?>">
                        <td><strong><?= strtoupper($r['status']) ?></strong></td>
                        <td><?= $r['msg'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($error === 0): ?>
            <div class="alert alert-success mt-3">
                <strong>Instalação concluída com sucesso!</strong><br>
                <span class="text-danger">⚠️ Delete este arquivo do servidor por segurança: <code>public/install_tables.php</code></span>
            </div>
            <?php endif; ?>

            <a href="/platform/negocios" class="btn btn-primary mt-2">Ir para Negócios</a>
            <a href="/platform/dashboard" class="btn btn-secondary mt-2">Ir para Dashboard</a>
        </div>
    </div>
</div>
</body>
</html>
