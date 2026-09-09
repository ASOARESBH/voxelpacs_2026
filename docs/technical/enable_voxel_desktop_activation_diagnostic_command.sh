#!/usr/bin/env bash
# Instala exclusivamente o subcomando SSH `voxel-desktop-activation-diagnose`.
# Execute uma única vez como root; o subcomando somente lê metadados de schema e classes de erro sanitizadas.
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  printf 'Este instalador precisa ser executado como root.\n' >&2
  exit 77
fi

readonly APP_ROOT=/var/www/voxelpacs/app
readonly FORCE_COMMAND=/usr/local/sbin/voxelpacs-deploy-force
readonly RUNNER=/usr/local/sbin/voxelpacs-voxel-desktop-activation-diagnose
readonly SUDOERS_FILE=/etc/sudoers.d/voxelpacs-voxel-desktop-activation-diagnose
readonly EXPECTED_REPOSITORY_SHA256=feb9e505da5eb214192e141ca2872dc067202343a0899122a3d724eeb5e571df
readonly MARKER='# VOXEL_DESKTOP_ACTIVATION_DIAGNOSE_EXACT_COMMAND'

test -d "$APP_ROOT"
test -x "$FORCE_COMMAND"

cat > "$RUNNER" <<'RUNNER_EOF'
#!/usr/bin/env bash
# Comando fechado: sem argumentos; leitura sanitizada da falha de habilitação do Voxel Desktop.
set -euo pipefail

if [[ "$#" -ne 0 || "${EUID}" -ne 0 ]]; then
  printf 'Uso não permitido.\n' >&2
  exit 64
fi

readonly APP_ROOT=/var/www/voxelpacs/app
readonly ENV_FILE="$APP_ROOT/.env"
readonly EXPECTED_SCHEMA=voxelpacs_mysql_source
readonly EXPECTED_REPOSITORY_SHA256=feb9e505da5eb214192e141ca2872dc067202343a0899122a3d724eeb5e571df

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
test -n "$db_name"
test "$schema" = "$EXPECTED_SCHEMA"

printf '%s\n' '=== ACTIVATION_RUNTIME_VERSION ==='
if test "$(sha256sum "$APP_ROOT/app/Repositories/VoxelDesktopRepository.php" | awk '{print $1}')" = "$EXPECTED_REPOSITORY_SHA256"; then
  printf '%s\n' 'ACTIVATION_RUNTIME_REPOSITORY_CURRENT'
else
  printf '%s\n' 'ACTIVATION_RUNTIME_REPOSITORY_STALE'
fi

printf '%s\n' '=== ACTIVATION_SCHEMA ==='
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -At -d "$db_name" -c "SELECT CASE WHEN EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='${EXPECTED_SCHEMA}' AND table_name='pacs_voxel_desktop_destinations' AND column_name='enabled' AND data_type='boolean') THEN 'ACTIVATION_ENABLED_BOOLEAN_OK' ELSE 'ACTIVATION_ENABLED_BOOLEAN_INVALID' END;"

printf '%s\n' '=== ACTIVATION_QUERY_PARSE ==='
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -At -d "$db_name" <<SQL
BEGIN;
PREPARE voxel_desktop_activation_check(boolean, bigint, bigint) AS
  UPDATE ${EXPECTED_SCHEMA}.pacs_voxel_desktop_destinations
     SET enabled=\$1, disparar_na_liberacao=FALSE, updated_at=NOW()
   WHERE id=\$2 AND tenant_id=\$3;
EXECUTE voxel_desktop_activation_check(TRUE, 0, 0);
ROLLBACK;
SQL
printf '%s\n' 'ACTIVATION_QUERY_PARSE_OK'

printf '%s\n' '=== ACTIVATION_LOG_CLASS ==='
log="$APP_ROOT/storage/logs/error-$(date +%F).log"
if test -r "$log" && grep -E 'VoxelDesktopController::activate|voxel-desktop/destinations/.*/activate|VoxelDesktopRepository' "$log" >/dev/null 2>&1; then
  activation_line="$(grep -E 'VoxelDesktopController::activate|voxel-desktop/destinations/.*/activate|VoxelDesktopRepository' "$log" | tail -n 1)"
  case "$activation_line" in
    *'SqlHelper::hasTable'*|*'SqlHelper\\\\hasTable'*) printf '%s\n' 'ACTIVATION_ERROR_SCHEMA_HELPER_ARGUMENTS' ;;
    *'boolean = integer'*|*'operator does not exist: boolean'*) printf '%s\n' 'ACTIVATION_ERROR_BOOLEAN_COMPARISON' ;;
    *'permission denied'*) printf '%s\n' 'ACTIVATION_ERROR_DATABASE_PERMISSION' ;;
    *'duplicate key'*|*'unique constraint'*) printf '%s\n' 'ACTIVATION_ERROR_DESTINATION_CONFLICT' ;;
    *) printf '%s\n' 'ACTIVATION_ERROR_UNCLASSIFIED' ;;
  esac
  printf '%s\n' 'ACTIVATION_LOG_MATCHED'
else
  printf '%s\n' 'ACTIVATION_LOG_NO_MATCH'
fi
printf '%s\n' 'VOXEL_DESKTOP_ACTIVATION_DIAGNOSTIC_OK'
RUNNER_EOF

chown root:root "$RUNNER"
chmod 0750 "$RUNNER"
bash -n "$RUNNER"

if ! grep -Fqx "$MARKER" "$FORCE_COMMAND"; then
  grep -Fqx 'set -euo pipefail' "$FORCE_COMMAND"
  backup_force="${FORCE_COMMAND}.before-voxel-desktop-activation-diagnose-$(date -u +%Y%m%dT%H%M%SZ)"
  cp -p "$FORCE_COMMAND" "$backup_force"
  temp_force="$(mktemp)"
  awk -v marker="$MARKER" '
    { print }
    $0 == "set -euo pipefail" {
      print marker
      print "if [[ \"${SSH_ORIGINAL_COMMAND:-}\" == \"voxel-desktop-activation-diagnose\" ]]; then"
      print "  exec sudo /usr/local/sbin/voxelpacs-voxel-desktop-activation-diagnose"
      print "fi"
    }
  ' "$FORCE_COMMAND" > "$temp_force"
  grep -Fqx "$MARKER" "$temp_force"
  install -o root -g root -m 0755 "$temp_force" "$FORCE_COMMAND"
  rm -f "$temp_force"
fi

printf 'voxeldeploy ALL=(root) NOPASSWD: /usr/local/sbin/voxelpacs-voxel-desktop-activation-diagnose\n' > "$SUDOERS_FILE"
chown root:root "$SUDOERS_FILE"
chmod 0440 "$SUDOERS_FILE"
visudo -cf "$SUDOERS_FILE" >/dev/null
bash -n "$FORCE_COMMAND"
printf 'VOXEL_DESKTOP_ACTIVATION_DIAGNOSTIC_COMMAND_READY\n'
