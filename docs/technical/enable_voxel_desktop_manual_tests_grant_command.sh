#!/usr/bin/env bash
# Instala exclusivamente o subcomando SSH `voxel-desktop-manual-tests-grant`.
# Execute uma única vez como root. Este instalador não concede privilégios por si só.
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  printf 'Este instalador precisa ser executado como root.\n' >&2
  exit 77
fi

readonly APP_ROOT=/var/www/voxelpacs/app
readonly FORCE_COMMAND=/usr/local/sbin/voxelpacs-deploy-force
readonly RUNNER=/usr/local/sbin/voxelpacs-voxel-desktop-manual-tests-grant
readonly SUDOERS_FILE=/etc/sudoers.d/voxelpacs-voxel-desktop-manual-tests-grant
readonly EXPECTED_SCHEMA=voxelpacs_mysql_source
readonly MARKER='# VOXEL_DESKTOP_MANUAL_TESTS_GRANT_EXACT_COMMAND'

test -d "$APP_ROOT"
test -x "$FORCE_COMMAND"

cat > "$RUNNER" <<'RUNNER_EOF'
#!/usr/bin/env bash
# Comando fechado: sem argumentos; concede somente privilégios mínimos do registro de teste manual.
set -euo pipefail

if [[ "$#" -ne 0 || "${EUID}" -ne 0 ]]; then
  printf 'Uso não permitido.\n' >&2
  exit 64
fi

readonly APP_ROOT=/var/www/voxelpacs/app
readonly ENV_FILE="$APP_ROOT/.env"
readonly EXPECTED_SCHEMA=voxelpacs_mysql_source

env_value() {
  local key="$1"
  awk -v key="$key" '
    $0 ~ "^[[:space:]]*" key "[[:space:]]*=" {
      value = $0
      sub("^[[:space:]]*" key "[[:space:]]*=[[:space:]]*", "", value)
      sub("[[:space:]]+$", "", value)
      if ((substr(value, 1, 1) == "\"" && substr(value, length(value), 1) == "\"") || (substr(value, 1, 1) == "\047" && substr(value, length(value), 1) == "\047")) value = substr(value, 2, length(value) - 2)
    }
    END { print value }
  ' "$ENV_FILE"
}

test -s "$ENV_FILE"
test "$(env_value DB_DRIVER)" = 'pgsql'
db_name="$(env_value DB_DATABASE)"
schema="$(env_value DB_SCHEMA)"
app_role="$(env_value DB_USERNAME)"
test -n "$db_name"
test "$schema" = "$EXPECTED_SCHEMA"
[[ "$app_role" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]

table_exists="$(sudo -u postgres psql -X -v ON_ERROR_STOP=1 -At -d "$db_name" -c "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='${EXPECTED_SCHEMA}' AND table_name='pacs_voxel_desktop_manual_tests');")"
test "$table_exists" = 't'

sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v app_role="$app_role" -d "$db_name" <<'SQL'
GRANT USAGE ON SCHEMA voxelpacs_mysql_source TO :"app_role";
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE voxelpacs_mysql_source.pacs_voxel_desktop_manual_tests TO :"app_role";
GRANT USAGE, SELECT ON SEQUENCE voxelpacs_mysql_source.pacs_voxel_desktop_manual_tests_id_seq TO :"app_role";
SQL

result="$(sudo -u postgres psql -X -v ON_ERROR_STOP=1 -At -d "$db_name" -c "SELECT CASE WHEN has_schema_privilege('${app_role}', '${EXPECTED_SCHEMA}', 'USAGE') AND has_table_privilege('${app_role}', '${EXPECTED_SCHEMA}.pacs_voxel_desktop_manual_tests', 'SELECT, INSERT, UPDATE, DELETE') AND has_sequence_privilege('${app_role}', '${EXPECTED_SCHEMA}.pacs_voxel_desktop_manual_tests_id_seq', 'USAGE, SELECT') THEN 'VOXEL_DESKTOP_MANUAL_TESTS_PRIVILEGES_OK' ELSE 'VOXEL_DESKTOP_MANUAL_TESTS_PRIVILEGES_FAILED' END;")"
test "$result" = 'VOXEL_DESKTOP_MANUAL_TESTS_PRIVILEGES_OK'
printf '%s\n' "$result"
RUNNER_EOF

chown root:root "$RUNNER"
chmod 0750 "$RUNNER"
bash -n "$RUNNER"

if ! grep -Fqx "$MARKER" "$FORCE_COMMAND"; then
  grep -Fqx 'set -euo pipefail' "$FORCE_COMMAND"
  backup_force="${FORCE_COMMAND}.before-voxel-desktop-manual-tests-grant-$(date -u +%Y%m%dT%H%M%SZ)"
  cp -p "$FORCE_COMMAND" "$backup_force"
  temp_force="$(mktemp)"
  awk -v marker="$MARKER" '
    { print }
    $0 == "set -euo pipefail" {
      print marker
      print "if [[ \"${SSH_ORIGINAL_COMMAND:-}\" == \"voxel-desktop-manual-tests-grant\" ]]; then"
      print "  exec sudo /usr/local/sbin/voxelpacs-voxel-desktop-manual-tests-grant"
      print "fi"
    }
  ' "$FORCE_COMMAND" > "$temp_force"
  grep -Fqx "$MARKER" "$temp_force"
  install -o root -g root -m 0755 "$temp_force" "$FORCE_COMMAND"
  rm -f "$temp_force"
fi

printf 'voxeldeploy ALL=(root) NOPASSWD: /usr/local/sbin/voxelpacs-voxel-desktop-manual-tests-grant\n' > "$SUDOERS_FILE"
chown root:root "$SUDOERS_FILE"
chmod 0440 "$SUDOERS_FILE"
visudo -cf "$SUDOERS_FILE" >/dev/null
bash -n "$FORCE_COMMAND"
printf 'VOXEL_DESKTOP_MANUAL_TESTS_GRANT_COMMAND_READY\n'
