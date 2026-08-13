<?php

declare(strict_types=1);

$raiz = dirname(__DIR__);
$config = file_get_contents($raiz . '/app/Config/BrandConfig.php');
$worklist = file_get_contents($raiz . '/app/Views/estudos/index.php');
$viewer = file_get_contents($raiz . '/app/Views/estudos/viewer.php');
$login = file_get_contents($raiz . '/app/Views/layout/auth_header.php');
$exame = file_get_contents($raiz . '/app/Views/pacs_exames/show.php');

$regras = [
    'configuração central declara a marca VOXEL PACS' => str_contains($config, "public const PACS_SERVER_NAME = 'VOXEL PACS';"),
    'Worklist usa a configuração no badge público' => str_contains($worklist, 'htmlspecialchars(\\App\\Config\\BrandConfig::PACS_SERVER_NAME)'),
    'mensagens públicas de download usam a configuração' => substr_count($worklist, 'json_encode(\\App\\Config\\BrandConfig::PACS_SERVER_NAME)') === 5,
    'Worklist não exibe Orthanc PACS' => !str_contains($worklist, 'Orthanc PACS'),
    'tela de login usa a configuração central' => str_contains($login, 'BrandConfig::PACS_SERVER_NAME')
        && !str_contains($login, 'direto do Orthanc PACS'),
    'viewer usa a configuração central' => str_contains($viewer, 'BrandConfig::PACS_SERVER_NAME')
        && !str_contains($viewer, 'servidor Orthanc'),
    'detalhe de exame usa a configuração central' => str_contains($exame, 'BrandConfig::PACS_SERVER_NAME')
        && !str_contains($exame, 'Abrir no Orthanc'),
    'exceção técnica é preservada' => str_contains($config, 'integração técnica continua usando Orthanc'),
];

$falhas = array_keys(array_filter($regras, static fn(bool $ok): bool => !$ok));
if ($falhas) {
    fwrite(STDERR, 'Regra(s) ausente(s): ' . implode(', ', $falhas) . PHP_EOL);
    exit(1);
}

echo "Regressão da marca pública do PACS verificada com sucesso.\n";
