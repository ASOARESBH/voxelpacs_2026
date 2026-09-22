#!/usr/bin/env bash
# Instala exclusivamente o subcomando SSH `report-delivery-vpn-diagnose`.
# Execute uma única vez como root; o subcomando é somente leitura e sanitiza a saída.
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  printf 'Este instalador precisa ser executado como root.\n' >&2
  exit 77
fi

readonly FORCE_COMMAND=/usr/local/sbin/voxelpacs-deploy-force
readonly RUNNER=/usr/local/sbin/voxelpacs-report-delivery-vpn-diagnose
readonly SUDOERS_FILE=/etc/sudoers.d/voxelpacs-report-delivery-vpn-diagnose
readonly MARKER='# REPORT_DELIVERY_VPN_DIAGNOSE_EXACT_COMMAND'

test -x "$FORCE_COMMAND"

cat > "$RUNNER" <<'RUNNER_EOF'
#!/usr/bin/env bash
# Comando fechado: sem argumentos; apenas resume o caminho VPN de entrega sem dados de destino.
set -euo pipefail

if [[ "$#" -ne 0 || "${EUID}" -ne 0 ]]; then
  printf 'Uso não permitido.\n' >&2
  exit 64
fi

readonly BRIDGE_ENV=/etc/voxelpacs/report-delivery-gateway-bridge.env

service_state() {
  if systemctl is-active --quiet "$1"; then
    printf 'active'
  else
    printf 'inactive'
  fi
}

target_host=''
bind_port=''
if [[ -r "$BRIDGE_ENV" ]]; then
  target_host="$(grep -E '^[[:space:]]*BRIDGE_TARGET_HOST=' "$BRIDGE_ENV" | head -n1 | cut -d= -f2- | tr -d '[:space:]\"\047' || true)"
  bind_port="$(grep -E '^[[:space:]]*BRIDGE_BIND_PORT=' "$BRIDGE_ENV" | head -n1 | cut -d= -f2- | tr -d '[:space:]\"\047' || true)"
fi

printf '%s\n' '=== REPORT_DELIVERY_VPN ==='
printf 'WORKER_SERVICE=%s\n' "$(service_state voxelpacs-report-delivery-worker.service)"
printf 'BRIDGE_SERVICE=%s\n' "$(service_state voxelpacs-report-delivery-bridge.service)"

if command -v wg >/dev/null 2>&1 && ip link show dev wg0 >/dev/null 2>&1; then
  printf 'VPN_TECHNOLOGY=wireguard\n'
  if ip link show dev wg0 | grep -q 'state UP'; then
    printf 'VPN_INTERFACE_STATE=up\n'
  else
    printf 'VPN_INTERFACE_STATE=down\n'
  fi
  handshakes="$(wg show wg0 latest-handshakes 2>/dev/null | awk '$2 > 0 { count++ } END { print count + 0 }')"
  if [[ "$handshakes" -gt 0 ]]; then
    printf 'VPN_HANDSHAKE=observed\n'
  else
    printf 'VPN_HANDSHAKE=not_observed\n'
  fi
else
  printf 'VPN_TECHNOLOGY=wireguard_unavailable\n'
  printf 'VPN_INTERFACE_STATE=absent\n'
  printf 'VPN_HANDSHAKE=not_observed\n'
fi

if [[ "$target_host" =~ ^[A-Za-z0-9._-]+$ ]]; then
  route="$(ip route get "$target_host" 2>/dev/null || true)"
  if [[ "$route" == *' dev wg0 '* ]]; then
    printf 'GATEWAY_TO_RECEIVER_ROUTE=wireguard\n'
  elif [[ -n "$route" ]]; then
    printf 'GATEWAY_TO_RECEIVER_ROUTE=non_wireguard\n'
  else
    printf 'GATEWAY_TO_RECEIVER_ROUTE=unresolved\n'
  fi
else
  printf 'GATEWAY_TO_RECEIVER_ROUTE=policy_unavailable\n'
fi

if [[ "$bind_port" =~ ^[0-9]{1,5}$ ]] && ss -H -ltn 2>/dev/null | awk -v port="$bind_port" '$4 ~ (":" port "$") { found=1 } END { exit(found ? 0 : 1) }'; then
  printf 'PRIVATE_BRIDGE_LISTENER=active\n'
else
  printf 'PRIVATE_BRIDGE_LISTENER=inactive\n'
fi
printf 'REPORT_DELIVERY_VPN_DIAGNOSTIC_OK\n'
RUNNER_EOF

chown root:root "$RUNNER"
chmod 0750 "$RUNNER"
bash -n "$RUNNER"

if ! grep -Fqx "$MARKER" "$FORCE_COMMAND"; then
  grep -Fqx 'set -euo pipefail' "$FORCE_COMMAND"
  backup_force="${FORCE_COMMAND}.before-report-delivery-vpn-diagnose-$(date -u +%Y%m%dT%H%M%SZ)"
  cp -p "$FORCE_COMMAND" "$backup_force"
  temp_force="$(mktemp)"
  awk -v marker="$MARKER" '
    { print }
    $0 == "set -euo pipefail" {
      print marker
      print "if [[ \"${SSH_ORIGINAL_COMMAND:-}\" == \"report-delivery-vpn-diagnose\" ]]; then"
      print "  exec sudo /usr/local/sbin/voxelpacs-report-delivery-vpn-diagnose"
      print "fi"
    }
  ' "$FORCE_COMMAND" > "$temp_force"
  grep -Fqx "$MARKER" "$temp_force"
  install -o root -g root -m 0755 "$temp_force" "$FORCE_COMMAND"
  rm -f "$temp_force"
fi

printf 'voxeldeploy ALL=(root) NOPASSWD: /usr/local/sbin/voxelpacs-report-delivery-vpn-diagnose\n' > "$SUDOERS_FILE"
chown root:root "$SUDOERS_FILE"
chmod 0440 "$SUDOERS_FILE"
visudo -cf "$SUDOERS_FILE" >/dev/null
bash -n "$FORCE_COMMAND"
printf 'REPORT_DELIVERY_VPN_DIAGNOSTIC_COMMAND_READY\n'
