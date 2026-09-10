#!/usr/bin/env bash
# Instala exclusivamente o subcomando SSH `gateway-philips-wireguard-audit`.
# Execute uma única vez como root no gateway-dicom-01. O subcomando é sem
# argumentos, somente leitura e emite somente classificações sanitizadas.
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  printf 'Este instalador precisa ser executado como root.\n' >&2
  exit 77
fi

readonly FORCE_COMMAND=/usr/local/sbin/voxelpacs-deploy-force
readonly RUNNER=/usr/local/sbin/voxelpacs-gateway-philips-wireguard-audit
readonly SUDOERS_FILE=/etc/sudoers.d/voxelpacs-gateway-philips-wireguard-audit
readonly MARKER='# GATEWAY_PHILIPS_WIREGUARD_AUDIT_EXACT_COMMAND'
readonly TECHNICAL_USER=voxeldeploy

test -x "$FORCE_COMMAND"

cat > "$RUNNER" <<'RUNNER_EOF'
#!/usr/bin/env bash
# Comando fechado: sem argumentos; não altera rede, firewall, serviços ou arquivos de configuração.
set -euo pipefail

if [[ "$#" -ne 0 || "${EUID}" -ne 0 ]]; then
  printf 'Uso não permitido.\n' >&2
  exit 64
fi

readonly TARGET_CIDR='10.201.10.0/24'
readonly TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

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

printf '%s\n' '=== GATEWAY_PHILIPS_WIREGUARD_AUDIT ==='
printf '%s\n' 'GATEWAY_AUDIT_SCHEMA=1'
printf 'TARGET_SUBNET=%s\n' "$TARGET_CIDR"
printf 'CPU_HEADROOM=%s\n' "$(classify_cpu_headroom)"
printf 'RAM_HEADROOM=%s\n' "$(classify_ram_headroom)"
printf 'ROOT_DISK_HEADROOM=%s\n' "$(classify_root_disk)"

ip -j -4 route show table all > "$TMP_DIR/routes.json" 2>/dev/null || printf '[]' > "$TMP_DIR/routes.json"
printf 'HOST_ROUTE_OVERLAP=%s\n' "$(classify_json_overlap routes "$TMP_DIR/routes.json")"

ip -j -4 addr show > "$TMP_DIR/interfaces.json" 2>/dev/null || printf '[]' > "$TMP_DIR/interfaces.json"
printf 'HOST_INTERFACE_OVERLAP=%s\n' "$(classify_json_overlap interfaces "$TMP_DIR/interfaces.json")"
printf 'WG_PHILIPS_INTERFACE=%s\n' "$(ip -o link show 2>/dev/null | awk -F': ' '$2 == "wg-philips" {found=1} END {print found ? "present" : "absent"}')"
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
printf 'UDP_BINDING_INVENTORY=%s\n' "$(command -v ss >/dev/null 2>&1 && printf 'available' || printf 'not_available')"
printf 'WG_QUICK_SEPARATE_INTERFACE=%s\n' "$(classify_wg_quick_readiness)"

printf 'DICOM_GATEWAY_SERVICE=%s\n' "$(classify_service_group '(dicom|dcm4che|storescp|dicom.*gateway)')"
printf 'ORTHANC_SERVICE=%s\n' "$(classify_service_group 'orthanc')"
printf 'POSTGRESQL_SERVICE=%s\n' "$(classify_service_group '(postgres|postgresql)')"
printf 'EVOLUTION_API_SERVICE=%s\n' "$(classify_service_group '(evolution.*api|evolution-api)')"
printf '%s\n' 'GATEWAY_PHILIPS_WIREGUARD_AUDIT_OK'
RUNNER_EOF

chown root:root "$RUNNER"
chmod 0750 "$RUNNER"
bash -n "$RUNNER"

if ! grep -Fqx "$MARKER" "$FORCE_COMMAND"; then
  grep -Fqx 'set -euo pipefail' "$FORCE_COMMAND"
  backup_force="${FORCE_COMMAND}.before-gateway-philips-wireguard-audit-$(date -u +%Y%m%dT%H%M%SZ)"
  cp -p "$FORCE_COMMAND" "$backup_force"
  temp_force="$(mktemp)"
  awk -v marker="$MARKER" '
    { print }
    $0 == "set -euo pipefail" {
      print marker
      print "if [[ \"${SSH_ORIGINAL_COMMAND:-}\" == \"gateway-philips-wireguard-audit\" ]]; then"
      print "  exec sudo /usr/local/sbin/voxelpacs-gateway-philips-wireguard-audit"
      print "fi"
    }
  ' "$FORCE_COMMAND" > "$temp_force"
  grep -Fqx "$MARKER" "$temp_force"
  install -o root -g root -m 0755 "$temp_force" "$FORCE_COMMAND"
  rm -f "$temp_force"
fi

printf '%s ALL=(root) NOPASSWD: %s\n' "$TECHNICAL_USER" "$RUNNER" > "$SUDOERS_FILE"
chown root:root "$SUDOERS_FILE"
chmod 0440 "$SUDOERS_FILE"
visudo -cf "$SUDOERS_FILE" >/dev/null
bash -n "$FORCE_COMMAND"
printf 'GATEWAY_PHILIPS_WIREGUARD_AUDIT_COMMAND_READY\n'
