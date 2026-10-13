#!/usr/bin/env bash
# VOXEL PACS — validação read-only do runtime contra o último commit publicado.
#
# A referência é o manifesto do commit que está publicado em produção (o SHA
# usado no último `voxelpacs-deploy-runtime --sha`, também gravado pelo builder
# em `vendor/composer/installed.php` → root.reference). Assim o gate só acusa
# alterações feitas fora do Git; o código novo que será publicado não entra na
# comparação. Extras fora do manifesto continuam aceitos somente quando
# coincidem com o baseline root-controlled.
#
# Não publica, copia, remove ou altera arquivos; apenas calcula hashes e estados.
set -Eeuo pipefail
umask 077
export PATH='/usr/sbin:/usr/bin:/sbin:/bin'

readonly COMPOSER_METADATA_PATH='vendor/composer/installed.php'
readonly MECHANISM_VERSION='3'
readonly REFERENCE_POLICY='LAST_PUBLISHED_COMMIT'

runtime_root=''
published_manifest=''
baseline_manifest=''

usage() {
  printf '%s\n' \
    'uso: validate-runtime-drift.sh --runtime-root <dir> --published-manifest <file> --baseline-manifest <file>' >&2
}

fail_preflight() {
  printf 'DRIFT_GATE=BLOCKED\nREASON=%s\nPRODUCTION_CHANGED=NO\n' "$1" >&2
  exit 64
}

safe_relative_path() {
  local rel="$1"
  [[ -n "$rel" && "$rel" != /* && "$rel" != '.' ]] || return 1
  [[ "$rel" != *$'\n'* && "$rel" != *$'\r'* && "$rel" != *$'\t'* ]] || return 1
  [[ "$rel" != '../'* && "$rel" != */../* && "$rel" != */.. && "$rel" != '..' ]] || return 1
  # shellcheck disable=SC2221,SC2222
  case "$rel" in
    .env|.env.*|*/.env|*/.env.*|storage|storage/*|*/storage|*/storage/*|uploads|uploads/*|*/uploads|*/uploads/*|public/uploads|public/uploads/*|*/public/uploads|*/public/uploads/*|logs|logs/*|*/logs|*/logs/*|backups|backups/*|*/backups|*/backups/*|runtime|runtime/*|*/runtime|*/runtime/*|cache|cache/*|*/cache|*/cache/*|sessions|sessions/*|*/sessions|*/sessions/*|tmp|tmp/*|*/tmp|*/tmp/*)
      return 1
      ;;
  esac
  return 0
}

parse_args() {
  [[ "$#" -eq 6 ]] || { usage; fail_preflight 'ARGUMENTS_INVALID'; }
  while [[ "$#" -gt 0 ]]; do
    case "$1" in
      --runtime-root) runtime_root="$2"; shift 2 ;;
      --published-manifest) published_manifest="$2"; shift 2 ;;
      --baseline-manifest) baseline_manifest="$2"; shift 2 ;;
      *) usage; fail_preflight 'ARGUMENTS_INVALID' ;;
    esac
  done
  [[ -n "$runtime_root" && -n "$published_manifest" && -n "$baseline_manifest" ]] || fail_preflight 'ARGUMENTS_MISSING'
  [[ -d "$runtime_root" && ! -L "$runtime_root" ]] || fail_preflight 'RUNTIME_ROOT_INVALID'
  [[ -f "$published_manifest" && ! -L "$published_manifest" ]] || fail_preflight 'PUBLISHED_MANIFEST_INVALID'
  [[ -f "$baseline_manifest" && ! -L "$baseline_manifest" ]] || fail_preflight 'BASELINE_MANIFEST_INVALID'
}

declare -A published_hash=()
declare -A baseline_hash=()
declare -A baseline_classification=()

published_entries=0
baseline_entries=0
baseline_legacy_entries=0
baseline_current_entries=0
invalid_published_entries=0
invalid_baseline_entries=0

load_published_manifest() {
  local rel hash extra
  while IFS=$'\t' read -r rel hash extra; do
    [[ -z "$rel" && -z "${hash:-}" && -z "${extra:-}" ]] && continue
    if [[ -n "${extra:-}" ]] || ! safe_relative_path "$rel" || ! [[ "$hash" =~ ^[0-9a-f]{64}$ ]] || [[ -n "${published_hash[$rel]+present}" ]]; then
      invalid_published_entries=$((invalid_published_entries + 1))
      continue
    fi
    published_hash["$rel"]="$hash"
    published_entries=$((published_entries + 1))
  done < "$published_manifest"
}

load_baseline_manifest() {
  local rel size hash owner group mode mtime classification read_status extra format
  # shellcheck disable=SC2034
  while IFS=$'\t' read -r rel size hash owner group mode mtime classification read_status extra; do
    [[ -z "$rel" && -z "${hash:-}" && -z "${extra:-}" ]] && continue
    format='CURRENT_9_COLUMN'
    if [[ -z "${read_status:-}" && -z "${extra:-}" ]]; then
      # Baselines captured before the READABLE column was introduced have
      # already proved readability by carrying a full SHA-256 digest. Accept
      # exactly that legacy shape; malformed/unhashed rows still block.
      read_status='READABLE'
      format='LEGACY_8_COLUMN'
    fi
    if [[ -n "${extra:-}" ]] || ! safe_relative_path "$rel" || ! [[ "$size" =~ ^[0-9]+$ ]] || ! [[ "$hash" =~ ^[0-9a-f]{64}$ ]] || ! [[ "$mode" =~ ^[0-9]+$ ]] || [[ "$read_status" != 'READABLE' ]] || [[ -n "${baseline_hash[$rel]+present}" ]]; then
      invalid_baseline_entries=$((invalid_baseline_entries + 1))
      continue
    fi
    case "$classification" in
      OWNED_RUNTIME_CODE|COMPOSER_DEPENDENCY|VERSIONED_DATABASE_CODE|DEVELOPMENT_OR_OPERATIONS|UNCLASSIFIED_RUNTIME_FILE) ;;
      *) invalid_baseline_entries=$((invalid_baseline_entries + 1)); continue ;;
    esac
    baseline_hash["$rel"]="$hash"
    baseline_classification["$rel"]="$classification"
    baseline_entries=$((baseline_entries + 1))
    if [[ "$format" == 'LEGACY_8_COLUMN' ]]; then
      baseline_legacy_entries=$((baseline_legacy_entries + 1))
    else
      baseline_current_entries=$((baseline_current_entries + 1))
    fi
  done < "$baseline_manifest"
}

# SHA gravado pelo builder em root.reference do metadata Composer do runtime.
# Informa qual commit está publicado; não imprime conteúdo do arquivo.
runtime_published_sha() {
  local installed="$runtime_root/$COMPOSER_METADATA_PATH" ref
  [[ -f "$installed" && ! -L "$installed" ]] || { printf 'UNAVAILABLE'; return 0; }
  # Sem expressões de intervalo, para funcionar igual em mawk e gawk.
  ref="$(awk -v q="'" '
    index($0, "    " q "root" q " => array(") == 1 { in_root = 1; next }
    index($0, "    " q "versions" q " => array(") == 1 { in_root = 0 }
    in_root && index($0, q "reference" q " =>") > 0 {
      v = $0; sub(/^.*=>[ ]*/, "", v); gsub(/[^0-9a-f]/, "", v); print v; exit
    }' "$installed" 2>/dev/null || true)"
  if [[ "$ref" =~ ^[0-9a-f]{40}$ ]]; then printf '%s' "$ref"; else printf 'UNAVAILABLE'; fi
}

runtime_hash() {
  local path="$1"
  [[ -f "$path" && ! -L "$path" ]] || return 1
  sha256sum -- "$path" 2>/dev/null | awk '{print $1}'
}

parse_args "$@"
load_published_manifest
load_baseline_manifest

expected_files=0
content_match=0
content_mismatch=0
mismatch_uncovered=0
composer_exception=0
missing=0
unreadable=0
extra_files=0
extras_in_baseline=0
extras_not_in_baseline=0
extra_hash_mismatch=0
extra_unreadable=0
mismatch_paths=''

for rel in "${!published_hash[@]}"; do
  expected_files=$((expected_files + 1))
  path="$runtime_root/$rel"
  if [[ ! -f "$path" || -L "$path" ]]; then
    missing=$((missing + 1))
    continue
  fi
  actual="$(runtime_hash "$path" || true)"
  if [[ ! "$actual" =~ ^[0-9a-f]{64}$ ]]; then
    unreadable=$((unreadable + 1))
    continue
  fi
  if [[ "$actual" == "${published_hash[$rel]}" ]]; then
    content_match=$((content_match + 1))
    continue
  fi
  content_mismatch=$((content_mismatch + 1))
  mismatch_paths+="$rel "
  if [[ "$rel" == "$COMPOSER_METADATA_PATH" && -n "${baseline_hash[$rel]+present}" && "${baseline_classification[$rel]}" == 'COMPOSER_DEPENDENCY' && "$actual" == "${baseline_hash[$rel]}" ]]; then
    composer_exception=$((composer_exception + 1))
  else
    mismatch_uncovered=$((mismatch_uncovered + 1))
  fi
done

while IFS= read -r -d '' path; do
  rel="${path#"$runtime_root/"}"
  case "$rel" in
    .env|.env.*|storage|storage/*|uploads|uploads/*|public/uploads|public/uploads/*|logs|logs/*|backups|backups/*|runtime|runtime/*|cache|cache/*|sessions|sessions/*|tmp|tmp/*)
      continue
      ;;
  esac
  [[ -n "${published_hash[$rel]+present}" ]] && continue
  extra_files=$((extra_files + 1))
  if [[ -L "$path" || ! -f "$path" ]]; then
    extra_unreadable=$((extra_unreadable + 1))
    continue
  fi
  actual="$(runtime_hash "$path" || true)"
  if [[ ! "$actual" =~ ^[0-9a-f]{64}$ ]]; then
    extra_unreadable=$((extra_unreadable + 1))
    continue
  fi
  if [[ -n "${baseline_hash[$rel]+present}" && "$actual" == "${baseline_hash[$rel]}" ]]; then
    extras_in_baseline=$((extras_in_baseline + 1))
  elif [[ -z "${baseline_hash[$rel]+present}" ]]; then
    extras_not_in_baseline=$((extras_not_in_baseline + 1))
  else
    extra_hash_mismatch=$((extra_hash_mismatch + 1))
  fi
done < <(find -P "$runtime_root" -xdev \
  \( -path "$runtime_root/.env" -o -path "$runtime_root/.env.*" -o -path "$runtime_root/storage" -o -path "$runtime_root/storage/*" -o -path "$runtime_root/uploads" -o -path "$runtime_root/uploads/*" -o -path "$runtime_root/public/uploads" -o -path "$runtime_root/public/uploads/*" -o -path "$runtime_root/logs" -o -path "$runtime_root/logs/*" -o -path "$runtime_root/backups" -o -path "$runtime_root/backups/*" -o -path "$runtime_root/runtime" -o -path "$runtime_root/runtime/*" -o -path "$runtime_root/cache" -o -path "$runtime_root/cache/*" -o -path "$runtime_root/sessions" -o -path "$runtime_root/sessions/*" -o -path "$runtime_root/tmp" -o -path "$runtime_root/tmp/*" \) -prune -o \
  \( -type f -o -type l \) -print0)

baseline_format='CURRENT_9_COLUMN'
if [[ "$baseline_legacy_entries" -gt 0 && "$baseline_current_entries" -gt 0 ]]; then
  baseline_format='MIXED_8_AND_9_COLUMN'
elif [[ "$baseline_legacy_entries" -gt 0 ]]; then
  baseline_format='LEGACY_8_COLUMN'
fi

runtime_sha="$(runtime_published_sha)"

printf 'MECHANISM_VERSION=%s\nREFERENCE=%s\nRUNTIME_PUBLISHED_SHA=%s\nCOMPOSER_METADATA_PATH=%s\nPUBLISHED_MANIFEST_ENTRIES=%s\nBASELINE_MANIFEST_ENTRIES=%s\nBASELINE_FORMAT=%s\nBASELINE_LEGACY_ENTRIES=%s\nBASELINE_CURRENT_ENTRIES=%s\nEXPECTED_FILES=%s\nCONTENT_MATCH=%s\nCONTENT_MISMATCH=%s\nCOMPOSER_METADATA_EXCEPTION=%s\nMISMATCH_UNCOVERED=%s\nMISSING=%s\nUNREADABLE=%s\nINVALID_PUBLISHED_ENTRIES=%s\nINVALID_BASELINE_ENTRIES=%s\nEXTRA_FILES=%s\nEXTRAS_IN_BASELINE=%s\nEXTRAS_NOT_IN_BASELINE=%s\nEXTRA_HASH_MISMATCH=%s\nEXTRA_UNREADABLE=%s\nMISMATCH_PATHS=%s\n' \
  "$MECHANISM_VERSION" "$REFERENCE_POLICY" "$runtime_sha" "$COMPOSER_METADATA_PATH" "$published_entries" "$baseline_entries" "$baseline_format" "$baseline_legacy_entries" "$baseline_current_entries" "$expected_files" "$content_match" "$content_mismatch" "$composer_exception" "$mismatch_uncovered" "$missing" "$unreadable" "$invalid_published_entries" "$invalid_baseline_entries" "$extra_files" "$extras_in_baseline" "$extras_not_in_baseline" "$extra_hash_mismatch" "$extra_unreadable" "${mismatch_paths% }"

if [[ "$published_entries" -eq 0 || "$baseline_entries" -eq 0 || "$invalid_published_entries" -ne 0 || "$invalid_baseline_entries" -ne 0 || "$missing" -ne 0 || "$unreadable" -ne 0 || "$mismatch_uncovered" -ne 0 || "$extras_not_in_baseline" -ne 0 || "$extra_hash_mismatch" -ne 0 || "$extra_unreadable" -ne 0 ]]; then
  printf 'DRIFT_GATE=FAIL\nREASON=RUNTIME_CHANGED_OUTSIDE_PUBLISHED_COMMIT\n'
  printf 'PRODUCTION_CHANGED=NO\nDATABASE_CHANGED=NO\nWORKER_STARTED=NO\nBRIDGE_CALLED=NO\nSMB_CALLED=NO\nTRANSMISSION=NO\n'
  exit 65
fi

printf 'DRIFT_GATE=PASS\nPOLICY=LAST_PUBLISHED_COMMIT_AND_BASELINE_EXTRAS\nPRODUCTION_CHANGED=NO\nDATABASE_CHANGED=NO\nWORKER_STARTED=NO\nBRIDGE_CALLED=NO\nSMB_CALLED=NO\nTRANSMISSION=NO\n'
