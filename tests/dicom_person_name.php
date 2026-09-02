<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Helpers/DicomPersonName.php';

use App\Helpers\DicomPersonName;

$failures = [];
$requireSame = static function (string $expected, string $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $message;
    }
};

$cases = [
    ['SILVA^MARIA', 'MARIA SILVA', 'Deve colocar nome antes do sobrenome.'],
    ['SILVA^MARIA^APARECIDA', 'MARIA APARECIDA SILVA', 'Deve preservar o componente intermediário.'],
    ['SILVA^MARIA^APARECIDA^DRA.^JR.', 'DRA. MARIA APARECIDA SILVA JR.', 'Deve respeitar prefixo e sufixo.'],
    ['  ÁVILA  ^  ANA  ^  CLARA ', 'ANA CLARA ÁVILA', 'Deve normalizar espaços sem perder acentos.'],
    ['NOME JÁ LEGÍVEL', 'NOME JÁ LEGÍVEL', 'Não deve inverter texto sem delimitador DICOM.'],
    ['SILVA^MARIA=山田^花子', 'MARIA SILVA', 'Deve preferir somente o grupo alfabético de PN.'],
    ['SILVA^^^DRA.', 'DRA. SILVA', 'Deve ignorar componentes PN vazios.'],
];

foreach ($cases as [$input, $expected, $message]) {
    $requireSame($expected, DicomPersonName::format($input), $message);
}

$legacyRecord = [
    'patient_name' => 'SILVA MARIA',
    'patient_name_display' => 'SILVA MARIA',
    'tags_raw' => json_encode(['PatientName' => 'SILVA^MARIA'], JSON_THROW_ON_ERROR),
];
$requireSame(
    'MARIA SILVA',
    DicomPersonName::displayFromStudy($legacyRecord),
    'Tags DICOM devem corrigir a apresentação de registros legados sem escrever no banco.'
);

if ($failures !== []) {
    fwrite(STDERR, "DICOM_PERSON_NAME_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "DICOM_PERSON_NAME_OK\n");
