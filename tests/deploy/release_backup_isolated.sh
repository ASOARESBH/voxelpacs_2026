#!/usr/bin/env bash
# Teste local sintético: não usa SSH, produção, banco, Worker, Bridge, SMB ou transmissão.
set -Eeuo pipefail

root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
test_root="$(mktemp -d "${TMPDIR:-/tmp}/voxelpacs-release-backup-test.XXXXXX")"
cleanup() {
  if [[ -d "$test_root" ]]; then
    sudo -n rm -rf -- "$test_root"
  fi
}
trap cleanup EXIT

app_root="$test_root/app"
backup_root="$test_root/backups/releases"
restore_root="$test_root/backups/restore-tests"
lock_path="$test_root/backup.lock"
helper="$test_root/helper"
secret='synthetic-secret-must-not-leak'
sha_a='0123456789abcdef0123456789abcdef01234567'
sha_b='abcdefabcdefabcdefabcdefabcdefabcdefabcd'
sha_c='1111111111111111111111111111111111111111'
sha_partial='2222222222222222222222222222222222222222'

mkdir -p \
  "$app_root/app/Config" "$app_root/app/Existing" \
  "$app_root/public/assets" "$app_root/public/uploads" \
  "$app_root/bin" "$app_root/routes" "$app_root/vendor" \
  "$app_root/Config" "$app_root/Controllers" \
  "$app_root/storage/uploads" "$app_root/storage/report_delivery" \
  "$app_root/storage/logs" "$app_root/storage/backups" \
  "$backup_root" "$restore_root"
sudo -n install -d -o root -g root -m 0700 -- "$backup_root" "$restore_root"
printf 'APP_SECRET=%s\n' "$secret" > "$app_root/.env"
printf 'runtime-config\n' > "$app_root/app/Config/ReportDeliveryRuntimeConfig.php"
printf 'legacy-flat-config\n' > "$app_root/Config/ReportDeliveryRuntimeConfig.php"
printf 'controller\n' > "$app_root/app/Existing/controller.php"
printf 'public-asset\n' > "$app_root/public/assets/app.css"
printf 'worker\n' > "$app_root/bin/report_delivery_worker.php"
printf 'route\n' > "$app_root/routes/platform.php"
printf 'vendor\n' > "$app_root/vendor/autoload.php"
printf 'must-not-be-backed-up\n' > "$app_root/public/uploads/clinical-upload-placeholder"
chmod 600 "$app_root/.env" "$app_root/app/Config/ReportDeliveryRuntimeConfig.php" \
  "$app_root/Config/ReportDeliveryRuntimeConfig.php" "$app_root/app/Existing/controller.php" \
  "$app_root/public/assets/app.css" "$app_root/bin/report_delivery_worker.php" \
  "$app_root/routes/platform.php" "$app_root/vendor/autoload.php"
find "$app_root" -path "$app_root/storage" -prune -o -type f -exec touch -d '@1700000000' {} +
find "$app_root" -type d -exec touch -d '@1700000000' {} +

cp -- "$root/scripts/release_backup.sh" "$helper"
python3 - "$helper" "$app_root" "$backup_root" "$restore_root" "$lock_path" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
text = path.read_text()
replacements = {
    "readonly APP_ROOT='/var/www/voxelpacs/app'": f"readonly APP_ROOT={sys.argv[2]!r}",
    "readonly BACKUP_ROOT='/var/backups/voxelpacs/releases'": f"readonly BACKUP_ROOT={sys.argv[3]!r}",
    "readonly RESTORE_TEST_ROOT='/var/backups/voxelpacs/restore-tests'": f"readonly RESTORE_TEST_ROOT={sys.argv[4]!r}",
    "readonly LOCK_PATH='/run/lock/voxelpacs-release-backup.lock'": f"readonly LOCK_PATH={sys.argv[5]!r}",
}
for old, new in replacements.items():
    if old not in text:
        raise SystemExit(f'missing replacement marker: {old}')
    text = text.replace(old, new)
path.write_text(text)
PY
chmod 700 "$helper"

run_helper() {
  sudo -n env SUDO_USER=manus-admin "$helper" "$@"
}

assert_blocked() {
  local reason="$1"
  shift
  local output
  if output="$(run_helper "$@" 2>&1)"; then
    printf 'expected_block=%s\n%s\n' "$reason" "$output" >&2
    exit 1
  fi
  grep -Fxq "REASON=$reason" <<<"$output" || {
    printf 'wrong_block=%s\n%s\n' "$reason" "$output" >&2
    exit 1
  }
}

# 1–7: SHA, argumentos, traversal, path arbitrário, injection e shell.
assert_blocked 'SHA_INVALID' create --sha not-a-sha
assert_blocked 'SHA_INVALID' create --sha '../escape'
assert_blocked 'ARGUMENTS_INVALID' --shell
assert_blocked 'ARGUMENTS_INVALID' create --sha "$sha_a" --source /tmp
assert_blocked 'SHA_INVALID' create --sha "${sha_a};id"
assert_blocked 'ARGUMENTS_INVALID' exec --sha "$sha_a"

# 8–10, 14–15: criação, determinismo, manifest, checksum e exclusão de persistência/segredo.
create_output="$(run_helper create --sha "$sha_a")"
grep -Fxq 'BACKUP=PASS' <<<"$create_output"
grep -Fxq 'PRODUCTION_CHANGED=NO' <<<"$create_output"
archive="$backup_root/$sha_a/release.tar.gz"
manifest="$backup_root/$sha_a/manifest.tsv"
metadata="$backup_root/$sha_a/metadata"
archive_before="$(sudo -n sha256sum "$archive" | awk '{print $1}')"
second_output="$(run_helper create --sha "$sha_a")"
grep -Fxq 'BACKUP=ALREADY_VALID' <<<"$second_output"
archive_after="$(sudo -n sha256sum "$archive" | awk '{print $1}')"
test "$archive_before" = "$archive_after"
backup_root_2="$test_root/backups-2/releases"
restore_root_2="$test_root/backups-2/restore-tests"
lock_path_2="$test_root/backup-2.lock"
helper_2="$test_root/helper-2"
sudo -n install -d -o root -g root -m 0700 -- "$backup_root_2" "$restore_root_2"
cp -- "$root/scripts/release_backup.sh" "$helper_2"
python3 - "$helper_2" "$app_root" "$backup_root_2" "$restore_root_2" "$lock_path_2" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
text = path.read_text()
replacements = {
    "readonly APP_ROOT='/var/www/voxelpacs/app'": f"readonly APP_ROOT={sys.argv[2]!r}",
    "readonly BACKUP_ROOT='/var/backups/voxelpacs/releases'": f"readonly BACKUP_ROOT={sys.argv[3]!r}",
    "readonly RESTORE_TEST_ROOT='/var/backups/voxelpacs/restore-tests'": f"readonly RESTORE_TEST_ROOT={sys.argv[4]!r}",
    "readonly LOCK_PATH='/run/lock/voxelpacs-release-backup.lock'": f"readonly LOCK_PATH={sys.argv[5]!r}",
}
for old, new in replacements.items():
    if old not in text:
        raise SystemExit(f'missing replacement marker: {old}')
    text = text.replace(old, new)
path.write_text(text)
PY
chmod 700 "$helper_2"
run_helper_2() { sudo -n env SUDO_USER=manus-admin "$helper_2" "$@"; }
run_helper_2 create --sha "$sha_a" >/dev/null
test "$archive_before" = "$(sudo -n sha256sum "$backup_root_2/$sha_a/release.tar.gz" | awk '{print $1}')"
sudo -n cmp "$manifest" "$backup_root_2/$sha_a/manifest.tsv"
sudo -n grep -Fq $'META\tsource_sha\t' "$manifest"
sudo -n grep -Fq $'EXCLUDED\t.env\t' "$manifest"
sudo -n grep -Fq $'EXCLUDED\tstorage\t' "$manifest"
sudo -n grep -Fq $'EXCLUDED\tpublic/uploads\t' "$manifest"
! sudo -n grep -Fq "$secret" "$manifest" "$metadata"
! sudo -n tar -tzf "$archive" | grep -Eq '(^|/)(\.env|storage|public/uploads)(/|$)'
validate_output="$(run_helper validate --sha "$sha_a")"
grep -Fxq 'BACKUP=PASS' <<<"$validate_output"
grep -Fxq 'CHECKSUM_VALIDATION=PASS' <<<"$validate_output"
grep -Fxq 'MANIFEST_VALIDATION=PASS' <<<"$validate_output"
! grep -Fq "$secret" <<<"$create_output$validate_output$second_output"

# 11: restore-test íntegro.
restore_output="$(run_helper restore-test --sha "$sha_a")"
grep -Fxq 'RESTORE_TEST=PASS' <<<"$restore_output"
sudo -n test -f "$restore_root/$sha_a/app/Config/ReportDeliveryRuntimeConfig.php"
sudo -n test ! -e "$restore_root/$sha_a/.env"
sudo -n test ! -e "$restore_root/$sha_a/storage"

# 12: alteração de hash no manifest é detectada no restore-test.
run_helper create --sha "$sha_b" >/dev/null
manifest_b="$backup_root/$sha_b/manifest.tsv"
sudo -n python3 - "$manifest_b" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
lines = path.read_text().splitlines()
for index, line in enumerate(lines):
    if line.startswith('FILE\t'):
        fields = line.split('\t')
        fields[3] = '0' * 64
        lines[index] = '\t'.join(fields)
        break
else:
    raise SystemExit('no FILE row')
path.write_text('\n'.join(lines) + '\n')
PY
assert_blocked 'RESTORE_HASH_MISMATCH' restore-test --sha "$sha_b"

# 13: remoção de entrada do manifest é detectada antes de qualquer restore produtivo.
run_helper create --sha "$sha_c" >/dev/null
manifest_c="$backup_root/$sha_c/manifest.tsv"
sudo -n python3 - "$manifest_c" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
lines = path.read_text().splitlines()
for index, line in enumerate(lines):
    if line.startswith('FILE\t'):
        del lines[index]
        break
else:
    raise SystemExit('no FILE row')
path.write_text('\n'.join(lines) + '\n')
PY
assert_blocked 'BACKUP_MANIFEST_ARCHIVE_MISMATCH' restore-test --sha "$sha_c"

# 16–18: mecanismo root-owned e contrato sudoers sem shell ou ALL genérico.
sudo -n chown root:root "$helper"
sudo -n chmod 0755 "$helper"
if runuser -u nobody -- /bin/sh -c "printf x >> '$helper'" 2>/dev/null; then
  echo 'non_root_modified_helper' >&2
  exit 1
fi
python3 - "$root/ops/sudoers/voxelpacs-release-backup" <<'PY'
from pathlib import Path
import re
import sys
text = Path(sys.argv[1]).read_text()
rules = [line for line in text.splitlines() if line.startswith('manus-admin ')]
assert len(rules) == 3
for rule in rules:
    match = re.search(r'--sha (\?+)$', rule)
    assert match and len(match.group(1)) == 40
assert 'NOPASSWD: ALL' not in text
assert '/bin/sh' not in text and '/bin/bash' not in text
print('SUDOERS_RULES=3')
print('SUDOERS_BOUNDED_SHA=40')
PY
visudo -cf "$root/ops/sudoers/voxelpacs-release-backup" >/dev/null

# 19: lock impede concorrência para o mesmo mecanismo.
sudo -n bash -c 'exec 9>"$1"; flock -n 9; sleep 2' _ "$lock_path" &
lock_holder=$!
sleep 0.1
assert_blocked 'BACKUP_LOCK_BUSY' validate --sha "$sha_a"
wait "$lock_holder"

# 20: backup parcial/corrompido nunca é aceito como válido.
sudo -n install -d -o root -g root -m 0700 -- "$backup_root/$sha_partial"
assert_blocked 'BACKUP_FILE_MISSING' validate --sha "$sha_partial"

# Nenhuma instalação, provisionamento, deploy, banco ou integração externa foi usada.
echo 'release_backup_isolated: PASS'
