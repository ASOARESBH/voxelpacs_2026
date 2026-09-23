<?php

declare(strict_types=1);

$script = (string) file_get_contents(dirname(__DIR__) . '/scripts/deploy.sh');

$required = [
    'REMOTE_PATH="${DEPLOY_PATH:-/var/www/voxelpacs}"' => 'deploy usa a raiz de runtime por padrão',
    'app_path="${remote_root%/}/app"' => 'deploy deriva o diretório app a partir da raiz do runtime',
    'bash -s -- deploy' => 'deploy reserva um argumento nominal para o script remoto',
    'REMOTE_APP_MODE="${DEPLOY_APP_MODE:-0751}"' => 'deploy define modo de travessia do app',
    'REMOTE_PUBLIC_MODE="${DEPLOY_PUBLIC_MODE:-0755}"' => 'deploy define modo do document root',
    'chmod "$app_mode" "$app_path"' => 'deploy aplica modo explícito ao app',
    'chmod "$public_mode" "$public_path"' => 'deploy aplica modo explícito ao public',
    'sudo -u "$web_user" test -x "$app_path"' => 'deploy valida travessia pelo usuário web',
    'sudo -u "$web_user" test -r "$public_path/index.php"' => 'deploy valida leitura do front controller',
    'DEPLOY_RUNTIME_PERMISSIONS=PASS' => 'deploy emite gate de permissões',
    'LOGIN_CODE=' => 'deploy executa smoke test do front controller',
];

foreach ($required as $needle => $description) {
    if (!str_contains($script, $needle)) {
        throw new RuntimeException("Contrato ausente: {$description}");
    }
}

$forbidden = [
    'chmod -R 775 storage/' => 'deploy não pode ampliar permissões recursivamente em storage',
    'Não foi possível verificar o health check' => 'falha do health check não pode ser apenas aviso',
];

foreach ($forbidden as $needle => $description) {
    if (str_contains($script, $needle)) {
        throw new RuntimeException("Regressão detectada: {$description}");
    }
}

if (!preg_match('/^set -Eeuo pipefail$/m', $script)) {
    throw new RuntimeException('Deploy deve falhar fechado em erro operacional.');
}

echo "DEPLOY_RUNTIME_PERMISSIONS_STATIC_OK\n";
