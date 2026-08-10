<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function source(string $path): string
{
    global $root;
    $full = $root . '/' . $path;
    if (!is_file($full)) throw new RuntimeException("Arquivo ausente: {$path}");
    return (string) file_get_contents($full);
}

function mustContain(string $haystack, string $needle, string $message): void
{
    if (strpos($haystack, $needle) === false) throw new RuntimeException($message);
}

function mustNotContain(string $haystack, string $needle, string $message): void
{
    if (strpos($haystack, $needle) !== false) throw new RuntimeException($message);
}

$routes = source('routes/web.php');
$repo = source('app/Repositories/ReportChatRepository.php');
$service = source('app/Services/ReportChatService.php');
$controller = source('app/Controllers/ReportChatController.php');
$reportService = source('app/Services/ReportService.php');
$reportsController = source('app/Controllers/ReportsController.php');
$view = source('app/Views/reports/partials/_chat_card.php');
$show = source('app/Views/reports/show.php');
$js = source('public/assets/js/reports/reports-chat.js');
$signature = source('public/assets/js/reports/reports-signature.js');
$header = source('app/Views/layout/reports_header.php');
$migration = source('database/migrations/2026-08-10_reports_chat.sql');
$design = source('docs/CHAT_REPORTS_DESIGN.md');

mustContain($routes, "Router::get('/api/reports/chat'", 'Rota GET do CHAT ausente.');
mustContain($routes, "Router::post('/api/reports/chat/send'", 'Rota de envio do CHAT ausente.');
mustContain($routes, "Router::post('/api/reports/chat/complete'", 'Rota de conclusão do CHAT ausente.');
mustContain($routes, "Router::get('/reports/{study_uid}'", 'Rota dinâmica do Report ausente.');
if (strpos($routes, "Router::get('/api/reports/chat'") > strpos($routes, "Router::get('/reports/{study_uid}'")) {
    throw new RuntimeException('Rota estática do CHAT deve preceder o wildcard do Report.');
}

mustContain($migration, 'pacs_report_chats', 'Tabela de cabeçalho do CHAT ausente na migration.');
mustContain($migration, 'pacs_report_chat_mensagens', 'Tabela de mensagens do CHAT ausente na migration.');
mustContain($migration, "ENUM('pendente','concluido')", 'Estados do CHAT não estão definidos.');
mustContain($migration, "'pendente'", 'Migration não prepara o estado pendente do estudo.');
mustContain($migration, "'peer_review'", 'Migration remove peer_review do ENUM existente.');
mustContain($migration, 'tenant_id', 'Migration do CHAT sem isolamento explícito por tenant.');

mustContain($repo, 'WHERE report_id = :report_id AND tenant_id = :tenant_id', 'Consulta de CHAT sem escopo report/tenant.');
mustContain($repo, 'listUsersByProfiles', 'Repository não resolve destinatários por grupo.');
mustContain($repo, 'findActiveUser', 'Repository não valida usuário ativo do tenant.');
mustContain($repo, 'status = "pendente"', 'Repository não consulta pendências abertas.');
mustContain($repo, 'status = "concluido"', 'Repository não conclui a conversa.');

mustContain($service, 'GROUP_ADMINISTRATIVO', 'Grupo Administrativo não definido no Service.');
mustContain($service, "['admin', 'secretaria', 'analista']", 'Perfis do grupo Administrativo não definidos.');
mustContain($service, 'Mailer::send', 'Notificação por e-mail ausente.');
mustContain($service, 'updateStudySituation((int) $context[\'estudo_id\'], $tenantId, \'pendente\')', 'Envio não muda estudo para pendente.');
mustContain($service, 'normalizarSituacaoRestaurada', 'Conclusão não restaura a situação anterior.');
mustContain($service, 'destinatario_invalido', 'Validação de destinatário ausente.');

mustContain($controller, 'validarCsrf', 'Controller do CHAT não valida CSRF.');
mustContain($controller, 'TenantContext::id', 'Controller do CHAT não resolve tenant.');
mustContain($controller, 'ReportChatService', 'Controller não usa o Service do CHAT.');

mustContain($reportService, 'hasPending($reportId', 'Assinatura não consulta CHAT pendente.');
mustContain($reportService, "'chat_pendente'", 'Assinatura não possui erro específico de CHAT pendente.');
mustContain($reportsController, 'ReportsController::liberar bloqueado por CHAT pendente', 'Liberação direta não possui bloqueio de CHAT.');
mustContain($reportsController, 'chat_pendente', 'Resposta de assinatura sem tradução do bloqueio CHAT.');
mustContain($reportsController, 'atualizarStatus bloqueado por CHAT pendente', 'Endpoint genérico de situação contorna pendência do CHAT.');

mustContain($show, "_chat_card.php", 'CHAT não foi incluído no Report.');
mustNotContain($show, "_equipamento_card.php", 'Quadrante Equipamento ainda está incluído no Report.');
mustContain($view, 'chatDestinatarioTipo', 'Seletor de destinatário ausente.');
mustContain($view, 'chatDestinatarioGrupo', 'Seletor de grupo ausente.');
mustContain($view, 'chatDestinatarioUsuario', 'Seletor de usuário ausente.');
mustContain($view, 'chatAssuntoCodigo', 'Seletor de temas ausente.');
mustContain($view, 'chatMensagem', 'Campo de interação ausente.');
mustContain($view, 'btn-chat-complete', 'Botão Concluído ausente.');

mustContain($js, '/api/reports/chat/send', 'Frontend não envia interação ao endpoint correto.');
mustContain($js, '/api/reports/chat/complete', 'Frontend não conclui CHAT pelo endpoint correto.');
mustContain($js, 'reports:chat-status', 'Frontend não publica estado da pendência.');
mustContain($signature, 'chatPendente', 'Assinatura não reage ao estado do CHAT.');
mustContain($header, 'data-chat-pending', 'Header não expõe o estado inicial do CHAT.');

mustContain($design, 'tenant atual', 'Desenho do CHAT não documenta o isolamento por tenant.');

fwrite(STDOUT, "REPORTS_CHAT_STATIC_OK\n");
