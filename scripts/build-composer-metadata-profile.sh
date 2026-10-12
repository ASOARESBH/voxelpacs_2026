#!/usr/bin/env bash
# VOXEL PACS — extrai perfil técnico sanitizado do installed.php local.
# Não imprime conteúdo, valores de referência ou hashes do arquivo.
set -Eeuo pipefail
umask 077
export PATH='/usr/sbin:/usr/bin:/sbin:/bin'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly SCRIPT_DIR
ROOT="$(git -C "$SCRIPT_DIR/.." rev-parse --show-toplevel 2>/dev/null || true)"
readonly ROOT
input_path=''
output_path=''
expected_sha=''

fail() {
  printf 'COMPOSER_PROFILE=BLOCKED\nREASON=%s\n' "$1" >&2
  exit 64
}

usage() {
  printf '%s\n' 'uso: build-composer-metadata-profile.sh --input <installed.php> --expected-sha <40-hex-sha> --output <profile.tsv>' >&2
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --input)
      [[ $# -ge 2 ]] || fail 'INPUT_ARGUMENT_MISSING'
      input_path="$2"
      shift 2
      ;;
    --expected-sha)
      [[ $# -ge 2 ]] || fail 'EXPECTED_SHA_ARGUMENT_MISSING'
      expected_sha="$2"
      shift 2
      ;;
    --output)
      [[ $# -ge 2 ]] || fail 'OUTPUT_ARGUMENT_MISSING'
      output_path="$2"
      shift 2
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      usage
      fail 'ARGUMENT_INVALID'
      ;;
  esac
done

[[ -n "$ROOT" && ( -d "$ROOT/.git" || -f "$ROOT/.git" ) ]] || fail 'GIT_ROOT_NOT_FOUND'
[[ "$SCRIPT_DIR" == "$ROOT/scripts" ]] || fail 'SCRIPT_LOCATION_INVALID'
[[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]] || fail 'EXPECTED_SHA_INVALID'
[[ "$(git -C "$ROOT" rev-parse HEAD 2>/dev/null)" == "$expected_sha" ]] || fail 'GIT_SHA_MISMATCH'
[[ -z "$(git -C "$ROOT" status --porcelain --untracked-files=all)" ]] || fail 'WORKTREE_NOT_CLEAN'
[[ -n "$input_path" && -f "$input_path" && ! -L "$input_path" ]] || fail 'INPUT_FILE_INVALID'
[[ -n "$output_path" ]] || fail 'OUTPUT_PATH_MISSING'
output_path="$(realpath -m -- "$output_path")"
case "$output_path" in
  "$ROOT"|"$ROOT"/*) fail 'OUTPUT_INSIDE_WORKTREE' ;;
esac
[[ ! -e "$output_path" && ! -L "$output_path" ]] || fail 'OUTPUT_ALREADY_EXISTS'
mkdir -p -- "$(dirname -- "$output_path")"
[[ -d "$(dirname -- "$output_path")" && ! -L "$(dirname -- "$output_path")" ]] || fail 'OUTPUT_DIRECTORY_INVALID'

profile_tmp="$(mktemp "${output_path}.tmp.XXXXXX")"
cleanup() { rm -f -- "$profile_tmp"; }
trap cleanup EXIT

python3 - "$input_path" "$expected_sha" "$profile_tmp" <<'PY'
from pathlib import Path
import re
import sys

source = Path(sys.argv[1])
expected = sys.argv[2]
out = Path(sys.argv[3])
text = source.read_text(encoding='utf-8')

def state(value: str) -> str:
    value = value.strip()
    if value == 'null':
        return 'NULL'
    if re.fullmatch(r"'[0-9a-f]{40}'", value):
        return 'SOURCE_SHA' if value[1:-1] == expected else 'OTHER_SHA'
    return 'UNRESOLVED'

def reference_in_block(block: str) -> str:
    matches = re.findall(r"'reference'\s*=>\s*(null|'[0-9a-f]{40}'),", block)
    if len(matches) != 1:
        return 'UNRESOLVED'
    return state(matches[0])

root_start = text.find("    'root' => array(")
versions_start = text.find("    'versions' => array(", root_start)
if root_start < 0 or versions_start <= root_start:
    root_state = 'UNRESOLVED'
else:
    root_state = reference_in_block(text[root_start:versions_start])

package_start = text.find("        'voxel/bi' => array(", versions_start)
if package_start < 0:
    package_state = 'UNRESOLVED'
else:
    package_end = text.find("\n        ),", package_start)
    package_block = text[package_start:] if package_end < 0 else text[package_start:package_end]
    package_state = reference_in_block(package_block)

out.write_text(
    f'profile_artifact_sha\t{expected}\n'
    f'root_reference_state\t{root_state}\n'
    f'package_reference_state\t{package_state}\n',
    encoding='utf-8',
)
out.chmod(0o600)
PY
mv -- "$profile_tmp" "$output_path"
trap - EXIT
printf 'COMPOSER_PROFILE=PASS\nPROFILE_FORMAT=STATE_ONLY_V1\nPROFILE_PATH=%s\nSOURCE_SHA=%s\nRAW_VALUES_EXPOSED=NO\nHASHES_EXPOSED=NO\nPRODUCTION_CHANGED=NO\n' "$output_path" "$expected_sha"
