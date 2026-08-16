<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/Controllers/Platform/NegociosController.php');
$form = (string) file_get_contents($root . '/app/Views/platform/negocios/form.php');
$template = (string) file_get_contents($root . '/app/Views/reports/pdf/templates/_moderno_lateral.php');
$migration = (string) file_get_contents($root . '/database/migrations/2026-08-15_bi_tenants_registro_crm_empresa.sql');
$failures = [];

$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach (['registro_crm_uf', 'registro_crm_numero'] as $field) {
    $require(strpos($migration, 'ADD COLUMN `' . $field . '`') !== false, "Migration não cria {$field}.");
    $require(strpos($controller, "'{$field}'") !== false, "Controller não persiste {$field}.");
    $require(strpos($form, 'name="' . $field . '"') !== false, "Formulário não contém {$field}.");
    $require(strpos($template, "['{$field}']") !== false, "Assinatura não lê {$field}.");
}

$require(strpos($migration, 'INFORMATION_SCHEMA') === false, 'Migration usa INFORMATION_SCHEMA, incompatível com o ambiente alvo.');
$require(strpos($migration, 'CREATE PROCEDURE') === false, 'Migration usa procedure, incompatível com o ambiente alvo.');
$require(strpos($controller, 'normalizarUfRegistro') !== false, 'UF do CRM institucional não possui validação no servidor.');
$require(strpos($controller, 'normalizarNumeroRegistro') !== false, 'Número do CRM institucional não possui normalização no servidor.');
$require(strpos($template, 'horário de Brasília') !== false, 'Assinatura não informa horário de Brasília.');
$require(strpos($template, 'Token de validação para auditoria:') !== false, 'Assinatura não exibe token de auditoria.');
$require(strpos($template, 'Empresa vinculada:') !== false, 'Assinatura não exibe a empresa vinculada.');
$require(strpos($template, 'CNPJ') !== false, 'Assinatura não contempla CNPJ institucional.');
$require(strpos($template, 'registroEmpresa !==') !== false, 'Assinatura não condiciona o CRM institucional ao preenchimento opcional.');

if ($failures !== []) {
    fwrite(STDERR, "NEGOCIO_REGISTRO_CRM_EMPRESA_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "NEGOCIO_REGISTRO_CRM_EMPRESA_OK\n");
