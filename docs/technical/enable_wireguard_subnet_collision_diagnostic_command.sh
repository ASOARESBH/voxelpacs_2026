#!/usr/bin/env bash
# Instala exclusivamente o subcomando SSH `wireguard-subnet-collision-diagnose`.
# Execute uma única vez como root; o subcomando é somente leitura e não revela rotas/IPs reais.
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  printf 'Este instalador precisa ser executado como root.\n' >&2
  exit 77
fi

readonly FORCE_COMMAND=/usr/local/sbin/voxelpacs-deploy-force
readonly RUNNER=/usr/local/sbin/voxelpacs-wireguard-subnet-collision-diagnose
readonly SUDOERS_FILE=/etc/sudoers.d/voxelpacs-wireguard-subnet-collision-diagnose
readonly MARKER='# WIREGUARD_SUBNET_COLLISION_DIAGNOSE_EXACT_COMMAND'

test -x "$FORCE_COMMAND"

cat > "$RUNNER" <<'RUNNER_EOF'
#!/usr/bin/env bash
# Comando fechado: sem argumentos; apenas classifica colisões da faixa WireGuard proposta.
set -euo pipefail

if [[ "$#" -ne 0 || "${EUID}" -ne 0 ]]; then
  printf 'Uso não permitido.\n' >&2
  exit 64
fi

readonly TARGET_CIDR='10.201.10.0/24'
readonly APP_DIR='/var/www/voxelpacs/app'
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

found = False
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
        found = True
        break
print('present' if found else 'absent')
PY
}

classify_allowed_overlap() {
  local source_file="$1"
  python3 - "$TARGET_CIDR" "$source_file" <<'PY'
import ipaddress
import sys

target = ipaddress.ip_network(sys.argv[1])
found = False
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
                found = True
                break
        if found:
            break
print('present' if found else 'absent')
PY
}

printf '%s\n' '=== WIREGUARD_SUBNET_COLLISION ==='
printf 'TARGET_SUBNET=%s\n' "$TARGET_CIDR"

ip -j -4 route show table all > "$TMP_DIR/routes.json" 2>/dev/null || printf '[]' > "$TMP_DIR/routes.json"
printf 'HOST_ROUTE_OVERLAP=%s\n' "$(classify_json_overlap routes "$TMP_DIR/routes.json")"

ip -j -4 addr show > "$TMP_DIR/interfaces.json" 2>/dev/null || printf '[]' > "$TMP_DIR/interfaces.json"
printf 'HOST_INTERFACE_OVERLAP=%s\n' "$(classify_json_overlap interfaces "$TMP_DIR/interfaces.json")"

if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  docker network inspect $(docker network ls -q) > "$TMP_DIR/docker.json" 2>/dev/null || printf '[]' > "$TMP_DIR/docker.json"
  printf 'DOCKER_NETWORK_OVERLAP=%s\n' "$(classify_json_overlap docker "$TMP_DIR/docker.json")"
else
  printf 'DOCKER_NETWORK_OVERLAP=not_available\n'
fi

if command -v wg >/dev/null 2>&1; then
  wg show all allowed-ips 2>/dev/null | awk '{print $3}' > "$TMP_DIR/wireguard-allowed.txt" || true
  printf 'WIREGUARD_PEER_OVERLAP=%s\n' "$(classify_allowed_overlap "$TMP_DIR/wireguard-allowed.txt")"
else
  printf 'WIREGUARD_PEER_OVERLAP=not_available\n'
fi

if [[ -f "$APP_DIR/app/bootstrap.php" ]]; then
  db_result="$(sudo -u voxel php -r '
require "/var/www/voxelpacs/app/app/bootstrap.php";
try {
    $pdo = \App\Core\Database::getInstance();
    $target = $pdo->quote("10.201.10.0/24");
    $sql = "SELECT
        CASE WHEN EXISTS (SELECT 1 FROM bi_pacs_tenant_provisioning WHERE vpn_client_ip <<= CAST($target AS cidr)) THEN \047present\047 ELSE \047absent\047 END AS client_within_target,
        CASE WHEN EXISTS (SELECT 1 FROM bi_pacs_tenant_provisioning WHERE vpn_client_ip >>= CAST($target AS cidr)) THEN \047present\047 ELSE \047absent\047 END AS target_within_existing";
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    echo "client=" . ($row["client_within_target"] ?? "not_available") . ";supernet=" . ($row["target_within_existing"] ?? "not_available");
} catch (Throwable) {
    echo "client=not_available;supernet=not_available";
}
' 2>/dev/null || printf 'client=not_available;supernet=not_available')"
  client_overlap="$(printf '%s' "$db_result" | sed -n 's/.*client=\([^;]*\).*/\1/p')"
  supernet_overlap="$(printf '%s' "$db_result" | sed -n 's/.*supernet=\([^;]*\).*/\1/p')"
  printf 'CONTROL_PLANE_CLIENT_WITHIN_TARGET=%s\n' "${client_overlap:-not_available}"
  printf 'CONTROL_PLANE_TARGET_WITHIN_EXISTING=%s\n' "${supernet_overlap:-not_available}"
else
  printf 'CONTROL_PLANE_CLIENT_WITHIN_TARGET=not_available\n'
  printf 'CONTROL_PLANE_TARGET_WITHIN_EXISTING=not_available\n'
fi

printf 'HETZNER_NETWORK_INVENTORY=not_available\n'
printf 'WIREGUARD_SUBNET_COLLISION_DIAGNOSTIC_OK\n'
RUNNER_EOF

chown root:root "$RUNNER"
chmod 0750 "$RUNNER"
bash -n "$RUNNER"

if ! grep -Fqx "$MARKER" "$FORCE_COMMAND"; then
  grep -Fqx 'set -euo pipefail' "$FORCE_COMMAND"
  backup_force="${FORCE_COMMAND}.before-wireguard-subnet-collision-$(date -u +%Y%m%dT%H%M%SZ)"
  cp -p "$FORCE_COMMAND" "$backup_force"
  temp_force="$(mktemp)"
  awk -v marker="$MARKER" '
    { print }
    $0 == "set -euo pipefail" {
      print marker
      print "if [[ \"${SSH_ORIGINAL_COMMAND:-}\" == \"wireguard-subnet-collision-diagnose\" ]]; then"
      print "  exec sudo /usr/local/sbin/voxelpacs-wireguard-subnet-collision-diagnose"
      print "fi"
    }
  ' "$FORCE_COMMAND" > "$temp_force"
  grep -Fqx "$MARKER" "$temp_force"
  install -o root -g root -m 0755 "$temp_force" "$FORCE_COMMAND"
  rm -f "$temp_force"
fi

printf 'voxeldeploy ALL=(root) NOPASSWD: /usr/local/sbin/voxelpacs-wireguard-subnet-collision-diagnose\n' > "$SUDOERS_FILE"
chown root:root "$SUDOERS_FILE"
chmod 0440 "$SUDOERS_FILE"
visudo -cf "$SUDOERS_FILE" >/dev/null
bash -n "$FORCE_COMMAND"
printf 'WIREGUARD_SUBNET_COLLISION_DIAGNOSTIC_COMMAND_READY\n'
