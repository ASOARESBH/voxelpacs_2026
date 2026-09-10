#!/usr/bin/env bash
# Habilita exclusivamente as feature flags temporárias da Fase 1 Philips Non-DICOM.
# Não inicia bridge, SMB, worker, outbox, entrega, XML ou automação clínica.
set -euo pipefail

if [[ "${EUID}" -ne 0 || "$#" -ne 0 ]]; then
  printf 'Uso permitido: executar localmente como root e sem argumentos.\n' >&2
  exit 77
fi

readonly APP_ROOT=/var/www/voxelpacs/app
readonly ENV_FILE="$APP_ROOT/.env"
readonly BACKUP_ROOT=/var/backups/voxelpacs/config
readonly PHP_FPM_SERVICE=php8.3-fpm
readonly FLAG_DELIVERY=PHILIPS_NON_DICOM_DELIVERY_ENABLED
readonly FLAG_SMB_TEST=PHILIPS_NON_DICOM_SMB_TEST_ENABLED

test -d "$APP_ROOT"
test -s "$ENV_FILE"
systemctl cat "$PHP_FPM_SERVICE" >/dev/null

env_value() {
  local key="$1"
  awk -v key="$key" '
    $0 ~ "^[[:space:]]*" key "[[:space:]]*=" {
      value = $0
      sub("^[[:space:]]*" key "[[:space:]]*=[[:space:]]*", "", value)
      sub("[[:space:]]+$", "", value)
      if ((substr(value, 1, 1) == "\"" && substr(value, length(value), 1) == "\"") || (substr(value, 1, 1) == "\047" && substr(value, length(value), 1) == "\047")) value = substr(value, 2, length(value) - 2)
    }
    END { print value }
  ' "$ENV_FILE"
}

readonly owner_uid="$(stat -c '%u' "$ENV_FILE")"
readonly owner_gid="$(stat -c '%g' "$ENV_FILE")"
readonly file_mode="$(stat -c '%a' "$ENV_FILE")"
readonly stamp="$(date -u +%Y%m%dT%H%M%SZ)"
readonly backup_dir="$BACKUP_ROOT/philips-non-dicom-flags-$stamp"
install -d -o root -g root -m 0700 "$backup_dir"
cp -p "$ENV_FILE" "$backup_dir/.env.before"

temp_env="$(mktemp "${ENV_FILE}.philips-nondicom.XXXXXX")"
changed=0
cleanup() {
  rm -f "$temp_env"
}
trap cleanup EXIT

rollback() {
  local exit_code="$?"
  if [[ "$changed" -eq 1 ]]; then
    cp -p "$backup_dir/.env.before" "$ENV_FILE"
    systemctl reload "$PHP_FPM_SERVICE" >/dev/null 2>&1 || true
  fi
  exit "$exit_code"
}
trap rollback ERR

awk -v delivery="$FLAG_DELIVERY" -v smb_test="$FLAG_SMB_TEST" '
  BEGIN { delivery_seen = 0; smb_test_seen = 0 }
  $0 ~ "^[[:space:]]*" delivery "[[:space:]]*=" {
    print delivery "=true"
    delivery_seen = 1
    next
  }
  $0 ~ "^[[:space:]]*" smb_test "[[:space:]]*=" {
    print smb_test "=true"
    smb_test_seen = 1
    next
  }
  { print }
  END {
    if (!delivery_seen) print delivery "=true"
    if (!smb_test_seen) print smb_test "=true"
  }
' "$ENV_FILE" > "$temp_env"

chown "$owner_uid:$owner_gid" "$temp_env"
chmod "$file_mode" "$temp_env"
mv -f "$temp_env" "$ENV_FILE"
changed=1

test "$(env_value "$FLAG_DELIVERY")" = 'true'
test "$(env_value "$FLAG_SMB_TEST")" = 'true'

systemctl reload "$PHP_FPM_SERVICE"
systemctl is-active --quiet "$PHP_FPM_SERVICE"

trap - ERR
printf '%s\n' 'PHILIPS_NON_DICOM_TEST_FLAGS_OK'
