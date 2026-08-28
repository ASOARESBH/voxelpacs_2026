#!/usr/bin/env bash
# Implantação cirúrgica da ponte privada API -> gateway. Não inicia listener.
# Uso: bash apply_report_delivery_routing_gateway.sh /caminho/release-gateway.tar.gz /caminho/release-gateway.sha256
set -Eeuo pipefail
umask 077

SERVICE=voxelpacs-report-delivery-bridge.service
INSTALL_ROOT=/opt/voxelpacs/report-delivery-gateway
POLICY_FILE=/etc/voxelpacs/report-delivery-gateway-bridge.env
RELEASE_TAR=${1:?Informe o arquivo release-gateway.tar.gz}
RELEASE_SHA=${2:?Informe o arquivo release-gateway.sha256}
RELEASE_ID="report-delivery-gateway-$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP_ROOT="/var/backups/voxelpacs/${RELEASE_ID}"
STAGE="/var/tmp/${RELEASE_ID}"

required_files=(
  bridge_server.py
  dicom_scu.py
  voxelpacs-report-delivery-bridge.service
  README.md
)

cleanup() {
  rm -rf "$STAGE"
}
trap cleanup EXIT

if [ "$(id -u)" -ne 0 ]; then
  echo 'Este procedimento deve ser executado como root.' >&2
  exit 1
fi
[ -f "$RELEASE_TAR" ] || { echo 'Arquivo de release ausente.' >&2; exit 1; }
[ -f "$RELEASE_SHA" ] || { echo 'Manifesto SHA-256 ausente.' >&2; exit 1; }

# Nenhum listener pode permanecer ativo durante a troca de artefato.
systemctl disable --now "$SERVICE" 2>/dev/null || true

install -d -m 0700 "$BACKUP_ROOT/files" "$STAGE"
(
  cd "$(dirname "$RELEASE_TAR")"
  sha256sum -c "$RELEASE_SHA"
)
tar -xzf "$RELEASE_TAR" -C "$STAGE"

for file_name in "${required_files[@]}"; do
  [ -f "$STAGE/$file_name" ] || { echo "Release incompleto: $file_name" >&2; exit 1; }
done

if [ -d "$INSTALL_ROOT" ]; then
  cp -a "$INSTALL_ROOT" "$BACKUP_ROOT/files/report-delivery-gateway"
fi
if [ -f "/etc/systemd/system/$SERVICE" ]; then
  cp -a "/etc/systemd/system/$SERVICE" "$BACKUP_ROOT/files/$SERVICE"
fi
# A policy poderá conter material sensível; o backup permanece root-only no gateway.
if [ -f "$POLICY_FILE" ]; then
  cp -a "$POLICY_FILE" "$BACKUP_ROOT/files/report-delivery-gateway-bridge.env"
fi

install -d -o root -g root -m 0750 "$INSTALL_ROOT"
install -o root -g root -m 0750 "$STAGE/bridge_server.py" "$INSTALL_ROOT/bridge_server.py"
install -o root -g root -m 0750 "$STAGE/dicom_scu.py" "$INSTALL_ROOT/dicom_scu.py"
install -o root -g root -m 0640 "$STAGE/README.md" "$INSTALL_ROOT/README.md"
install -o root -g root -m 0644 "$STAGE/voxelpacs-report-delivery-bridge.service" "/etc/systemd/system/$SERVICE"

/usr/bin/python3 -m py_compile "$INSTALL_ROOT/bridge_server.py" "$INSTALL_ROOT/dicom_scu.py"
systemctl daemon-reload
systemctl disable --now "$SERVICE"

printf 'GATEWAY_RELEASE_OK backup=%s bridge=disabled policy=unchanged\n' "$BACKUP_ROOT"
