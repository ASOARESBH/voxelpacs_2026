#!/usr/bin/env bash
# Instala exclusivamente o subcomando SSH `downloads-grant` para os privilégios
# mínimos do catálogo VOXEL Desktop Downloads. Execute uma única vez como root.
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  printf 'Este instalador precisa ser executado como root.\n' >&2
  exit 77
fi

readonly APP_ROOT=/var/www/voxelpacs/app
readonly FORCE_COMMAND=/usr/local/sbin/voxelpacs-deploy-force
readonly RUNNER=/usr/local/sbin/voxelpacs-downloads-grant
readonly SUDOERS_FILE=/etc/sudoers.d/voxelpacs-downloads-grant
readonly MARKER='# DOWNLOADS_GRANT_EXACT_COMMAND'

test -d "$APP_ROOT"
test -x "$FORCE_COMMAND"

cat > "$RUNNER" <<'RUNNER_EOF'
#!/usr/bin/env bash
# Comando fechado: sem argumentos, somente grants em duas tabelas e duas sequências.
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
test "$(env_value DB_DRIVER)" = "pgsql"
db_name="$(env_value DB_DATABASE)"
schema="$(env_value DB_SCHEMA)"
app_role="$(env_value DB_USERNAME)"
test -n "$db_name"
test "$schema" = "$EXPECTED_SCHEMA"
[[ "$app_role" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]

sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v app_role="$app_role" -d "$db_name" <<'SQL'
GRANT USAGE ON SCHEMA voxelpacs_mysql_source TO :"app_role";
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE voxelpacs_mysql_source.bi_desktop_release_packages, voxelpacs_mysql_source.bi_desktop_download_events TO :"app_role";
GRANT USAGE, SELECT ON SEQUENCE voxelpacs_mysql_source.bi_desktop_release_packages_id_seq, voxelpacs_mysql_source.bi_desktop_download_events_id_seq TO :"app_role";
SQL

# app_role foi validado contra identificador PostgreSQL antes de interpolação.
result="$(sudo -u postgres psql -X -v ON_ERROR_STOP=1 -At -d "$db_name" -c "SELECT CASE WHEN has_schema_privilege('${app_role}', '${EXPECTED_SCHEMA}', 'USAGE') AND has_table_privilege('${app_role}', '${EXPECTED_SCHEMA}.bi_desktop_release_packages', 'SELECT, INSERT, UPDATE, DELETE') AND has_table_privilege('${app_role}', '${EXPECTED_SCHEMA}.bi_desktop_download_events', 'SELECT, INSERT, UPDATE, DELETE') AND has_sequence_privilege('${app_role}', '${EXPECTED_SCHEMA}.bi_desktop_release_packages_id_seq', 'USAGE, SELECT') AND has_sequence_privilege('${app_role}', '${EXPECTED_SCHEMA}.bi_desktop_download_events_id_seq', 'USAGE, SELECT') THEN 'DOWNLOADS_CATALOG_PRIVILEGES_OK' ELSE 'DOWNLOADS_CATALOG_PRIVILEGES_FAILED' END;")"
test "$result" = 'DOWNLOADS_CATALOG_PRIVILEGES_OK'
printf '%s\n' "$result"
RUNNER_EOF

chown root:root "$RUNNER"
chmod 0750 "$RUNNER"
bash -n "$RUNNER"

if ! grep -Fqx "$MARKER" "$FORCE_COMMAND"; then
  grep -Fqx 'set -euo pipefail' "$FORCE_COMMAND"
  backup_force="${FORCE_COMMAND}.before-downloads-grant-$(date -u +%Y%m%dT%H%M%SZ)"
  cp -p "$FORCE_COMMAND" "$backup_force"
  temp_force="$(mktemp)"
  awk -v marker="$MARKER" '
    { print }
    $0 == "set -euo pipefail" {
      print marker
      print "if [[ \"${SSH_ORIGINAL_COMMAND:-}\" == \"downloads-grant\" ]]; then"
      print "  exec sudo /usr/local/sbin/voxelpacs-downloads-grant"
      print "fi"
    }
  ' "$FORCE_COMMAND" > "$temp_force"
  grep -Fqx "$MARKER" "$temp_force"
  install -o root -g root -m 0755 "$temp_force" "$FORCE_COMMAND"
  rm -f "$temp_force"
fi

printf 'voxeldeploy ALL=(root) NOPASSWD: /usr/local/sbin/voxelpacs-downloads-grant\n' > "$SUDOERS_FILE"
chown root:root "$SUDOERS_FILE"
chmod 0440 "$SUDOERS_FILE"
visudo -cf "$SUDOERS_FILE" >/dev/null
bash -n "$FORCE_COMMAND"
printf 'DOWNLOADS_GRANT_COMMAND_READY\n'
