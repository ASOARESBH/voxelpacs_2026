#!/usr/bin/env bash
# VOXEL PACS — construtor determinístico do artefato runtime.
#
# O payload é extraído do checkout Git limpo e mantém o layout versionado:
#   repo/app/*    -> APP_ROOT/app/*
#   repo/public/* -> APP_ROOT/public/*
#
# Este script somente constrói e valida um artefato local. Não acessa produção,
# banco, Bridge, SMB, Worker ou qualquer serviço externo.
set -Eeuo pipefail

readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"
readonly EXPECTED_RUNTIME_CONFIG='app/Config/ReportDeliveryRuntimeConfig.php'
readonly REQUIRED_RUNTIME_FILES=(
  'app/bootstrap.php'
  'app/autoload.php'
  'app/Config/ReportDeliveryRuntimeConfig.php'
  'public/index.php'
  'bin/report_delivery_worker.php'
  'composer.json'
  'composer.lock'
)
readonly ARCHIVE_PATHS=(
  '.htaccess'
  'VERSAO.txt'
  'app'
  'public'
  'bin'
  'routes'
  'lang'
  'cron'
  'config'
  'composer.json'
  'composer.lock'
)
readonly ALLOWED_TOP_LEVEL=(
  '.htaccess'
  'VERSAO.txt'
  'app'
  'public'
  'bin'
  'routes'
  'lang'
  'cron'
  'config'
  'composer.json'
  'composer.lock'
  'vendor'
)

output_path=''
manifest_path=''
checksum_path=''
expected_sha="${RUNTIME_ARTIFACT_EXPECTED_SHA:-}"
skip_composer_install=0

fail() {
  printf 'RUNTIME_ARTIFACT=BLOCKED\nREASON=%s\n' "$1" >&2
  exit 64
}

usage() {
  cat <<'USAGE'
Uso:
  scripts/build-runtime-artifact.sh [opções]

Opções:
  --output PATH              ZIP de saída; por padrão, ~/voxelpacs_runtime_<sha>.zip
  --manifest PATH            Manifesto sidecar; por padrão, PATH.manifest.tsv
  --checksum PATH            SHA-256 sidecar; por padrão, PATH.sha256
  --expected-sha SHA         Exige que HEAD seja exatamente este SHA
  --skip-composer-install    Usa somente o vendor já validado pelo guard
  --help                     Mostra esta ajuda

O script nunca inclui .env, storage, uploads, logs, backups, testes, docs,
scripts, migrations ou conteúdo clínico no ZIP.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --output)
      [[ $# -ge 2 ]] || fail 'output_argument_missing'
      output_path="$2"
      shift 2
      ;;
    --manifest)
      [[ $# -ge 2 ]] || fail 'manifest_argument_missing'
      manifest_path="$2"
      shift 2
      ;;
    --checksum)
      [[ $# -ge 2 ]] || fail 'checksum_argument_missing'
      checksum_path="$2"
      shift 2
      ;;
    --expected-sha)
      [[ $# -ge 2 ]] || fail 'expected_sha_argument_missing'
      expected_sha="$2"
      shift 2
      ;;
    --skip-composer-install)
      skip_composer_install=1
      shift
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      fail "unknown_argument_$1"
      ;;
  esac
done

[[ -d "$ROOT/.git" || -f "$ROOT/.git" ]] || fail 'git_root_not_found'
git -C "$ROOT" rev-parse --verify HEAD >/dev/null 2>&1 || fail 'git_head_unresolved'

current_sha="$(git -C "$ROOT" rev-parse HEAD)"
[[ "$current_sha" =~ ^[0-9a-f]{40}$ ]] || fail 'git_sha_invalid'
if [[ -n "$expected_sha" ]]; then
  [[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]] || fail 'expected_sha_invalid'
  [[ "$current_sha" == "$expected_sha" ]] || fail 'git_sha_mismatch'
fi

if [[ -n "$(git -C "$ROOT" status --porcelain --untracked-files=all)" ]]; then
  fail 'worktree_not_clean'
fi

if [[ -z "$output_path" ]]; then
  output_path="${HOME}/voxelpacs_runtime_${current_sha:0:12}.zip"
fi
output_path="$(realpath -m "$output_path")"
case "$output_path" in
  "$ROOT"|"$ROOT"/*) fail 'output_inside_worktree' ;;
esac

manifest_path="$(realpath -m "${manifest_path:-${output_path}.manifest.tsv}")"
checksum_path="$(realpath -m "${checksum_path:-${output_path}.sha256}")"
for path in "$output_path" "$manifest_path" "$checksum_path"; do
  case "$path" in
    "$ROOT"|"$ROOT"/*) fail 'sidecar_inside_worktree' ;;
  esac
  [[ ! -e "$path" ]] || fail "output_already_exists_$(basename "$path")"
done

mkdir -p "$(dirname "$output_path")" "$(dirname "$manifest_path")" "$(dirname "$checksum_path")"

if ! command -v composer >/dev/null 2>&1; then
  fail 'composer_not_found'
fi
if [[ "$skip_composer_install" -eq 0 ]]; then
  bash "$ROOT/scripts/verify-composer-tree.sh" --allow-missing-vendor
  composer -d "$ROOT" install --no-dev --prefer-dist --no-progress --no-interaction
fi
bash "$ROOT/scripts/verify-composer-tree.sh"

stage_dir="$(mktemp -d "${TMPDIR:-/tmp}/voxelpacs-runtime-stage.XXXXXX")"
tmp_zip="$(mktemp "${TMPDIR:-/tmp}/voxelpacs-runtime-artifact.XXXXXX.zip")"
rm -f -- "$tmp_zip"
tmp_checksum="$(mktemp "${checksum_path}.tmp.XXXXXX")"
cleanup() {
  rm -rf -- "$stage_dir"
  rm -f -- "$tmp_zip" "$tmp_checksum"
}
trap cleanup EXIT

# O pathspec explícito impede que docs, scripts, migrations, testes ou outros
# arquivos de desenvolvimento entrem no payload. Arquivos ignorados (.env,
# storage e vendor local) também não entram no archive.
git -C "$ROOT" archive --format=tar HEAD "${ARCHIVE_PATHS[@]}" | tar -xf - -C "$stage_dir"

# vendor não é versionado, mas é aceito somente depois do guard de Composer.
vendor_real="$(realpath "$ROOT/vendor" 2>/dev/null || true)"
[[ -n "$vendor_real" && "$vendor_real" == "$ROOT"/* && -d "$vendor_real" ]] || fail 'vendor_not_inside_validated_tree'
cp -a -- "$vendor_real" "$stage_dir/vendor"

for entry in "$stage_dir"/* "$stage_dir"/.[!.]* "$stage_dir"/..?*; do
  [[ -e "$entry" || -L "$entry" ]] || continue
  top="${entry##*/}"
  allowed=0
  for candidate in "${ALLOWED_TOP_LEVEL[@]}"; do
    [[ "$top" == "$candidate" ]] && allowed=1 && break
  done
  [[ "$allowed" -eq 1 ]] || fail "unexpected_top_level_${top}"
done

# Rejeita qualquer caminho operacional ou persistente mesmo que ele seja
# introduzido no futuro por uma alteração no repositório.
while IFS= read -r -d '' entry; do
  relative="${entry#"$stage_dir"/}"
  case "$relative" in
    .env|.env.*|*/.env|*/.env.*|storage|storage/*|*/storage|*/storage/*|uploads|uploads/*|*/uploads|*/uploads/*|logs|logs/*|*/logs|*/logs/*|backups|backups/*|*/backups|*/backups/*|tests|tests/*|docs|docs/*|scripts|scripts/*|database|database/*|.git|.git/*|*/.git|*/.git/*)
      fail "forbidden_runtime_path_${relative}"
      ;;
  esac
done < <(find "$stage_dir" -mindepth 1 \( -type f -o -type l \) -print0)

for relative in "${REQUIRED_RUNTIME_FILES[@]}"; do
  [[ -f "$stage_dir/$relative" ]] || fail "required_runtime_file_missing_${relative}"
done
[[ ! -e "$stage_dir/Config/ReportDeliveryRuntimeConfig.php" ]] || fail 'legacy_flat_runtime_config_in_artifact'
[[ -f "$stage_dir/vendor/autoload.php" ]] || fail 'composer_autoload_missing'
[[ -f "$stage_dir/vendor/dompdf/dompdf/src/Dompdf.php" ]] || fail 'dompdf_missing'

# Valida o layout novamente a partir do ZIP, não apenas da pasta temporária.
(
  cd "$stage_dir"
  zip -q -r "$tmp_zip" .
)
zip_entries="$(unzip -Z1 "$tmp_zip")"
for relative in "${REQUIRED_RUNTIME_FILES[@]}" 'vendor/autoload.php' 'vendor/dompdf/dompdf/src/Dompdf.php'; do
  grep -Fxq "$relative" <<<"$zip_entries" || fail "required_zip_entry_missing_${relative}"
done
while IFS= read -r relative; do
  case "$relative" in
    .env|.env.*|storage|storage/*|uploads|uploads/*|logs|logs/*|backups|backups/*|tests|tests/*|docs|docs/*|scripts|scripts/*|database|database/*|.git|.git/*)
      fail "forbidden_zip_entry_${relative}"
      ;;
  esac
done <<<"$zip_entries"

while IFS= read -r relative; do
  [[ -n "$relative" ]] || continue
  printf '%s\t%s\n' "$relative" "$(sha256sum "$stage_dir/$relative" | awk '{print $1}')"
done < <(find "$stage_dir" -type f -printf '%P\n' | sort) > "$manifest_path"
mv -- "$tmp_zip" "$output_path"
sha256sum "$output_path" > "$tmp_checksum"
sha256sum -c "$tmp_checksum" >/dev/null
mv -- "$tmp_checksum" "$checksum_path"

printf 'RUNTIME_ARTIFACT=PASS\n'
printf 'SOURCE_SHA_PREFIX=%s…\n' "${current_sha:0:12}"
printf 'ARTIFACT_PATH=%s\n' "$output_path"
printf 'MANIFEST_PATH=%s\n' "$manifest_path"
printf 'CHECKSUM_PATH=%s\n' "$checksum_path"
printf 'RUNTIME_CONFIG_IN_ARTIFACT=YES\n'
printf 'RUNTIME_CONFIG_SHA_PREFIX=%s…\n' "$(awk -F '\t' '$1 == "app/Config/ReportDeliveryRuntimeConfig.php" {print substr($2, 1, 12)}' "$manifest_path")"
printf 'ARTIFACT_MANIFEST_ENTRIES=%s\n' "$(wc -l < "$manifest_path")"
printf 'CHECKSUM_FILE_VALID=YES\n'
printf 'SECRETS_INCLUDED=NO\n'
printf 'PERSISTENT_DATA_INCLUDED=NO\n'
printf 'PRODUCTION_CHANGED=NO\n'
