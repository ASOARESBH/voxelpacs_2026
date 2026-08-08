<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) throw new RuntimeException('Arquivo ausente: ' . $relative);
    return (string) file_get_contents($path);
};

$routes = $read('routes/web.php');
$controller = $read('app/Controllers/ReportsController.php');
$service = $read('app/Services/ReportService.php');
$repository = $read('app/Repositories/ReportRepository.php');
$editor = $read('app/Views/reports/partials/_editor.php');
$autosave = $read('public/assets/js/reports/reports-autosave.js');
$signature = $read('public/assets/js/reports/reports-signature.js');
$templates = $read('public/assets/js/reports/reports-templates.js');
$history = $read('public/assets/js/reports/reports-history.js');
$autotext = $read('public/assets/js/reports/reports-autotext.js');
$main = $read('public/assets/js/reports/reports-main.js');
$pdf = $read('app/Views/reports/pdf.php');
$header = $read('app/Views/layout/reports_header.php');
$migration = $read('database/migrations/2026-08-08_reports_workflow_prerequisites.sql');

$staticPdf = strpos($routes, "Router::get('/reports/pdf'");
$staticHistory = strpos($routes, "Router::get('/reports/history'");
$staticTemplates = strpos($routes, "Router::get('/reports/templates'");
$dynamicPdf = strpos($routes, "Router::get('/reports/{study_uid}/pdf'");
$dynamicShow = strpos($routes, "Router::get('/reports/{study_uid}'");
$expect($staticPdf !== false && $dynamicShow !== false && $staticPdf < $dynamicShow, 'Rota estática de PDF está depois da rota dinâmica.');
$expect($staticHistory !== false && $staticHistory < $dynamicShow, 'Rota estática de histórico está depois da rota dinâmica.');
$expect($staticTemplates !== false && $staticTemplates < $dynamicShow, 'Rota plural de templates ausente ou depois da rota dinâmica.');
$expect($dynamicPdf !== false && $dynamicPdf < $dynamicShow, 'Alias de PDF por StudyUID ausente ou mal ordenado.');
$expect(str_contains($routes, "Router::post('/reports/history/restore'"), 'Endpoint de restauração ausente.');
$expect(str_contains($routes, "Router::get('/reports/autotext'"), 'Alias de autotexto do frontend ausente.');
$expect(str_contains($routes, "Router::post('/reports/ai-generate'"), 'Endpoint de IA ausente.');

$expect(str_contains($controller, "WHERE r.id = :id AND r.tenant_id = :tenant_id"), 'PDF não está escopado por tenant.');
$expect(str_contains($controller, 'public function pdfByStudyUid'), 'Compatibilidade de PDF por StudyUID ausente.');
$expect(str_contains($controller, 'public function templates'), 'Listagem plural de templates ausente.');
$expect(str_contains($controller, 'public function restoreHistory'), 'Restauração de histórico ausente.');
$expect(str_contains($controller, "'versions' => \$versoes"), 'Histórico não retorna a chave esperada pelo frontend.');
$expect(substr_count($controller, 'validarCsrf') >= 5, 'Endpoints de escrita do Reports não validam CSRF de forma consistente.');
$expect(str_contains($controller, 'AND r.tenant_id = :tenant_id'), 'Atualização de situação não contém filtro tenant.');
$expect(str_contains($controller, 'public function aiGenerate'), 'Controller não expõe aiGenerate.');
$expect(str_contains($controller, 'normalizarTemplate'), 'Controller não normaliza templates entre schemas.');
$expect(str_contains($controller, "SHOW COLUMNS FROM report_autotext"), 'Autotexto não detecta o schema real antes da consulta.');
$expect(str_contains($controller, 'contentColumn') && str_contains($controller, 'texto_sugerido'), 'Autotexto não possui fallback de coluna de conteúdo.');
$expect(str_contains($controller, 'Schema report_autotext sem colunas de conteúdo reconhecidas'), 'Autotexto não registra schema desconhecido.');
$expect(!str_contains($controller, 'ReportsController::autotextSearch tentando schema alternativo'), 'Autotexto ainda registra warnings por tentativas SQL esperadas.');

$expect(str_contains($service, 'extrairSecoesDoReport'), 'Service não centraliza extração de seções.');
$expect(str_contains($service, 'secoesTemConteudo'), 'Service não possui validação de conteúdo real.');
$expect(str_contains($service, "\$report->estudo_id ?? \$report->bi_pacs_estudos_id"), 'Assinatura não possui fallback para estudo_id.');
$expect(str_contains($service, "'pdf_url' => '/reports/pdf?report_id='"), 'Resposta de assinatura não aponta para PDF compatível.');
$expect(str_contains($service, 'beginTransaction') && str_contains($service, 'inTransaction'), 'Assinatura não possui persistência atômica.');
$expect(str_contains($repository, 'lock_heartbeat_em'), 'Repository não trata heartbeat.');
$expect(str_contains($repository, 'migration pendente'), 'Fallback de migration pendente não está registrado em log.');
$expect(str_contains($repository, 'usuario_id, usuario_nome'), 'Registro de assinatura não tenta schema operacional.');
$expect(str_contains($repository, 'findVersion(int $versionId)'), 'findVersion não existe.');
$expect(str_contains($repository, "execute(['id' => \$versionId])"), 'findVersion usa parâmetro incorreto.');

$expect(str_contains($editor, 'property_exists($report, $campo)'), 'Editor não lê colunas secao_* com fallback.');
$expect(str_contains($editor, '$reportSituacao'), 'Editor não usa situacao/status compatível.');
$expect(str_contains($autosave, 'savingPromise'), 'Autosave não aguarda requisição concorrente.');
$expect(str_contains($autosave, 'body: JSON.stringify({ report_id: config.reportId, secoes, modo })'), 'Autosave não envia modo e seções.');
$expect(str_contains($signature, 'if (!saveData || !saveData.ok)'), 'Assinatura ainda pode prosseguir após save falhar.');
$expect(!str_contains($signature, '.finally(() =>'), 'Assinatura ainda usa finally para chamar o endpoint após save falho.');
$expect(str_contains($templates, '/reports/templates?modalidade='), 'Frontend não usa a listagem plural de templates.');
$expect(str_contains($history, 'data.versions || []'), 'Frontend não consome a chave versions do histórico.');
$expect(str_contains($autotext, 'data.items || []'), 'Frontend não consome envelope items do autotexto.');
$expect(str_contains($main, '/reports/pdf?report_id='), 'Botão PDF não usa rota compatível.');
$expect(str_contains($main, "fetch('/api/reports/liberar'"), 'Botão Liberar não possui handler.');
$expect(str_contains($header, 'id="btn-liberar"') && str_contains($header, "\$situacao === 'assinado'"), 'Liberar não está disponível para laudo assinado.');
$expect(substr_count($pdf, '<!DOCTYPE html>') === 1, 'Template PDF possui mais de um documento HTML.');
$expect(str_contains($pdf, '/reports/assinatura-imagem?report_id='), 'PDF não consulta assinatura visual pelo proxy.');

$expect(str_contains($migration, "COLUMN_NAME = 'lock_heartbeat_em'"), 'Migration não cria lock_heartbeat_em.');
$expect(str_contains($migration, "COLUMN_NAME = 'usuario_responsavel_id'"), 'Migration não cria posse do laudo.');
$expect(str_contains($migration, "COLUMN_NAME = 'data_inicio_laudo'"), 'Migration não cria data de início.');
$expect(str_contains($migration, "COLUMN_NAME = 'hora_inicio_laudo'"), 'Migration não cria hora de início.');
$expect(str_contains($migration, "COLUMN_NAME = 'laudo_assinado_em'"), 'Migration não cria timestamp de assinatura.');
$expect(str_contains($migration, "TABLE_NAME = 'bi_medicos'"), 'Migration não garante vínculo médico-conta.');

if ($failures) {
    fwrite(STDERR, "FALHOU:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: contrato estático do workflow Reports validado.\n";
