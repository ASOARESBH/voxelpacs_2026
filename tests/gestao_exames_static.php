<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        throw new RuntimeException('Arquivo ausente: ' . $relative);
    }
    return (string) file_get_contents($path);
};

$routes      = $read('routes/web.php');
$controller  = $read('app/Controllers/EstudosController.php');
$view        = $read('app/Views/estudos/index.php');
$service     = $read('app/Services/PedidoMedicoService.php');
$repository  = $read('app/Repositories/PedidoMedicoRepository.php');
$migration   = $read('database/migrations/2026-08-08_bi_pacs_estudos_pedidos.sql');
$report      = $read('app/Services/ReportService.php');
$reportCard  = $read('app/Views/reports/partials/_exame_card.php');
$permissions = $read('app/Core/Permission.php');
$header      = $read('app/Views/layout/pacs_header.php');

$expect(str_contains($routes, "Router::get('/gestao-exames'") , 'Rota da Gestão de Exames ausente.');
$expect(str_contains($header, "fetch('/api/estudos/contadores'") && !str_contains($header, "fetch('/estudos/contadores'"), 'Sidebar chama rota inválida de contadores.');
$expect(str_contains($routes, "GestaoExamesController@anexar") , 'Rota de anexação ausente.');
$expect(str_contains($routes, "GestaoExamesController@remover") , 'Rota de remoção ausente.');
$expect(str_contains($routes, "GestaoExamesController@arquivo") , 'Rota do proxy ausente.');
$expect(str_contains($controller, 'LEFT JOIN bi_pacs_estudos_pedidos') , 'Join do pedido não está na Worklist.');
$expect(str_contains($controller, "public function gestao(): void") , 'Ação gestao() ausente no Controller de Estudos.');
$expect(str_contains($view, "t('pedido_medico.coluna')") , 'Coluna PEDIDO não está internacionalizada.');
$expect(str_contains($view, 'id="pedidoModal"') , 'Modal do pedido não foi renderizada.');
$expect(str_contains($view, "setAttribute('capture', 'environment')") , 'Fluxo de câmera não está configurado.');
$expect(str_contains($view, 'new FormData(form)') , 'Upload multipart não está implementado.');
$expect(str_contains($view, '<?php if ($modoGestao): ?>'), 'Branch administrativa ausente.');

$branchStart = strpos($view, '<?php if ($modoGestao): ?>');
$branchEnd   = $branchStart === false ? false : strpos($view, '<?php else: ?>', $branchStart);
$managementBranch = ($branchStart !== false && $branchEnd !== false)
    ? substr($view, $branchStart, $branchEnd - $branchStart)
    : '';
$expect($managementBranch !== '', 'Não foi possível delimitar o branch da Gestão.');
$expect(!str_contains($managementBranch, 'wl-btn-assumir'), 'Gestão expõe botão Assumir.');
$expect(!str_contains($managementBranch, 'wl-btn-laudo'), 'Gestão expõe botão Laudo.');
$expect(!str_contains($managementBranch, 'wl-btn-abrir'), 'Gestão expõe botão Abrir.');
$expect(!str_contains($managementBranch, '/abrir'), 'Gestão expõe rota de abertura.');
$expect(str_contains($view, '<?php if (!$modoGestao): ?>'), 'Duplo clique do viewer não está condicionado ao modo médico.');
$expect(!str_contains($view, '<?php if ($modoGestao && $podeGerenciarPedido): ?>\n<script>'), 'Modal do pedido contém script aninhado dentro do bloco principal.');
$expect(substr_count($view, '<script>') === 1 && substr_count($view, '</script>') === 1, 'Worklist possui quantidade inconsistente de tags script.');

$expect(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `bi_pacs_estudos_pedidos`'), 'Migration sem CREATE TABLE idempotente.');
$expect(str_contains($migration, 'UNIQUE KEY `uq_pedido_tenant_estudo`'), 'Migration sem unicidade tenant/estudo.');
$expect(str_contains($migration, '`tenant_id`') && str_contains($migration, '`caminho_arquivo`'), 'Migration sem isolamento ou path privado.');
$expect(str_contains($service, 'public function podeGerenciar'), 'Regra central de autorização do pedido ausente.');
$expect(str_contains($controller, '->podeGerenciar('), 'Controller não usa a autorização central do pedido.');
$expect(str_contains($service, 'finfo_open(FILEINFO_MIME_TYPE)'), 'Service não valida MIME real.');
$expect(str_contains($service, 'MAX_BYTES = 15 * 1024 * 1024'), 'Limite de upload não está definido.');
$expect(str_contains($service, 'storage/uploads/pedidos_medicos'), 'Service sem diretório privado de anexos.');
$expect(str_contains($repository, 'WHERE estudo_id = :estudo_id AND tenant_id = :tenant_id'), 'Repository sem filtro por tenant.');
$expect(str_contains($permissions, "'manage_pedidos'"), 'Permissão manage_pedidos ausente.');
$expect(str_contains($report, "'pedido' => \$pedido"), 'ReportService não devolve pedido.');
$expect(str_contains($reportCard, "pedido_medico.status.anexado"), 'Card do report sem status do pedido.');

$locales = [
    'pt_BR' => $root . '/lang/pt_BR.php',
    'en'    => $root . '/lang/en.php',
    'es'    => $root . '/lang/es.php',
];
$keys = [];
foreach ($locales as $locale => $path) {
    $keys[$locale] = array_keys(require $path);
}
$allKeys = array_unique(array_merge(...array_values($keys)));
foreach ($allKeys as $key) {
    foreach ($keys as $locale => $localeKeys) {
        $expect(in_array($key, $localeKeys, true), "Chave {$key} ausente em {$locale}.");
    }
}

if ($failures) {
    fwrite(STDERR, "FALHOU:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: contrato estático da Gestão de Exames validado (" . count($allKeys) . " chaves i18n).\n";
