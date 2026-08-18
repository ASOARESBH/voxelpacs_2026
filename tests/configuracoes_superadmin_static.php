<?php
/**
 * Regressão estática — Configurações por grupo de permissão.
 * Executar: php tests/configuracoes_superadmin_static.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$falhas = [];

function exigirConfiguracoes(bool $condicao, string $mensagem): void
{
    global $falhas;
    if (!$condicao) {
        $falhas[] = $mensagem;
    }
}

function lerConfiguracoes(string $caminho): string
{
    $conteudo = file_get_contents($caminho);
    if ($conteudo === false) {
        throw new RuntimeException('Arquivo ausente: ' . $caminho);
    }
    return $conteudo;
}

$controller = lerConfiguracoes($root . '/app/Controllers/ConfiguracoesController.php');
$model = lerConfiguracoes($root . '/app/Models/Configuracao.php');
$view = lerConfiguracoes($root . '/app/Views/configuracoes/index.php');
$routes = lerConfiguracoes($root . '/routes/web.php');
$pacsHeader = lerConfiguracoes($root . '/app/Views/layout/pacs_header.php');
$biHeader = lerConfiguracoes($root . '/app/Views/layout/bi_header.php');
$authDocs = lerConfiguracoes($root . '/SKILL-VOXEL-PACS/architecture/auth-e-permissoes.md');

foreach ([
    'private function canManageCompanySettings(): bool',
    "Auth::isPlatformAdmin() || Auth::can('manage_configuracoes')",
    'private function guardCompanySettings(): void',
    'private function guardSuperadminOnly(): void',
    'private function guardTenantConfigurationContext(): void',
    'private function guardCsrf(string $scope): void',
    'private function salvarCampos(Configuracao $configModel, array $campos, bool $ignorarVazios = false): void',
    "if (\$grupo === 'empresa')",
    "if (\$grupo === 'infraestrutura')",
    "'empresa_nome', 'empresa_cnpj', 'empresa_email', 'empresa_telefone'",
    "'orthanc_url', 'orthanc_user', 'viewer_url'",
    "\$this->guardSuperadminOnly();",
    "\$this->guardCompanySettings();",
    "\$this->guardCsrf('dados_empresa');",
    "\$this->guardCsrf('infraestrutura_pacs');",
    "\$this->guardCsrf('visualizadores_desktop');",
    "Logger::warning('Tentativa negada de acesso a Configurações do Sistema'",
] as $contrato) {
    exigirConfiguracoes(strpos($controller, $contrato) !== false, 'Contrato de permissão ausente: ' . $contrato);
}

exigirConfiguracoes(strpos($controller, "Auth::can('manage_configuracoes')") !== false,
    'Administrador do negócio não possui mais a guarda explícita de Dados da Empresa.');
exigirConfiguracoes(strpos($controller, "if (\$grupo === 'empresa')") < strpos($controller, "if (\$grupo === 'infraestrutura')"),
    'A separação de grupo empresa/infraestrutura está fora da ordem esperada.');
exigirConfiguracoes(strpos($controller, "\$this->guardSuperadminOnly();\n        \$this->guardTenantConfigurationContext();\n        \$this->guardCsrf('visualizadores_desktop');") !== false,
    'Visualizadores desktop precisam permanecer exclusivos de superadmin com contexto tenant e CSRF.');

foreach ([
    'public function getMany(array $chaves): array',
    "WHERE chave IN (' . implode(', ', \$placeholders) . ')'",
    'array_unique(array_filter($chaves',
] as $contrato) {
    exigirConfiguracoes(strpos($model, $contrato) !== false, 'Whitelist de leitura de Configurações ausente: ' . $contrato);
}

foreach ([
    '$podeGerenciarInfraestrutura = (bool)',
    '<?php if ($podeGerenciarInfraestrutura): ?>',
    'name="grupo" value="infraestrutura"',
    'name="grupo" value="empresa"',
    'name="_csrf_token"',
    'Servidor PACS (Orthanc)',
    'Dados da Empresa',
] as $contrato) {
    exigirConfiguracoes(strpos($view, $contrato) !== false, 'View de Configurações incompleta: ' . $contrato);
}

foreach ([
    "Router::get('/configuracoes',          'ConfiguracoesController@index');",
    "Router::post('/configuracoes/salvar',  'ConfiguracoesController@salvar');",
    "Router::post('/configuracoes/viewer-desktop/salvar', 'ConfiguracoesController@salvarViewerDesktop');",
] as $rota) {
    exigirConfiguracoes(strpos($routes, $rota) !== false, 'Rota de Configurações não inventariada: ' . $rota);
}

foreach ([
    [$pacsHeader, 'pacs_header'],
    [$biHeader, 'bi_header'],
] as [$header, $nome]) {
    exigirConfiguracoes(strpos($header, "Auth::isPlatformAdmin() || \\App\\Core\\Auth::can('manage_configuracoes')") !== false,
        'Menu Configurações não está disponível para administrador do negócio em ' . $nome . '.');
}

foreach ([
    'Configurações do Sistema — permissões por grupo',
    'Dados da Empresa (`empresa_*`)',
    'Orthanc e URL do Viewer (`orthanc_*`, `viewer_url`)',
    'Todos os POSTs validam CSRF antes de gravar.',
] as $registro) {
    exigirConfiguracoes(strpos($authDocs, $registro) !== false,
        'Documentação de permissões incompleta: ' . $registro);
}

if ($falhas !== []) {
    fwrite(STDERR, "FALHOU\n- " . implode("\n- ", $falhas) . "\n");
    exit(1);
}

echo "OK: Dados da Empresa para admin e infraestrutura PACS somente para superadmin validados.\n";
