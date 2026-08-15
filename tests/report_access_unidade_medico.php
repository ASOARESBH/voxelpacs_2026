<?php

declare(strict_types=1);

use App\Core\Access\MedicoAccess;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

$failures = [];
$accessSource = (string) file_get_contents($root . '/app/Core/Access/MedicoAccess.php');
$reportAccessSource = (string) file_get_contents($root . '/app/Services/ReportAccessService.php');
$studiesSource = (string) file_get_contents($root . '/app/Controllers/EstudosController.php');

function reportAccessAssert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

// A permissão continua sendo por vínculo explícito, mas respeita as mesmas
// equivalências de texto que a infraestrutura DICOM/MySQL já aplica.
$method = new ReflectionMethod(MedicoAccess::class, 'sameInstitutionName');
$method->setAccessible(true);
reportAccessAssert($method->invoke(null, 'NOVA IMAGEM - CAMBUÍ', 'nova imagem - cambui'), 'Acento e caixa deveriam equivaler no InstitutionName.');
reportAccessAssert($method->invoke(null, 'INOVA  ', 'inova'), 'Espaços de borda não deveriam invalidar vínculo existente.');
reportAccessAssert(!$method->invoke(null, 'NOVA IMAGEM - CAMBUI', 'INOVA'), 'Unidades diferentes não podem ser consideradas equivalentes.');

reportAccessAssert(strpos($accessSource, 'private static function sameInstitutionName') !== false, 'Comparação canônica de InstitutionName ausente.');
reportAccessAssert(strpos($accessSource, "iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE'") !== false, 'Normalização de acentos ausente.');
reportAccessAssert(strpos($reportAccessSource, 'MedicoAccess::isInstitutionAllowed') !== false, 'ReportAccessService não aplica o escopo médico por Unidade.');

$assumirStart = strpos($studiesSource, 'public function assumirEstudo(): void');
$assumirSource = $assumirStart === false ? '' : substr($studiesSource, $assumirStart);
$guard = strpos($assumirSource, "isStudyAllowed((object) \$estudo, false)");
$update = strpos($assumirSource, "UPDATE bi_pacs_estudos SET");
reportAccessAssert($guard !== false && $update !== false && $guard < $update, 'Tomada de posse não valida a Unidade antes de atualizar o estudo.');
reportAccessAssert(strpos($studiesSource, 'Unidades autorizadas para o seu perfil') !== false, 'Retorno claro para tentativa de assumir Unidade não autorizada ausente.');

if ($failures !== []) {
    fwrite(STDERR, "REPORT_ACCESS_UNIDADE_MEDICO_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "REPORT_ACCESS_UNIDADE_MEDICO_OK\n");
