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
$gerenciarService = $read('app/Services/GestaoExamesService.php');
$gerenciarRepository = $read('app/Repositories/GestaoExamesRepository.php');
$gerenciarController = $read('app/Controllers/GestaoExamesController.php');
$gerenciarMigration = $read('database/migrations/2026-08-11_gestao_exames_gerenciar.sql');
$gerenciarJs = $read('public/assets/js/gestao-exames-gerenciar.js');
$descricaoService = $read('app/Services/ModalidadeDescricaoService.php');
$chatService = $read('app/Services/ReportChatService.php');
$chatRepository = $read('app/Repositories/ReportChatRepository.php');
$chatController = $read('app/Controllers/ReportChatController.php');

$expect(str_contains($routes, "Router::get('/gestao-exames'") , 'Rota da Gestão de Exames ausente.');
$expect(str_contains($routes, "Router::get('/api/gestao-exames/estudos/{id}/gerenciar'") && str_contains($routes, 'GestaoExamesController@gerenciarContext'), 'Rota do submenu Gerenciar ausente.');
$expect(str_contains($routes, "Router::post('/api/gestao-exames/estudos/{id}/prioridade'") && str_contains($routes, 'GestaoExamesController@alterarPrioridade'), 'Rota de alteração de prioridade ausente.');
$expect(str_contains($routes, "Router::get('/api/gestao-exames/descricoes-por-modalidade'") && str_contains($routes, 'GestaoExamesController@descricoesPorModalidade'), 'Rota de sugestões de Descrição do Estudo ausente.');
$expect(str_contains($routes, "Router::post('/api/gestao-exames/estudos/{id}/descricao'") && str_contains($routes, 'GestaoExamesController@alterarDescricao'), 'Rota de gravação de Descrição do Estudo ausente.');
$expect(str_contains($header, "'/api/estudos/contadores'") && !str_contains($header, "'/estudos/contadores'"), 'Sidebar chama rota inválida de contadores.');
$expect(str_contains($routes, "GestaoExamesController@anexar") , 'Rota de anexação ausente.');
$expect(str_contains($routes, "GestaoExamesController@remover") , 'Rota de remoção ausente.');
$expect(str_contains($routes, "GestaoExamesController@arquivo") , 'Rota do proxy ausente.');
$expect(str_contains($controller, 'LEFT JOIN bi_pacs_estudos_pedidos') , 'Join do pedido não está na Worklist.');
$expect(str_contains($controller, "public function gestao(): void") , 'Ação gestao() ausente no Controller de Estudos.');
$expect(str_contains($view, "t('pedido_medico.coluna')") , 'Coluna PEDIDO não está internacionalizada.');
$expect(str_contains($view, 'id="pedidoModal"') , 'Modal do pedido não foi renderizada.');
$expect(str_contains($view, 'gerenciar-trigger') && str_contains($view, "t('gestao_gerenciar.acao.gerenciar')"), 'Botão Gerenciar não está na Worklist ou não está internacionalizado.');
$expect(str_contains($view, 'id="gerenciarModal"') && str_contains($view, 'id="gerenciarChatModal"') && str_contains($view, 'id="gerenciarPrioridadeModal"') && str_contains($view, 'id="gerenciarDescricaoModal"'), 'Modais do submenu Gerenciar incompletos.');
$expect(str_contains($view, 'gestao-exames-gerenciar.js'), 'JavaScript do submenu Gerenciar não foi carregado.');
$expect(str_contains($view, "setAttribute('capture', 'environment')"), 'Fallback de câmera não está configurado.');
$expect(str_contains($view, 'id="pedidoCameraFile"') && str_contains($view, 'accept="image/*" capture="environment"'), 'Input nativo exclusivo de câmera ausente.');
$expect(str_contains($view, 'cameraInput.click()'), 'Botão Câmera não aciona o input exclusivo.');
$expect(str_contains($view, "body.set('pedido', cameraFile"), 'Foto capturada não é anexada ao campo pedido.');
$expect(str_contains($view, 'new FormData(form)') , 'Upload multipart não está implementado.');
$expect(str_contains($view, '<?php if ($modoGestao): ?>'), 'Branch administrativa ausente.');

$actionsStart = strpos($view, '<!-- Ações -->');
$branchStart = $actionsStart === false ? false : strpos($view, '<?php if ($modoGestao): ?>', $actionsStart);
$branchEnd   = $branchStart === false ? false : strpos(
    $view,
    '<?php else: ?>' . "\n                        <?php if (\$podePeerReview",
    $branchStart
);
$managementBranch = ($branchStart !== false && $branchEnd !== false)
    ? substr($view, $branchStart, $branchEnd - $branchStart)
    : '';
$expect($managementBranch !== '', 'Não foi possível delimitar o branch da Gestão.');
$expect(!str_contains($managementBranch, 'wl-btn-assumir'), 'Gestão expõe botão Assumir.');
$expect(str_contains($managementBranch, 'wl-btn-laudo-gestao'), 'Gestão não expõe o botão Laudo administrativo.');
$expect(str_contains($managementBranch, 'target="_self"') && str_contains($managementBranch, 'rawurlencode($reportTokenGestao)') && str_contains($managementBranch, '/pdf?origem=gestao'), 'Laudo administrativo não abre o PDF opaco no contexto da Gestão.');
$expect(str_contains($managementBranch, 'aria-disabled="true"') && str_contains($managementBranch, 'is-disabled'), 'Laudo administrativo indisponível não possui estado desabilitado acessível.');
$expect(!str_contains($managementBranch, 'wl-btn-abrir'), 'Gestão expõe botão Abrir.');
$expect(!str_contains($managementBranch, '/abrir'), 'Gestão expõe rota de abertura.');
$expect(str_contains($view, '$podeConsultarLaudoGestao = $modoGestao') && str_contains($view, 'in_array($reportSituacaoGestao, [\'assinado\', \'liberado\'], true)'), 'Laudo administrativo não está restrito a laudos assinados ou liberados.');
$expect(str_contains($view, '$podeGerenciarPedido') && str_contains($view, 'preg_match(\'/^[a-f0-9]{48}$/\', $reportTokenGestao)'), 'Laudo administrativo não exige permissão e token opaco válido.');
$expect(str_contains($view, '<?php if (!$modoGestao): ?>'), 'Duplo clique do viewer não está condicionado ao modo médico.');
$expect(!str_contains($view, '<?php if ($modoGestao && $podeGerenciarPedido): ?>\n<script>'), 'Modal do pedido contém script aninhado dentro do bloco principal.');
$expect(substr_count($view, '<script>') === 1 && substr_count($view, '</script>') === 2, 'Worklist possui quantidade inconsistente de tags script.');

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

// Contrato do submenu Gerenciar: leitura, Chat contextual e prioridade auditável.
$expect(str_contains($gerenciarController, 'public function gerenciarContext') && str_contains($gerenciarController, 'public function alterarPrioridade'), 'Endpoints do Gerenciar ausentes no Controller.');
$expect(str_contains($gerenciarController, 'autorizadoGerenciar') && str_contains($gerenciarController, 'validarCsrf'), 'Gerenciar não centraliza autorização e CSRF.');
$expect(str_contains($gerenciarService, 'dicom_priority_override') && str_contains($gerenciarService, 'addPriorityAudit'), 'Service não separa override DICOM da auditoria.');
$expect(str_contains($gerenciarService, "'can_view_report' => in_array(\$reportSituacao, ['assinado', 'liberado'], true)"), 'Serviço administrativo não restringe a consulta a laudos assinados ou liberados.');
$expect(str_contains($gerenciarService, "mb_strlen(\$reason, 'UTF-8') < 20"), 'Motivo de prioridade não exige pelo menos 20 caracteres.');
$expect(str_contains($gerenciarService, "'chat_pendente'"), 'Alteração de prioridade não bloqueia Chat pendente.');
$expect(str_contains($gerenciarRepository, 'WHERE id = :study_id AND tenant_id = :tenant_id'), 'Repository do Gerenciar não está tenant-scoped.');
$expect(str_contains($gerenciarRepository, 'SET dicom_priority_override = :priority') && !str_contains($gerenciarRepository, 'atualizado_em = NOW()'), 'Override ainda referencia atualizado_em inexistente ou não confirmado.');
$expect(str_contains($gerenciarMigration, 'INFORMATION_SCHEMA.COLUMNS') && str_contains($gerenciarMigration, 'CREATE TABLE IF NOT EXISTS'), 'Migration do Gerenciar não é idempotente.');
$expect(str_contains($gerenciarMigration, 'dicom_priority_override') && str_contains($gerenciarMigration, 'bi_pacs_estudos_prioridade_auditoria'), 'Migration sem override e auditoria de prioridade.');
$expect(str_contains($gerenciarJs, '/api/gestao-exames/estudos/') && str_contains($gerenciarJs, '/prioridade'), 'Frontend não chama o endpoint de prioridade.');
$expect(str_contains($gerenciarJs, 'origem: \'gestao_exames\'') && str_contains($gerenciarJs, '/api/reports/chat/send'), 'Frontend do Gerenciar não envia Chat com origem administrativa.');
$expect(str_contains($gerenciarJs, 'reason.length < 20'), 'Frontend não valida motivo mínimo da prioridade.');
$expect(str_contains($gerenciarJs, "hidden.bs.modal") && str_contains($gerenciarJs, 'reopenGerenciarAfterDescription'), 'Descrição do Estudo não faz transição segura entre modais Bootstrap.');
$expect(str_contains($gerenciarJs, 'function csrfToken()') && str_contains($gerenciarJs, 'csrf: csrfToken()'), 'Descrição do Estudo não recupera token CSRF do próprio formulário.');
$expect(str_contains($gerenciarService, 'primaryModality') && str_contains($gerenciarService, "'modalidade' => \$this->primaryModality"), 'Contexto do Gerenciar não normaliza modalidade multisseriada.');
$expect(str_contains($descricaoService, 'private function normalizeModalidade') && str_contains($descricaoService, "preg_match('/[A-Z0-9]{1,16}/'"), 'Serviço de Descrição não aceita modalidade principal de estudos multisseriados.');
$expect(str_contains($chatService, 'lastMessageAuthorId') && str_contains($chatService, 'origemGestao'), 'Chat não aplica bloqueio por último autor ou exceção administrativa.');
$expect(str_contains($chatRepository, 'lastMessageAuthorId'), 'Repository do Chat não expõe o último autor.');
$expect(str_contains($chatController, 'PedidoMedicoService') && str_contains($chatController, 'origem') && str_contains($chatController, 'podeGerenciar'), 'Controller do Chat não protege a origem administrativa.');

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
