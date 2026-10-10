#!/usr/bin/env bash
# VOXEL PACS — captura sanitizada do baseline de produção.
#
# Somente inventaria arquivos regulares e symlinks; não altera a origem,
# não acessa banco/serviços e exclui configuração secreta, persistência,
# uploads, logs, backups e dados clínicos.
set -Eeuo pipefail
umask 077

readonly DEFAULT_SOURCE_ROOT='/var/www/voxelpacs/app'
readonly DEFAULT_OUTPUT_ROOT='/var/lib/voxelpacs/baselines'
readonly MECHANISM_VERSION='1'
source_root="$DEFAULT_SOURCE_ROOT"
output_root="$DEFAULT_OUTPUT_ROOT"
require_complete='NO'

fail() {
  printf 'BASELINE_CAPTURE=BLOCKED\nREASON=%s\n' "$1" >&2
  exit 64
}

usage() {
  cat <<'USAGE'
Uso:
  scripts/capture-production-baseline.sh [opções]

Opções:
  --source-root PATH       raiz somente-leitura a inventariar
  --output-dir PATH        diretório de evidências; será criado com modo 0700
  --require-complete       falha se algum hash não puder ser lido
  --help                   mostra esta ajuda

Por padrão, a origem é /var/www/voxelpacs/app e a saída é
/var/lib/voxelpacs/baselines. O script não aceita credenciais, comandos,
serviços, SQL, transporte ou paths persistentes como parte do inventário.
USAGE
}

while (($#)); do
  case "$1" in
    --source-root)
      [[ $# -ge 2 ]] || fail 'SOURCE_ROOT_ARGUMENT_MISSING'
      source_root="$2"
      shift 2
      ;;
    --output-dir)
      [[ $# -ge 2 ]] || fail 'OUTPUT_DIR_ARGUMENT_MISSING'
      output_root="$2"
      shift 2
      ;;
    --require-complete)
      require_complete='YES'
      shift
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      fail 'UNKNOWN_ARGUMENT'
      ;;
  esac
done

source_root="$(realpath -e -- "$source_root")" || fail 'SOURCE_ROOT_NOT_FOUND'
[[ -d "$source_root" && ! -L "$source_root" ]] || fail 'SOURCE_ROOT_INVALID'
output_root="$(realpath -m -- "$output_root")" || fail 'OUTPUT_ROOT_INVALID'
[[ "$output_root" != "$source_root" && "$output_root" != "$source_root"/* ]] || fail 'OUTPUT_INSIDE_SOURCE_ROOT'

parent_root="$(dirname -- "$output_root")"
mkdir -p -- "$parent_root"
[[ -d "$parent_root" && ! -L "$parent_root" ]] || fail 'OUTPUT_PARENT_INVALID'
run_dir="$(mktemp -d "$parent_root/.baseline-capture.XXXXXX")"
cleanup() {
  rm -rf -- "$run_dir"
}
trap cleanup EXIT
chmod 700 -- "$run_dir"

manifest_tmp="$run_dir/manifest.tsv"
symlink_tmp="$run_dir/symlinks.tsv"
exclusions_tmp="$run_dir/exclusions.tsv"
metadata_tmp="$run_dir/metadata.txt"
: > "$manifest_tmp"
: > "$symlink_tmp"
: > "$exclusions_tmp"

is_excluded() {
  local rel="$1"
  case "$rel" in
    .env|.env.*|*/.env|*/.env.*|storage|storage/*|uploads|uploads/*|public/uploads|public/uploads/*|logs|logs/*|backups|backups/*|runtime|runtime/*|cache|cache/*|sessions|sessions/*|tmp|tmp/*)
      return 0
      ;;
    *)
      return 1
      ;;
  esac
}

classify() {
  local rel="$1"
  case "$rel" in
    app/*|public/*|bin/*|routes/*|lang/*|config/*|cron/*|composer.json|composer.lock|VERSAO.txt|.htaccess)
      printf 'OWNED_RUNTIME_CODE'
      ;;
    vendor/*)
      printf 'COMPOSER_DEPENDENCY'
      ;;
    database/*)
      printf 'VERSIONED_DATABASE_CODE'
      ;;
    docs/*|tests/*|scripts/*|ops/*|SKILL-*|AGENTS.md|README*|Dockerfile|.github/*)
      printf 'DEVELOPMENT_OR_OPERATIONS'
      ;;
    *)
      printf 'UNCLASSIFIED_RUNTIME_FILE'
      ;;
  esac
}

safe_relative() {
  local rel="$1"
  [[ -n "$rel" && "$rel" != /* && "$rel" != '.' ]] || return 1
  [[ "$rel" != *$'\n'* && "$rel" != *$'\r'* && "$rel" != *$'\t'* ]] || return 1
  [[ "$rel" != '../'* && "$rel" != */../* && "$rel" != */.. && "$rel" != '..' ]] || return 1
}

file_count=0
hash_unavailable=0
metadata_unavailable=0
excluded_count=0
symlink_count=0

for excluded_root in \
  "$source_root/.env" "$source_root"/.env.* \
  "$source_root/storage" "$source_root/uploads" "$source_root/public/uploads" \
  "$source_root/logs" "$source_root/backups" "$source_root/runtime" \
  "$source_root/cache" "$source_root/sessions" "$source_root/tmp"; do
  [[ -e "$excluded_root" || -L "$excluded_root" ]] || continue
  excluded_rel="${excluded_root#"$source_root/"}"
  printf '%s\tEXCLUDED_PERSISTENT_OR_SECRET\n' "$excluded_rel" >> "$exclusions_tmp"
  excluded_count=$((excluded_count + 1))
done

while IFS= read -r -d '' entry; do
  rel="${entry#"$source_root/"}"
  safe_relative "$rel" || fail 'UNSAFE_RELATIVE_PATH'
  if is_excluded "$rel"; then
    printf '%s\tEXCLUDED_PERSISTENT_OR_SECRET\n' "$rel" >> "$exclusions_tmp"
    excluded_count=$((excluded_count + 1))
    continue
  fi
  if [[ -L "$entry" ]]; then
    target="$(readlink -- "$entry")"
    read -r owner group mode mtime < <(stat -c '%U %G %a %Y' -- "$entry") || {
      owner='UNAVAILABLE'; group='UNAVAILABLE'; mode='UNAVAILABLE'; mtime='UNAVAILABLE'
      metadata_unavailable=$((metadata_unavailable + 1))
    }
    if [[ "$mtime" =~ ^[0-9]+$ ]]; then
      mtime_utc="$(date -u -d "@$mtime" '+%Y-%m-%dT%H:%M:%SZ')"
    else
      mtime_utc='UNAVAILABLE'
    fi
    printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$rel" "$target" "$owner" "$group" "$mode" "$mtime_utc" >> "$symlink_tmp"
    symlink_count=$((symlink_count + 1))
    continue
  fi
  [[ -f "$entry" ]] || continue
  read -r size owner group mode mtime < <(stat -c '%s %U %G %a %Y' -- "$entry") || {
    size='UNAVAILABLE'; owner='UNAVAILABLE'; group='UNAVAILABLE'; mode='UNAVAILABLE'; mtime='UNAVAILABLE'
    metadata_unavailable=$((metadata_unavailable + 1))
  }
  if [[ "$mtime" =~ ^[0-9]+$ ]]; then
    mtime_utc="$(date -u -d "@$mtime" '+%Y-%m-%dT%H:%M:%SZ')"
  else
    mtime_utc='UNAVAILABLE'
  fi
  if digest="$(sha256sum -- "$entry" 2>/dev/null | awk '{print $1}')" && [[ "$digest" =~ ^[0-9a-f]{64}$ ]]; then
    read_status='READABLE'
  else
    digest='UNAVAILABLE'
    read_status='HASH_READ_PERMISSION_DENIED'
    hash_unavailable=$((hash_unavailable + 1))
  fi
  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "$rel" "$size" "$digest" "$owner" "$group" "$mode" "$mtime_utc" "$(classify "$rel")" "$read_status" >> "$manifest_tmp"
  file_count=$((file_count + 1))
done < <(find -P "$source_root" -xdev \
  \( -path "$source_root/.env" -o -path "$source_root/.env.*" -o -path "$source_root/storage" -o -path "$source_root/storage/*" -o -path "$source_root/uploads" -o -path "$source_root/uploads/*" -o -path "$source_root/public/uploads" -o -path "$source_root/public/uploads/*" -o -path "$source_root/logs" -o -path "$source_root/logs/*" -o -path "$source_root/backups" -o -path "$source_root/backups/*" -o -path "$source_root/runtime" -o -path "$source_root/runtime/*" -o -path "$source_root/cache" -o -path "$source_root/cache/*" -o -path "$source_root/sessions" -o -path "$source_root/sessions/*" -o -path "$source_root/tmp" -o -path "$source_root/tmp/*" \) -prune -o \
  \( -type f -o -type l \) -print0)

LC_ALL=C sort -t $'\t' -k1,1 "$manifest_tmp" -o "$manifest_tmp"
LC_ALL=C sort -t $'\t' -k1,1 "$symlink_tmp" -o "$symlink_tmp"
LC_ALL=C sort -t $'\t' -k1,1 "$exclusions_tmp" -o "$exclusions_tmp"

manifest_sha="$(sha256sum -- "$manifest_tmp" | awk '{print $1}')"
baseline_id="${manifest_sha:0:40}"
hostname_safe="$(hostname -s 2>/dev/null || printf 'UNKNOWN')"
{
  printf 'mechanism_version=%s\n' "$MECHANISM_VERSION"
  printf 'captured_at_utc=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  printf 'hostname=%s\n' "$hostname_safe"
  printf 'source_root=%s\n' "$source_root"
  printf 'manifest_sha256=%s\n' "$manifest_sha"
  printf 'baseline_id=%s\n' "$baseline_id"
  printf 'file_count=%s\n' "$file_count"
  printf 'symlink_count=%s\n' "$symlink_count"
  printf 'excluded_count=%s\n' "$excluded_count"
  printf 'hash_unavailable=%s\n' "$hash_unavailable"
  printf 'metadata_unavailable=%s\n' "$metadata_unavailable"
  printf 'secrets_content=NOT_CAPTURED\n'
  printf 'persistent_content=NOT_CAPTURED\n'
  printf 'clinical_content=NOT_CAPTURED\n'
  printf 'production_changed=NO\n'
  printf 'database_changed=NO\n'
  printf 'worker_started=NO\n'
  printf 'transmission=NO\n'
} > "$metadata_tmp"

if [[ "$require_complete" == 'YES' && "$hash_unavailable" -ne 0 ]]; then
  printf 'BASELINE_CAPTURE=INCOMPLETE\nBASELINE_ID=%s\nHASH_UNAVAILABLE=%s\nOUTPUT_NOT_PUBLISHED=YES\n' "$baseline_id" "$hash_unavailable" >&2
  exit 65
fi

mkdir -p -- "$output_root"
[[ -d "$output_root" && ! -L "$output_root" ]] || fail 'OUTPUT_ROOT_INVALID'
chmod 700 -- "$output_root"
final_dir="$output_root/$baseline_id"
[[ ! -e "$final_dir" && ! -L "$final_dir" ]] || fail 'BASELINE_ID_ALREADY_EXISTS'
staged_dir="$run_dir/$baseline_id"
mkdir -p -- "$staged_dir"
chmod 700 -- "$staged_dir"
install -m 600 -- "$manifest_tmp" "$staged_dir/manifest.tsv"
install -m 600 -- "$symlink_tmp" "$staged_dir/symlinks.tsv"
install -m 600 -- "$exclusions_tmp" "$staged_dir/exclusions.tsv"
install -m 600 -- "$metadata_tmp" "$staged_dir/metadata.txt"
printf '%s\n' "$manifest_sha" > "$staged_dir/manifest.sha256"
printf '%s\n' "$baseline_id" > "$staged_dir/baseline-id"
chmod 600 "$staged_dir/manifest.sha256" "$staged_dir/baseline-id"
mv -T -- "$staged_dir" "$final_dir"

printf 'BASELINE_CAPTURE=PASS\nBASELINE_ID=%s\nOUTPUT_DIR=%s\nMANIFEST_SHA256=%s\nFILE_COUNT=%s\nSYMLINK_COUNT=%s\nEXCLUDED_COUNT=%s\nHASH_UNAVAILABLE=%s\nMETADATA_UNAVAILABLE=%s\nSECRETS_CAPTURED=NO\nPERSISTENT_CONTENT_CAPTURED=NO\nCLINICAL_CONTENT_CAPTURED=NO\nPRODUCTION_CHANGED=NO\nDATABASE_CHANGED=NO\nWORKER_STARTED=NO\nTRANSMISSION=NO\n' \
  "$baseline_id" "$final_dir" "$manifest_sha" "$file_count" "$symlink_count" "$excluded_count" "$hash_unavailable" "$metadata_unavailable"
