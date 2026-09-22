<?php

declare(strict_types=1);

$base = dirname(__DIR__);
$controller = file_get_contents($base . '/app/Controllers/ReportPeerReviewController.php');
$service = file_get_contents($base . '/app/Services/ReportPeerReviewService.php');
$repository = file_get_contents($base . '/app/Repositories/ReportPeerReviewRepository.php');

if ($controller === false || $service === false || $repository === false) {
    fwrite(STDERR, "VALIDACAO_PEER_REVIEW_AUTORIZACAO=FALHOU: arquivo ausente\n");
    exit(1);
}

$checks = [
    'controller reutiliza acesso autorizado' => str_contains($controller, '$authorizedReport = (new ReportAccessService())->findAuthorizedReport($reportId);'),
    'controller entrega contexto autorizado ao serviço' => str_contains($controller, '$this->service->abrir($authorizedReport, $motivo);'),
    'serviço recebe contexto autorizado' => str_contains($service, 'public function abrir(object $report, string $motivo): array'),
    'serviço não repete busca institucional exata' => !str_contains($service, '$this->repo->findReportContext($reportId);'),
    'vínculo médico ativo continua obrigatório' => str_contains($service, 'findByUsuarioId($userId, $tenantId)'),
    'estado elegível continua obrigatório' => str_contains($service, "['assinado', 'liberado']"),
    'trava transacional mantém tenant do laudo' => str_contains($repository, 'AND tenant_id = :tenant_id'),
    'atualização do estudo mantém escopo institucional' => str_contains($repository, 'study_update_institution'),
    'snapshot e auditoria continuam presentes' => str_contains($repository, 'pacs_report_peer_review_originais') && str_contains($service, "AuditLogger::log('report.peer_review_aberto'"),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if ($failed) {
    fwrite(STDERR, 'VALIDACAO_PEER_REVIEW_AUTORIZACAO=FALHOU: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "VALIDACAO_PEER_REVIEW_AUTORIZACAO=OK\n";
