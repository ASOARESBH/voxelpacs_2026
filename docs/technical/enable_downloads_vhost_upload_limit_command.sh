#!/usr/bin/env bash
# Instala exclusivamente o subcomando SSH `downloads-vhost-upload-limit`.
# Execute uma única vez como root; o subcomando altera somente o limite dominante identificado.
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  printf 'Este instalador precisa ser executado como root.\n' >&2
  exit 77
fi

readonly FORCE_COMMAND=/usr/local/sbin/voxelpacs-deploy-force
readonly RUNNER=/usr/local/sbin/voxelpacs-downloads-vhost-upload-limit
readonly SUDOERS_FILE=/etc/sudoers.d/voxelpacs-downloads-vhost-upload-limit
readonly MARKER='# DOWNLOADS_VHOST_UPLOAD_LIMIT_EXACT_COMMAND'

test -x "$FORCE_COMMAND"

cat > "$RUNNER" <<'RUNNER_EOF'
#!/usr/bin/env bash
# Comando fechado: sem argumentos; atualiza somente o limite Nginx dominante do domínio VOXEL.
set -euo pipefail

if [[ "$#" -ne 0 || "${EUID}" -ne 0 ]]; then
  printf 'Uso não permitido.\n' >&2
  exit 64
fi

readonly VHOST_CONF=/etc/nginx/sites-enabled/voxel-api.conf
readonly BACKUP_ROOT=/var/backups/voxelpacs/infrastructure
readonly OLD_LIMIT='client_max_body_size 50m;'
readonly NEW_LIMIT='client_max_body_size 1100M;'

test -f "$VHOST_CONF"
install -d -o root -g root -m 0700 "$BACKUP_ROOT"
count="$(grep -Fxc "$OLD_LIMIT" "$VHOST_CONF" || true)"
test "$count" = '1'

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_dir="$BACKUP_ROOT/downloads-vhost-upload-limit-${stamp}"
install -d -o root -g root -m 0700 "$backup_dir"
install -m 0600 "$VHOST_CONF" "$backup_dir/voxel-api.conf"

temp_conf="$(mktemp)"
sed "s|^${OLD_LIMIT}$|${NEW_LIMIT}|" "$VHOST_CONF" > "$temp_conf"
test "$(grep -Fxc "$NEW_LIMIT" "$temp_conf" || true)" = '1'
test "$(grep -Fxc "$OLD_LIMIT" "$temp_conf" || true)" = '0'
install -o root -g root -m 0644 "$temp_conf" "$VHOST_CONF"
rm -f "$temp_conf"

nginx -t
systemctl reload nginx
systemctl is-active --quiet nginx
test "$(grep -Fxc "$NEW_LIMIT" "$VHOST_CONF" || true)" = '1'
nginx -T 2>&1 | awk '
  /^# configuration file \/etc\/nginx\/sites-enabled\/voxel-api\.conf:/ { source = 1; next }
  /^# configuration file / { source = 0 }
  source && /^[[:space:]]*client_max_body_size[[:space:]]+1100M;/ { found = 1 }
  END { exit(found ? 0 : 1) }
'
printf 'DOWNLOADS_VHOST_UPLOAD_LIMIT_OK\n'
RUNNER_EOF

chown root:root "$RUNNER"
chmod 0750 "$RUNNER"
bash -n "$RUNNER"

if ! grep -Fqx "$MARKER" "$FORCE_COMMAND"; then
  grep -Fqx 'set -euo pipefail' "$FORCE_COMMAND"
  backup_force="${FORCE_COMMAND}.before-downloads-vhost-upload-limit-$(date -u +%Y%m%dT%H%M%SZ)"
  cp -p "$FORCE_COMMAND" "$backup_force"
  temp_force="$(mktemp)"
  awk -v marker="$MARKER" '
    { print }
    $0 == "set -euo pipefail" {
      print marker
      print "if [[ \"${SSH_ORIGINAL_COMMAND:-}\" == \"downloads-vhost-upload-limit\" ]]; then"
      print "  exec sudo /usr/local/sbin/voxelpacs-downloads-vhost-upload-limit"
      print "fi"
    }
  ' "$FORCE_COMMAND" > "$temp_force"
  grep -Fqx "$MARKER" "$temp_force"
  install -o root -g root -m 0755 "$temp_force" "$FORCE_COMMAND"
  rm -f "$temp_force"
fi

printf 'voxeldeploy ALL=(root) NOPASSWD: /usr/local/sbin/voxelpacs-downloads-vhost-upload-limit\n' > "$SUDOERS_FILE"
chown root:root "$SUDOERS_FILE"
chmod 0440 "$SUDOERS_FILE"
visudo -cf "$SUDOERS_FILE" >/dev/null
bash -n "$FORCE_COMMAND"
printf 'DOWNLOADS_VHOST_UPLOAD_LIMIT_COMMAND_READY\n'
