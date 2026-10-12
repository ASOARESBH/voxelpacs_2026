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
lines = source.read_text(encoding='utf-8').splitlines()

ARRAY_OPEN = re.compile(r"\barray\s*\(")
ARRAY_CLOSE = re.compile(r"\),")
ROOT_START = re.compile(r"^\s*'root'\s*=>\s*array\s*\(")
VERSIONS_START = re.compile(r"^\s*'versions'\s*=>\s*array\s*\(")
PACKAGE_START = re.compile(r"^\s*'voxel/bi'\s*=>\s*array\s*\(")
REFERENCE = re.compile(r"'reference'\s*=>\s*(null|'[0-9a-f]{40}'),")

def state(value: str) -> str:
    value = value.strip()
    if value == 'null':
        return 'NULL'
    if re.fullmatch(r"'[0-9a-f]{40}'", value):
        return 'SOURCE_SHA' if value[1:-1] == expected else 'OTHER_SHA'
    return 'UNRESOLVED'

def line_reference_state(line: str) -> str:
    matches = REFERENCE.findall(line)
    if len(matches) != 1:
        return 'UNRESOLVED'
    return state(matches[0])

def delta(line: str) -> int:
    return len(ARRAY_OPEN.findall(line)) - len(ARRAY_CLOSE.findall(line))

def root_reference_state() -> str:
    depth = 0
    direct = []
    nested = 0
    closed = False
    malformed = False
    for line in lines:
        if depth == 0:
            if ROOT_START.search(line):
                depth = 1
            continue
        if depth == 1 and VERSIONS_START.search(line):
            malformed = True
            break
        if "'reference'" in line:
            if depth == 1:
                direct.append(line_reference_state(line))
            else:
                nested += 1
        depth += delta(line)
        if depth <= 0:
            closed = True
            break
    if closed and not malformed and len(direct) == 1 and nested == 0:
        return direct[0]
    return 'UNRESOLVED'

def package_reference_state() -> str:
    versions_depth = 0
    package_depth = 0
    package_found = False
    package_entries = 0
    direct = []
    any_references = 0
    closed = False
    for line in lines:
        if versions_depth == 0:
            if VERSIONS_START.search(line):
                versions_depth = 1
            continue
        if versions_depth == 1 and PACKAGE_START.search(line):
            package_entries += 1
            if package_entries > 1:
                return 'UNRESOLVED'
            package_found = True
            package_depth = 1
            continue
        if not package_found:
            versions_depth += delta(line)
            if versions_depth <= 0:
                break
            continue
        if package_depth > 0:
            if "'reference'" in line:
                any_references += 1
                if package_depth == 1:
                    direct.append(line_reference_state(line))
            package_depth += delta(line)
            if package_depth <= 0:
                closed = True
                package_depth = 0
    if package_entries == 1 and package_found and closed and len(direct) == 1 and any_references == 1:
        return direct[0]
    return 'UNRESOLVED'

out.write_text(
    f'profile_artifact_sha\t{expected}\n'
    f'root_reference_state\t{root_reference_state()}\n'
    f'package_reference_state\t{package_reference_state()}\n',
    encoding='utf-8',
)
out.chmod(0o600)
PY
mv -- "$profile_tmp" "$output_path"
trap - EXIT
printf 'COMPOSER_PROFILE=PASS\nPROFILE_FORMAT=STATE_ONLY_V1\nPROFILE_PATH=%s\nSOURCE_SHA=%s\nRAW_VALUES_EXPOSED=NO\nHASHES_EXPOSED=NO\nPRODUCTION_CHANGED=NO\n' "$output_path" "$expected_sha"
