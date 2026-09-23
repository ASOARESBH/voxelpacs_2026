<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
$dicomPersonName = $root . '/app/Helpers/DicomPersonName.php';
if (is_file($dicomPersonName)) {
    require_once $dicomPersonName;
}
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$controller = (string) file_get_contents($root . '/app/Controllers/ReportsController.php');
$pdfService = (string) file_get_contents($root . '/app/Services/ReportPdfService.php');
$contextService = (string) file_get_contents($root . '/app/Services/ReportPdfDeliveryContextService.php');
$assert(str_contains($controller, 'private bool $portalPdfRequest = false;'), 'O portal PDF deve usar marcador interno, não GET confiável.');
$assert(!str_contains($controller, '$_GET[\'portal_patient_pdf\'] = \'1\';'), 'O link público não deve marcar portal por parâmetro manipulável.');
$assert(str_contains($controller, '$this->portalPdfTenantId'), 'O link público deve preservar o tenant resolvido da sessão.');
$assert(str_contains($controller, 'WHERE r.id = :id AND r.tenant_id = :tenant_id'), 'A consulta do PDF deve filtrar report por tenant.');
$assert(str_contains($controller, 'e.tenant_id = r.tenant_id'), 'A consulta do PDF deve filtrar estudo pelo mesmo tenant do report.');
$assert(str_contains($controller, 'prepareVisualAssets($data)'), 'O viewer deve usar o mesmo resolver de assets do snapshot.');
$assert(str_contains($pdfService, 'str_starts_with($normalizedLogoPath, \'uploads/unidades/\')'), 'Logo deve ficar restrito ao namespace da unidade.');
$assert(str_contains($pdfService, 'STORAGE_PATH'), 'Snapshot/viewer devem aceitar storage persistente de assets.');
$assert(str_contains($contextService, 'r.tenant_id = :tenant_id'), 'O contexto de delivery deve continuar tenant-scoped.');

$logo = 'data:image/png;base64,AA==';
$r = [
    'unidade_nome_fantasia' => 'UNIDADE TESTE',
    'unidade_razao_social' => 'UNIDADE TESTE LTDA',
    'unidade_logo_path' => 'uploads/unidades/u/2/7/logo.png',
    'pdf_snapshot_logo_src' => $logo,
    'tenant_nome' => 'TENANT TESTE',
    'tenant_cnpj' => '12345678000190',
    'patient_sex' => 'F',
    'patient_birth_date' => '1972-08-05',
    'patient_age' => '54',
    'patient_id' => 'TESTE-1',
    'institution_name' => 'UNIDADE TESTE',
    'referring_physician_name' => 'SOLICITANTE^TESTE',
    'study_date' => '2026-09-22',
    'study_time' => '120000',
    'modalities' => 'OT',
    'accession_number' => 'ACC-1',
    'medico_nome' => 'MÉDICO TESTE',
    'medico_crm' => 'CRM 1',
    'assinado_em' => '2026-09-22 12:00:00',
    'assinatura_hash' => 'hash-teste',
    'public_token' => 'token-teste',
];
$paciente = 'PACIENTE TESTE';
$corpoLaudo = '<p>Conteúdo de teste.</p>';
$secoesClinicasPdf = [];
$download = false;
$portalPatientPdf = true;
$reportReturnUrl = '/estudos';
$snapshotPdf = true;
$customTemplate = null;

foreach (['classico_centralizado', 'minimalista'] as $codigo) {
    $templatePath = $root . '/app/Views/reports/pdf/templates/_' . $codigo . '.php';
    ob_start();
    require $templatePath;
    $html = (string) ob_get_clean();
    $assert(str_contains($html, 'src="data:image/png;base64,AA=="'), "Template {$codigo} deve renderizar logo incorporado.");
    $assert(!str_contains($html, 'src="/data:image/png;base64'), "Template {$codigo} não pode transformar data URI em caminho HTTP.");
    $assert(!str_contains($html, 'Imprimir'), "Snapshot {$codigo} não pode reintroduzir ação de viewer.");
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "REPORT_PDF_UNIT_BRANDING_STATIC_OK\n";
