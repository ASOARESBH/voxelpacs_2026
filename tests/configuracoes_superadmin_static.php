<?php
/**
 * Regressão estática — Configurações é exclusiva de superadmin.
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
$routes = lerConfiguracoes($root . '/routes/web.php');
$pacsHeader = lerConfiguracoes($root . '/app/Views/layout/pacs_header.php');
$biHeader = lerConfiguracoes($root . '/app/Views/layout/bi_header.php');
$authDocs = lerConfiguracoes($root . '/SKILL-VOXEL-PACS/architecture/auth-e-permissoes.md');

foreach ([
    'private function guardSuperadminOnly(): void',
    'Auth::check() && Auth::isPlatformAdmin()',
    "Logger::warning('Tentativa negada de acesso a Configurações do Sistema'",
    "http_response_code(403);",
    "exit('Acesso negado: esta área é exclusiva de administradores da plataforma.');",
] as $contrato) {
    exigirConfiguracoes(strpos($controller, $contrato) !== false, 'Guarda de Configurações incompleta: ' . $contrato);
}

exigirConfiguracoes(substr_count($controller, '$this->guardSuperadminOnly();') === 3,
    'Cada endpoint de Configurações deve chamar a guarda uma única vez.');
exigirConfiguracoes(strpos($controller, "Auth::can('manage_configuracoes')") === false,
    'RBAC de admin do tenant ainda é usado em Configurações.');
exigirConfiguracoes(strpos($controller, '$_POST') > strpos($controller, '$this->guardSuperadminOnly();'),
    'A guarda deve executar antes da leitura do POST.');

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
    $posGuard = strpos($header, 'Auth::isPlatformAdmin()');
    $posLink = strpos($header, 'href="/configuracoes"');
    exigirConfiguracoes($posGuard !== false && $posLink !== false && $posGuard < $posLink,
        'Link de Configurações sem guarda visual de superadmin em ' . $nome . '.');
}

foreach ([
    'Configurações do Sistema — superadmin exclusivo',
    'bi_configuracoes.tenant_id',
    'via impersonação',
    '`/usuarios`, `/sla-regras` e `/modalidades` continuam fora desta entrega',
] as $registro) {
    exigirConfiguracoes(strpos($authDocs, $registro) !== false,
        'Documentação de permissões incompleta: ' . $registro);
}

if ($falhas !== []) {
    fwrite(STDERR, "FALHOU\n- " . implode("\n- ", $falhas) . "\n");
    exit(1);
}

echo "OK: Configurações restrita a superadmin em GET, POST, menus e documentação.\n";
