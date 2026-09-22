#!/usr/bin/env bash
# Instala exclusivamente o subcomando SSH `downloads-upload-limits`.
# Execute uma única vez como root; o subcomando aplica limites de upload do catálogo Downloads.
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  printf 'Este instalador precisa ser executado como root.\n' >&2
  exit 77
fi

readonly FORCE_COMMAND=/usr/local/sbin/voxelpacs-deploy-force
readonly RUNNER=/usr/local/sbin/voxelpacs-downloads-upload-limits
readonly SUDOERS_FILE=/etc/sudoers.d/voxelpacs-downloads-upload-limits
readonly MARKER='# DOWNLOADS_UPLOAD_LIMITS_EXACT_COMMAND'

test -x "$FORCE_COMMAND"

cat > "$RUNNER" <<'RUNNER_EOF'
#!/usr/bin/env bash
# Comando fechado: sem argumentos; somente configura os limites de upload do catálogo Downloads.
set -euo pipefail

if [[ "$#" -ne 0 || "${EUID}" -ne 0 ]]; then
  printf 'Uso não permitido.\n' >&2
  exit 64
fi

readonly NGINX_CONF=/etc/nginx/conf.d/zz-voxelpacs-downloads-upload.conf
readonly PHP_CONF=/etc/php/8.3/fpm/conf.d/99-voxelpacs-downloads-upload.ini
readonly BACKUP_ROOT=/var/backups/voxelpacs/infrastructure
readonly UPLOAD_LIMIT=1024M
readonly BODY_LIMIT=1100M

nginx -T 2>&1 | grep -Fq 'include /etc/nginx/conf.d/*.conf;'
test -d /etc/php/8.3/fpm/conf.d
install -d -o root -g root -m 0700 "$BACKUP_ROOT"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_dir="$BACKUP_ROOT/downloads-upload-limits-${stamp}"
install -d -o root -g root -m 0700 "$backup_dir"

if test -f "$NGINX_CONF"; then install -m 0600 "$NGINX_CONF" "$backup_dir/nginx.conf"; fi
if test -f "$PHP_CONF"; then install -m 0600 "$PHP_CONF" "$backup_dir/php.ini"; fi

cat > "$NGINX_CONF" <<'NGINX_EOF'
# VOXEL Downloads: suporta ZIP privado de até 1 GiB mais overhead multipart.
client_max_body_size 1100M;
NGINX_EOF

cat > "$PHP_CONF" <<'PHP_EOF'
; VOXEL Downloads: limite da aplicação é 1 GiB; post suporta overhead multipart.
upload_max_filesize = 1024M
post_max_size = 1100M
max_input_time = 3600
max_execution_time = 3600
PHP_EOF

chown root:root "$NGINX_CONF" "$PHP_CONF"
chmod 0644 "$NGINX_CONF" "$PHP_CONF"
nginx -t
php-fpm8.3 -t
systemctl reload nginx
systemctl reload php8.3-fpm
systemctl is-active --quiet nginx
systemctl is-active --quiet php8.3-fpm
nginx -T 2>&1 | grep -Fq 'client_max_body_size 1100M;'
grep -Fqx 'upload_max_filesize = 1024M' "$PHP_CONF"
grep -Fqx 'post_max_size = 1100M' "$PHP_CONF"
printf 'DOWNLOADS_UPLOAD_LIMITS_OK\n'
RUNNER_EOF

chown root:root "$RUNNER"
chmod 0750 "$RUNNER"
bash -n "$RUNNER"

if ! grep -Fqx "$MARKER" "$FORCE_COMMAND"; then
  grep -Fqx 'set -euo pipefail' "$FORCE_COMMAND"
  backup_force="${FORCE_COMMAND}.before-downloads-upload-limits-$(date -u +%Y%m%dT%H%M%SZ)"
  cp -p "$FORCE_COMMAND" "$backup_force"
  temp_force="$(mktemp)"
  awk -v marker="$MARKER" '
    { print }
    $0 == "set -euo pipefail" {
      print marker
      print "if [[ \"${SSH_ORIGINAL_COMMAND:-}\" == \"downloads-upload-limits\" ]]; then"
      print "  exec sudo /usr/local/sbin/voxelpacs-downloads-upload-limits"
      print "fi"
    }
  ' "$FORCE_COMMAND" > "$temp_force"
  grep -Fqx "$MARKER" "$temp_force"
  install -o root -g root -m 0755 "$temp_force" "$FORCE_COMMAND"
  rm -f "$temp_force"
fi

printf 'voxeldeploy ALL=(root) NOPASSWD: /usr/local/sbin/voxelpacs-downloads-upload-limits\n' > "$SUDOERS_FILE"
chown root:root "$SUDOERS_FILE"
chmod 0440 "$SUDOERS_FILE"
visudo -cf "$SUDOERS_FILE" >/dev/null
bash -n "$FORCE_COMMAND"
printf 'DOWNLOADS_UPLOAD_LIMITS_COMMAND_READY\n'
