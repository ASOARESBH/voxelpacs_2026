#!/usr/bin/env bash
set -Eeuo pipefail

REPO_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
readonly REPO_ROOT
TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/voxelpacs-composer-reference-test.XXXXXX")"
readonly TEMP_ROOT
readonly SOURCE_ROOT="$TEMP_ROOT/source"
readonly OUTPUT_ROOT="$TEMP_ROOT/output"
trap 'rm -rf -- "$TEMP_ROOT"' EXIT

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

GIT_CONFIG_GLOBAL=/dev/null git clone --quiet --no-hardlinks --no-tags "$REPO_ROOT" "$SOURCE_ROOT"
GIT_CONFIG_GLOBAL=/dev/null git -C "$SOURCE_ROOT" checkout --detach --quiet HEAD
[[ -z "$(GIT_CONFIG_GLOBAL=/dev/null git -C "$SOURCE_ROOT" status --porcelain --untracked-files=all)" ]] || fail 'fixture Git sujo'
expected_sha="$(GIT_CONFIG_GLOBAL=/dev/null git -C "$SOURCE_ROOT" rev-parse HEAD)"
[[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]] || fail 'sha do fixture inválida'
composer -d "$SOURCE_ROOT" install --no-dev --prefer-dist --no-progress --no-interaction >/dev/null 2>&1
installed="$SOURCE_ROOT/vendor/composer/installed.php"
[[ -f "$installed" && ! -L "$installed" ]] || fail 'installed.php ausente'

OLD_REFERENCE='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
python3 - "$installed" "$OLD_REFERENCE" <<'PY'
from pathlib import Path
import re
import sys

path = Path(sys.argv[1])
old = sys.argv[2]
text = path.read_text()
root_start = text.find("    'root' => array(")
versions_start = text.find("    'versions' => array(", root_start)
if root_start < 0 or versions_start <= root_start:
    raise SystemExit('fixture Composer sem root/versions')
root = text[root_start:versions_start]
matches = list(re.finditer(r"('reference'\s*=>\s*)(null|'[0-9a-f]{40}')", root))
if len(matches) != 1:
    raise SystemExit('fixture Composer com referência root ambígua')
start, end = matches[0].span(2)
root = root[:start] + repr(old) + root[end:]
path.write_text(text[:root_start] + root + text[versions_start:])
PY

mkdir -p "$OUTPUT_ROOT"
bash "$SOURCE_ROOT/scripts/build-runtime-artifact.sh" \
    --skip-composer-install \
    --output "$OUTPUT_ROOT/runtime.zip" \
    --manifest "$OUTPUT_ROOT/runtime.manifest.tsv" \
    --checksum "$OUTPUT_ROOT/builder.sha256" \
    --expected-sha "$expected_sha" >/dev/null

[[ -s "$OUTPUT_ROOT/runtime.zip" ]] || fail 'artefato não produzido'
[[ -s "$OUTPUT_ROOT/runtime.manifest.tsv" ]] || fail 'manifesto não produzido'
printf 'runtime_artifact_composer_reference_isolated: PASS\n'
