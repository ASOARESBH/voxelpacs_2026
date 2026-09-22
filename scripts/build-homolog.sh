#!/usr/bin/env bash
# VOXEL PACS — build explícito da homologação versionada.
# Este wrapper é deliberadamente restrito à branch versao/1.0.
set -euo pipefail

EXPECTED_BRANCH="versao/1.0"
ROOT="$(git rev-parse --show-toplevel 2>/dev/null || true)"
BRANCH="$(git branch --show-current 2>/dev/null || true)"

if [[ -z "$ROOT" || "$BRANCH" != "$EXPECTED_BRANCH" ]]; then
    printf 'HOMOLOG_BUILD=BLOCKED\n'
    printf 'REASON=RUN_ONLY_FROM_BRANCH_%s\n' "$EXPECTED_BRANCH"
    printf 'CURRENT_BRANCH=%s\n' "${BRANCH:-DETACHED_OR_NOT_GIT}"
    exit 1
fi

if [[ ! -f "$ROOT/VERSAO.txt" ]] || [[ "$(tr -d '\r\n' < "$ROOT/VERSAO.txt")" != "1.0" ]]; then
    printf 'HOMOLOG_BUILD=BLOCKED\n'
    printf 'REASON=VERSION_MARKER_MUST_BE_1_0\n'
    exit 1
fi

printf 'HOMOLOG_BUILD=PASS\n'
printf 'WORKTREE=%s\n' "$ROOT"
printf 'BRANCH=%s\n' "$BRANCH"
printf 'VERSION=1.0\n'
printf 'ROOT_PROJECT_MUTATION=BLOCKED\n'
printf 'OUTPUT_SCOPE=HOME_ARCHIVE_AND_VERSION_WORKTREE_VENDOR\n'

# O build oficial instala apenas dependências ignoradas pelo Git e gera o
# pacote fora da árvore; nenhum arquivo é copiado para o checkout raiz.
exec "$ROOT/scripts/build.sh"
