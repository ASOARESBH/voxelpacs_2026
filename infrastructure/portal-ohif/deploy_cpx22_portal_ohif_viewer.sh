#!/usr/bin/env bash
set -euo pipefail

ARCHIVE=/root/voxel-portal-ohif-static.tgz
TARGET=/var/www/voxelpacs/portal-viewer
NGINX=/etc/nginx/sites-available/voxel-api.conf
BACKUP="${NGINX}.before-portal-viewer-$(date +%Y%m%d%H%M%S)"

[[ -f "$ARCHIVE" ]] || { echo "Arquivo estático do Viewer ausente." >&2; exit 1; }

rm -rf "${TARGET}.new"
mkdir -p "${TARGET}.new"
tar -xzf "$ARCHIVE" -C "${TARGET}.new"
[[ -f "${TARGET}.new/index.html" && -f "${TARGET}.new/app-config.js" ]] || {
  echo "Pacote do Viewer incompleto." >&2
  exit 1
}

grep -q 'VOXEL Portal Anonymized DICOMWeb' "${TARGET}.new/app-config.js"
grep -q 'dicomUploadEnabled: false' "${TARGET}.new/app-config.js"
grep -q 'allowMultiSelectExport: false' "${TARGET}.new/app-config.js"

mv "$NGINX" "$BACKUP"
cat > "$NGINX" <<'NGINX'
# Compatibilidade: URLs HTTP antigas que usavam o IP público passam a usar o domínio oficial.
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name 167.233.254.41 _;
    return 301 https://server.voxelpacs.com.br$request_uri;
}
server {
    listen 80;
    listen [::]:80;
    server_name server.voxelpacs.com.br portal.voxelpacs.com.br;
    root /var/www/voxelpacs/app/public;
    location ^~ /.well-known/acme-challenge/ {
        default_type text/plain;
        try_files $uri =404;
    }
    location / {
        return 301 https://$host$request_uri;
    }
}
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name server.voxelpacs.com.br portal.voxelpacs.com.br;
    root /var/www/voxelpacs/app/public;
    index index.php;
    client_max_body_size 50m;
    server_tokens off;
    access_log /var/log/nginx/voxel-api-access.log;
    error_log  /var/log/nginx/voxel-api-error.log warn;
    ssl_certificate /etc/letsencrypt/live/server.voxelpacs.com.br/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/server.voxelpacs.com.br/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    ssl_session_tickets off;
    add_header Strict-Transport-Security "max-age=31536000" always;
    add_header X-Content-Type-Options "nosniff" always;

    location = /health {
        access_log off;
        default_type text/plain;
        return 200 "ok\n";
    }

    # O Viewer é estático, same-origin e somente leitura. A API DICOMweb continua
    # sendo atendida pelo Router PHP, que valida sessão opaca em cada chamada.
    location = /imagens/viewer {
        return 301 /imagens/viewer/;
    }
    location ^~ /imagens/viewer/ {
        alias /var/www/voxelpacs/portal-viewer/;
        index index.html;
        try_files $uri $uri/ =404;
        add_header Cache-Control "no-store, private" always;
        add_header Content-Security-Policy "default-src 'self'; connect-src 'self' blob:; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval'; worker-src 'self' blob:; frame-ancestors 'self'; base-uri 'self'; form-action 'none'" always;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm-voxel.sock;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    }
    location ~ /\. {
        deny all;
    }
}
NGINX

if ! nginx -t; then
  mv "$NGINX" "$NGINX.invalid"
  mv "$BACKUP" "$NGINX"
  nginx -t
  exit 1
fi

rm -rf "$TARGET"
mv "${TARGET}.new" "$TARGET"
chown -R root:root "$TARGET"
find "$TARGET" -type d -exec chmod 755 {} +
find "$TARGET" -type f -exec chmod 644 {} +
systemctl reload nginx

curl -kfsS --resolve portal.voxelpacs.com.br:443:127.0.0.1 https://portal.voxelpacs.com.br/imagens/viewer/ | grep -q 'VOXEL Portal'
echo "PORTAL_OHIF_VIEWER_DEPLOYED"
