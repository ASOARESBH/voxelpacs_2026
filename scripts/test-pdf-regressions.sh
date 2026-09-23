#!/usr/bin/env bash
set -Eeuo pipefail

tests=(
  tests/reports_pdf_layout_render_static.php
  tests/reports_pdf_qr_a4_static.php
  tests/reports_moderno_lateral_mascara.php
  tests/reports_moderno_lateral_orix.php
  tests/reports_minimalista_footer.php
  tests/report_custom_templates_static.php
  tests/report_pdf_snapshot_path_static.php
  tests/report_pdf_snapshot_static.php
  tests/reports_pdf_legacy_snapshot_static.php
  tests/report_version_pdf_snapshot_static.php
  tests/report_version_pdf_revision_static.php
  tests/report_pdf_revision_viewer_static.php
  tests/report_pdf_unit_branding_static.php
  tests/report_delivery_pdf_visual_static.php
  tests/report_delivery_pdf_revision_link_static.php
)

for test in "${tests[@]}"; do
  printf 'PDF_REGRESSION_TEST=%s\n' "$test"
  php "$test"
done

printf 'PDF_REGRESSIONS_OK=%s\n' "${#tests[@]}"
