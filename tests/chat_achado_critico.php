<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function sourceCritical(string $path): string
{
    global $root;
    $full = $root . '/' . $path;
    if (!is_file($full)) {
        throw new RuntimeException("Arquivo ausente: {$path}");
    }
    return (string) file_get_contents($full);
}

function requireCritical(string $haystack, string $needle, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message);
    }
}

$migration = sourceCritical('database/migrations/2026-08-14_bi_pacs_estudos_achado_critico.sql');
$repo = sourceCritical('app/Repositories/ReportChatRepository.php');
$service = sourceCritical('app/Services/ReportChatService.php');
$controller = sourceCritical('app/Controllers/ReportChatController.php');
$worklistController = sourceCritical('app/Controllers/EstudosController.php');
$worklistView = sourceCritical('app/Views/estudos/index.php');
$chatView = sourceCritical('app/Views/reports/partials/_chat_card.php');
$reportsJs = sourceCritical('public/assets/js/reports/reports-chat.js');
$managementJs = sourceCritical('public/assets/js/gestao-exames-gerenciar.js');
$pacsCss = sourceCritical('public/assets/css/pacs.css');
$reportsCss = sourceCritical('public/assets/css/reports.css');

// Migration MySQL 5.7: atributo novo e independente de prioridade.
requireCritical($migration, 'achado_critico_em', 'Migration não cria data do Achado Crítico.');
requireCritical($migration, 'achado_critico_por', 'Migration não cria autor do Achado Crítico.');
requireCritical($migration, 'achado_critico_assunto', 'Migration não cria assunto do Achado Crítico.');
requireCritical($migration, 'idx_achado_critico_em', 'Migration não cria índice temporal do Achado Crítico.');
requireCritical($migration, 'idx_achado_critico_por', 'Migration não cria índice do médico marcador.');
requireCritical($migration, 'fk_bi_pacs_estudos_achado_critico_por', 'Migration não cria integridade referencial do médico marcador.');
requireCritical($migration, 'utf8_unicode_ci', 'Migration não declara collation compatível com HostGator.');
requireCritical($migration, 'ROLLBACK', 'Migration não possui rollback.');
if (strpos($migration, 'ADD COLUMN IF NOT EXISTS') !== false
    || preg_match('/^\\s*(?:SELECT|FROM|JOIN).*INFORMATION_SCHEMA/im', $migration)) {
    throw new RuntimeException('Migration usa recurso proibido no HostGator/MySQL 5.7.');
}

// Persistência e destinatários obrigatórios dentro do tenant.
requireCritical($repo, 'markCriticalFinding', 'Repository não marca Achado Crítico.');
requireCritical($repo, 'listActiveTenantAdmins', 'Repository não resolve administradores ativos do tenant.');
requireCritical($repo, 'achado_critico_em = NOW()', 'Marcação não registra data/hora do Achado Crítico.');
requireCritical($repo, 'achado_critico_por = :user_id', 'Marcação não registra o médico.');
requireCritical($repo, 'achado_critico_assunto = :assunto', 'Marcação não registra o assunto.');
requireCritical($repo, 'ut.tenant_id = :tenant_id', 'Administradores não possuem escopo explícito por tenant.');
requireCritical($repo, 'ut.perfil = "admin"', 'Destinatários obrigatórios não estão restritos a administradores do tenant.');

// Serviço: somente médico, sem alterar prioridade, auditável e com e-mail verificável.
requireCritical($service, "'achado_critico' => 'ACHADO CRÍTICO'", 'Tema ACHADO CRÍTICO não está cadastrado.');
requireCritical($service, "Auth::perfilAtual() !== 'medico'", 'A regra de perfil médico não é validada no servidor.');
requireCritical($service, 'achado_critico_restrito_medico', 'Erro de permissão do Achado Crítico ausente.');
requireCritical($service, 'markCriticalFinding', 'Serviço não delega a persistência do Achado Crítico.');
requireCritical($service, "AuditLogger::log('estudo.achado_critico_marcado'", 'Auditoria de Achado Crítico ausente.');
requireCritical($service, 'notifyCriticalRecipients', 'Notificação clínica obrigatória não foi separada.');
requireCritical($service, 'listActiveTenantAdmins', 'Administradores não são incluídos na notificação clínica.');
requireCritical($service, 'Mailer::send', 'Resultado real de envio de e-mail não é verificado.');
requireCritical($service, 'email_warning', 'Falha de e-mail não retorna aviso ao CHAT.');
requireCritical($service, 'VIEWER_ERP_URL', 'Link do e-mail não usa a URL oficial do ERP.');
if (strpos($service, 'SET prioridade') !== false) {
    throw new RuntimeException('Achado Crítico não pode alterar o campo prioridade.');
}

// Endpoint e interface do Laudário.
requireCritical($controller, 'achado_critico_restrito_medico', 'Controller não traduz a restrição do Achado Crítico.');
requireCritical($chatView, 'chatAssuntoCodigo', 'Card de CHAT não expõe seletor de tema.');
requireCritical($chatView, 'chat-critical-alert', 'Card de CHAT não possui alerta visual de Achado Crítico.');
requireCritical($reportsJs, "'achado_critico'", 'JavaScript do Laudário não reconhece o tema crítico.');
requireCritical($reportsJs, 'criticalConfirm', 'JavaScript do Laudário não confirma a comunicação crítica.');
requireCritical($reportsJs, 'email_warning', 'JavaScript do Laudário não mostra aviso de e-mail.');
requireCritical($reportsCss, '.reports-chat-critical-alert', 'CSS do alerta clínico no CHAT ausente.');

// Worklist: projeção, contador com escopo, card e badge distintos de urgência.
requireCritical($worklistController, 'e.achado_critico_em', 'Worklist não projeta data do Achado Crítico.');
requireCritical($worklistController, 'e.achado_critico_assunto', 'Worklist não projeta assunto do Achado Crítico.');
requireCritical($worklistController, "'achado_critico'=>0", 'Contadores da Worklist não incluem Achado Crítico.');
requireCritical($worklistController, 'e.achado_critico_em IS NOT NULL', 'Contagem de Achado Crítico não usa condição clínica explícita.');
requireCritical($worklistView, 'function achadoCriticoBadge', 'Badge de Achado Crítico não foi criado.');
requireCritical($worklistView, "achadoCriticoBadge(\$e['achado_critico_em']", 'Badge não é renderizado na Worklist.');
requireCritical($worklistView, 'wl-card-achado-critico', 'Card de resumo de Achados Críticos ausente.');
requireCritical($pacsCss, '.achado-critico-badge', 'CSS do badge de Achado Crítico ausente.');
requireCritical($pacsCss, '#d946ef', 'Badge de Achado Crítico não usa cor visualmente distinta.');

// Gestão de Exames usa o mesmo endpoint e reconhece o mesmo tema.
requireCritical($managementJs, "achado_critico: 'ACHADO CRÍTICO'", 'Gestão de Exames não lista o tema crítico.');
requireCritical($managementJs, '/api/reports/chat/send', 'Gestão de Exames não usa o endpoint compartilhado do CHAT.');
requireCritical($managementJs, 'email_warning', 'Gestão de Exames não exibe falha de e-mail do Achado Crítico.');

fwrite(STDOUT, "CHAT_ACHADO_CRITICO_OK\n");
