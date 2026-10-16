#!/usr/bin/env bash
# Teste sintético root-controlled: não usa produção, SSH, banco, Worker, Bridge ou SMB.
set -Eeuo pipefail

root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
test_root="$(mktemp -d "${TMPDIR:-/tmp}/voxelpacs-abort-unpublished-test.XXXXXX")"
cleanup() {
  case "$test_root" in
    /tmp/voxelpacs-abort-unpublished-test.*) sudo -n rm -rf -- "$test_root" ;;
    *) printf 'unsafe_test_cleanup_path\n' >&2; exit 1 ;;
  esac
}
trap cleanup EXIT

app_root="$test_root/app"
release_root="$test_root/releases"
transaction_root="$release_root/transactions"
backup_root="$test_root/backups"
lock_path="$test_root/deploy.lock"
helper="$test_root/transaction-helper"
previous_sha='c90ae2301e32c794d0819dcbcaa3c06b4cb4d7f9'
target_sha='12718f69283d10549ad7f16d3a1391d9113894b1'

mkdir -p "$app_root/app/Config" "$app_root/public" "$app_root/bin" "$app_root/vendor/composer" \
  "$app_root/storage" "$app_root/storage/uploads" "$release_root" "$transaction_root" \
  "$backup_root/$previous_sha"
sudo -n chown -R root:root "$test_root"
sudo -n chmod 700 "$test_root" "$release_root" "$transaction_root" "$backup_root" "$backup_root/$previous_sha"

printf 'synthetic-env\n' > "$app_root/.env"
printf 'runtime-config\n' > "$app_root/app/Config/ReportDeliveryRuntimeConfig.php"
printf 'index\n' > "$app_root/public/index.php"
printf 'worker\n' > "$app_root/bin/report_delivery_worker.php"
printf '{}\n' > "$app_root/composer.json"
printf '{}\n' > "$app_root/composer.lock"
cat > "$app_root/vendor/composer/installed.php" <<PHP
<?php
return array(
    'root' => array(
        'name' => 'voxel/bi',
        'reference' => '$previous_sha',
    ),
    'versions' => array(
    ),
);
PHP
printf 'autoload\n' > "$app_root/vendor/autoload.php"
find "$app_root" -path "$app_root/storage" -prune -o -path "$app_root/.env" -prune -o -type f -exec chmod 600 {} +
# A transação é criada depois dos arquivos do runtime, portanto o mtime do runtime é anterior.
sleep 1

manifest="$backup_root/$previous_sha/manifest.tsv"
{
  printf 'META\tmechanism_version\t1\n'
  printf 'META\tsource_sha\t%s\n' "$previous_sha"
  printf 'META\tmtime_encoding\tPOSIX_EPOCH_UTC\n'
  printf 'META\tarchive\trelease.tar.gz\n'
  printf 'EXCLUDED\t.env\tcontent_not_backed_up\n'
  printf 'EXCLUDED\tstorage\tpersistent_path_not_backed_up\n'
  while IFS= read -r relative; do
    printf 'FILE\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
      "$relative" \
      "$(stat -c '%s' "$app_root/$relative")" \
      "$(sha256sum "$app_root/$relative" | awk '{print $1}')" \
      "$(stat -c '%a' "$app_root/$relative")" \
      "$(stat -c '%u' "$app_root/$relative")" \
      "$(stat -c '%g' "$app_root/$relative")" \
      "$(stat -c '%Y' "$app_root/$relative")"
  done < <(find -P "$app_root" -xdev -path "$app_root/.env" -prune -o -path "$app_root/storage" -prune -o -type f -printf '%P\n' | sort)
} > "$manifest"
tar -czf "$backup_root/$previous_sha/release.tar.gz" -C "$app_root" app public bin vendor composer.json composer.lock
sha256sum "$backup_root/$previous_sha/release.tar.gz" > "$backup_root/$previous_sha/release.sha256"
printf '%s\n' "$previous_sha" > "$backup_root/$previous_sha/source-sha"
printf 'metadata\n' > "$backup_root/$previous_sha/metadata"
printf 'created\n' > "$backup_root/$previous_sha/status"
sudo -n chown root:root "$backup_root/$previous_sha"/*
sudo -n chmod 600 "$backup_root/$previous_sha"/*

cp "$root/ops/deploy/voxelpacs-deploy-transaction" "$helper"
python3 - "$helper" "$app_root" "$release_root" "$backup_root" "$lock_path" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
text = path.read_text()
replacements = {
    "readonly APP_ROOT='/var/www/voxelpacs/app'": f"readonly APP_ROOT={sys.argv[2]!r}",
    "readonly RELEASE_ROOT='/var/lib/voxelpacs/deploy/releases'": f"readonly RELEASE_ROOT={sys.argv[3]!r}",
    "readonly BACKUP_ROOT='/var/backups/voxelpacs/releases'": f"readonly BACKUP_ROOT={sys.argv[4]!r}",
    "readonly LOCK_PATH='/run/lock/voxelpacs-deploy-runtime.lock'": f"readonly LOCK_PATH={sys.argv[5]!r}",
}
for old, new in replacements.items():
    if old not in text:
        raise SystemExit(f'missing replacement marker: {old}')
    text = text.replace(old, new)
path.write_text(text)
PY
sudo -n chown root:root "$helper"
sudo -n chmod 700 "$helper"

run_tx() {
  sudo -n env SUDO_USER=manus-admin "$helper" "$@"
}
assert_blocked() {
  local expected="$1"; shift
  local output
  if output="$(run_tx "$@" 2>&1)"; then
    printf 'expected block=%s\n%s\n' "$expected" "$output" >&2
    exit 1
  fi
  grep -Fxq "REASON=$expected" <<<"$output" || {
    printf 'wrong block=%s\n%s\n' "$expected" "$output" >&2
    exit 1
  }
}

make_tx() {
  local id="$1" status="${2:-UNKNOWN}"
  mkdir -p "$transaction_root/$id/validated"
  chmod 700 "$transaction_root/$id" "$transaction_root/$id/validated"
  printf 'target_sha=%s\ncreated_at=synthetic\n' "$id" > "$transaction_root/$id/metadata"
  chmod 600 "$transaction_root/$id/metadata"
  printf 'synthetic-stage\n' > "$transaction_root/$id/validated/runtime.txt"
  chmod 600 "$transaction_root/$id/validated/runtime.txt"
  if [[ "$status" != 'UNKNOWN' ]]; then
    printf '%s\n' "$status" > "$transaction_root/$id/status"
    chmod 600 "$transaction_root/$id/status"
  fi
}

# 1. validated/UNKNOWN: aborta, registra evidência e preserva runtime.
make_tx "$target_sha"
output="$(run_tx abort-unpublished --sha "$target_sha")"
grep -Fxq 'ABORT_UNPUBLISHED=YES' <<<"$output"
grep -Fxq 'TRANSACTION_STATUS_AFTER=aborted_unpublished' <<<"$output"
grep -Fxq 'RUNTIME_CHANGED=NO' <<<"$output"
grep -Fxq 'AUDIT_PRESERVED=YES' <<<"$output"
grep -Fxq 'aborted_unpublished' "$transaction_root/$target_sha/status"
test -f "$transaction_root/$target_sha/abort-unpublished"/*/audit.tsv
test -f "$transaction_root/$target_sha/abort-unpublished"/*/audit.sha256
inspect="$(run_tx inspect --sha "$target_sha")"
grep -Fxq 'TRANSACTION_STATUS=aborted_unpublished' <<<"$inspect"
grep -Fxq 'CLASSIFICATION=ABORTED' <<<"$inspect"

# 2. published: nunca pode ser encerrada pelo modo de abort.
published_sha='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
make_tx "$published_sha" published
assert_blocked 'TRANSACTION_ALREADY_PUBLISHED_OR_PUBLISHING' abort-unpublished --sha "$published_sha"
grep -Fxq 'published' "$transaction_root/$published_sha/status"

# 3. drift no runtime: recusa e não grava status.
drift_sha='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
make_tx "$drift_sha"
printf 'runtime-drift-after-transaction\n' >> "$app_root/public/index.php"
assert_blocked 'RUNTIME_DRIFT_DETECTED' abort-unpublished --sha "$drift_sha"
test ! -e "$transaction_root/$drift_sha/status"

# 4. lock ativo: recusa e não grava status.
lock_sha='cccccccccccccccccccccccccccccccccccccccc'
make_tx "$lock_sha"
(
  exec 9>"$lock_path"
  flock -n 9
  sleep 5
) &
lock_pid=$!
sleep 1
assert_blocked 'DEPLOY_LOCK_ACTIVE_OR_UNKNOWN' abort-unpublished --sha "$lock_sha"
kill "$lock_pid" 2>/dev/null || true
wait "$lock_pid" 2>/dev/null || true
test ! -e "$transaction_root/$lock_sha/status"

echo 'deploy_transaction_abort_unpublished_isolated: PASS'
