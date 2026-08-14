<?php

declare(strict_types=1);

$raiz = dirname(__DIR__);
$access = file_get_contents($raiz . '/app/Services/ReportAccessService.php');
$service = file_get_contents($raiz . '/app/Services/ReportService.php');
$reports = file_get_contents($raiz . '/app/Controllers/ReportsController.php');
$chat = file_get_contents($raiz . '/app/Controllers/ReportChatController.php');
$peer = file_get_contents($raiz . '/app/Controllers/ReportPeerReviewController.php');
$measurements = file_get_contents($raiz . '/app/Controllers/ReportMeasurementsController.php');

$regras = [
    'serviço central une report e estudo para autorização' => str_contains($access, 'INNER JOIN bi_pacs_estudos e ON e.id = r.estudo_id'),
    'serviço central valida tenant do report' => str_contains($access, '$reportTenantId !== $currentTenantId'),
    'serviço central aplica Unidade médica permitida' => str_contains($access, 'MedicoAccess::isInstitutionAllowed'),
    'médico sem vínculo é bloqueado por padrão' => str_contains($access, "'medico_nao_vinculado'")
        && str_contains($access, '$perfil === \'medico\''),
    'médico restrito exige posse do estudo' => str_contains($access, 'MedicoAccess::isRestricted()')
        && str_contains($access, 'usuario_responsavel_id'),
    'show protege estudo antes de criar report' => str_contains($service, '(new ReportAccessService())->isStudyAllowed($estudo)'),
    'salvar protege report antes de UPDATE' => str_contains($service, 'public function salvar')
        && str_contains($service, '(new ReportAccessService())->findAuthorizedReport($reportId)'),
    'assinar protege report antes do ato médico-legal' => str_contains($service, 'public function assinar')
        && substr_count($service, '(new ReportAccessService())->findAuthorizedReport($reportId)') >= 3,
    'restaurar versão protege report antes de alterar conteúdo' => str_contains($service, 'public function restoreVersion')
        && str_contains($service, '$report = (new ReportAccessService())->findAuthorizedReport($reportId);'),
    'PDF protege report antes de carregar dados clínicos' => str_contains($reports, "if (!(new ReportAccessService())->findAuthorizedReport(\$reportId))"),
    'status e liberação usam autorização central' => substr_count($reports, '(new ReportAccessService())->findAuthorizedReport($reportId)') >= 5,
    'histórico protege report antes de listar versões' => str_contains($reports, 'public function history')
        && str_contains($reports, "if (!(new ReportAccessService())->findAuthorizedReport(\$reportId))"),
    'busca por estudo usa autorização central' => str_contains($reports, 'findAuthorizedReportByEstudoId($estudoId)'),
    'proxy de assinatura visual é protegido' => str_contains($reports, "!\$reportId || !\$tenantId || !(new ReportAccessService())->findAuthorizedReport(\$reportId)"),
    'CHAT exige acesso autorizado ao report' => substr_count($chat, '(new ReportAccessService())->findAuthorizedReport($reportId)') === 3,
    'Peer Review exige acesso autorizado ao report e snapshot' => substr_count($peer, '(new ReportAccessService())->findAuthorizedReport($reportId)') === 2
        && str_contains($peer, 'findAuthorizedReport((int) $review->report_id)'),
    'Medidas exigem acesso autorizado ao report' => substr_count($measurements, '(new ReportAccessService())->findAuthorizedReport($reportId)') === 2,
];

$falhas = array_keys(array_filter($regras, static fn(bool $ok): bool => !$ok));
if ($falhas) {
    fwrite(STDERR, 'Regra(s) de acesso IDOR ausente(s): ' . implode(', ', $falhas) . PHP_EOL);
    exit(1);
}

echo "Regressão de acesso IDOR de laudos verificada com sucesso.\n";
