<?php
/**
 * VOXEL B.I - Script de Diagnóstico para HostGator
 * Acesse: https://bi.voxelbi.com.br/test.php
 * IMPORTANTE: bootstrap.php carregado PRIMEIRO (antes de qualquer output)
 */

// Carrega bootstrap ANTES de qualquer echo/output (session, headers, autoload, env)
$base = dirname(__DIR__);
require_once $base . '/app/bootstrap.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Diagnóstico VOXEL B.I</h1>";

// 1. Teste de PHP
echo "<h2>1. Ambiente PHP</h2>";
echo "Versão do PHP: " . phpversion() . "<br>";
echo "SAPI: " . php_sapi_name() . "<br>";

// 2. Teste de Extensões
echo "<h2>2. Extensões Necessárias</h2>";
$exts = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'fileinfo', 'curl'];
foreach ($exts as $ext) {
    echo "Extensão <b>{$ext}</b>: " . (extension_loaded($ext) ? "<span style='color:green'>OK</span>" : "<span style='color:red'>FALHA</span>") . "<br>";
}

// 3. Teste de Diretórios
echo "<h2>3. Permissões de Diretório</h2>";
$dirs = [
    $base . '/storage',
    $base . '/storage/logs',
    $base . '/storage/sessions',
    $base . '/storage/uploads'
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        echo "Diretório <b>" . basename($dir) . "</b>: <span style='color:red'>NÃO EXISTE</span><br>";
    } else {
        echo "Diretório <b>" . basename($dir) . "</b>: " . (is_writable($dir) ? "<span style='color:green'>GRAVÁVEL</span>" : "<span style='color:red'>SEM PERMISSÃO DE ESCRITA</span>") . "<br>";
    }
}

// 4. Teste de Banco de Dados
echo "<h2>4. Conexão com Banco de Dados</h2>";
try {
    $pdo = \App\Core\Database::getInstance();
    echo "<span style='color:green'>Conexão com o banco de dados OK!</span><br>";
    // Testa se tabelas existem
    $tables = $pdo->query("SHOW TABLES LIKE 'bi_%'")->fetchAll(\PDO::FETCH_COLUMN);
    echo "Tabelas encontradas: <b>" . count($tables) . "</b><br>";
    foreach ($tables as $t) {
        echo "&nbsp;&nbsp;→ {$t}<br>";
    }
} catch (\Exception $e) {
    echo "<span style='color:red'>Erro de Conexão: " . $e->getMessage() . "</span><br>";
}

echo "<h2>5. Variáveis de Ambiente</h2>";
echo "APP_ENV: " . ($_ENV['APP_ENV'] ?? 'Não definido') . "<br>";
echo "APP_DEBUG: " . ($_ENV['APP_DEBUG'] ?? 'Não definido') . "<br>";
echo "DB_HOST: " . ($_ENV['DB_HOST'] ?? 'Não definido') . "<br>";
echo "DB_DATABASE: " . ($_ENV['DB_DATABASE'] ?? 'Não definido') . "<br>";
echo "DB_USERNAME: " . ($_ENV['DB_USERNAME'] ?? 'Não definido') . "<br>";

// 6. Teste cURL (necessário para Orthanc e CNPJ)
echo "<h2>6. cURL</h2>";
if (function_exists('curl_version')) {
    $cv = curl_version();
    echo "cURL: <span style='color:green'>OK</span> — versão " . $cv['version'] . "<br>";
} else {
    echo "cURL: <span style='color:red'>NÃO DISPONÍVEL</span><br>";
}

echo "<hr><p>Se tudo estiver OK aqui, o erro 500 pode estar sendo causado por um erro fatal no código. Verifique o arquivo <b>storage/logs/php_errors.log</b>.</p>";
