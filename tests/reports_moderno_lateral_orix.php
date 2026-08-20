<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
$templatePath = $root . '/app/Views/reports/pdf/templates/_moderno_lateral.php';
$source = (string) file_get_contents($templatePath);
$failures = [];

function modernRequire(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function modernContains(string $haystack, string $needle, string $message): void
{
    modernRequire(strpos($haystack, $needle) !== false, $message);
}

// Contrato visual e de impressão: um único HTML abastece tela, impressão e salvar como PDF.
foreach ([
    'class="pdf-header"',
    'class="pdf-header-left"',
    'class="pdf-header-right"',
    'class="pdf-patient"',
    'class="pdf-patient-col"',
    'class="pdf-exam-title"',
    'class="pdf-report-content"',
    'class="pdf-signature"',
    'class="pdf-footer"',
    '@page { size: A4 portrait;',
    'display: flex; flex-direction: column; width: 210mm;',
    '.pdf-header, .pdf-patient, .pdf-signature, .pdf-footer { position: static; }',
    'window.print()',
    '$corpoLaudo',
    'study_description',
    'unidade_logo_path',
    'assinatura_caminho_arquivo',
    'pdf-validation-token',
    'Token de validação para auditoria:',
    'horário de Brasília',
    'pdf-company-details',
    'registro_crm_numero',
    'unidade_cnpj',
    'unidade_telefone',
    'unidade_logradouro',
    'pdf-footer-details',
] as $required) {
    modernContains($source, $required, "Template Moderno Lateral não contém o elemento obrigatório: {$required}");
}
modernRequire(strpos($source, 'secao_exame') === false, 'O template reintroduziu uma seção clínica legada.');
modernRequire(strpos($source, 'pdf-patient-box') === false, 'O card cinza legado ainda está presente no Moderno Lateral.');
modernRequire(strpos($source, 'onclick="window.print()"') !== false, 'A impressão manual não está disponível no template.');
modernRequire(strpos($source, 'position: fixed;') === false, 'Elementos fixos podem deslocar cabeçalho ou assinatura entre páginas.');

// Renderiza o partial com dados equivalentes ao contrato de ReportsController::pdf().
$r = [
    'unidade_nome_fantasia' => 'ORIX TELERRADIOLOGIA LTDA',
    'unidade_razao_social' => 'ORIX TELERRADIOLOGIA LTDA',
    'tenant_nome' => 'ORIX TELERRADIOLOGIA LTDA',
    'tenant_cnpj' => '65757819000143',
    'registro_crm_uf' => 'SP',
    'registro_crm_numero' => '1.045.798',
    'unidade_logo_path' => 'uploads/unidades/orix-logo.png',
    'unidade_cnpj' => '07679915000114',
    'unidade_telefone' => '(34) 3251-7788',
    'unidade_logradouro' => 'Vereador Waldomiro Bueno',
    'unidade_numero' => '130',
    'unidade_complemento' => 'Sala 04',
    'unidade_bairro' => 'São Benedito',
    'unidade_cidade' => 'Cambuí',
    'unidade_estado' => 'MG',
    'patient_birth_date' => '1975-11-05',
    'patient_id' => '26175694',
    'study_date' => '2026-08-14',
    'accession_number' => '241098',
    'modalities' => 'TC',
    'study_description' => 'Tomografia Computadorizada do Tórax',
    'referring_physician_name' => 'Dr. João Silva',
    'medico_nome' => 'Dr. Paulo Vitor M. M. de Brito',
    'medico_crm' => '230161',
    'medico_crm_uf' => 'SP',
    'medico_especialidade' => 'Radiologia e Diagnóstico por Imagem',
    'assinado_em' => '2026-08-14 17:26:00',
    'assinatura_hash' => 'abc123456789',
    'assinatura_caminho_arquivo' => null,
    'public_token' => 'token-teste',
];
$paciente = 'Maria José Alves';
$corpoLaudo = '<p><strong>TÉCNICA:</strong><br>Aquisição volumétrica sem contraste.</p><p><strong>Impressão:</strong><br>Sem alterações significativas.</p>';
$download = false;

ob_start();
require $templatePath;
$html = (string) ob_get_clean();

foreach ([
    'ORIX TELERRADIOLOGIA LTDA',
    'Maria José Alves',
    'ID do Paciente:',
    '26175694',
    'Data do Exame:',
    '14/08/2026',
    'Prontuário:',
    '241098',
    'Tomografia Computadorizada do Tórax',
    'Aquisição volumétrica sem contraste.',
    'Dr. Paulo Vitor M. M. de Brito',
    'Radiologia e Diagnóstico por Imagem',
    'CRM-SP 230161',
    'Assinado digitalmente em 14/08/2026 17:26 (horário de Brasília)',
    'Token de validação para auditoria: abc123456789',
    'Empresa vinculada:',
    'ORIX TELERRADIOLOGIA LTDA',
    'CNPJ 65.757.819/0001-43',
    'CRM-SP 1.045.798',
    'CNPJ 07.679.915/0001-14',
    'Telefone (34) 3251-7788',
    'Vereador Waldomiro Bueno, 130 — Sala 04 — São Benedito — Cambuí/MG',
] as $expected) {
    modernContains($html, $expected, "Renderização Orix não contém: {$expected}");
}

if ($failures !== []) {
    fwrite(STDERR, "REPORTS_MODERNO_LATERAL_ORIX_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "REPORTS_MODERNO_LATERAL_ORIX_OK\n");
