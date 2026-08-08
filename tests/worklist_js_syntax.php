<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string) file_get_contents($root . '/app/Views/estudos/index.php');
if (!preg_match('/<script>\s*(.*?)\s*<\/script>/s', $view, $match)) {
    fwrite(STDERR, "Nenhum bloco principal de script encontrado.\n");
    exit(1);
}

$javascript = preg_replace('/<\?(?:php|=).*?\?>/s', 'null', $match[1]);
if (!is_string($javascript) || trim($javascript) === '') {
    fwrite(STDERR, "JavaScript extraído vazio.\n");
    exit(1);
}

$output = sys_get_temp_dir() . '/voxel_worklist_' . bin2hex(random_bytes(6)) . '.js';
file_put_contents($output, $javascript);
echo $output . PHP_EOL;
