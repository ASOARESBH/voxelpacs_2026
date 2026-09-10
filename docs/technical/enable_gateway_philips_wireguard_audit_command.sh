#!/usr/bin/env bash
# Materializa exclusivamente o executável local root-only
# `voxelpacs-gateway-philips-wireguard-audit` no gateway-dicom-01.
# Não pressupõe componentes externos de acesso ou despacho existentes.
# O executável é sem argumentos, somente leitura e emite classificações sanitizadas.
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  printf 'Este instalador precisa ser executado como root local.\n' >&2
  exit 77
fi

readonly RUNNER=/usr/local/sbin/voxelpacs-gateway-philips-wireguard-audit

cat > "$RUNNER" <<'RUNNER_EOF'
#!/usr/bin/env bash
# Comando fechado: sem argumentos; não altera rede, firewall, serviços, SSH ou arquivos de produção.
set -euo pipefail

if [[ "$#" -ne 0 || "${EUID}" -ne 0 ]]; then
  printf 'Uso não permitido.\n' >&2
  exit 64
fi

readonly TARGET_CIDR='10.201.10.0/24'
readonly TMP_DIR="$(mktemp -d)"
cleanup() {
  find "$TMP_DIR" -mindepth 1 -maxdepth 1 -type f -delete 2>/dev/null || true
  rmdir "$TMP_DIR" 2>/dev/null || true
}
trap cleanup EXIT

classify_json_overlap() {
  local mode="$1"
  local source_file="$2"
  python3 - "$TARGET_CIDR" "$mode" "$source_file" <<'PY'
import ipaddress
import json
import sys

target = ipaddress.ip_network(sys.argv[1])
mode = sys.argv[2]
with open(sys.argv[3], encoding='utf-8') as handle:
    data = json.load(handle)

if mode == 'routes':
    candidates = [row.get('dst') for row in data if isinstance(row, dict)]
elif mode == 'interfaces':
    candidates = []
    for row in data:
        for info in row.get('addr_info', []) if isinstance(row, dict) else []:
            if info.get('family') == 'inet' and info.get('local') and info.get('prefixlen') is not None:
                candidates.append(f"{info['local']}/{info['prefixlen']}")
else:
    candidates = []
    for row in data:
        for config in row.get('IPAM', {}).get('Config') or [] if isinstance(row, dict) else []:
            candidates.append(config.get('Subnet'))

for candidate in candidates:
    if not candidate or candidate == 'default':
        continue
    try:
        network = ipaddress.ip_network(candidate, strict=False)
    except ValueError:
        continue
    if network.version == target.version and network.overlaps(target):
        print('present')
        raise SystemExit(0)
print('absent')
PY
}

classify_allowed_overlap() {
  local source_file="$1"
  python3 - "$TARGET_CIDR" "$source_file" <<'PY'
import ipaddress
import sys

target = ipaddress.ip_network(sys.argv[1])
with open(sys.argv[2], encoding='utf-8') as handle:
    for raw in handle:
        for candidate in raw.strip().split(','):
            if not candidate:
                continue
            try:
                network = ipaddress.ip_network(candidate, strict=False)
            except ValueError:
                continue
            if network.version == target.version and network.overlaps(target):
                print('present')
                raise SystemExit(0)
print('absent')
PY
}

classify_cpu_headroom() {
  local cpu_count load_1
  cpu_count="$(nproc 2>/dev/null || printf '0')"
  load_1="$(awk '{print $1}' /proc/loadavg 2>/dev/null || printf '')"
  awk -v cpus="$cpu_count" -v load="$load_1" 'BEGIN {
    if (cpus < 1 || load == "") { print "not_available"; exit }
    ratio = load / cpus
    if (ratio < 0.70) print "adequate"
    else if (ratio < 0.90) print "watch"
    else print "insufficient"
  }'
}

classify_ram_headroom() {
  awk '
    /^MemTotal:/ { total=$2 }
    /^MemAvailable:/ { available=$2 }
    END {
      if (total < 1 || available == "") { print "not_available"; exit }
      ratio = available / total
      if (ratio >= 0.25) print "adequate"
      else if (ratio >= 0.10) print "watch"
      else print "insufficient"
    }
  ' /proc/meminfo 2>/dev/null || printf 'not_available\n'
}

classify_root_disk() {
  local used
  used="$(df -P / 2>/dev/null | awk 'NR==2 {gsub(/%/, "", $5); print $5}')"
  if [[ ! "$used" =~ ^[0-9]+$ ]]; then
    printf 'not_available\n'
  elif (( used < 75 )); then
    printf 'adequate\n'
  elif (( used < 90 )); then
    printf 'watch\n'
  else
    printf 'insufficient\n'
  fi
}

classify_service_group() {
  local matcher="$1"
  local unit states
  unit="$(systemctl list-units --type=service --all --no-legend 2>/dev/null | awk -v pattern="$matcher" 'tolower($1) ~ pattern {print $1; exit}')"
  if [[ -z "$unit" ]]; then
    printf 'not_detected\n'
    return
  fi
  states="$(systemctl is-active "$unit" 2>/dev/null || true)"
  case "$states" in
    active) printf 'active\n' ;;
    inactive|failed|activating|deactivating) printf 'not_active\n' ;;
    *) printf 'not_available\n' ;;
  esac
}

classify_firewall() {
  if command -v ufw >/dev/null 2>&1; then
    if ufw status 2>/dev/null | grep -q '^Status: active'; then
      printf 'ufw_active\n'
    else
      printf 'ufw_inactive\n'
    fi
  elif command -v firewall-cmd >/dev/null 2>&1; then
    if systemctl is-active --quiet firewalld 2>/dev/null; then
      printf 'firewalld_active\n'
    else
      printf 'firewalld_inactive\n'
    fi
  elif command -v nft >/dev/null 2>&1; then
    if nft list ruleset >/dev/null 2>&1; then
      printf 'nftables_present\n'
    else
      printf 'nftables_not_available\n'
    fi
  elif command -v iptables >/dev/null 2>&1; then
    if iptables -S >/dev/null 2>&1; then
      printf 'iptables_present\n'
    else
      printf 'iptables_not_available\n'
    fi
  else
    printf 'not_detected\n'
  fi
}

classify_wg_quick_readiness() {
  if command -v wg >/dev/null 2>&1 && command -v wg-quick >/dev/null 2>&1 && systemctl cat 'wg-quick@.service' >/dev/null 2>&1; then
    printf 'ready\n'
  elif command -v wg >/dev/null 2>&1; then
    printf 'partial\n'
  else
    printf 'not_available\n'
  fi
}

classify_wg_handshake() {
  local interface="$1"
  if ! command -v wg >/dev/null 2>&1 || ! ip link show dev "$interface" >/dev/null 2>&1; then
    printf 'not_available\n'
    return
  fi
  if wg show "$interface" latest-handshakes 2>/dev/null | awk '$2 > 0 {found=1} END {exit(found ? 0 : 1)}'; then
    printf 'observed\n'
  else
    printf 'not_observed\n'
  fi
}

classify_udp_binding() {
  local port="$1"
  if ! command -v ss >/dev/null 2>&1; then
    printf 'not_available\n'
  elif ss -H -lun 2>/dev/null | awk -v port="$port" '$5 ~ (":" port "$") {found=1} END {exit(found ? 0 : 1)}'; then
    printf 'present\n'
  else
    printf 'absent\n'
  fi
}

printf '%s\n' '=== GATEWAY_PHILIPS_WIREGUARD_AUDIT ==='
printf '%s\n' 'GATEWAY_AUDIT_SCHEMA=2'
printf 'TARGET_SUBNET=%s\n' "$TARGET_CIDR"
printf 'CPU_HEADROOM=%s\n' "$(classify_cpu_headroom)"
printf 'RAM_HEADROOM=%s\n' "$(classify_ram_headroom)"
printf 'ROOT_DISK_HEADROOM=%s\n' "$(classify_root_disk)"

ip -j -4 route show table all > "$TMP_DIR/routes.json" 2>/dev/null || printf '[]' > "$TMP_DIR/routes.json"
printf 'HOST_ROUTE_OVERLAP=%s\n' "$(classify_json_overlap routes "$TMP_DIR/routes.json")"

ip -j -4 addr show > "$TMP_DIR/interfaces.json" 2>/dev/null || printf '[]' > "$TMP_DIR/interfaces.json"
printf 'HOST_INTERFACE_OVERLAP=%s\n' "$(classify_json_overlap interfaces "$TMP_DIR/interfaces.json")"
printf 'WG0_INTERFACE=%s\n' "$(ip link show dev wg0 >/dev/null 2>&1 && printf 'present' || printf 'absent')"
printf 'WG0_HANDSHAKE=%s\n' "$(classify_wg_handshake wg0)"
printf 'WG_PHILIPS_INTERFACE=%s\n' "$(ip -o link show 2>/dev/null | awk -F': ' '$2 == "wg-philips" {found=1} END {print found ? "present" : "absent"}')"
printf 'WG_PHILIPS_HANDSHAKE=%s\n' "$(classify_wg_handshake wg-philips)"
printf 'WIREGUARD_INTERFACE_COUNT=%s\n' "$(ip -o link show type wireguard 2>/dev/null | awk 'END {print NR+0}')"
printf 'EXISTING_DICOM_WIREGUARD=%s\n' "$(ip -o link show type wireguard 2>/dev/null | awk -F': ' '$2 == "wg0" || $2 == "wg-dicom" {found=1} END {print found ? "present" : "absent"}')"

if command -v wg >/dev/null 2>&1; then
  wg show all allowed-ips 2>/dev/null | awk '{print $3}' > "$TMP_DIR/wireguard-allowed.txt" || true
  printf 'WIREGUARD_PEER_OVERLAP=%s\n' "$(classify_allowed_overlap "$TMP_DIR/wireguard-allowed.txt")"
else
  printf 'WIREGUARD_PEER_OVERLAP=not_available\n'
fi

if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  docker_ids="$(docker network ls -q 2>/dev/null || true)"
  if [[ -n "$docker_ids" ]]; then
    docker network inspect $docker_ids > "$TMP_DIR/docker.json" 2>/dev/null || printf '[]' > "$TMP_DIR/docker.json"
  else
    printf '[]' > "$TMP_DIR/docker.json"
  fi
  printf 'DOCKER_NETWORK_OVERLAP=%s\n' "$(classify_json_overlap docker "$TMP_DIR/docker.json")"
else
  printf 'DOCKER_NETWORK_OVERLAP=not_available\n'
fi

printf 'FIREWALL_MANAGER=%s\n' "$(classify_firewall)"
printf 'UDP_BINDING_51820=%s\n' "$(classify_udp_binding 51820)"
printf 'UDP_BINDING_51821=%s\n' "$(classify_udp_binding 51821)"
printf 'WG_QUICK_SEPARATE_INTERFACE=%s\n' "$(classify_wg_quick_readiness)"

printf 'DICOM_GATEWAY_SERVICE=%s\n' "$(classify_service_group '(dicom|dcm4che|storescp|dicom.*gateway)')"
printf 'ORTHANC_SERVICE=%s\n' "$(classify_service_group 'orthanc')"
printf 'POSTGRESQL_SERVICE=%s\n' "$(classify_service_group '(postgres|postgresql)')"
printf 'EVOLUTION_API_SERVICE=%s\n' "$(classify_service_group '(evolution.*api|evolution-api)')"
printf '%s\n' 'GATEWAY_PHILIPS_WIREGUARD_AUDIT_OK'
RUNNER_EOF

chown root:root "$RUNNER"
chmod 0700 "$RUNNER"
bash -n "$RUNNER"
printf 'GATEWAY_PHILIPS_WIREGUARD_AUDIT_COMMAND_READY\n'
