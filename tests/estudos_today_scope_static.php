<?php

declare(strict_types=1);

$path = dirname(__DIR__) . '/app/Controllers/EstudosController.php';
$source = (string) file_get_contents($path);

if ($source === '') {
    fwrite(STDERR, "ESTUDOS_TODAY_SCOPE_FALHOU: controller indisponível.\n");
    exit(1);
}

$definition = strpos($source, "\$today = date('Y-m-d');");
$summaryUse = strpos($source, 'array_merge($rBase_p, [$today])');
$summaryComment = strpos($source, 'Painel de resumo');

if ($definition === false || $summaryUse === false || $summaryComment === false) {
    fwrite(STDERR, "ESTUDOS_TODAY_SCOPE_FALHOU: definição ou uso do resumo não encontrado.\n");
    exit(1);
}
if ($definition > $summaryComment || $definition > $summaryUse) {
    fwrite(STDERR, "ESTUDOS_TODAY_SCOPE_FALHOU: \$today não é inicializada antes do painel-resumo.\n");
    exit(1);
}

fwrite(STDOUT, "ESTUDOS_TODAY_SCOPE_OK\n");
