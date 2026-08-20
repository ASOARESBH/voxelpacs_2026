<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

$failures = [];
function minimalFooterAssert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$templatePath = $root . '/app/Views/reports/pdf/templates/_minimalista.php';
$source = (string) file_get_contents($templatePath);
foreach (['unidade_cnpj', 'unidade_telefone', 'unidade_logradouro', 'pdf-footer-details', 'Identificação da unidade'] as $needle) {
    minimalFooterAssert(strpos($source, $needle) !== false, "Template Minimalista não contém o contrato institucional: {$needle}");
}

$r = [
    'id' => 41,
    'unidade_nome_fantasia' => 'NOVA IMAGEM',
    'unidade_razao_social' => 'NOVA IMAGEM CENTRO DE DIAGNOSTICO POR IMAGEM LTDA',
    'unidade_cnpj' => '07679915000114',
    'unidade_telefone' => '(34) 3251-7788',
    'unidade_logradouro' => 'Vereador Waldomiro Bueno',
    'unidade_numero' => '130',
    'unidade_complemento' => 'Sala 04',
    'unidade_bairro' => 'São Benedito',
    'unidade_cidade' => 'Cambuí',
    'unidade_estado' => 'MG',
    'patient_sex' => 'Feminino',
    'patient_birth_date' => '1979-09-11',
    'patient_age' => '46 anos',
    'patient_id' => '46702',
    'study_date' => '2026-08-19',
    'modalities' => 'CT',
    'referring_physician_name' => 'Dra. Carolina',
    'medico_nome' => 'Dr. João de Teste',
    'medico_crm' => 'MG 112234',
    'assinado_em' => '2026-08-20 10:39:40',
    'public_token' => 'token-teste',
    'assinatura_hash' => 'hash-teste',
];
$paciente = 'Flavia Helena Barbosa';
$corpoLaudo = '<p>Laudo clínico de teste.</p>';
$download = false;
$portalPatientPdf = false;

ob_start();
require $templatePath;
$html = (string) ob_get_clean();

foreach ([
    'NOVA IMAGEM',
    'CNPJ 07.679.915/0001-14',
    'Telefone (34) 3251-7788',
    'Vereador Waldomiro Bueno, 130 — Sala 04 — São Benedito — Cambuí/MG',
] as $expected) {
    minimalFooterAssert(strpos($html, $expected) !== false, "Rodapé Minimalista não contém: {$expected}");
}
minimalFooterAssert(strpos($html, 'NOVA IMAGEM — Laudo 41') === false, 'Rodapé Minimalista preservou o formato antigo sem dados institucionais.');

if ($failures !== []) {
    fwrite(STDERR, "REPORTS_MINIMALISTA_FOOTER_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "REPORTS_MINIMALISTA_FOOTER_OK\n");
