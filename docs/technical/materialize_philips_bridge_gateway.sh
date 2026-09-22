#!/usr/bin/env bash
# Materialização controlada da bridge Philips no gateway-dicom-01.
# Não executa smbclient, não cria job/outbox, não gera PDF/XML e não altera firewall, WireGuard ou SSH.
set -euo pipefail
umask 077

if [[ "${EUID}" -ne 0 || "$#" -ne 0 ]]; then
  printf 'Uso não permitido. Execute localmente como root, sem argumentos.\n' >&2
  exit 64
fi

readonly EXPECTED_HOST='gateway-dicom-01'
readonly EXPECTED_PRIVATE_IP='10.0.0.4'
readonly BRIDGE_PORT='8443'
readonly DESTINATION_ID='6'
readonly PEER_HOST='10.201.10.2'
readonly BRIDGE_UNIT='voxelpacs-philips-folder-bridge.service'
readonly RUNTIME_DIR='/opt/voxelpacs/report-delivery-gateway'
readonly CONFIG_DIR='/etc/voxelpacs/philips-folder'
readonly ENV_FILE='/etc/voxelpacs/philips-folder-bridge.env'
readonly STATE_ROOT='/var/lib/voxelpacs/philips-folder-bridge'
readonly TARGET_ROOT='/var/lib/voxelpacs/philips-folder-target'
readonly CLIENT_BUNDLE='/root/philips-folder-client-bundle.tar'
readonly REPOSITORY='https://raw.githubusercontent.com/ASOARESBH/voxelpacs_2026/main'
readonly BRIDGE_SHA256='6a7d7fb2298aafc8126c0a8deeb028ce3801efbaba25cee619a0e5790b625231'
readonly ENVELOPE_HELPER_SHA256='30238368bf33c6c837ff911ac01df37594027587ea536b017daddc44f7ceff7a'

require() {
  command -v "$1" >/dev/null 2>&1 || { printf 'DEPENDENCIA_AUSENTE=%s\n' "$1" >&2; exit 69; }
}

for command in curl sha256sum install openssl python3 systemctl tar ss ip; do require "$command"; done
python3 -c 'import cryptography' >/dev/null 2>&1 || { printf 'DEPENDENCIA_AUSENTE=python_cryptography\n' >&2; exit 69; }
command -v smbclient >/dev/null 2>&1 || { printf 'DEPENDENCIA_AUSENTE=smbclient\n' >&2; exit 69; }

[[ "$(hostname -s)" == "$EXPECTED_HOST" ]] || { printf 'GATEWAY_IDENTIDADE=INCORRETA\n' >&2; exit 65; }
ip -4 -o addr show | awk '{print $4}' | cut -d/ -f1 | grep -Fxq "$EXPECTED_PRIVATE_IP" || { printf 'GATEWAY_IP_PRIVADO=INCORRETO\n' >&2; exit 65; }
if ss -H -ltn | awk -v port=":${BRIDGE_PORT}" '$4 ~ (port "$") {found=1} END {exit(found ? 0 : 1)}'; then
  printf 'BRIDGE_PORT_8443=EM_USO\n' >&2
  exit 66
fi
for protected in "$ENV_FILE" "/etc/systemd/system/${BRIDGE_UNIT}" "$RUNTIME_DIR/philips_folder_bridge.py" "$CLIENT_BUNDLE"; do
  [[ ! -e "$protected" ]] || { printf 'MATERIALIZACAO_EXISTENTE=SIM\n' >&2; exit 73; }
done

workdir="$(mktemp -d)"
cleanup() { rm -rf "$workdir"; }
trap cleanup EXIT

fetch_verified() {
  local relative="$1" expected="$2" output="$3" actual
  curl --fail --silent --show-error --location "$REPOSITORY/$relative" --output "$output"
  actual="$(sha256sum "$output" | awk '{print $1}')"
  [[ "$actual" == "$expected" ]] || { printf 'ARQUIVO_VERSIONADO_INVALIDO=%s\n' "$relative" >&2; exit 74; }
}

fetch_verified 'deploy/report-delivery-gateway-bridge/philips_folder_bridge.py' "$BRIDGE_SHA256" "$workdir/philips_folder_bridge.py"
fetch_verified 'deploy/report-delivery-gateway-bridge/generate_envelope_keypair.py' "$ENVELOPE_HELPER_SHA256" "$workdir/generate_envelope_keypair.py"

install -d -o root -g root -m 0700 "$RUNTIME_DIR" "$CONFIG_DIR" "$STATE_ROOT" "$TARGET_ROOT/staging"
install -o root -g root -m 0700 "$workdir/philips_folder_bridge.py" "$RUNTIME_DIR/philips_folder_bridge.py"
install -o root -g root -m 0700 "$workdir/generate_envelope_keypair.py" "$RUNTIME_DIR/generate_envelope_keypair.py"

openssl genpkey -algorithm ED25519 -out "$CONFIG_DIR/ca.key" >/dev/null 2>&1
openssl req -x509 -new -key "$CONFIG_DIR/ca.key" -days 3650 -subj '/CN=VOXEL Philips Folder CA' \
  -addext 'basicConstraints=critical,CA:TRUE,pathlen:0' \
  -addext 'keyUsage=critical,keyCertSign,cRLSign' \
  -addext 'subjectKeyIdentifier=hash' \
  -out "$CONFIG_DIR/ca.crt" >/dev/null 2>&1
openssl genpkey -algorithm ED25519 -out "$CONFIG_DIR/server.key" >/dev/null 2>&1
openssl req -new -key "$CONFIG_DIR/server.key" -subj '/CN=VOXEL Philips Folder Gateway' -out "$workdir/server.csr" >/dev/null 2>&1
printf 'basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=serverAuth\nsubjectAltName=IP:%s\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid,issuer\n' "$EXPECTED_PRIVATE_IP" > "$workdir/server.ext"
openssl x509 -req -in "$workdir/server.csr" -CA "$CONFIG_DIR/ca.crt" -CAkey "$CONFIG_DIR/ca.key" -CAcreateserial -days 825 -extfile "$workdir/server.ext" -out "$CONFIG_DIR/server.crt" >/dev/null 2>&1
openssl genpkey -algorithm ED25519 -out "$CONFIG_DIR/client.key" >/dev/null 2>&1
openssl req -new -key "$CONFIG_DIR/client.key" -subj '/CN=VOXEL Philips Folder Client' -out "$workdir/client.csr" >/dev/null 2>&1
printf 'basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=clientAuth\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid,issuer\n' > "$workdir/client.ext"
openssl x509 -req -in "$workdir/client.csr" -CA "$CONFIG_DIR/ca.crt" -CAkey "$CONFIG_DIR/ca.key" -CAcreateserial -days 825 -extfile "$workdir/client.ext" -out "$CONFIG_DIR/client.crt" >/dev/null 2>&1
openssl rand -hex 32 > "$CONFIG_DIR/hmac"
python3 "$RUNTIME_DIR/generate_envelope_keypair.py" "$CONFIG_DIR/envelope-private.b64" "$CONFIG_DIR/envelope-public.b64"
chmod 0600 "$CONFIG_DIR"/*

cat > "$ENV_FILE" <<EOF
PHILIPS_FOLDER_BIND_IP=${EXPECTED_PRIVATE_IP}
PHILIPS_FOLDER_BIND_PORT=${BRIDGE_PORT}
PHILIPS_FOLDER_DESTINATION_ID=${DESTINATION_ID}
PHILIPS_FOLDER_MODE=single_test
PHILIPS_FOLDER_TARGET_DIRECTORY=${TARGET_ROOT}/staging
PHILIPS_FOLDER_HMAC_FILE=${CONFIG_DIR}/hmac
PHILIPS_FOLDER_CLIENT_CA_FILE=${CONFIG_DIR}/ca.crt
PHILIPS_FOLDER_SERVER_CERT_FILE=${CONFIG_DIR}/server.crt
PHILIPS_FOLDER_SERVER_KEY_FILE=${CONFIG_DIR}/server.key
PHILIPS_FOLDER_VPN_PEER_HOST=${PEER_HOST}
PHILIPS_FOLDER_TRANSPORT=smb
PHILIPS_SMB_HOST=${PEER_HOST}
PHILIPS_SMB_SHARE=PhilipsUpload
PHILIPS_SMB_REMOTE_PATH=/
PHILIPS_SMB_USER=SRVPVM\\voxel_philips
PHILIPS_NON_DICOM_ENVELOPE_PRIVATE_KEY_FILE=${CONFIG_DIR}/envelope-private.b64
EOF
chown root:root "$ENV_FILE"
chmod 0600 "$ENV_FILE"

cat > "/etc/systemd/system/${BRIDGE_UNIT}" <<EOF
[Unit]
Description=VOXEL Philips Folder Private Bridge
After=network-online.target
Wants=network-online.target
ConditionPathExists=${ENV_FILE}

[Service]
Type=simple
User=root
Group=root
EnvironmentFile=${ENV_FILE}
ExecStart=/usr/bin/python3 ${RUNTIME_DIR}/philips_folder_bridge.py
Restart=on-failure
RestartSec=10
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=${STATE_ROOT} ${TARGET_ROOT}
UMask=0077

[Install]
WantedBy=multi-user.target
EOF
chown root:root "/etc/systemd/system/${BRIDGE_UNIT}"
chmod 0644 "/etc/systemd/system/${BRIDGE_UNIT}"

bundle_dir="$workdir/client-bundle"
install -d -o root -g root -m 0700 "$bundle_dir"
install -o root -g root -m 0600 "$CONFIG_DIR/ca.crt" "$bundle_dir/ca.crt"
install -o root -g root -m 0600 "$CONFIG_DIR/client.crt" "$bundle_dir/client.crt"
install -o root -g root -m 0600 "$CONFIG_DIR/client.key" "$bundle_dir/client.key"
install -o root -g root -m 0600 "$CONFIG_DIR/hmac" "$bundle_dir/hmac"
install -o root -g root -m 0600 "$CONFIG_DIR/envelope-public.b64" "$bundle_dir/envelope-public.b64"
tar --create --file "$CLIENT_BUNDLE" --directory "$bundle_dir" ca.crt client.crt client.key hmac envelope-public.b64
chown root:root "$CLIENT_BUNDLE"
chmod 0600 "$CLIENT_BUNDLE"

systemctl daemon-reload
systemctl enable --now "$BRIDGE_UNIT"
systemctl is-active --quiet "$BRIDGE_UNIT" || { printf 'BRIDGE_START=FALHOU\n' >&2; exit 75; }

printf '%s\n' 'PHILIPS_BRIDGE_GATEWAY_MATERIALIZED'
printf 'CLIENT_BUNDLE_SHA256=%s\n' "$(sha256sum "$CLIENT_BUNDLE" | awk '{print $1}')"
printf 'GATEWAY_ENVELOPE_PUBLIC_SHA256=%s\n' "$(sha256sum "$CONFIG_DIR/envelope-public.b64" | awk '{print $1}')"
