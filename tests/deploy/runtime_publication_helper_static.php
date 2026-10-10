<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$helper = (string) file_get_contents($root . '/ops/deploy/voxelpacs-deploy-runtime');
$deploy = (string) file_get_contents($root . '/scripts/deploy.sh');
$sudoers = (string) file_get_contents($root . '/ops/sudoers/voxelpacs-deploy-runtime');
$docs = (string) file_get_contents($root . '/ops/deploy/README_RUNTIME_PUBLICATION_HELPER.md');

$expect($helper !== '', 'helper privilegiado ausente');
$expect(str_contains($helper, "readonly APP_ROOT='/var/www/voxelpacs/app'"), 'APP_ROOT fixo ausente');
$expect(str_contains($helper, "readonly INCOMING_ROOT='/var/lib/voxelpacs/deploy/incoming'"), 'entrada fixa ausente');
$expect(str_contains($helper, "readonly EFFECTIVE_CONFIG_REL='app/Config/ReportDeliveryRuntimeConfig.php'"), 'configuração efetiva ausente');
$expect(str_contains($helper, '[[ "$EUID" -eq 0 ]]'), 'helper não exige root');
$expect(str_contains($helper, "CALLER_NOT_ALLOWLISTED"), 'helper não restringe caller sudo');
$expect(str_contains($helper, 'manus-admin|manus-deploy'), 'helper não permite os dois callers aprovados');
$expect(str_contains($helper, 'assert_allowed_archive_entries'), 'allowlist do ZIP ausente');
$expect(str_contains($helper, 'validate_manifest_shape'), 'validação do manifesto ausente');
$expect(str_contains($helper, 'MANIFEST_FILE_SET_MISMATCH'), 'comparação do conjunto do manifesto ausente');
$expect(str_contains($helper, 'MANIFEST_HASH_MISMATCH'), 'validação de hash por arquivo ausente');
$expect(str_contains($helper, 'PERSISTENT_STATE_CHANGED'), 'guard de dados persistentes ausente');
$expect(str_contains($helper, 'snapshot_current_runtime'), 'snapshot transacional ausente');
$expect(str_contains($helper, 'restore_transaction'), 'rollback transacional ausente');
$expect(str_contains($helper, 'PUBLICATION_FAILED_ROLLED_BACK'), 'rollback em falha ausente');
$expect(str_contains($helper, 'prepare_runtime_parent_directories'), 'preparação de diretórios-pai ausente');
$expect(str_contains($helper, "created-directories.tsv"), 'registro de diretórios criados ausente');
$expect(str_contains($helper, 'RUNTIME_PARENT_SYMLINK_NOT_ALLOWED'), 'guard de symlink de diretório-pai ausente');
$expect(str_contains($helper, 'restore_created_directories'), 'rollback de diretórios criados ausente');
$expect(str_contains($helper, 'rmdir -- "$target"'), 'rollback não usa remoção não recursiva');
$expect(str_contains($helper, 'mktemp "$parent/.voxelpacs-deploy.'), 'arquivo temporário no diretório do destino ausente');
$expect(str_contains($helper, 'mv -f -- "$tmp" "$target"'), 'publicação atômica por arquivo ausente');
$expect(str_contains($helper, 'WORKER_STARTED=NO'), 'invariante Worker ausente');
$expect(str_contains($helper, 'TRANSMISSION=NO'), 'invariante transmissão ausente');
$expect(!str_contains($helper, 'rm -rf -- "$APP_ROOT"'), 'helper não pode remover APP_ROOT');
$expect(!str_contains($helper, 'chmod -R'), 'helper não pode alterar permissões recursivamente');
$expect(!str_contains($helper, 'systemctl restart'), 'helper não pode reiniciar serviços');

$expect(str_contains($deploy, "REMOTE_INCOMING_ROOT='/var/lib/voxelpacs/deploy/incoming'"), 'deploy não usa entrada fixa');
$expect(str_contains($deploy, "REMOTE_HELPER='/usr/local/sbin/voxelpacs-deploy-runtime'"), 'deploy não usa helper fixo');
$expect(str_contains($deploy, 'REMOTE_USER="${DEPLOY_USER:-manus-admin}"'), 'deploy não preserva manus-admin como padrão');
$expect(str_contains($deploy, 'manus-admin|manus-deploy'), 'deploy não restringe callers aprovados');
$expect(str_contains($deploy, 'DEPLOY_USER_NOT_ALLOWLISTED'), 'deploy não bloqueia caller arbitrário');
$expect(str_contains($deploy, 'sudo -n -- "$helper" --sha "$sha"'), 'deploy não chama helper por sudo restrito');
$expect(str_contains($deploy, 'PRIVILEGED_HELPER_UNAVAILABLE'), 'deploy não bloqueia helper ausente');
$expect(str_contains($deploy, 'LOCAL_ARTIFACT_CHECKSUM_RECONCILIATION_FAILED'), 'deploy não reconcilia checksum local');
$expect(str_contains($deploy, 'REMOTE_ROOT=APP_ROOT'), 'deploy não declara raiz efetiva');
$expect(str_contains($deploy, 'archive_name="voxelpacs-runtime-${EXPECTED_SHA}.zip"'), 'ZIP não usa SHA completo no nome');
$expect(str_contains($deploy, 'manifest_name="voxelpacs-runtime-${EXPECTED_SHA}.manifest.tsv"'), 'manifesto não usa SHA completo no nome');
$expect(str_contains($deploy, 'checksum_name="voxelpacs-runtime-${EXPECTED_SHA}.sha256"'), 'checksum não usa SHA completo no nome');
$expect(str_contains($deploy, 'source_sha_name="voxelpacs-runtime-${EXPECTED_SHA}.source-sha"'), 'source-sha não usa SHA completo no nome');
$expect(str_contains($deploy, 'artifact="$tmp_dir/$archive_name"'), 'ZIP local não usa nome SHA-bound');
$expect(str_contains($deploy, 'manifest="$tmp_dir/$manifest_name"'), 'manifest local não usa nome SHA-bound');
$expect(str_contains($deploy, 'checksum="$tmp_dir/$checksum_name"'), 'checksum local não usa nome SHA-bound');
$expect(str_contains($deploy, 'source_sha="$tmp_dir/$source_sha_name"'), 'source-sha local não usa nome SHA-bound');
$expect(str_contains($deploy, 'remote_manifest="$REMOTE_INCOMING_ROOT/$manifest_name"'), 'manifest remoto não usa nome SHA-bound');
$expect(str_contains($deploy, 'remote_checksum="$REMOTE_INCOMING_ROOT/$checksum_name"'), 'checksum remoto não usa nome SHA-bound');
$expect(str_contains($deploy, 'remote_source_sha="$REMOTE_INCOMING_ROOT/$source_sha_name"'), 'source-sha remoto não usa nome SHA-bound');
$expect(str_contains($deploy, '--expected-sha "$EXPECTED_SHA"'), 'builder não recebe o SHA esperado');
$expect(!str_contains($deploy, 'unzip -o'), 'deploy não pode extrair in-place');
$expect(!str_contains($deploy, 'DEPLOY_PATH'), 'deploy não aceita destino remoto arbitrário');
$expect(!str_contains($deploy, 'chmod -R'), 'deploy não pode fazer chmod recursivo');
$expect(!str_contains($deploy, 'composer install'), 'deploy não executa Composer remoto');

$expect(str_contains($sudoers, 'manus-admin ALL=(root) NOPASSWD: /usr/local/sbin/voxelpacs-deploy-runtime --sha [a-f0-9]*'), 'regra sudoers de publicação ausente');
$expect(str_contains($sudoers, 'manus-admin ALL=(root) NOPASSWD: /usr/local/sbin/voxelpacs-deploy-runtime --rollback --sha [a-f0-9]*'), 'regra sudoers de rollback ausente');
$expect(str_contains($sudoers, 'manus-deploy ALL=(root) NOPASSWD: /usr/local/sbin/voxelpacs-deploy-runtime --sha [a-f0-9]*'), 'regra manus-deploy de publicação ausente');
$expect(str_contains($sudoers, 'manus-deploy ALL=(root) NOPASSWD: /usr/local/sbin/voxelpacs-deploy-runtime --rollback --sha [a-f0-9]*'), 'regra manus-deploy de rollback ausente');
$expect(substr_count($sudoers, 'manus-admin ALL=(root) NOPASSWD:') === 2, 'sudoers alterou as duas regras de manus-admin');
$expect(substr_count($sudoers, 'manus-deploy ALL=(root) NOPASSWD:') === 2, 'sudoers não contém exatamente duas regras de manus-deploy');

foreach ([
    'quatro arquivos',
    'manifesto',
    'checksum',
    'rollback',
    'visudo -cf',
    'não executa Composer',
    'não executa Composer',
] as $marker) {
    $expect(str_contains($docs, $marker), "documentação do helper incompleta: {$marker}");
}

fwrite(STDOUT, "runtime_publication_helper_static: PASS\n");
