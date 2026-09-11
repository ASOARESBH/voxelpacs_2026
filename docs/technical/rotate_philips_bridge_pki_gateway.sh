#!/usr/bin/env bash
# Rotação PKI do gateway Philips. Por padrão executa somente --dry-run.
# --stage, --apply e --rollback exigem autorização operacional separada.
# Este script não contém chamadas SMB, não chama a bridge e não recarrega serviços.
set -euo pipefail
umask 077

if [[ "${EUID}" -ne 0 || "$#" -ne 1 ]]; then
  printf 'Uso permitido: root com um argumento: --dry-run, --stage, --apply ou --rollback.\n' >&2
  exit 64
fi

readonly ACTION="$1"
readonly CONFIG_DIR='/etc/voxelpacs/philips-folder'
readonly ROTATION_ROOT='/var/lib/voxelpacs/philips-folder-pki-rotation'
readonly STAGED_LINK="$ROTATION_ROOT/staged"
readonly APPLIED_LINK="$ROTATION_ROOT/applied"
readonly SERVER_SAN_IP='10.0.0.4'
readonly CA_CERT="$CONFIG_DIR/ca.crt"
readonly CA_KEY="$CONFIG_DIR/ca.key"
readonly SERVER_CERT="$CONFIG_DIR/server.crt"
readonly SERVER_KEY="$CONFIG_DIR/server.key"
readonly CLIENT_CERT="$CONFIG_DIR/client.crt"
readonly CLIENT_KEY="$CONFIG_DIR/client.key"

case "$ACTION" in --dry-run|--stage|--apply|--rollback) ;; *) printf 'ACAO_NAO_PERMITIDA\n' >&2; exit 64 ;; esac
for command in openssl stat install sha256sum tar date readlink find cp mv ln mktemp; do
  command -v "$command" >/dev/null 2>&1 || { printf 'DEPENDENCIA_AUSENTE=%s\n' "$command" >&2; exit 69; }
done

protected_file() {
  local path="$1" owner mode
  [[ -f "$path" && ! -L "$path" ]] || return 1
  owner="$(stat -c '%U:%G' "$path")"
  mode="$(stat -c '%a' "$path")"
  [[ "$owner" == 'root:root' && "$mode" == '600' ]]
}

certificate_matches_key() {
  local certificate="$1" key="$2" cert_pub key_pub
  cert_pub="$(openssl x509 -in "$certificate" -noout -pubkey | openssl pkey -pubin -outform DER | sha256sum | awk '{print $1}')"
  key_pub="$(openssl pkey -in "$key" -pubout -outform DER | sha256sum | awk '{print $1}')"
  [[ -n "$cert_pub" && "$cert_pub" == "$key_pub" ]]
}

assert_current_material() {
  for path in "$CA_CERT" "$CA_KEY" "$SERVER_CERT" "$SERVER_KEY" "$CLIENT_CERT" "$CLIENT_KEY"; do
    protected_file "$path" || { printf 'PKI_ATUAL=NAO_CONFORME\n' >&2; exit 65; }
  done
  openssl x509 -in "$CA_CERT" -noout >/dev/null 2>&1
  openssl x509 -in "$SERVER_CERT" -noout >/dev/null 2>&1
  openssl x509 -in "$CLIENT_CERT" -noout >/dev/null 2>&1
  certificate_matches_key "$SERVER_CERT" "$SERVER_KEY" || { printf 'PAR_SERVIDOR=INVALIDO\n' >&2; exit 65; }
  certificate_matches_key "$CLIENT_CERT" "$CLIENT_KEY" || { printf 'PAR_CLIENTE=INVALIDO\n' >&2; exit 65; }
}

assert_staged_material() {
  local stage="$1"
  [[ -d "$stage" && "$stage" == "$ROTATION_ROOT"/* ]] || { printf 'STAGE=INVALIDO\n' >&2; exit 66; }
  for name in ca.crt server.crt client.crt; do
    protected_file "$stage/$name" || { printf 'STAGE_CERTIFICADO=NAO_CONFORME\n' >&2; exit 66; }
    openssl x509 -in "$stage/$name" -noout >/dev/null 2>&1
    openssl x509 -in "$stage/$name" -checkend 86400 -noout >/dev/null
  done
  certificate_matches_key "$stage/server.crt" "$SERVER_KEY" || { printf 'STAGE_PAR_SERVIDOR=INVALIDO\n' >&2; exit 66; }
  certificate_matches_key "$stage/client.crt" "$CLIENT_KEY" || { printf 'STAGE_PAR_CLIENTE=INVALIDO\n' >&2; exit 66; }
  openssl verify -x509_strict -purpose sslserver -CAfile "$stage/ca.crt" "$stage/server.crt" >/dev/null
  openssl verify -x509_strict -purpose sslclient -CAfile "$stage/ca.crt" "$stage/client.crt" >/dev/null
  openssl x509 -in "$stage/ca.crt" -noout -ext basicConstraints | grep -Eq 'CA:TRUE'
  openssl x509 -in "$stage/ca.crt" -noout -ext keyUsage | grep -Eqi 'Certificate Sign|keyCertSign'
  openssl x509 -in "$stage/ca.crt" -noout -ext keyUsage | grep -Eqi 'CRL Sign|cRLSign'
  openssl x509 -in "$stage/server.crt" -noout -ext basicConstraints | grep -Eq 'CA:FALSE'
  openssl x509 -in "$stage/server.crt" -noout -ext keyUsage | grep -Eqi 'Digital Signature|digitalSignature'
  openssl x509 -in "$stage/server.crt" -noout -ext extendedKeyUsage | grep -Eqi 'TLS Web Server Authentication|serverAuth'
  openssl x509 -in "$stage/server.crt" -noout -ext subjectAltName | grep -Fq "$SERVER_SAN_IP"
  openssl x509 -in "$stage/client.crt" -noout -ext basicConstraints | grep -Eq 'CA:FALSE'
  openssl x509 -in "$stage/client.crt" -noout -ext keyUsage | grep -Eqi 'Digital Signature|digitalSignature'
  openssl x509 -in "$stage/client.crt" -noout -ext extendedKeyUsage | grep -Eqi 'TLS Web Client Authentication|clientAuth'
}

dry_run() {
  assert_current_material
  printf '%s\n' '=== PHILIPS_BRIDGE_PKI_ROTATION_DRY_RUN ==='
  printf 'DRY_RUN_SCHEMA=1\n'
  printf 'CA_KEY=preserve\n'
  printf 'SERVER_KEY=preserve\n'
  printf 'CLIENT_KEY=preserve\n'
  printf 'HMAC=preserve\n'
  printf 'ENVELOPE_KEYS=preserve\n'
  printf 'GATEWAY_STAGE_CERTIFICATES=ca_crt,server_crt,client_crt\n'
  printf 'GATEWAY_APPLY_CERTIFICATES=ca_crt,server_crt,client_crt\n'
  printf 'PACS_UPDATE_CERTIFICATES=ca_crt,client_crt\n'
  printf 'PACS_PRESERVED_FILES=client_key,hmac,envelope_public\n'
  printf 'VALIDATION=strict_chain,key_usage,basic_constraints,eku,san,keypair,validity\n'
  printf 'BRIDGE_RELOAD=not_performed\n'
  printf '%s\n' 'PHILIPS_BRIDGE_PKI_ROTATION_DRY_RUN_OK'
}

stage_rotation() {
  assert_current_material
  install -d -o root -g root -m 0700 "$ROTATION_ROOT"
  [[ ! -e "$STAGED_LINK" ]] || { printf 'STAGE_EXISTENTE=SIM\n' >&2; exit 73; }
  local stage serial
  stage="$(mktemp -d "$ROTATION_ROOT/stage.XXXXXXXX")"
  install -d -o root -g root -m 0700 "$stage"
  printf 'basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=serverAuth\nsubjectAltName=IP:%s\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid,issuer\n' "$SERVER_SAN_IP" > "$stage/server.ext"
  printf 'basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=clientAuth\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid,issuer\n' > "$stage/client.ext"
  openssl req -x509 -new -key "$CA_KEY" -days 3650 -subj '/CN=VOXEL Philips Folder CA' \
    -addext 'basicConstraints=critical,CA:TRUE,pathlen:0' \
    -addext 'keyUsage=critical,keyCertSign,cRLSign' \
    -addext 'subjectKeyIdentifier=hash' \
    -out "$stage/ca.crt" >/dev/null 2>&1
  openssl req -new -key "$SERVER_KEY" -subj '/CN=VOXEL Philips Folder Gateway' -out "$stage/server.csr" >/dev/null 2>&1
  serial="0x$(openssl rand -hex 16)"
  openssl x509 -req -in "$stage/server.csr" -CA "$stage/ca.crt" -CAkey "$CA_KEY" -set_serial "$serial" -days 825 -extfile "$stage/server.ext" -out "$stage/server.crt" >/dev/null 2>&1
  openssl req -new -key "$CLIENT_KEY" -subj '/CN=VOXEL Philips Folder Client' -out "$stage/client.csr" >/dev/null 2>&1
  serial="0x$(openssl rand -hex 16)"
  openssl x509 -req -in "$stage/client.csr" -CA "$stage/ca.crt" -CAkey "$CA_KEY" -set_serial "$serial" -days 825 -extfile "$stage/client.ext" -out "$stage/client.crt" >/dev/null 2>&1
  rm -f "$stage/server.csr" "$stage/client.csr" "$stage/server.ext" "$stage/client.ext"
  chmod 0600 "$stage/ca.crt" "$stage/server.crt" "$stage/client.crt"
  assert_staged_material "$stage"
  tar --create --file "$stage/pacs-client-pki-update.tar" --directory "$stage" ca.crt client.crt
  chmod 0600 "$stage/pacs-client-pki-update.tar"
  {
    printf 'schema=1\n'
    printf 'ca_sha256=%s\n' "$(sha256sum "$stage/ca.crt" | awk '{print $1}')"
    printf 'server_sha256=%s\n' "$(sha256sum "$stage/server.crt" | awk '{print $1}')"
    printf 'client_sha256=%s\n' "$(sha256sum "$stage/client.crt" | awk '{print $1}')"
    printf 'pacs_bundle_sha256=%s\n' "$(sha256sum "$stage/pacs-client-pki-update.tar" | awk '{print $1}')"
  } > "$stage/manifest"
  chmod 0600 "$stage/manifest"
  ln -s "$stage" "$STAGED_LINK"
  printf 'PKI_STAGE=ready\n'
  printf 'STAGED_CA_BASIC_CONSTRAINTS=CA_TRUE\n'
  printf 'STAGED_CA_KEY_USAGE=keyCertSign_cRLSign\n'
  printf 'STAGED_SERVER_BASIC_CONSTRAINTS=CA_FALSE\n'
  printf 'STAGED_SERVER_KEY_USAGE=digitalSignature\n'
  printf 'STAGED_SERVER_EKU=serverAuth\n'
  printf 'STAGED_SERVER_SAN=approved_private_ip\n'
  printf 'STAGED_SERVER_CHAIN_STRICT=valid\n'
  printf 'STAGED_SERVER_KEYPAIR=match\n'
  printf 'STAGED_CLIENT_BASIC_CONSTRAINTS=CA_FALSE\n'
  printf 'STAGED_CLIENT_KEY_USAGE=digitalSignature\n'
  printf 'STAGED_CLIENT_EKU=clientAuth\n'
  printf 'STAGED_CLIENT_CHAIN_STRICT=valid\n'
  printf 'STAGED_CLIENT_KEYPAIR=match\n'
  printf 'STAGED_CA_SHA256=%s\n' "$(sha256sum "$stage/ca.crt" | awk '{print $1}')"
  printf 'STAGED_SERVER_SHA256=%s\n' "$(sha256sum "$stage/server.crt" | awk '{print $1}')"
  printf 'STAGED_CLIENT_SHA256=%s\n' "$(sha256sum "$stage/client.crt" | awk '{print $1}')"
  printf 'PACS_CLIENT_UPDATE_BUNDLE=ready\n'
  printf 'PACS_CLIENT_UPDATE_BUNDLE_SHA256=%s\n' "$(sha256sum "$stage/pacs-client-pki-update.tar" | awk '{print $1}')"
  printf 'RUNTIME_CERTIFICATES_REPLACED=no\n'
  printf 'BRIDGE_RELOAD=not_performed\n'
}

apply_rotation() {
  [[ -L "$STAGED_LINK" ]] || { printf 'STAGE=AUSENTE\n' >&2; exit 66; }
  local stage backup timestamp
  stage="$(readlink -f "$STAGED_LINK")"
  assert_staged_material "$stage"
  [[ ! -e "$APPLIED_LINK" ]] || { printf 'ROTACAO_APLICADA=SIM\n' >&2; exit 73; }
  timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
  backup="$ROTATION_ROOT/backup-$timestamp"
  install -d -o root -g root -m 0700 "$backup"
  for name in ca.crt server.crt client.crt; do
    install -o root -g root -m 0600 "$CONFIG_DIR/$name" "$backup/$name"
    install -o root -g root -m 0600 "$stage/$name" "$CONFIG_DIR/$name.next"
    mv -f "$CONFIG_DIR/$name.next" "$CONFIG_DIR/$name"
  done
  ln -s "$backup" "$APPLIED_LINK"
  printf 'PKI_GATEWAY_CERTIFICATES_REPLACED=ready\n'
  printf 'BRIDGE_RELOAD=not_performed\n'
}

rollback_rotation() {
  [[ -L "$APPLIED_LINK" ]] || { printf 'ROLLBACK=AUSENTE\n' >&2; exit 66; }
  local backup
  backup="$(readlink -f "$APPLIED_LINK")"
  [[ -d "$backup" && "$backup" == "$ROTATION_ROOT"/* ]] || { printf 'BACKUP=INVALIDO\n' >&2; exit 66; }
  for name in ca.crt server.crt client.crt; do
    protected_file "$backup/$name" || { printf 'BACKUP=NAO_CONFORME\n' >&2; exit 66; }
    install -o root -g root -m 0600 "$backup/$name" "$CONFIG_DIR/$name.restore"
    mv -f "$CONFIG_DIR/$name.restore" "$CONFIG_DIR/$name"
  done
  rm -f "$APPLIED_LINK"
  printf 'PKI_GATEWAY_CERTIFICATES_ROLLED_BACK=ready\n'
  printf 'BRIDGE_RELOAD=not_performed\n'
}

case "$ACTION" in
  --dry-run) dry_run ;;
  --stage) stage_rotation ;;
  --apply) apply_rotation ;;
  --rollback) rollback_rotation ;;
esac
