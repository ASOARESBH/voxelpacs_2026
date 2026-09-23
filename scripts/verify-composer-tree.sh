#!/usr/bin/env bash
set -Eeuo pipefail

# Verifica que o vendor usado pelos gates pertence à mesma árvore Git testada.
# --allow-missing-vendor é usado somente antes de composer install.

ROOT="$(git rev-parse --show-toplevel 2>/dev/null || true)"
if [[ -z "$ROOT" || ! -f "$ROOT/composer.json" || ! -f "$ROOT/composer.lock" ]]; then
    printf 'TEST_TREE=FAIL\n'
    printf 'COMPOSER_TREE_MISMATCH=FAIL\n'
    printf 'REASON=TEST_TREE_OR_COMPOSER_FILES_UNRESOLVED\n'
    exit 1
fi

ROOT="$(realpath "$ROOT")"
CURRENT_SHA="$(git -C "$ROOT" rev-parse HEAD 2>/dev/null || true)"
if [[ ! "$CURRENT_SHA" =~ ^[0-9a-f]{40}$ ]]; then
    printf 'TEST_TREE=FAIL\n'
    printf 'COMPOSER_TREE_MISMATCH=FAIL\n'
    printf 'REASON=TEST_TREE_SHA_UNRESOLVED\n'
    exit 1
fi

if ! git -C "$ROOT" diff --quiet HEAD -- composer.json composer.lock; then
    printf 'TEST_TREE=FAIL\n'
    printf 'COMPOSER_TREE=FAIL\n'
    printf 'COMPOSER_TREE_MISMATCH=FAIL\n'
    printf 'REASON=COMPOSER_MANIFEST_OR_LOCK_DIRTY\n'
    exit 1
fi

printf 'TEST_TREE=PASS\n'
printf 'COMPOSER_TREE=PASS\n'
printf 'COMPOSER_TREE_SHA=%s\n' "$CURRENT_SHA"

VENDOR="$ROOT/vendor"
ALLOW_MISSING=0
if [[ "${1:-}" == '--allow-missing-vendor' ]]; then
    ALLOW_MISSING=1
fi

if [[ -L "$VENDOR" ]]; then
    VENDOR_REAL="$(realpath "$VENDOR" 2>/dev/null || true)"
    if [[ -z "$VENDOR_REAL" || "$VENDOR_REAL" != "$ROOT"/* ]]; then
        printf 'VENDOR_TREE=FAIL\n'
        printf 'COMPOSER_TREE_MISMATCH=FAIL\n'
        printf 'REASON=VENDOR_SYMLINK_OUTSIDE_TEST_TREE\n'
        exit 1
    fi
fi

if [[ ! -e "$VENDOR" ]]; then
    if [[ "$ALLOW_MISSING" -eq 1 ]]; then
        printf 'VENDOR_TREE=NOT_READY\n'
        printf 'COMPOSER_TREE_MATCH=NOT_READY\n'
        exit 0
    fi
    printf 'VENDOR_TREE=FAIL\n'
    printf 'COMPOSER_TREE_MISMATCH=FAIL\n'
    printf 'REASON=VENDOR_MISSING\n'
    exit 1
fi

if [[ ! -d "$VENDOR" ]]; then
    printf 'VENDOR_TREE=FAIL\n'
    printf 'COMPOSER_TREE_MISMATCH=FAIL\n'
    printf 'REASON=VENDOR_NOT_DIRECTORY\n'
    exit 1
fi

VENDOR_REAL="$(realpath "$VENDOR")"
if [[ "$VENDOR_REAL" != "$ROOT"/* ]]; then
    printf 'VENDOR_TREE=FAIL\n'
    printf 'COMPOSER_TREE_MISMATCH=FAIL\n'
    printf 'REASON=VENDOR_OUTSIDE_TEST_TREE\n'
    exit 1
fi

INSTALLED="$VENDOR/composer/installed.php"
if [[ ! -f "$INSTALLED" || ! -f "$VENDOR/autoload.php" ]]; then
    printf 'VENDOR_TREE=FAIL\n'
    printf 'COMPOSER_TREE_MISMATCH=FAIL\n'
    printf 'REASON=COMPOSER_INSTALLED_METADATA_MISSING\n'
    exit 1
fi

VENDOR_SHA="$(awk '
    /'"'"'root'"'"'[[:space:]]*=>[[:space:]]*array/ { in_root=1; next }
    in_root && /'"'"'reference'"'"'[[:space:]]*=>/ {
        line=$0
        sub(/^.*'"'"'reference'"'"'[[:space:]]*=>[[:space:]]*'"'"'/, "", line)
        sub(/'"'"'.*$/, "", line)
        print line
        exit
    }
' "$INSTALLED")"

if [[ ! "$VENDOR_SHA" =~ ^[0-9a-f]{40}$ ]]; then
    printf 'VENDOR_TREE=FAIL\n'
    printf 'COMPOSER_TREE_MISMATCH=FAIL\n'
    printf 'REASON=VENDOR_SOURCE_REFERENCE_UNRESOLVED\n'
    exit 1
fi

if [[ "$VENDOR_SHA" != "$CURRENT_SHA" ]]; then
    printf 'VENDOR_TREE=FAIL\n'
    printf 'VENDOR_TREE_SHA=%s\n' "$VENDOR_SHA"
    printf 'COMPOSER_TREE_MISMATCH=FAIL\n'
    printf 'REASON=VENDOR_SOURCE_REFERENCE_MISMATCH\n'
    exit 1
fi

printf 'VENDOR_TREE=PASS\n'
printf 'VENDOR_TREE_SHA=%s\n' "$VENDOR_SHA"
printf 'COMPOSER_TREE_MATCH=PASS\n'
