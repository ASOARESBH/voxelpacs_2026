#!/usr/bin/env bash
set -euo pipefail

# Executar a partir da raiz do repositório antes de empacotar portal-viewer.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TARGET="${1:-$ROOT/../ohif-portal-static/viewer.ohif.org}"

[[ -f "$TARGET/index.html" ]] || {
  echo "Entrada estática do Viewer não encontrada: $TARGET/index.html" >&2
  exit 1
}

install -m 0644 "$ROOT/infrastructure/portal-ohif/voxel-patient-viewer.css" "$TARGET/voxel-patient-viewer.css"
install -m 0644 "$ROOT/infrastructure/portal-ohif/voxel-patient-viewer.js" "$TARGET/voxel-patient-viewer.js"

if ! grep -q 'voxel-patient-viewer.css' "$TARGET/index.html"; then
  perl -0pi -e 's#<link href="app\.bundle\.css" rel="stylesheet">#<link href="app.bundle.css" rel="stylesheet"><link href="voxel-patient-viewer.css" rel="stylesheet">#' "$TARGET/index.html"
fi
if ! grep -q 'voxel-patient-viewer.js' "$TARGET/index.html"; then
  perl -0pi -e 's#<script defer src="app\.bundle\.b62f33e07e99aff8\.js"></script>#<script defer src="app.bundle.b62f33e07e99aff8.js"></script><script defer src="voxel-patient-viewer.js"></script>#' "$TARGET/index.html"
fi

grep -q 'voxel-patient-viewer.css' "$TARGET/index.html"
grep -q 'voxel-patient-viewer.js' "$TARGET/index.html"
printf 'VOXEL_PATIENT_VIEWER_ASSETS_READY\n'
