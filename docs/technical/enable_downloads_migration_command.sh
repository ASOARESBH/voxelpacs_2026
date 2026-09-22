#!/usr/bin/env bash
# Instala exclusivamente o subcomando SSH `downloads-migration` para a migration
# PostgreSQL do catálogo VOXEL Desktop Downloads. Execute uma única vez como root.
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  printf 'Este instalador precisa ser executado como root.\n' >&2
  exit 77
fi

readonly APP_ROOT=/var/www/voxelpacs/app
readonly FORCE_COMMAND=/usr/local/sbin/voxelpacs-deploy-force
readonly RUNNER=/usr/local/sbin/voxelpacs-downloads-migration
readonly SUDOERS_FILE=/etc/sudoers.d/voxelpacs-downloads-migration
readonly MIGRATION_REL=database/migrations/2026-09-09_desktop_download_catalog_postgresql.sql
readonly MIGRATION_SHA256=668ae9710a6a4e3c682b6056d8a73ba28087f912a756e31a6472c985a4d3f126
readonly EXPECTED_SCHEMA=voxelpacs_mysql_source
readonly MARKER='# DOWNLOADS_MIGRATION_EXACT_COMMAND'

test -d "$APP_ROOT"
test -x "$FORCE_COMMAND"

cat > "$RUNNER" <<'RUNNER_EOF'
#!/usr/bin/env bash
# Comando fechado: sem argumentos, sem SQL arbitrário, sem schema variável.
set -euo pipefail

if [[ "$#" -ne 0 || "${EUID}" -ne 0 ]]; then
  printf 'Uso não permitido.\n' >&2
  exit 64
fi

readonly APP_ROOT=/var/www/voxelpacs/app
readonly ENV_FILE="$APP_ROOT/.env"
readonly MIGRATION="$APP_ROOT/database/migrations/2026-09-09_desktop_download_catalog_postgresql.sql"
readonly EXPECTED_SHA256=668ae9710a6a4e3c682b6056d8a73ba28087f912a756e31a6472c985a4d3f126
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
test -s "$MIGRATION"
test "$(sha256sum "$MIGRATION" | awk '{print $1}')" = "$EXPECTED_SHA256"
test "$(env_value DB_DRIVER)" = "pgsql"
db_name="$(env_value DB_DATABASE)"
schema="$(env_value DB_SCHEMA)"
test -n "$db_name"
test "$schema" = "$EXPECTED_SCHEMA"

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_dir="/var/backups/voxelpacs/migrations/downloads-catalog-${stamp}"
dump_file="$backup_dir/${schema}-before-downloads-catalog.dump"
install -d -o postgres -g postgres -m 700 "$backup_dir"
sudo -u postgres pg_dump -Fc --no-owner -d "$db_name" -n "$schema" -f "$dump_file"
chown root:root "$dump_file"
chmod 600 "$dump_file"
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d "$db_name" -f "$MIGRATION"
tables="$(sudo -u postgres psql -X -At -d "$db_name" -c "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${EXPECTED_SCHEMA}' AND table_name IN ('bi_desktop_release_packages', 'bi_desktop_download_events');")"
if [[ "$tables" != '2' ]]; then
  printf 'DOWNLOADS_CATALOG_MIGRATION_INCOMPLETA\n' >&2
  exit 1
fi
printf 'DOWNLOADS_CATALOG_MIGRATION_OK\nBACKUP=%s\n' "$dump_file"
RUNNER_EOF

chown root:root "$RUNNER"
chmod 0750 "$RUNNER"
php -l "$APP_ROOT/app/Core/Database.php" >/dev/null
bash -n "$RUNNER"

if ! grep -Fqx "$MARKER" "$FORCE_COMMAND"; then
  grep -Fqx 'set -euo pipefail' "$FORCE_COMMAND"
  backup_force="${FORCE_COMMAND}.before-downloads-migration-$(date -u +%Y%m%dT%H%M%SZ)"
  cp -p "$FORCE_COMMAND" "$backup_force"
  temp_force="$(mktemp)"
  awk -v marker="$MARKER" '
    { print }
    $0 == "set -euo pipefail" {
      print marker
      print "if [[ \"${SSH_ORIGINAL_COMMAND:-}\" == \"downloads-migration\" ]]; then"
      print "  exec sudo /usr/local/sbin/voxelpacs-downloads-migration"
      print "fi"
    }
  ' "$FORCE_COMMAND" > "$temp_force"
  grep -Fqx "$MARKER" "$temp_force"
  install -o root -g root -m 0755 "$temp_force" "$FORCE_COMMAND"
  rm -f "$temp_force"
fi

printf 'voxeldeploy ALL=(root) NOPASSWD: /usr/local/sbin/voxelpacs-downloads-migration\n' > "$SUDOERS_FILE"
chown root:root "$SUDOERS_FILE"
chmod 0440 "$SUDOERS_FILE"
visudo -cf "$SUDOERS_FILE" >/dev/null
bash -n "$FORCE_COMMAND"
printf 'DOWNLOADS_MIGRATION_COMMAND_READY\n'
