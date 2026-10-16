<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$transaction = (string) file_get_contents($root . '/ops/deploy/voxelpacs-deploy-transaction');
$runtime = (string) file_get_contents($root . '/ops/deploy/voxelpacs-deploy-runtime');
$builder = (string) file_get_contents($root . '/scripts/build-runtime-artifact.sh');
$sudoers = (string) file_get_contents($root . '/ops/sudoers/voxelpacs-deploy-transaction');
$ci = (string) file_get_contents($root . '/.github/workflows/ci.yml');
$docs = (string) file_get_contents($root . '/ops/deploy/README_DEPLOY_TRANSACTION_RECONCILIATION.md');

$expect(str_contains($transaction, 'abort-unpublished --sha <40-hex-sha>'), 'interface abort-unpublished ausente');
$expect(str_contains($transaction, 'abort_unpublished_transaction'), 'implementação abort-unpublished ausente');
$expect(str_contains($transaction, "^(created|validated)$"), 'stage abortável não está fechado');
$expect(str_contains($transaction, 'TRANSACTION_ALREADY_PUBLISHED_OR_PUBLISHING'), 'published não é bloqueado');
$expect(str_contains($transaction, 'runtime_matches_backup'), 'prova de drift contra backup ausente');
$expect(str_contains($transaction, 'RUNTIME_MODIFIED_AFTER_TRANSACTION_START'), 'prova de mtime ausente');
$expect(str_contains($transaction, 'RELEASE_POINTER_REFERENCES_TRANSACTION'), 'prova de ponteiro de release ausente');
$expect(str_contains($transaction, 'aborted_unpublished'), 'estado terminal ausente');
$expect(str_contains($transaction, 'audit.sha256'), 'checksum de evidência ausente');
$expect(str_contains($transaction, 'DEPLOY_LOCK_ACTIVE_OR_UNKNOWN'), 'lock fail-closed ausente');
$expect(str_contains($runtime, 'validation_stage'), 'staging pré-transação ausente');
$expect(str_contains($runtime, "VENDOR_NESTED_VENDOR"), 'guard vendor/vendor no helper ausente');
$expect(str_contains($runtime, 'assert_aborted_unpublished_transaction'), 'reuso seguro de transação abortada ausente');
$expect(str_contains($builder, "vendor_nested_vendor"), 'guard vendor/vendor no builder ausente');
$expect(str_contains($sudoers, 'abort-unpublished --sha [a-f0-9]*'), 'allowlist abort-unpublished ausente');
$expect(substr_count($sudoers, 'abort-unpublished --sha [a-f0-9]*') === 2, 'abort-unpublished deve ter dois callers');
$expect(str_contains($ci, 'deploy_transaction_abort_unpublished_isolated.sh'), 'teste isolado não está no CI');
$expect(str_contains($docs, 'validated/UNKNOWN'), 'runbook não registra o problema resolvido');
$expect(str_contains($docs, 'abort-unpublished'), 'runbook não documenta abort-unpublished');

fwrite(STDOUT, "deploy_transaction_abort_unpublished_static: PASS\n");
