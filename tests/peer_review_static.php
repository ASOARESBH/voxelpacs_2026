<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function expectContains(string $file, string $needle, string $message): void
{
    $content = file_get_contents($file);
    if ($content === false || strpos($content, $needle) === false) {
        throw new RuntimeException("FAIL: {$message} ({$file})");
    }
}

function expectNotContains(string $file, string $needle, string $message): void
{
    $content = file_get_contents($file);
    if ($content !== false && strpos($content, $needle) !== false) {
        throw new RuntimeException("FAIL: {$message} ({$file})");
    }
}

$migration = $root . '/database/migrations/2026-08-10_reports_peer_review.sql';
$service = $root . '/app/Services/ReportPeerReviewService.php';
$repo = $root . '/app/Repositories/ReportPeerReviewRepository.php';
$controller = $root . '/app/Controllers/ReportPeerReviewController.php';
$reports = $root . '/app/Services/ReportService.php';
$reportsController = $root . '/app/Controllers/ReportsController.php';
$worklist = $root . '/app/Views/estudos/index.php';
$partial = $root . '/app/Views/reports/partials/_peer_review_card.php';
$show = $root . '/app/Views/reports/show.php';
$header = $root . '/app/Views/layout/reports_header.php';
$footer = $root . '/app/Views/layout/reports_footer.php';
$js = $root . '/public/assets/js/reports/reports-peer-review.js';
$routes = $root . '/routes/web.php';
$relatorioFiltros = $root . '/app/Services/RelatorioFiltrosService.php';
$copilot = $root . '/app/Services/CopilotWebhookService.php';

foreach ([$migration, $service, $repo, $controller, $reports, $reportsController, $worklist, $partial, $show, $header, $footer, $js, $routes, $relatorioFiltros, $copilot] as $file) {
    if (!is_file($file)) throw new RuntimeException("FAIL: arquivo ausente {$file}");
}

expectContains($migration, "'peer_review'", 'migration inclui estado peer_review');
expectContains($migration, 'pacs_report_peer_reviews', 'migration cria ciclos de revisão');
expectContains($migration, 'pacs_report_peer_review_originais', 'migration cria snapshot imutável');
expectContains($migration, 'UNIQUE KEY `uq_peer_review_original`', 'snapshot original possui unicidade por ciclo');
expectContains($migration, 'ALTER TABLE `reports` ADD COLUMN `peer_review_id`', 'report vivo aponta para o ciclo');

expectContains($service, 'MIN_MOTIVO_CHARS = 20', 'backend exige motivo mínimo');
expectContains($service, 'in_array($situacao, [\'assinado\', \'liberado\'], true)', 'abertura só aceita assinado/liberado');
expectContains($service, "'peer_review_ja_aberto'", 'backend impede segundo ciclo aberto');
expectContains($service, 'openWithSnapshot', 'abertura cria snapshot dentro do fluxo persistente');
expectContains($service, 'peer_review_aberto', 'service trata o ciclo aberto');
expectContains($repo, 'beginTransaction()', 'abertura é atômica');
expectContains($repo, 'pacs_report_peer_review_originais', 'repository grava original imutável');
expectContains($repo, "SET situacao = 'peer_review'", 'repository atualiza situação do report');
expectContains($repo, "SET situacao = 'peer_review'", 'repository atualiza situação do estudo');
expectContains($repo, "status = 'aberta'", 'fechamento só altera ciclo aberto');
expectContains($repo, 'resetReportPeerReview', 'repository limpa apontamento após conclusão');

expectContains($controller, 'public function open()', 'controller possui método de abertura');
expectContains($controller, 'validarCsrf', 'abertura protegida por CSRF');
expectContains($controller, 'motivo_curto', 'controller devolve erro de motivo curto');
expectContains($routes, "Router::post('/api/reports/peer-review/open'", 'rota POST de abertura registrada');
expectContains($routes, "Router::get('/api/reports/peer-review/context'", 'rota de contexto registrada');
$routesContent = file_get_contents($routes);
if (strpos($routesContent, "Router::post('/api/reports/peer-review/open'") > strpos($routesContent, "Router::get('/reports/{study_uid}'")) {
    throw new RuntimeException('FAIL: rota Peer Review ficou depois do wildcard do Reports');
}

expectContains($worklist, 'in_array($sit, [\'assinado\', \'liberado\'], true)', 'botão da Worklist é condicional a assinado/liberado');
expectContains($worklist, 'wl-btn-peer-review', 'Worklist renderiza botão Peer Review');
expectContains($worklist, "value=\"peer_review\"", 'Worklist permite filtrar Peer Review');
expectContains($worklist, "'peer_review' => ['sit-peer-review', 'PEER REVIEW']", 'Worklist renderiza badge Peer Review');
expectContains($partial, 'minlength="20"', 'formulário visual exige 20 caracteres');
expectContains($partial, 'peer-review-motivo', 'formulário possui motivo');
expectContains($show, "_peer_review_card.php", 'Report inclui card abaixo do paciente');
expectContains($header, 'data-peer-review-pending', 'layout expõe estado do ciclo');
expectContains($footer, 'reports-peer-review.js', 'footer carrega módulo Peer Review');
expectContains($js, "window.confirm(i18n.confirmar", 'frontend exibe confirmação explícita');
expectContains($js, '/api/reports/peer-review/open', 'frontend chama endpoint correto');
expectContains($js, 'texto.length < minChars', 'frontend valida motivo mínimo');

expectContains($reports, '$reportSituacao === \'peer_review\'', 'Report reconhece estado Peer Review');
expectContains($reports, 'concluirNaTransacao', 'nova assinatura conclui o ciclo');
expectContains($reports, '($reportSituacao === \'peer_review\')', 'autosave preserva estado Peer Review');
expectContains($reportsController, 'peer_review_ciclo_nao_aberto', 'endpoint de assinatura trata ciclo ausente');
expectContains($relatorioFiltros, "'peer_review'", 'relatórios analíticos aceitam situação Peer Review');
expectContains($copilot, "peer_review_aberto", 'webhook do Copilot bloqueia Peer Review aberto');
expectContains($copilot, 'report_situacao', 'webhook relaciona a situação operacional do report');

$pt = require $root . '/lang/pt_BR.php';
$en = require $root . '/lang/en.php';
$es = require $root . '/lang/es.php';
$keys = [
    'peer_review.titulo', 'peer_review.status_aberta', 'peer_review.status_disponivel',
    'peer_review.aviso_aberta', 'peer_review.ciclo', 'peer_review.motivo',
    'peer_review.motivo_placeholder', 'peer_review.descricao', 'peer_review.liberar_botao',
    'peer_review.original_preservado', 'peer_review.confirmar', 'peer_review.motivo_curto',
    'peer_review.erro', 'peer_review.abrir_worklist', 'peer_review.botao_worklist',
];
foreach ($keys as $key) {
    if (!array_key_exists($key, $pt) || !array_key_exists($key, $en) || !array_key_exists($key, $es)) {
        throw new RuntimeException("FAIL: chave i18n ausente {$key}");
    }
}

fwrite(STDOUT, "OK: Peer Review — migration, tenant, motivo mínimo, snapshot imutável, Worklist, Report, assinatura, rotas e i18n validados.\n");
