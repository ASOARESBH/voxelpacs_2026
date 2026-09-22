<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/app/Helpers/DicomPersonName.php';
require_once $root . '/app/Services/ReportCustomTemplateService.php';

$r = [
    'unidade_nome_fantasia' => 'UNIDADE TESTE',
    'tenant_nome' => 'TENANT TESTE',
    'patient_id' => 'PATIENT-123456789',
    'patient_birth_date' => '1980-01-15',
    'study_date' => '2026-09-22',
    'accession_number' => 'ACCESSION-987654321',
    'referring_physician_name' => 'SILVA^JOAO',
    'medico_nome' => 'MEDICO TESTE',
    'public_token' => 'test-token',
    'assinado_em' => '2026-09-22 10:00:00',
];
$secoesClinicasPdf = [
    'tecnica' => ['rotulo' => 'TÉCNICA', 'conteudo' => '<p>Conteúdo controlado.</p>'],
];
$corpoLaudo = '';
$paciente = 'PACIENTE TESTE';
$laudoPossuiConteudo = true;
$portalPatientPdf = true;
$reportReturnUrl = '/estudos';
$download = false;
$snapshotPdf = true;
$templatePath = $root . '/app/Views/reports/pdf/templates/_moderno_lateral.php';
ob_start();
require $templatePath;
$html = (string) ob_get_clean();

$dompdf = new Dompdf\Dompdf(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true]);
$dompdf->getOptions()->setDefaultMediaType('print');
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4');
$dompdf->render();
$path = tempnam(sys_get_temp_dir(), 'voxelpacs-pdf-layout-');
if ($path === false || file_put_contents($path, $dompdf->output()) === false) {
    fwrite(STDERR, "PDF_LAYOUT_RENDER_FALHOU\n");
    exit(1);
}
echo $path . "\n";
