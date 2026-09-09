#!/usr/bin/env bash
# Instala exclusivamente o subcomando SSH `downloads-upload-diagnose`.
# Execute uma única vez como root; o subcomando apenas lê limites de upload.
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  printf 'Este instalador precisa ser executado como root.\n' >&2
  exit 77
fi

readonly FORCE_COMMAND=/usr/local/sbin/voxelpacs-deploy-force
readonly RUNNER=/usr/local/sbin/voxelpacs-downloads-upload-diagnose
readonly SUDOERS_FILE=/etc/sudoers.d/voxelpacs-downloads-upload-diagnose
readonly MARKER='# DOWNLOADS_UPLOAD_DIAGNOSE_EXACT_COMMAND'

test -x "$FORCE_COMMAND"

cat > "$RUNNER" <<'RUNNER_EOF'
#!/usr/bin/env bash
# Comando fechado: sem argumentos; somente mostra limites efetivos de upload sanitizados.
set -euo pipefail

if [[ "$#" -ne 0 || "${EUID}" -ne 0 ]]; then
  printf 'Uso não permitido.\n' >&2
  exit 64
fi

printf '%s\n' '=== NGINX_CLIENT_BODY_LIMITS ==='
nginx -T 2>&1 | awk '
  /^# configuration file / { source = $4; sub(/:$/, "", source); next }
  /^[[:space:]]*client_max_body_size[[:space:]]+/ {
    value = $2; sub(/;$/, "", value)
    printf "limit=%s;source=%s\n", value, source
  }
'

printf '%s\n' '=== PHP_FPM_UPLOAD_LIMITS ==='
php-fpm8.3 -i 2>/dev/null | awk '
  /^upload_max_filesize[[:space:]]*=>/ || /^post_max_size[[:space:]]*=>/ { print }
'

printf '%s\n' '=== SERVICE_STATUS ==='
systemctl is-active nginx
systemctl is-active php8.3-fpm
printf 'DOWNLOADS_UPLOAD_DIAGNOSTIC_OK\n'
RUNNER_EOF

chown root:root "$RUNNER"
chmod 0750 "$RUNNER"
bash -n "$RUNNER"

if ! grep -Fqx "$MARKER" "$FORCE_COMMAND"; then
  grep -Fqx 'set -euo pipefail' "$FORCE_COMMAND"
  backup_force="${FORCE_COMMAND}.before-downloads-upload-diagnose-$(date -u +%Y%m%dT%H%M%SZ)"
  cp -p "$FORCE_COMMAND" "$backup_force"
  temp_force="$(mktemp)"
  awk -v marker="$MARKER" '
    { print }
    $0 == "set -euo pipefail" {
      print marker
      print "if [[ \"${SSH_ORIGINAL_COMMAND:-}\" == \"downloads-upload-diagnose\" ]]; then"
      print "  exec sudo /usr/local/sbin/voxelpacs-downloads-upload-diagnose"
      print "fi"
    }
  ' "$FORCE_COMMAND" > "$temp_force"
  grep -Fqx "$MARKER" "$temp_force"
  install -o root -g root -m 0755 "$temp_force" "$FORCE_COMMAND"
  rm -f "$temp_force"
fi

printf 'voxeldeploy ALL=(root) NOPASSWD: /usr/local/sbin/voxelpacs-downloads-upload-diagnose\n' > "$SUDOERS_FILE"
chown root:root "$SUDOERS_FILE"
chmod 0440 "$SUDOERS_FILE"
visudo -cf "$SUDOERS_FILE" >/dev/null
bash -n "$FORCE_COMMAND"
printf 'DOWNLOADS_UPLOAD_DIAGNOSTIC_COMMAND_READY\n'
