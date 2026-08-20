#!/usr/bin/env bash
set -euo pipefail

APP_DIR=/var/www/voxelpacs/app
SERVICE=/etc/systemd/system/voxelpacs-portal-images.service
TIMER=/etc/systemd/system/voxelpacs-portal-images.timer

if [[ ! -x /usr/bin/php || ! -f "$APP_DIR/scripts/portal_images_anonymized_worker.php" ]]; then
  echo "Worker de imagens ainda não está publicado." >&2
  exit 1
fi

cat > "$SERVICE" <<'UNIT'
[Unit]
Description=VOXEL PACS — preparação e limpeza de imagens anonimizadas do Portal
After=network-online.target orthanc-portal-anonymized.service
Wants=network-online.target

[Service]
Type=oneshot
User=voxel
Group=voxel
WorkingDirectory=/var/www/voxelpacs/app
EnvironmentFile=/var/www/voxelpacs/app/.env
ExecStart=/usr/bin/php /var/www/voxelpacs/app/scripts/portal_images_anonymized_worker.php --prepare --purge --limit=2
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ProtectHome=true
ReadWritePaths=/var/www/voxelpacs/app/storage /tmp

UNIT

cat > "$TIMER" <<'UNIT'
[Unit]
Description=Agendamento do worker de imagens anonimizadas do Portal

[Timer]
OnBootSec=5min
OnUnitActiveSec=10min
RandomizedDelaySec=60
Persistent=true

[Install]
WantedBy=timers.target
UNIT

systemctl daemon-reload
systemctl enable --now voxelpacs-portal-images.timer
systemctl start voxelpacs-portal-images.service
systemctl is-active --quiet voxelpacs-portal-images.timer
systemctl status --no-pager -n 10 voxelpacs-portal-images.service || true
systemctl list-timers --all voxelpacs-portal-images.timer --no-pager
