<?php

declare(strict_types=1);

/**
 * Regressão estática — contrato atual do workflow de Reports.
 *
 * O contrato público usa token opaco; o editor persiste um documento clínico
 * único; schemas legados são detectados antes das consultas; e o PDF resolve
 * layout/assinatura por serviços e proxy tenant-scoped.
 * Executar: php tests/reports_workflow_static.php
 */
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

$routes = $read('routes/web.php');
$controller = $read('app/Controllers/ReportsController.php');
$access = $read('app/Services/ReportAccessService.php');
$service = $read('app/Services/ReportService.php');
$repository = $read('app/Repositories/ReportRepository.php');
$editor = $read('app/Views/reports/partials/_editor.php');
$autosave = $read('public/assets/js/reports/reports-autosave.js');
$signature = $read('public/assets/js/reports/reports-signature.js');
$templates = $read('public/assets/js/reports/reports-templates.js');
$history = $read('public/assets/js/reports/reports-history.js');
$autotext = $read('public/assets/js/reports/reports-autotext.js');
$main = $read('public/assets/js/reports/reports-main.js');
$editorJs = $read('public/assets/js/reports/reports-editor.js');
$viewCore = $read('app/Core/View.php');
$pdf = $read('app/Views/reports/pdf.php');
$pdfModern = $read('app/Views/reports/pdf/templates/_moderno_lateral.php');
$header = $read('app/Views/layout/reports_header.php');
$migration = $read('database/migrations/2026-08-08_reports_workflow_prerequisites.sql');

// Rotas estáticas e tokenizadas: não reintroduzir URLs públicas por ID ou Study UID.
$historyRoute = strpos($routes, "Router::get('/reports/history'");
$tokenPdfRoute = strpos($routes, "Router::get('/reports/r/{token}/pdf'");
$tokenSignatureRoute = strpos($routes, "Router::get('/reports/r/{token}/assinatura'");
$tokenShowRoute = strpos($routes, "Router::get('/reports/r/{token}'");
$templatesRoute = strpos($routes, "Router::get('/reports/templates'");
$expect($historyRoute !== false && $tokenPdfRoute !== false && $tokenPdfRoute < $tokenShowRoute,
    'Rota tokenizada de PDF deve preceder a rota tokenizada do editor.');
$expect($historyRoute !== false && $historyRoute < $tokenShowRoute,
    'Rota estática de histórico deve preceder a rota tokenizada do editor.');
$expect($templatesRoute !== false && $templatesRoute < $tokenShowRoute,
    'Rota plural de templates deve preceder a rota tokenizada do editor.');
$expect($tokenSignatureRoute !== false && $tokenSignatureRoute < $tokenShowRoute,
    'Proxy tokenizado de assinatura deve preceder a rota tokenizada do editor.');
$expect(str_contains($routes, "Router::post('/reports/history/restore'"), 'Endpoint de restauração ausente.');
$expect(str_contains($routes, "Router::get('/reports/autotext'"), 'Alias de autotexto do frontend ausente.');
$expect(str_contains($routes, "Router::post('/reports/ai-generate'"), 'Endpoint de IA ausente.');
$expect(!str_contains($routes, "Router::get('/reports/{study_uid}'"), 'Rota pública por Study UID não pode ser reintroduzida.');
$expect(!str_contains($routes, "Router::get('/reports/pdf'"), 'Alias público por report_id não pode ser reintroduzido.');

// Controller: token, tenant, CSRF e schema-aware autotext.
$expect(str_contains($controller, 'public function pdfByToken'), 'PDF não resolve token opaco.');
$expect(str_contains($controller, 'public function assinaturaImagemByToken'), 'Assinatura não possui proxy tokenizado.');
$expect(str_contains($controller, 'findAuthorizedReportByPublicToken($token)'), 'Controller não usa o resolvedor autorizado do token.');
$expect(str_contains($access, 'public function findAuthorizedReportByPublicToken'), 'Resolvedor de token opaco ausente.');
$expect(str_contains($access, 'tenant_divergente') && str_contains($access, '$reportTenantId !== $currentTenantId'),
    'Resolvedor de Report não falha fechado para tenant divergente.');
$expect(str_contains($controller, 'public function templates'), 'Listagem plural de templates ausente.');
$expect(str_contains($controller, 'public function restoreHistory'), 'Restauração de histórico ausente.');
$expect(str_contains($controller, "'versions' => \$versoes"), 'Histórico não retorna a chave esperada pelo frontend.');
$expect(substr_count($controller, 'validarCsrf') >= 5, 'Endpoints de escrita do Reports não validam CSRF de forma consistente.');
$expect(str_contains($controller, 'AND r.tenant_id = :tenant_id'), 'Atualização de situação não contém filtro tenant.');
$expect(str_contains($controller, 'public function aiGenerate'), 'Controller não expõe aiGenerate.');
$expect(str_contains($controller, 'normalizarTemplate'), 'Controller não normaliza templates entre schemas.');
$expect(str_contains($controller, "SqlHelper::tableColumns(\$pdo, 'report_autotext')"), 'Autotexto não detecta o schema real antes da consulta.');
$expect(str_contains($controller, 'contentColumn') && str_contains($controller, 'texto_sugerido'), 'Autotexto não possui fallback de coluna de conteúdo.');
$expect(str_contains($controller, 'Schema report_autotext sem colunas de conteúdo reconhecidas'), 'Autotexto não falha fechado para schema desconhecido.');
$autotextStart = strpos($controller, 'public function autotextSearch');
$autotextEnd = strpos($controller, 'public function atualizarStatus', $autotextStart === false ? 0 : $autotextStart);
$autotextMethod = ($autotextStart !== false && $autotextEnd !== false)
    ? substr($controller, $autotextStart, $autotextEnd - $autotextStart)
    : '';
$expect($autotextMethod !== '' && !str_contains($autotextMethod, 'tentando schema alternativo'),
    'Autotexto não deve registrar warnings por tentativas SQL alternativas dentro do próprio método.');

// Serviço/repositório: documento único, compatibilidade legada e persistência atômica.
$expect(str_contains($service, 'urlPublica'), 'Service não centraliza a URL pública tokenizada.');
$expect(str_contains($service, 'extrairSecoesDoReport'), 'Service não centraliza extração de conteúdo.');
$expect(str_contains($service, 'secoesTemConteudo'), 'Service não possui validação de conteúdo real.');
$expect(str_contains($service, "\$report->estudo_id ?? \$report->bi_pacs_estudos_id"), 'Assinatura não possui fallback para estudo_id.');
$expect(str_contains($service, "'pdf_url' => \$this->urlPublica(\$report) . '/pdf'"), 'Resposta de assinatura não aponta para o PDF tokenizado.');
$expect(str_contains($service, 'beginTransaction') && str_contains($service, 'inTransaction'), 'Assinatura não possui persistência atômica.');
$expect(str_contains($repository, 'lock_heartbeat_em'), 'Repository não trata heartbeat.');
$expect(str_contains($repository, 'migration pendente'), 'Fallback de migration pendente não está registrado em log.');
$expect(str_contains($repository, 'usuario_id, usuario_nome'), 'Registro de assinatura não tenta schema operacional.');
$expect(str_contains($repository, 'findVersion(int $versionId)'), 'findVersion não existe.');
$expect(str_contains($repository, "execute(['id' => \$versionId])"), 'findVersion usa parâmetro incorreto.');

// Editor livre: seções legadas são fallback de leitura, mas o documento salvo é único.
$expect(str_contains($editor, 'property_exists($report, $campo)'), 'Editor não lê colunas secao_* com fallback.');
$expect(str_contains($editor, '$corpoLaudo'), 'Editor não prepara o corpo livre do laudo.');
$expect(str_contains($editor, '$secoesJson'), 'Editor não mantém compatibilidade de leitura com JSON legado.');
$expect(str_contains($editorJs, 'function extractSecoes()'), 'Editor não expõe extração do documento atual.');
$expect(str_contains($editorJs, 'return { corpo: html'), 'Editor não persiste o HTML atual como corpo único.');
$expect(str_contains($editorJs, 'normalizeClinicalHtml'), 'Editor não normaliza o conteúdo clínico antes de salvar.');
$expect(str_contains($viewCore, "ASSET_VERSION = '2.3.14'"), 'Assets dos Reports não possuem cache-bust atual.');
$expect(str_contains($editor, '$reportSituacao'), 'Editor não usa situacao/status compatível.');

// Autosave/assinatura/templates/histórico/autotext usam envelopes atuais.
$expect(str_contains($autosave, 'savingPromise'), 'Autosave não aguarda requisição concorrente.');
$expect(str_contains($autosave, 'secoes,') && str_contains($autosave, 'modo,') && str_contains($autosave, 'template_id:'),
    'Autosave não envia seções, modo e template.');
$expect(str_contains($signature, 'if (!saveData || !saveData.ok)'), 'Assinatura ainda pode prosseguir após save falhar.');
$expect(!str_contains($signature, '.finally(() =>'), 'Assinatura não pode chamar o endpoint após save falho em finally.');
$expect(str_contains($templates, 'fetch(`/reports/templates?${params.toString()}`'), 'Frontend não usa a listagem plural de templates.');
$expect(str_contains($history, 'data.versions || []'), 'Frontend não consome a chave versions do histórico.');
$expect(str_contains($autotext, 'data.items || []'), 'Frontend não consome envelope items do autotexto.');
$expect(str_contains($main, 'config.reportToken') && str_contains($main, '/reports/r/'), 'Botão PDF não usa rota tokenizada.');
$expect(str_contains($main, "window.open(pdfUrl, 'voxel-laudo-pdf')"), 'Prévia PDF não reutiliza sua aba nomeada.');
$expect(str_contains($main, "fetch('/api/reports/liberar'"), 'Botão Liberar não possui handler.');
$expect(str_contains($header, 'id="btn-liberar"') && str_contains($header, "\$situacao === 'assinado'"), 'Liberar não está disponível para laudo assinado.');

// O dispatcher escolhe um partial; cada layout HTML fixo é um documento único.
$expect(str_contains($pdf, 'ReportLayoutService'), 'Dispatcher PDF não delega a escolha de layout.');
$expect(str_contains($pdf, 'require $partial;'), 'Dispatcher PDF não renderiza o partial escolhido.');
$expect(substr_count($pdf, '<!DOCTYPE html>') === 0, 'Dispatcher PDF não deve conter um segundo documento HTML.');
foreach (['_classico_centralizado.php', '_corporativo_faixa.php', '_minimalista.php', '_moderno_lateral.php'] as $layout) {
    $layoutSource = $read('app/Views/reports/pdf/templates/' . $layout);
    $expect(substr_count($layoutSource, '<!DOCTYPE html>') === 1, 'Layout PDF não possui exatamente um documento HTML: ' . $layout);
}
$expect(str_contains($controller, 'public function assinaturaImagemByToken')
    && str_contains($pdfModern, '/reports/r/')
    && str_contains($pdfModern, '/assinatura'),
    'PDF não consulta assinatura visual pelo proxy tokenizado.');

// Pré-requisitos de workflow permanecem versionados sem executar migration nesta suíte.
foreach ([
    "COLUMN_NAME = 'lock_heartbeat_em'",
    "COLUMN_NAME = 'usuario_responsavel_id'",
    "COLUMN_NAME = 'data_inicio_laudo'",
    "COLUMN_NAME = 'hora_inicio_laudo'",
    "COLUMN_NAME = 'laudo_assinado_em'",
    "TABLE_NAME = 'bi_medicos'",
] as $migrationContract) {
    $expect(str_contains($migration, $migrationContract), 'Migration não contém o contrato esperado: ' . $migrationContract);
}

if ($failures !== []) {
    fwrite(STDERR, "FALHOU:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: contrato atual do workflow Reports validado.\n";
