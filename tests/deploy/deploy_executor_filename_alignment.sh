#!/usr/bin/env bash
# Teste sintético do contrato de nomenclatura do executor.
# Não usa SSH real, produção, banco, Worker, Bridge, SMB ou transmissão.
set -Eeuo pipefail

root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
test_root="$(mktemp -d "${TMPDIR:-/tmp}/voxelpacs-deploy-executor-test.XXXXXX")"
cleanup() {
  case "$test_root" in
    /tmp/voxelpacs-deploy-executor-test.*) rm -rf -- "$test_root" ;;
    *) printf 'unsafe_test_cleanup_path\n' >&2; exit 1 ;;
  esac
}
trap cleanup EXIT

sha='0123456789abcdef0123456789abcdef01234567'
mock_bin="$test_root/bin"
mock_root="$test_root/project"
log="$test_root/mock.log"
mkdir -p "$mock_bin" "$mock_root/scripts"
cp "$root/scripts/deploy.sh" "$mock_root/scripts/deploy.sh"

cat > "$mock_root/scripts/build-runtime-artifact.sh" <<'MOCK_BUILDER'
#!/usr/bin/env bash
set -Eeuo pipefail
output=''
manifest=''
checksum=''
expected=''
while (($#)); do
  case "$1" in
    --output) output="$2"; shift 2 ;;
    --manifest) manifest="$2"; shift 2 ;;
    --checksum) checksum="$2"; shift 2 ;;
    --expected-sha) expected="$2"; shift 2 ;;
    *) printf 'unexpected_builder_argument=%s\n' "$1" >&2; exit 1 ;;
  esac
done
[[ "$expected" =~ ^[0-9a-f]{40}$ ]]
printf 'synthetic-runtime-%s\n' "$expected" > "$output"
printf 'synthetic.txt\t%s\n' "$(sha256sum "$output" | awk '{print $1}')" > "$manifest"
printf '%s\n' "$(sha256sum "$output" | awk '{print $1}')" > "$checksum"
MOCK_BUILDER
chmod 700 "$mock_root/scripts/build-runtime-artifact.sh"

cat > "$mock_bin/ssh" <<'MOCK_SSH'
#!/usr/bin/env bash
set -Eeuo pipefail
cat >/dev/null
printf 'SSH_MOCK=PASS\n' >> "${VOXEL_MOCK_LOG:?}"
printf 'PRIVILEGED_DEPLOY=PASS\n'
MOCK_SSH

cat > "$mock_bin/scp" <<'MOCK_SCP'
#!/usr/bin/env bash
set -Eeuo pipefail
log="${VOXEL_MOCK_LOG:?}"
sha="${VOXEL_EXPECTED_SHA:?}"
found_zip=0
found_manifest=0
found_checksum=0
found_source=0
for arg in "$@"; do
  case "$arg" in
    *"voxelpacs-runtime-${sha}.zip") found_zip=$((found_zip + 1));;
    *"voxelpacs-runtime-${sha}.manifest.tsv") found_manifest=$((found_manifest + 1));;
    *"voxelpacs-runtime-${sha}.sha256") found_checksum=$((found_checksum + 1));;
    *"voxelpacs-runtime-${sha}.source-sha") found_source=$((found_source + 1));;
    *runtime.zip|*runtime.manifest.tsv|*runtime.sha256|*runtime.source-sha)
      printf 'generic_filename_sent\n' >&2; exit 1;;
  esac
done
[[ "$found_zip" -eq 1 ]]
[[ "$found_manifest" -eq 1 ]]
[[ "$found_checksum" -eq 1 ]]
[[ "$found_source" -eq 1 ]]
printf 'SCP_FILENAME_CONTRACT=PASS\n' >> "$log"
MOCK_SCP

cat > "$mock_bin/curl" <<'MOCK_CURL'
#!/usr/bin/env bash
set -Eeuo pipefail
printf 'CURL_MOCK=PASS\n' >> "${VOXEL_MOCK_LOG:?}"
MOCK_CURL
chmod 700 "$mock_bin/ssh" "$mock_bin/scp" "$mock_bin/curl"

export PATH="$mock_bin:$PATH"
export VOXEL_MOCK_LOG="$log"
export VOXEL_EXPECTED_SHA="$sha"

output="$({
  DEPLOY_EXPECTED_SHA="$sha" \
  DEPLOY_USER=manus-admin \
  DEPLOY_HOST=synthetic-host.invalid \
  DEPLOY_HEALTH_URL=https://synthetic-host.invalid/health \
  DEPLOY_BACKUP_VERIFIED=YES \
  DEPLOY_DRIFT_VERIFIED=YES \
  DEPLOY_AUTHORIZED=YES \
  bash "$mock_root/scripts/deploy.sh"
} 2>&1)"

grep -Fxq 'DEPLOY=PASS' <<<"$output"
grep -Fxq 'SCP_FILENAME_CONTRACT=PASS' "$log"
grep -Fxq 'CURL_MOCK=PASS' "$log"

printf 'deploy_executor_filename_alignment: PASS\n'
