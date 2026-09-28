#!/usr/bin/env bash
# VOXEL PACS — compatibilidade para o builder oficial do artefato runtime.
set -Eeuo pipefail

readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
exec bash "$SCRIPT_DIR/build-runtime-artifact.sh" "$@"
