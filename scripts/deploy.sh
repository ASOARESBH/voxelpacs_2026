#!/usr/bin/env bash
# VOXEL PACS — publicação do artefato runtime validado.
#
# Este script não faz backup, drift check, migration, reload, restart, Worker
# ou transmissão. Essas são gates operacionais separados do workflow de deploy.
set -Eeuo pipefail

readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"
readonly REMOTE_USER="${DEPLOY_USER:-manus-admin}"
readonly REMOTE_HOST="${DEPLOY_HOST:-}"
readonly REMOTE_PATH="${DEPLOY_PATH:-/var/www/voxelpacs/app}"
readonly HEALTH_URL="${DEPLOY_HEALTH_URL:-https://${REMOTE_HOST}/health}"
readonly CONNECT_TIMEOUT="${DEPLOY_CONNECT_TIMEOUT:-8}"
readonly EXPECTED_SHA="${DEPLOY_EXPECTED_SHA:-}"

fail() {
  printf 'DEPLOY=BLOCKED\nREASON=%s\n' "$1" >&2
  exit 64
}

[[ -n "$REMOTE_HOST" ]] || fail 'DEPLOY_HOST_REQUIRED'
[[ "$REMOTE_PATH" != '/var/www/voxelpacs' ]] || fail 'PARENT_ROOT_IS_NOT_RUNTIME_ROOT'
[[ "$REMOTE_PATH" == */app ]] || fail 'DEPLOY_PATH_MUST_END_IN_APP'
[[ "$CONNECT_TIMEOUT" =~ ^[0-9]+$ ]] || fail 'CONNECT_TIMEOUT_INVALID'
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || fail 'DEPLOY_EXPECTED_SHA_REQUIRED'
[[ "${DEPLOY_BACKUP_VERIFIED:-NO}" == 'YES' ]] || fail 'BACKUP_GATE_NOT_VERIFIED'
[[ "${DEPLOY_DRIFT_VERIFIED:-NO}" == 'YES' ]] || fail 'DRIFT_GATE_NOT_VERIFIED'
[[ "${DEPLOY_AUTHORIZED:-NO}" == 'YES' ]] || fail 'DEPLOY_AUTHORIZATION_REQUIRED'

ssh_opts=(-o BatchMode=yes -o StrictHostKeyChecking=yes -o IdentitiesOnly=yes -o "ConnectTimeout=${CONNECT_TIMEOUT}")
if [[ -n "${DEPLOY_IDENTITY_FILE:-}" ]]; then
  [[ -f "$DEPLOY_IDENTITY_FILE" ]] || fail 'DEPLOY_IDENTITY_FILE_NOT_FOUND'
  ssh_opts+=(-i "$DEPLOY_IDENTITY_FILE")
fi

tmp_dir="$(mktemp -d "${TMPDIR:-/tmp}/voxelpacs-deploy.XXXXXX")"
cleanup() {
  rm -rf -- "$tmp_dir"
}
trap cleanup EXIT

artifact="$tmp_dir/runtime.zip"
manifest="$tmp_dir/runtime.manifest.tsv"
checksum="$tmp_dir/runtime.sha256"
expected_sha_args=()
expected_sha_args=(--expected-sha "$EXPECTED_SHA")

bash "$ROOT/scripts/build-runtime-artifact.sh" \
  --output "$artifact" \
  --manifest "$manifest" \
  --checksum "$checksum" \
  "${expected_sha_args[@]}"

archive_name="voxelpacs-runtime-$(date +%s).zip"
remote_archive="/tmp/$archive_name"

scp "${ssh_opts[@]}" "$artifact" "$REMOTE_USER@$REMOTE_HOST:$remote_archive"

ssh "${ssh_opts[@]}" "$REMOTE_USER@$REMOTE_HOST" bash -s -- "$REMOTE_PATH" "$remote_archive" <<'REMOTE'
set -Eeuo pipefail
runtime_root="$1"
archive="$2"

[[ -d "$runtime_root" ]] || { printf 'REMOTE_DEPLOY=BLOCKED\nREASON=RUNTIME_ROOT_NOT_FOUND\n' >&2; exit 65; }
[[ "$runtime_root" != '/var/www/voxelpacs' ]] || { printf 'REMOTE_DEPLOY=BLOCKED\nREASON=PARENT_ROOT_IS_NOT_RUNTIME_ROOT\n' >&2; exit 65; }
[[ "$runtime_root" == */app ]] || { printf 'REMOTE_DEPLOY=BLOCKED\nREASON=RUNTIME_ROOT_SUFFIX_INVALID\n' >&2; exit 65; }
[[ -f "$archive" ]] || { printf 'REMOTE_DEPLOY=BLOCKED\nREASON=ARTIFACT_NOT_FOUND\n' >&2; exit 65; }

unzip -t "$archive" >/dev/null
unzip -o "$archive" -d "$runtime_root" >/dev/null

test -f "$runtime_root/app/Config/ReportDeliveryRuntimeConfig.php"
test -f "$runtime_root/public/index.php"
test ! -e "$runtime_root/Config/ReportDeliveryRuntimeConfig.php"
rm -f -- "$archive"

printf 'REMOTE_DEPLOY=PASS\n'
printf 'RUNTIME_ROOT=APP_ROOT\n'
printf 'ENV_PRESERVED=YES\n'
printf 'STORAGE_PRESERVED=YES\n'
printf 'UPLOADS_PRESERVED=YES\n'
printf 'COMPOSER_REMOTE_EXECUTED=NO\n'
printf 'RECURSIVE_CHMOD_EXECUTED=NO\n'
REMOTE

if curl --fail --silent --show-error --max-time 15 "$HEALTH_URL" >/dev/null; then
  printf 'HEALTH=PASS\n'
else
  printf 'HEALTH=NOT_CONCLUSIVE\n' >&2
  exit 66
fi

printf 'DEPLOY=PASS\n'
printf 'REMOTE_HOST=%s\n' "$REMOTE_HOST"
printf 'REMOTE_ROOT=APP_ROOT\n'
printf 'ARTIFACT_SHA256_PREFIX=%s…\n' "$(cut -c1-12 "$checksum")"
printf 'BACKUP_GATE=VERIFIED_BY_PREDEPLOY_WORKFLOW\n'
printf 'DRIFT_GATE=VERIFIED_BY_PREDEPLOY_WORKFLOW\n'
printf 'MIGRATION=NO\n'
printf 'WORKER_STARTED=NO\n'
printf 'TRANSMISSION=NO\n'
