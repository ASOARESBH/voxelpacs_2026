<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) throw new RuntimeException('Arquivo ausente: ' . $relative);
    return (string) file_get_contents($path);
};

$controller = $read('app/Controllers/ReportsController.php');
$header = $read('app/Views/layout/reports_header.php');
$bootstrap = $read('public/assets/js/reports/reports-main.js');
$templates = $read('public/assets/js/reports/reports-templates.js');

$expect(str_contains($controller, 'normalizarModalidades') && str_contains($controller, 'study_description_match'), 'Endpoint de templates não reconhece modalidades múltiplas nem prioridade por Study Description.');
$expect(str_contains($controller, 'study_description_tag') && str_contains($controller, "UPPER(TRIM(COALESCE(study_description_tag, ''))) = :study_description"), 'Vínculo exato pela TAG DICOM Study Description não é consultado.');
$expect(str_contains($controller, 'modalidade IN (') && str_contains($controller, 'OR UPPER(TRIM(COALESCE(study_description_tag,'), 'Vínculo DICOM não prevalece sobre modalidade importada inconsistente.');
$expect(str_contains($controller, 'medico_id = :medico_id OR compartilhar = 1 OR medico_id IS NULL'), 'Lista de Máscaras não protege visibilidade médico–compartilhada.');
$expect(str_contains($controller, "'sugeridos' => \$sugeridos") && str_contains($controller, "'study_description_match' => !empty"), 'Endpoint não expõe a coleção priorizada de Templates vinculados.');
$expect(!str_contains($header, "explode('/', (string) (\$estudo->modalities"), 'Header ainda reduz modalidades somente pelo separador legado /.');
$expect(str_contains($header, 'data-modalidades=') && str_contains($header, 'data-study-description='), 'Header não expõe modalidades e Study Description ao editor.');
$expect(str_contains($bootstrap, 'modalidades:') && str_contains($bootstrap, 'studyDescription:'), 'Bootstrap não propaga modalidades e Study Description ao módulo de Templates.');
$expect(str_contains($templates, 'sugerirAutomaticamente') && str_contains($templates, 'config.templateId > 0') && str_contains($templates, '!isEditorVazio()'), 'Sugestão automática não preserva Template ou conteúdo clínico já existente.');
$expect(str_contains($templates, 'sugeridosIds()') && str_contains($templates, 'reports-mascara-search-option-suggested'), 'Busca inline não destaca os Templates vinculados ao estudo.');
$expect(str_contains($templates, 'modalidades') && str_contains($templates, 'study_description'), 'Frontend não envia modalidades e Study Description ao endpoint ativo.');
$expect(str_contains($templates, "editor.loadSecoes(parseSecoes(template), ['tecnica', 'achados', 'conclusao'])") && str_contains($templates, 'editor.loadConteudoLivre(livre)'), 'Aplicação não preserva os contratos livre e legado das Máscaras.');

if ($failures) {
    fwrite(STDERR, "FALHOU:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "REPORTS_TEMPLATES_STUDY_DESCRIPTION_OK\n";
