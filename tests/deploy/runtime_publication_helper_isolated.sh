#!/usr/bin/env bash
# Teste local sintético: não usa SSH, produção, banco, Worker, Bridge ou SMB.
set -Eeuo pipefail

root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
test_root="$(mktemp -d "${TMPDIR:-/tmp}/voxelpacs-helper-test.XXXXXX")"
cleanup() {
  case "$test_root" in
    /tmp/voxelpacs-helper-test.*) sudo -n rm -rf -- "$test_root" ;;
    *) printf 'unsafe_test_cleanup_path\n' >&2; exit 1 ;;
  esac
}
trap cleanup EXIT

app_root="$test_root/app"
incoming="$test_root/incoming"
release="$test_root/releases"
transaction_root="$release/transactions"
lock_path="$test_root/deploy.lock"
helper="$test_root/helper"
mkdir -p "$app_root/Config" "$app_root/app/Config" "$app_root/app/Existing" "$app_root/public/assets" \
  "$app_root/bin" "$app_root/vendor/dompdf/dompdf/src" "$incoming" "$transaction_root"
chmod 700 "$test_root" "$incoming" "$release" "$transaction_root"

printf 'synthetic-env\n' > "$app_root/.env"
mkdir -p "$app_root/storage"
printf 'legacy-flat\n' > "$app_root/Config/ReportDeliveryRuntimeConfig.php"
printf 'old-controller\n' > "$app_root/app/Existing/controller.php"
printf 'old-css\n' > "$app_root/public/assets/test.css"
chmod 600 "$app_root/.env" "$app_root/Config/ReportDeliveryRuntimeConfig.php" \
  "$app_root/app/Existing/controller.php" "$app_root/public/assets/test.css"

cp "$root/ops/deploy/voxelpacs-deploy-runtime" "$helper"
python3 - "$helper" "$app_root" "$incoming" "$release" "$lock_path" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
text = path.read_text()
replacements = {
    "readonly APP_ROOT='/var/www/voxelpacs/app'": f"readonly APP_ROOT={sys.argv[2]!r}",
    "readonly INCOMING_ROOT='/var/lib/voxelpacs/deploy/incoming'": f"readonly INCOMING_ROOT={sys.argv[3]!r}",
    "readonly RELEASE_ROOT='/var/lib/voxelpacs/deploy/releases'": f"readonly RELEASE_ROOT={sys.argv[4]!r}",
    "readonly LOCK_PATH='/run/lock/voxelpacs-deploy-runtime.lock'": f"readonly LOCK_PATH={sys.argv[5]!r}",
}
for old, new in replacements.items():
    if old not in text:
        raise SystemExit(f'missing replacement marker: {old}')
    text = text.replace(old, new)
path.write_text(text)
PY
chmod 700 "$helper"

sha='0123456789abcdef0123456789abcdef01234567'
source="$test_root/source"
mkdir -p "$source/app/Config" "$source/app/Existing" "$source/public/assets" \
  "$source/bin" "$source/vendor/dompdf/dompdf/src"
printf 'new-bootstrap\n' > "$source/app/bootstrap.php"
printf 'new-autoload\n' > "$source/app/autoload.php"
printf 'new-runtime-config\n' > "$source/app/Config/ReportDeliveryRuntimeConfig.php"
printf 'new-existing\n' > "$source/app/Existing/controller.php"
printf 'new-index\n' > "$source/public/index.php"
printf 'new-css\n' > "$source/public/assets/test.css"
printf 'new-worker\n' > "$source/bin/report_delivery_worker.php"
printf '{}\n' > "$source/composer.json"
printf '{}\n' > "$source/composer.lock"
printf 'new-vendor\n' > "$source/vendor/autoload.php"
printf 'new-dompdf\n' > "$source/vendor/dompdf/dompdf/src/Dompdf.php"

archive="$incoming/voxelpacs-runtime-${sha}.zip"
manifest="$incoming/voxelpacs-runtime-${sha}.manifest.tsv"
checksum="$incoming/voxelpacs-runtime-${sha}.sha256"
source_sha="$incoming/voxelpacs-runtime-${sha}.source-sha"
(
  cd "$source"
  find . -type f -printf '%P\n' | sort | while IFS= read -r relative; do
    printf '%s\t%s\n' "$relative" "$(sha256sum "$relative" | awk '{print $1}')"
  done > "$manifest"
  zip -q -r "$archive" .
)
printf '%s\n' "$(sha256sum "$archive" | awk '{print $1}')" > "$checksum"
printf '%s\n' "$sha" > "$source_sha"
chmod 600 "$archive" "$manifest" "$checksum" "$source_sha"

run_helper() {
  sudo -n env SUDO_USER=manus-admin "$helper" "$@"
}

output="$(run_helper --sha "$sha")"
grep -Fxq 'PRIVILEGED_DEPLOY=PASS' <<<"$output"
grep -Fxq 'ENV_PRESERVED=YES' <<<"$output"
grep -Fxq 'STORAGE_PRESERVED=YES' <<<"$output"
grep -Fxq 'LEGACY_FLAT_PRESERVED=YES' <<<"$output"
grep -Fxq 'DATABASE_CHANGED=NO' <<<"$output"

test -f "$app_root/app/Config/ReportDeliveryRuntimeConfig.php"
grep -Fxq 'new-runtime-config' "$app_root/app/Config/ReportDeliveryRuntimeConfig.php"
grep -Fxq 'legacy-flat' "$app_root/Config/ReportDeliveryRuntimeConfig.php"
grep -Fxq 'synthetic-env' "$app_root/.env"
test -d "$app_root/storage"
for file in "$archive" "$manifest" "$checksum" "$source_sha"; do
  test ! -e "$file"
done

rollback_output="$(run_helper --rollback --sha "$sha")"
grep -Fxq 'PRIVILEGED_DEPLOY=ROLLBACK_PASS' <<<"$rollback_output"
test ! -e "$app_root/app/Config/ReportDeliveryRuntimeConfig.php"
grep -Fxq 'legacy-flat' "$app_root/Config/ReportDeliveryRuntimeConfig.php"
grep -Fxq 'synthetic-env' "$app_root/.env"
test -d "$app_root/storage"

echo 'runtime_publication_helper_isolated: PASS'
