<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\ReportPdfService;

$syntheticBody = implode('', array_fill(0, 11, '<p>Texto sintético de homologação para validar paginação do documento clínico.</p>'));
$syntheticLogo = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9JxZ4AAAAASUVORK5CYII=';

$context = [
    'report' => [
        'id' => 999,
        'tenant_id' => 999,
        'tenant_nome' => 'Clínica Sintética',
        'tenant_cnpj' => '',
        'patient_name_display' => 'PACIENTE SINTETICO',
        'patient_id' => 'SYN-001',
        'patient_birth_date' => '2000-01-01',
        'patient_sex' => 'O',
        'patient_age' => '25Y',
        'study_date' => '2026-08-28',
        'study_time' => '120000',
        'study_description' => 'ESTUDO SINTETICO',
        'accession_number' => 'SYN-ACC-001',
        'modalities' => 'CT',
        'institution_name' => 'UNIDADE SINTETICA',
        'referring_physician_name' => 'MEDICO SINTETICO',
        'medico_nome' => 'RADIOLOGISTA SINTETICO',
        'medico_crm' => '000000',
        'medico_crm_uf' => 'MG',
        'medico_especialidade' => 'Radiologia',
        'assinado_em' => '2026-08-28 12:00:00',
        'assinatura_hash' => str_repeat('a', 64),
        'corpo_laudo' => $syntheticBody,
        'unidade_nome_fantasia' => 'Unidade Sintética',
        'unidade_razao_social' => 'Unidade Sintética',
        'unidade_cnpj' => '',
        'unidade_logo_path' => 'uploads/unidades/synthetic-logo.png',
        'pdf_snapshot_logo_src' => $syntheticLogo,
        'unidade_telefone' => '',
        'unidade_email' => '',
        'unidade_logradouro' => '',
        'unidade_numero' => '',
        'unidade_complemento' => '',
        'unidade_bairro' => '',
        'unidade_cidade' => '',
        'unidade_estado' => '',
        'unidade_personalizado_qrcode_habilitado' => 0,
        'unidade_personalizado_site_habilitado' => 0,
        'unidade_personalizado_instagram_habilitado' => 0,
        'unidade_personalizado_facebook_habilitado' => 0,
        'registro_crm_uf' => '',
        'registro_crm_numero' => '',
        'public_token' => '',
    ],
    'template_codigo' => 'moderno_lateral',
    'custom_template' => null,
];

try {
    $pdf = (new ReportPdfService())->renderSnapshotBinary($context);
    if (!str_starts_with($pdf, '%PDF') || strlen($pdf) < 1000) {
        throw new RuntimeException('snapshot_binary_contract_invalid');
    }
    if (isset($argv[1]) && $argv[1] !== '') {
        if (file_put_contents($argv[1], $pdf, LOCK_EX) === false) {
            throw new RuntimeException('snapshot_pdf_write_failed');
        }
    }
    fwrite(STDOUT, "snapshot_runtime_ok bytes=" . strlen($pdf) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'snapshot_runtime_error=' . get_class($error) . ':' . $error->getMessage() . "\n");
    exit(1);
}
