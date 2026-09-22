#!/usr/bin/env bash
# Instala exclusivamente o subcomando SSH `voxel-desktop-manual-tests-migration`.
# Execute uma única vez como root. Este instalador não executa SQL por si só.
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  printf 'Este instalador precisa ser executado como root.\n' >&2
  exit 77
fi

readonly APP_ROOT=/var/www/voxelpacs/app
readonly FORCE_COMMAND=/usr/local/sbin/voxelpacs-deploy-force
readonly RUNNER=/usr/local/sbin/voxelpacs-voxel-desktop-manual-tests-migration
readonly SUDOERS_FILE=/etc/sudoers.d/voxelpacs-voxel-desktop-manual-tests-migration
readonly MIGRATION_REL=database/migrations/2026-09-09_voxel_desktop_manual_tests_postgresql.sql
readonly MIGRATION_SHA256=41ce3b8ab43201bd1b27ef6f17b589221859923df85b0d4e09ea4258cd810d19
readonly EXPECTED_SCHEMA=voxelpacs_mysql_source
readonly MARKER='# VOXEL_DESKTOP_MANUAL_TESTS_MIGRATION_EXACT_COMMAND'

test -d "$APP_ROOT"
test -x "$FORCE_COMMAND"
test -s "$APP_ROOT/$MIGRATION_REL"
test "$(sha256sum "$APP_ROOT/$MIGRATION_REL" | awk '{print $1}')" = "$MIGRATION_SHA256"

cat > "$RUNNER" <<'RUNNER_EOF'
#!/usr/bin/env bash
# Comando fechado: sem argumentos; aplica somente a migration do teste manual isolado.
set -euo pipefail

if [[ "$#" -ne 0 || "${EUID}" -ne 0 ]]; then
  printf 'Uso não permitido.\n' >&2
  exit 64
fi

readonly APP_ROOT=/var/www/voxelpacs/app
readonly ENV_FILE="$APP_ROOT/.env"
readonly MIGRATION="$APP_ROOT/database/migrations/2026-09-09_voxel_desktop_manual_tests_postgresql.sql"
readonly EXPECTED_SHA256=41ce3b8ab43201bd1b27ef6f17b589221859923df85b0d4e09ea4258cd810d19
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
test "$(env_value DB_DRIVER)" = 'pgsql'
db_name="$(env_value DB_DATABASE)"
schema="$(env_value DB_SCHEMA)"
test -n "$db_name"
test "$schema" = "$EXPECTED_SCHEMA"

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_dir="/var/backups/voxelpacs/migrations/voxel-desktop-manual-tests-${stamp}"
dump_file="$backup_dir/${schema}-before-manual-tests.dump"
install -d -o postgres -g postgres -m 0700 "$backup_dir"
sudo -u postgres pg_dump -Fc --no-owner -d "$db_name" -n "$schema" -f "$dump_file"
chown root:root "$dump_file"
chmod 0600 "$dump_file"
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d "$db_name" -f "$MIGRATION"

result="$(sudo -u postgres psql -X -v ON_ERROR_STOP=1 -At -d "$db_name" -c "SELECT CASE WHEN EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='${EXPECTED_SCHEMA}' AND table_name='pacs_voxel_desktop_manual_tests') AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='${EXPECTED_SCHEMA}' AND table_name='pacs_voxel_desktop_destinations' AND column_name='disparar_na_liberacao' AND COALESCE(column_default, '') LIKE 'false%') THEN 'VOXEL_DESKTOP_MANUAL_TESTS_MIGRATION_OK' ELSE 'VOXEL_DESKTOP_MANUAL_TESTS_MIGRATION_INCOMPLETA' END;")"
test "$result" = 'VOXEL_DESKTOP_MANUAL_TESTS_MIGRATION_OK'
printf '%s\nBACKUP=%s\n' "$result" "$dump_file"
RUNNER_EOF

sed -i "s/__MIGRATION_SHA256__/${MIGRATION_SHA256}/g" "$RUNNER"
chown root:root "$RUNNER"
chmod 0750 "$RUNNER"
bash -n "$RUNNER"

if ! grep -Fqx "$MARKER" "$FORCE_COMMAND"; then
  grep -Fqx 'set -euo pipefail' "$FORCE_COMMAND"
  backup_force="${FORCE_COMMAND}.before-voxel-desktop-manual-tests-$(date -u +%Y%m%dT%H%M%SZ)"
  cp -p "$FORCE_COMMAND" "$backup_force"
  temp_force="$(mktemp)"
  awk -v marker="$MARKER" '
    { print }
    $0 == "set -euo pipefail" {
      print marker
      print "if [[ \"${SSH_ORIGINAL_COMMAND:-}\" == \"voxel-desktop-manual-tests-migration\" ]]; then"
      print "  exec sudo /usr/local/sbin/voxelpacs-voxel-desktop-manual-tests-migration"
      print "fi"
    }
  ' "$FORCE_COMMAND" > "$temp_force"
  grep -Fqx "$MARKER" "$temp_force"
  install -o root -g root -m 0755 "$temp_force" "$FORCE_COMMAND"
  rm -f "$temp_force"
fi

printf 'voxeldeploy ALL=(root) NOPASSWD: /usr/local/sbin/voxelpacs-voxel-desktop-manual-tests-migration\n' > "$SUDOERS_FILE"
chown root:root "$SUDOERS_FILE"
chmod 0440 "$SUDOERS_FILE"
visudo -cf "$SUDOERS_FILE" >/dev/null
bash -n "$FORCE_COMMAND"
printf 'VOXEL_DESKTOP_MANUAL_TESTS_MIGRATION_COMMAND_READY\n'
