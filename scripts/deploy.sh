#!/usr/bin/env bash
# VOXEL PACS — publicação do artefato runtime validado.
#
# O executor sem privilégio não escreve APP_ROOT. Ele constrói o artefato,
# envia somente quatro arquivos allowlisted à entrada fixa e solicita o helper
# root-owned instalado pelo procedimento operacional. Não faz backup, drift,
# migration, reload, restart, Worker, Bridge, SMB ou transmissão.
set -Eeuo pipefail

readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"
readonly REMOTE_USER="${DEPLOY_USER:-manus-admin}"
readonly REMOTE_HOST="${DEPLOY_HOST:-}"
readonly HEALTH_URL="${DEPLOY_HEALTH_URL:-https://${REMOTE_HOST}/health}"
readonly CONNECT_TIMEOUT="${DEPLOY_CONNECT_TIMEOUT:-8}"
readonly EXPECTED_SHA="${DEPLOY_EXPECTED_SHA:-}"
readonly REMOTE_RUNTIME_ROOT='/var/www/voxelpacs/app'
readonly REMOTE_INCOMING_ROOT='/var/lib/voxelpacs/deploy/incoming'
readonly REMOTE_HELPER='/usr/local/sbin/voxelpacs-deploy-runtime'
readonly REMOTE_HELPER_SOURCE='ops/deploy/voxelpacs-deploy-runtime'

fail() {
  printf 'DEPLOY=BLOCKED\nREASON=%s\n' "$1" >&2
  exit 64
}

case "$REMOTE_USER" in
  manus-admin|manus-deploy) ;;
  *) fail 'DEPLOY_USER_NOT_ALLOWLISTED' ;;
esac

[[ -n "$REMOTE_HOST" ]] || fail 'DEPLOY_HOST_REQUIRED'
[[ "$REMOTE_RUNTIME_ROOT" != '/var/www/voxelpacs' ]] || fail 'PARENT_ROOT_IS_NOT_RUNTIME_ROOT'
[[ "$REMOTE_RUNTIME_ROOT" == */app ]] || fail 'REMOTE_RUNTIME_ROOT_SUFFIX_INVALID'
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

# O helper é provisionado separadamente por root. A verificação não imprime
# sudoers, caminhos privados adicionais, ambiente ou conteúdo de configuração.
if ! ssh "${ssh_opts[@]}" "$REMOTE_USER@$REMOTE_HOST" bash -s -- "$REMOTE_HELPER" "$EXPECTED_SHA" <<'REMOTE_CHECK'
set -Eeuo pipefail
helper="$1"
sha="$2"
[[ -x "$helper" ]]
[[ "$sha" =~ ^[0-9a-f]{40}$ ]]
sudo -n -l -- "$helper" --sha "$sha" >/dev/null 2>&1
REMOTE_CHECK
then
  fail 'PRIVILEGED_HELPER_UNAVAILABLE'
fi

# A verificação acima não lê nem escreve artefatos. A autorização real ocorre
# abaixo com o SHA exato da main.
tmp_dir="$(mktemp -d "${TMPDIR:-/tmp}/voxelpacs-deploy.XXXXXX")"
cleanup() {
  rm -rf -- "$tmp_dir"
}
trap cleanup EXIT

artifact="$tmp_dir/runtime.zip"
manifest="$tmp_dir/runtime.manifest.tsv"
builder_checksum="$tmp_dir/runtime.builder.sha256"
checksum="$tmp_dir/runtime.sha256"
source_sha="$tmp_dir/runtime.source-sha"

bash "$ROOT/scripts/build-runtime-artifact.sh" \
  --output "$artifact" \
  --manifest "$manifest" \
  --checksum "$builder_checksum" \
  --expected-sha "$EXPECTED_SHA"

artifact_hash="$(sha256sum "$artifact" | awk '{print $1}')"
builder_hash="$(awk '{print $1}' "$builder_checksum")"
[[ "$artifact_hash" == "$builder_hash" ]] || fail 'LOCAL_ARTIFACT_CHECKSUM_RECONCILIATION_FAILED'
printf '%s\n' "$artifact_hash" > "$checksum"
printf '%s\n' "$EXPECTED_SHA" > "$source_sha"
chmod 600 "$checksum" "$source_sha"

archive_name="voxelpacs-runtime-${EXPECTED_SHA}.zip"
remote_archive="$REMOTE_INCOMING_ROOT/$archive_name"
remote_manifest="$REMOTE_INCOMING_ROOT/voxelpacs-runtime-${EXPECTED_SHA}.manifest.tsv"
remote_checksum="$REMOTE_INCOMING_ROOT/voxelpacs-runtime-${EXPECTED_SHA}.sha256"
remote_source_sha="$REMOTE_INCOMING_ROOT/voxelpacs-runtime-${EXPECTED_SHA}.source-sha"

scp "${ssh_opts[@]}" \
  "$artifact" "$manifest" "$checksum" "$source_sha" \
  "$REMOTE_USER@$REMOTE_HOST:$REMOTE_INCOMING_ROOT/"

ssh "${ssh_opts[@]}" "$REMOTE_USER@$REMOTE_HOST" bash -s -- \
  "$REMOTE_HELPER" "$EXPECTED_SHA" "$REMOTE_INCOMING_ROOT" <<'REMOTE_PUBLISH'
set -Eeuo pipefail
helper="$1"
sha="$2"
incoming="$3"
[[ "$incoming" == '/var/lib/voxelpacs/deploy/incoming' ]] || {
  printf 'REMOTE_DEPLOY=BLOCKED\nREASON=INCOMING_ROOT_INVALID\n' >&2
  exit 65
}
sudo -n -- "$helper" --sha "$sha"
REMOTE_PUBLISH

if curl --fail --silent --show-error --max-time 15 "$HEALTH_URL" >/dev/null; then
  printf 'HEALTH=PASS\n'
else
  printf 'HEALTH=NOT_CONCLUSIVE\n' >&2
  exit 66
fi

printf 'DEPLOY=PASS\n'
printf 'REMOTE_HOST=%s\n' "$REMOTE_HOST"
printf 'RUNTIME_ROOT=APP_ROOT\n'
printf 'REMOTE_ROOT=APP_ROOT\n'
printf 'ARTIFACT_SHA256_PREFIX=%s…\n' "${artifact_hash:0:12}"
printf 'SOURCE_SHA_PREFIX=%s…\n' "${EXPECTED_SHA:0:12}"
printf 'PUBLICATION_MODE=PRIVILEGED_ALLOWLISTED_HELPER\n'
printf 'ENV_PRESERVED=YES\n'
printf 'STORAGE_PRESERVED=YES\n'
printf 'UPLOADS_PRESERVED=YES\n'
printf 'BACKUP_GATE=VERIFIED_BY_PREDEPLOY_WORKFLOW\n'
printf 'DRIFT_GATE=VERIFIED_BY_PREDEPLOY_WORKFLOW\n'
printf 'MIGRATION=NO\n'
printf 'WORKER_STARTED=NO\n'
printf 'BRIDGE_RESTART=NO\n'
printf 'SMB=NO\n'
printf 'TRANSMISSION=NO\n'
printf 'REMOTE_HELPER=%s\n' "$REMOTE_HELPER_SOURCE"
printf 'DATABASE_CHANGED=NO\n'
