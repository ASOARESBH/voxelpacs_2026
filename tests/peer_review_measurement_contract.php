<?php

declare(strict_types=1);

$raiz = dirname(__DIR__);
$measurementService = file_get_contents($raiz . '/app/Services/ReportMeasurementService.php');
$measurementRepository = file_get_contents($raiz . '/app/Repositories/ViewerMeasurementRepository.php');
$peerRepository = file_get_contents($raiz . '/app/Repositories/ReportPeerReviewRepository.php');
$peerService = file_get_contents($raiz . '/app/Services/ReportPeerReviewService.php');
$peerController = file_get_contents($raiz . '/app/Controllers/ReportPeerReviewController.php');
$peerCard = file_get_contents($raiz . '/app/Views/reports/partials/_peer_review_card.php');
$header = file_get_contents($raiz . '/app/Views/layout/reports_header.php');
$measurementsCard = file_get_contents($raiz . '/app/Views/reports/partials/_measurements_card.php');
$css = file_get_contents($raiz . '/public/assets/css/reports.css');
$migration = file_get_contents($raiz . '/database/migrations/2026-08-10_reports_peer_review.sql');
$peerReviewJs = file_get_contents($raiz . '/public/assets/js/reports/reports-peer-review.js');

$regras = [
    'serviço de medidas usa estudo_id canônico' => str_contains($measurementService, '$report->estudo_id')
        && !str_contains($measurementService, '$report->bi_pacs_estudos_id'),
    'serviço de medidas usa situacao canônica' => str_contains($measurementService, '$report->situacao')
        && !str_contains($measurementService, '$report->status'),
    'repositório de medidas usa estudo_id canônico' => str_contains($measurementRepository, '$report->estudo_id')
        && !str_contains($measurementRepository, '$report->bi_pacs_estudos_id'),
    'Peer Review resolve tenant por InstitutionName' => str_contains($peerRepository, 'InstitutionResolverService::getInstitutionNamesByTenant')
        && str_contains($peerRepository, 'institutionScope(')
        && !str_contains($peerRepository, 'e.tenant_id'),
    'update do estudo Peer Review não depende de tenant_id inexistente' => !str_contains($peerRepository, 'WHERE id = :estudo_id AND tenant_id = :tenant_id'),
    'schema ausente produz orientação específica' => str_contains($peerService, 'peer_review_schema_ausente')
        && str_contains($peerController, '2026-08-10_reports_peer_review.sql'),
    'endpoint de Peer Review possui leitores JSON e CSRF próprios' => str_contains($peerController, 'private function getJsonInput(): array')
        && str_contains($peerController, 'private function validarCsrf(string $token): bool')
        && str_contains($peerController, "file_get_contents('php://input')"),
    'interface preserva resposta específica e diagnostica HTTP não JSON' => str_contains($peerReviewJs, 'const raw = await response.text();')
        && str_contains($peerReviewJs, 'data.msg || fallback')
        && str_contains($peerReviewJs, '[PeerReview] resposta não JSON'),
    'botão roxo usa componente primário' => str_contains($peerCard, 'class="btn-pacs-primary peer-review-open-btn"')
        && !str_contains($peerCard, 'pacs-btn btn-pacs-warning'),
    'botões textuais do cabeçalho não usam pacs-btn' => !str_contains($header, 'class="pacs-btn" id="btn-template"')
        && !str_contains($header, 'class="pacs-btn btn-pacs-primary" id="btn-sign"')
        && str_contains($header, 'class="btn-pacs-primary" id="btn-sign"'),
    'botões de medidas seguem componentes PACS' => str_contains($measurementsCard, 'class="btn-pacs-primary reports-measurements-action-btn"')
        && str_contains($measurementsCard, 'class="btn-pacs-outline reports-measurements-action-btn"'),
    'Peer Review mantém gradiente roxo e alinhamento primário' => str_contains($css, '.peer-review-open-btn:hover')
        && str_contains($css, 'color: #fff;'),
    'migration é compatível com HostGator' => !str_contains($migration, 'INFORMATION_SCHEMA')
        && str_contains($migration, 'DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci')
        && str_contains($migration, 'pacs_report_peer_reviews'),
];

$falhas = array_keys(array_filter($regras, static fn(bool $ok): bool => !$ok));
if ($falhas) {
    fwrite(STDERR, 'Regra(s) ausente(s): ' . implode(', ', $falhas) . PHP_EOL);
    exit(1);
}

echo "Regressão de Peer Review e integração de medidas verificada com sucesso.\n";
