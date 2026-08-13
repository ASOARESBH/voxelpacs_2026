<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/Controllers/TemplatesController.php');
$routes = (string) file_get_contents($root . '/routes/web.php');
$form = (string) file_get_contents($root . '/app/Views/medicos/form.php');
$view = (string) file_get_contents($root . '/app/Views/mascaras/visualizar.php');
$failures = [];

$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(str_contains($routes, "Router::get('/medicos/{medicoId}/mascaras/{mascaraId}/visualizar', 'TemplatesController@visualizar');"), 'Rota GET de pré-visualização não encontrada.');
$previewStart = strpos($controller, 'public function visualizar(int $medicoId, int $mascaraId): void');
$previewEnd = $previewStart === false ? false : strpos($controller, '// POST /api/medicos/{medicoId}/templates', $previewStart);
$previewMethod = ($previewStart !== false && $previewEnd !== false) ? substr($controller, $previewStart, $previewEnd - $previewStart) : '';
$expect($previewMethod !== '', 'Endpoint TemplatesController::visualizar ausente.');
$expect(str_contains($previewMethod, 'AND tenant_id = :tid'), 'Prévia não possui filtro explícito de tenant.');
$expect(str_contains($previewMethod, 'AND (medico_id = :mid OR compartilhar = 1 OR medico_id IS NULL)'), 'Prévia não respeita máscara própria/compartilhada/global.');
$expect(!preg_match('/\b(?:UPDATE|INSERT|DELETE)\b/i', $previewMethod), 'Prévia não pode alterar report_templates.');
$expect(str_contains($form, 'title="Visualizar Laudo"'), 'Botão Visualizar Laudo não está na listagem.');
$expect(str_contains($form, 'fa fa-eye'), 'Ícone de olho da prévia está ausente.');
$expect(str_contains($form, 'target="_blank"'), 'Prévia deve abrir em nova aba.');
$expect(str_contains($view, 'PRÉ-VISUALIZAÇÃO DE MÁSCARA'), 'Aviso explícito de pré-visualização ausente.');
$expect(str_contains($view, 'Técnica') && str_contains($view, 'Achados') && str_contains($view, 'Impressão'), 'As três seções clínicas esperadas não estão na prévia.');
$expect(!str_contains($view, 'pdf-patient-box'), 'Prévia não pode renderizar caixa de dados de paciente.');
$expect(!str_contains($view, 'patient_name') && !str_contains($view, 'patient_id') && !str_contains($view, 'patient_birth_date'), 'Prévia contém referência a dados de paciente.');
$expect(str_contains($view, '<?= $tecnica ?>') && str_contains($view, '<?= $achados ?>') && str_contains($view, '<?= $impressao ?>'), 'Prévia não preserva HTML das seções clínicas.');
$expect(str_contains($view, 'window.print()'), 'Botão Imprimir ausente.');
$expect(!str_contains($view, 'Baixar PDF'), 'Prévia não deve oferecer download de PDF.');

if ($failures) {
    fwrite(STDERR, "FALHOU:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: pré-visualização de máscaras validada estaticamente.\n";
