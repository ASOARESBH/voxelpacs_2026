#!/usr/bin/env bash
# Preflight fechado e somente leitura para a materialização da bridge Philips.
# Não instala pacotes, não altera serviço, rede, firewall, WireGuard, SSH ou arquivos de produção.
set -euo pipefail

if [[ "${EUID}" -ne 0 || "$#" -ne 0 ]]; then
  printf 'Uso não permitido. Execute localmente como root, sem argumentos.\n' >&2
  exit 64
fi

readonly EXPECTED_HOST='gateway-dicom-01'
readonly EXPECTED_PRIVATE_IP='10.0.0.4'
readonly PACS_API_PRIVATE_IP='10.0.0.2'
readonly BRIDGE_PORT='8443'
readonly BRIDGE_UNIT='voxelpacs-philips-folder-bridge.service'
readonly BRIDGE_RUNTIME='/opt/voxelpacs/report-delivery-gateway/philips_folder_bridge.py'

local_hostname="$(hostname -s 2>/dev/null || true)"
private_match='no'
ip -4 -o addr show 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | grep -Fxq "$EXPECTED_PRIVATE_IP" && private_match='yes'

if ss -H -ltn 2>/dev/null | awk -v port=":${BRIDGE_PORT}" '$4 ~ (port "$") {found=1} END {exit(found ? 0 : 1)}'; then
  port_state='in_use'
else
  port_state='available'
fi

if ip route get "$PACS_API_PRIVATE_IP" 2>/dev/null | grep -Eq '(^|[[:space:]])(via|dev)[[:space:]]'; then
  pacs_route='present'
else
  pacs_route='not_confirmed'
fi

if [[ -e "/etc/systemd/system/${BRIDGE_UNIT}" || -e "/lib/systemd/system/${BRIDGE_UNIT}" ]]; then
  unit_state='existing'
else
  unit_state='absent'
fi

if [[ -e "$BRIDGE_RUNTIME" ]]; then
  runtime_state='existing'
else
  runtime_state='absent'
fi

printf '%s\n' '=== PHILIPS_BRIDGE_PREFLIGHT_READONLY ==='
printf 'PREFLIGHT_SCHEMA=%s\n' '1'
printf 'GATEWAY_HOSTNAME=%s\n' "$( [[ "$local_hostname" == "$EXPECTED_HOST" ]] && printf 'match' || printf 'mismatch' )"
printf 'GATEWAY_PRIVATE_IP=%s\n' "$private_match"
printf 'PACS_API_ROUTE=%s\n' "$pacs_route"
printf 'BRIDGE_PORT_8443=%s\n' "$port_state"
printf 'BRIDGE_UNIT_COLLISION=%s\n' "$unit_state"
printf 'BRIDGE_RUNTIME_COLLISION=%s\n' "$runtime_state"
printf 'PYTHON3=%s\n' "$(command -v python3 >/dev/null 2>&1 && printf 'available' || printf 'absent')"
printf 'PYTHON_CRYPTOGRAPHY=%s\n' "$(python3 -c 'import cryptography' >/dev/null 2>&1 && printf 'available' || printf 'absent')"
printf 'OPENSSL=%s\n' "$(command -v openssl >/dev/null 2>&1 && printf 'available' || printf 'absent')"
printf 'SYSTEMD=%s\n' "$(command -v systemctl >/dev/null 2>&1 && printf 'available' || printf 'absent')"
printf 'SMBCLIENT=%s\n' "$(command -v smbclient >/dev/null 2>&1 && printf 'available' || printf 'absent')"
printf '%s\n' 'PHILIPS_BRIDGE_PREFLIGHT_READONLY_OK'
