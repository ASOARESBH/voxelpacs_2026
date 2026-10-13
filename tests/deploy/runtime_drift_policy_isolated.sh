#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
script="$repo/scripts/validate-runtime-drift.sh"
tmp="$(mktemp -d /tmp/voxelpacs-runtime-drift-test.XXXXXX)"
cleanup() { rm -rf -- "$tmp"; }
trap cleanup EXIT

fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

published_sha='d1e9f69a1b2c3d4e5f60718293a4b5c6d7e8f901'
runtime="$tmp/runtime"
mkdir -p "$runtime/app/Services" "$runtime/vendor/composer" "$runtime/legacy"
printf '%s\n' 'owned-code' > "$runtime/app/ok.php"
printf '%s\n' 'published-service' > "$runtime/app/Services/Example.php"
cat > "$runtime/vendor/composer/installed.php" <<PHP
<?php return array(
    'root' => array(
        'name' => 'voxel/pacs',
        'reference' => '$published_sha',
    ),
    'versions' => array(
        'dompdf/dompdf' => array(
            'reference' => 'ffffffffffffffffffffffffffffffffffffffff',
        ),
    ),
);
PHP
printf '%s\n' 'baseline-extra' > "$runtime/legacy/allowed.txt"

hash_of() { sha256sum "$1" | awk '{print $1}'; }

# Manifesto do último commit publicado: idêntico ao runtime.
published="$tmp/published.tsv"
for rel in app/ok.php app/Services/Example.php vendor/composer/installed.php; do
  printf '%s\t%s\n' "$rel" "$(hash_of "$runtime/$rel")" >> "$published"
done
baseline="$tmp/baseline.tsv"
printf 'legacy/allowed.txt\t%s\t%s\troot\troot\t644\t2026-01-01T00:00:00Z\tDEVELOPMENT_OR_OPERATIONS\tREADABLE\n' \
  "$(stat -c '%s' "$runtime/legacy/allowed.txt")" "$(hash_of "$runtime/legacy/allowed.txt")" > "$baseline"
printf 'vendor/composer/installed.php\t%s\t%s\troot\troot\t644\t2026-01-01T00:00:00Z\tCOMPOSER_DEPENDENCY\tREADABLE\n' \
  "$(stat -c '%s' "$runtime/vendor/composer/installed.php")" "$(hash_of "$runtime/vendor/composer/installed.php")" >> "$baseline"

run() { "$script" --runtime-root "$runtime" --published-manifest "$1" --baseline-manifest "$2"; }

# 1. Runtime idêntico ao commit publicado: passa, sem allowlist manual.
positive="$tmp/positive.out"
run "$published" "$baseline" > "$positive" || fail 'positive_rejected'
grep -Fxq 'DRIFT_GATE=PASS' "$positive" || fail 'positive_gate'
grep -Fxq 'MECHANISM_VERSION=3' "$positive" || fail 'mechanism_version_output'
grep -Fxq 'REFERENCE=LAST_PUBLISHED_COMMIT' "$positive" || fail 'reference_output'
grep -Fxq "RUNTIME_PUBLISHED_SHA=$published_sha" "$positive" || fail 'published_sha_not_reported'
grep -Fxq 'CONTENT_MISMATCH=0' "$positive" || fail 'unexpected_mismatch'
grep -Fxq 'EXTRAS_IN_BASELINE=1' "$positive" || fail 'baseline_extra_not_counted'
! grep -Fq 'SYNTHETIC' "$positive" || fail 'content_exposed'

# 2. O código NOVO que será publicado não participa: um manifesto de um commit
#    mais novo (com arquivo alterado) não é a referência e por isso falharia;
#    o gate correto é sempre chamado com o manifesto do commit publicado.
newer="$tmp/newer.tsv"
sed "s|^app/Services/Example.php\t.*|app/Services/Example.php\t$(printf '%s\n' 'new-code' | sha256sum | awk '{print $1}')|" "$published" > "$newer"
if run "$newer" "$baseline" > "$tmp/newer.out" 2>&1; then fail 'newer_manifest_should_not_match_runtime'; fi
grep -Fxq 'MISMATCH_PATHS=app/Services/Example.php' "$tmp/newer.out" || fail 'newer_mismatch_path'

# 3. Baseline legado de oito colunas continua aceito.
legacy_baseline="$tmp/baseline-legacy.tsv"
awk -F '\t' 'BEGIN {OFS="\t"} {print $1,$2,$3,$4,$5,$6,$7,$8}' "$baseline" > "$legacy_baseline"
run "$published" "$legacy_baseline" > "$tmp/legacy.out" || fail 'legacy_baseline_rejected'
grep -Fxq 'BASELINE_FORMAT=LEGACY_8_COLUMN' "$tmp/legacy.out" || fail 'legacy_format_not_reported'

# 4. Arquivo de código editado direto no servidor: bloqueia.
printf '%s\n' 'hotfix-direto-no-servidor' > "$runtime/app/ok.php"
if run "$published" "$baseline" > "$tmp/edited.out" 2>&1; then fail 'server_edit_accepted'; fi
grep -Fxq 'DRIFT_GATE=FAIL' "$tmp/edited.out" || fail 'server_edit_reason'
grep -Fxq 'REASON=RUNTIME_CHANGED_OUTSIDE_PUBLISHED_COMMIT' "$tmp/edited.out" || fail 'server_edit_reason_text'
grep -Fxq 'MISMATCH_PATHS=app/ok.php' "$tmp/edited.out" || fail 'server_edit_path'
! grep -Fq 'hotfix-direto-no-servidor' "$tmp/edited.out" || fail 'content_exposed_on_fail'
printf '%s\n' 'owned-code' > "$runtime/app/ok.php"

# 5. Arquivo novo criado no servidor (fora do commit e do baseline): bloqueia.
printf '%s\n' 'unknown' > "$runtime/app/novo.php"
if run "$published" "$baseline" > "$tmp/unknown.out" 2>&1; then fail 'unknown_extra_accepted'; fi
grep -Fxq 'EXTRAS_NOT_IN_BASELINE=1' "$tmp/unknown.out" || fail 'unknown_extra_reason'
rm -f "$runtime/app/novo.php"

# 6. Extra do baseline com hash divergente: bloqueia.
printf '%s\n' 'tampered' > "$runtime/legacy/allowed.txt"
if run "$published" "$baseline" > "$tmp/tampered-extra.out" 2>&1; then fail 'baseline_hash_mismatch_accepted'; fi
grep -Fxq 'EXTRA_HASH_MISMATCH=1' "$tmp/tampered-extra.out" || fail 'baseline_hash_mismatch_reason'
printf '%s\n' 'baseline-extra' > "$runtime/legacy/allowed.txt"

# 7. Arquivo do commit publicado removido do servidor: bloqueia.
mv "$runtime/app/Services/Example.php" "$tmp/Example.php.bak"
if run "$published" "$baseline" > "$tmp/missing.out" 2>&1; then fail 'missing_file_accepted'; fi
grep -Fxq 'MISSING=1' "$tmp/missing.out" || fail 'missing_file_reason'
mv "$tmp/Example.php.bak" "$runtime/app/Services/Example.php"

# 8. Metadata Composer sem root.reference reconhecível: SHA reportado como UNAVAILABLE.
printf '%s\n' '<?php return array();' > "$runtime/vendor/composer/installed.php"
run "$published" "$baseline" > "$tmp/no-ref.out" 2>&1 || true
grep -Fxq 'RUNTIME_PUBLISHED_SHA=UNAVAILABLE' "$tmp/no-ref.out" || fail 'unavailable_sha_not_reported'

# 9. Argumento legado do artefato não é mais aceito.
if "$script" --runtime-root "$runtime" --artifact-manifest "$published" --baseline-manifest "$baseline" > "$tmp/legacy-arg.out" 2>&1; then fail 'legacy_argument_accepted'; fi
grep -Fq 'DRIFT_GATE=BLOCKED' "$tmp/legacy-arg.out" || fail 'legacy_argument_reason'

printf 'runtime_drift_policy_isolated: PASS\nREFERENCE=LAST_PUBLISHED_COMMIT\nPUBLISHED_RUNTIME=PASS\nNEW_CODE_NOT_COMPARED=YES\nSERVER_EDIT=BLOCKED\nUNKNOWN_EXTRA=BLOCKED\nBASELINE_HASH_MISMATCH=BLOCKED\nMISSING_FILE=BLOCKED\nPUBLISHED_SHA_REPORTED=YES\nLEGACY_ARGUMENT=BLOCKED\nPRODUCTION_CHANGED=NO\n'
