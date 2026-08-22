<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'migration' => $root . '/database/migrations/2026-08-21_imagiflow_integration_postgresql.sql',
    'repository' => $root . '/app/Repositories/ImagiflowIntegrationRepository.php',
    'auth' => $root . '/app/Services/ImagiflowApiAuthService.php',
    'apuracao' => $root . '/app/Services/ImagiflowApuracaoService.php',
    'api' => $root . '/app/Controllers/ImagiflowApiController.php',
    'platform' => $root . '/app/Controllers/Platform/ImagiflowIntegrationController.php',
    'routes' => $root . '/routes/web.php',
    'platform_routes' => $root . '/routes/platform.php',
    'form' => $root . '/app/Views/platform/negocios/form.php',
    'router' => $root . '/app/Core/Router.php',
];
foreach ($files as $name => $file) {
    if (!is_file($file)) throw new RuntimeException("Arquivo ausente: {$name}");
    $files[$name] = (string) file_get_contents($file);
}

$must = static function (string $text, string $needle, string $message): void {
    if (!str_contains($text, $needle)) throw new RuntimeException($message);
};
$must($files['migration'], 'bi_imagiflow_integrations', 'Migration sem configuração Imagiflow.');
$must($files['migration'], 'tenant_id BIGINT NOT NULL UNIQUE', 'Configuração deve ser única por tenant.');
$must($files['migration'], 'bi_imagiflow_integration_logs', 'Migration sem auditoria Imagiflow.');
$must($files['repository'], 'Crypto::encrypt($secret)', 'Segredo deve ser cifrado antes da persistência.');
$must($files['auth'], "hash_hmac('sha256'", 'Autenticação deve usar HMAC SHA-256.');
$must($files['auth'], 'abs(time() - (int) $timestamp) > 300', 'Autenticação deve limitar replay temporal.');
$must($files['auth'], 'findActiveByCode', 'API deve exigir integração ativa.');
$must($files['apuracao'], "r.situacao::text IN ('assinado', 'liberado')", 'Apuração deve expor somente laudos concluídos.');
$must($files['apuracao'], 'r.tenant_id = :tenant_id', 'Consulta de apuração deve ter escopo de tenant.');
$must($files['platform'], 'Auth::isPlatformAdmin()', 'Administração Imagiflow deve ser exclusiva de superadmin.');
$must($files['router'], "'/api/integracoes/imagiflow/'", 'Prefixo Imagiflow deve alcançar a autenticação HMAC sem sessão.');
$must($files['routes'], "Router::post('/api/integracoes/imagiflow/v1/medicos/consultar'", 'Rota de médico Imagiflow ausente.');
$must($files['routes'], "Router::post('/api/integracoes/imagiflow/v1/apuracao/estudos'", 'Rota de apuração Imagiflow ausente.');
$must($files['platform_routes'], "Router::get('/platform/negocios/{id}/imagiflow'", 'Rota administrativa Imagiflow ausente.');
$must($files['form'], 'Conector Imagiflow', 'Aba Conector Imagiflow ausente no negócio.');

if (str_contains($files['api'], "'report_body'") || str_contains($files['apuracao'], "'report_body'")) {
    throw new RuntimeException('API Imagiflow não pode expor corpo de laudo.');
}

echo "IMAGIFLOW_INTEGRATION_STATIC_OK\n";
