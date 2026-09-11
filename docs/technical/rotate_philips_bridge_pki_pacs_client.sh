#!/usr/bin/env bash
# Atualização PKI PACS/API para a bridge Philips. Sem chamadas à bridge ou reload de serviço.
# Por padrão executa somente --dry-run; --apply e --rollback exigem autorização operacional separada.
set -euo pipefail
umask 077

if [[ "${EUID}" -ne 0 || "$#" -ne 1 ]]; then
  printf 'Uso permitido: root com um argumento: --dry-run, --apply ou --rollback.\n' >&2
  exit 64
fi

readonly ACTION="$1"
readonly CLIENT_DIR='/etc/voxelpacs/philips-folder-client'
readonly ROTATION_ROOT='/var/lib/voxelpacs/philips-folder-pki-rotation-pacs'
readonly APPLIED_LINK="$ROTATION_ROOT/applied"
readonly BUNDLE='/root/philips-folder-pki-client-update.tar'
readonly PROCESS_GROUP='voxel'

case "$ACTION" in --dry-run|--apply|--rollback) ;; *) printf 'ACAO_NAO_PERMITIDA\n' >&2; exit 64 ;; esac
for command in openssl stat install tar date readlink find cp mv ln mktemp getent; do
  command -v "$command" >/dev/null 2>&1 || { printf 'DEPENDENCIA_AUSENTE=%s\n' "$command" >&2; exit 69; }
done

client_file() {
  local path="$1" owner mode
  [[ -f "$path" && ! -L "$path" ]] || return 1
  owner="$(stat -c '%U:%G' "$path")"
  mode="$(stat -c '%a' "$path")"
  [[ "$owner" == "root:$PROCESS_GROUP" && "$mode" == '640' ]]
}

keypair_matches() {
  local certificate="$1" key="$2" cert_pub key_pub
  cert_pub="$(openssl x509 -in "$certificate" -noout -pubkey | openssl pkey -pubin -outform DER | sha256sum | awk '{print $1}')"
  key_pub="$(openssl pkey -in "$key" -pubout -outform DER | sha256sum | awk '{print $1}')"
  [[ -n "$cert_pub" && "$cert_pub" == "$key_pub" ]]
}

assert_current() {
  getent group "$PROCESS_GROUP" >/dev/null
  for name in ca.crt client.crt client.key hmac envelope-public.b64; do client_file "$CLIENT_DIR/$name" || return 1; done
  openssl x509 -in "$CLIENT_DIR/ca.crt" -noout >/dev/null 2>&1
  openssl x509 -in "$CLIENT_DIR/client.crt" -noout >/dev/null 2>&1
  keypair_matches "$CLIENT_DIR/client.crt" "$CLIENT_DIR/client.key"
}

dry_run() {
  assert_current || { printf 'PACS_PKI_ATUAL=NAO_CONFORME\n' >&2; exit 65; }
  printf '%s\n' '=== PHILIPS_BRIDGE_PACS_PKI_ROTATION_DRY_RUN ==='
  printf 'DRY_RUN_SCHEMA=1\n'
  printf 'PACS_APPLY_CERTIFICATES=ca_crt,client_crt\n'
  printf 'PACS_PRESERVED_FILES=client_key,hmac,envelope_public\n'
  printf 'BRIDGE_CALL=not_performed\n'
  printf 'PHP_FPM_RELOAD=not_performed\n'
  printf '%s\n' 'PHILIPS_BRIDGE_PACS_PKI_ROTATION_DRY_RUN_OK'
}

apply_rotation() {
  assert_current || { printf 'PACS_PKI_ATUAL=NAO_CONFORME\n' >&2; exit 65; }
  [[ -f "$BUNDLE" && ! -L "$BUNDLE" && ! -e "$APPLIED_LINK" ]] || { printf 'PACS_ROTACAO=NAO_DISPONIVEL\n' >&2; exit 66; }
  local workdir backup timestamp
  workdir="$(mktemp -d)"
  trap 'rm -rf "$workdir"' EXIT
  tar --extract --file "$BUNDLE" --directory "$workdir" --no-same-owner --no-same-permissions
  [[ "$(find "$workdir" -maxdepth 1 -type f | wc -l)" -eq 2 && -f "$workdir/ca.crt" && -f "$workdir/client.crt" ]] || { printf 'PACOTE_PKI=INVALIDO\n' >&2; exit 68; }
  openssl x509 -in "$workdir/ca.crt" -noout >/dev/null 2>&1
  openssl x509 -in "$workdir/client.crt" -noout >/dev/null 2>&1
  openssl x509 -in "$workdir/ca.crt" -checkend 86400 -noout >/dev/null
  openssl x509 -in "$workdir/client.crt" -checkend 86400 -noout >/dev/null
  keypair_matches "$workdir/client.crt" "$CLIENT_DIR/client.key" || { printf 'PACOTE_PKI=PAR_CLIENTE_INVALIDO\n' >&2; exit 68; }
  openssl verify -x509_strict -purpose sslclient -CAfile "$workdir/ca.crt" "$workdir/client.crt" >/dev/null
  timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
  install -d -o root -g root -m 0700 "$ROTATION_ROOT"
  backup="$ROTATION_ROOT/backup-$timestamp"
  install -d -o root -g root -m 0700 "$backup"
  for name in ca.crt client.crt; do
    install -o root -g root -m 0640 "$CLIENT_DIR/$name" "$backup/$name"
    install -o root -g "$PROCESS_GROUP" -m 0640 "$workdir/$name" "$CLIENT_DIR/$name.next"
    mv -f "$CLIENT_DIR/$name.next" "$CLIENT_DIR/$name"
  done
  ln -s "$backup" "$APPLIED_LINK"
  printf 'PACS_PKI_CERTIFICATES_REPLACED=ready\n'
  printf 'PHP_FPM_RELOAD=not_performed\n'
}

rollback_rotation() {
  [[ -L "$APPLIED_LINK" ]] || { printf 'PACS_ROLLBACK=AUSENTE\n' >&2; exit 66; }
  local backup
  backup="$(readlink -f "$APPLIED_LINK")"
  [[ -d "$backup" && "$backup" == "$ROTATION_ROOT"/* ]] || { printf 'PACS_BACKUP=INVALIDO\n' >&2; exit 66; }
  for name in ca.crt client.crt; do
    [[ -f "$backup/$name" && ! -L "$backup/$name" ]] || { printf 'PACS_BACKUP=NAO_CONFORME\n' >&2; exit 66; }
    install -o root -g "$PROCESS_GROUP" -m 0640 "$backup/$name" "$CLIENT_DIR/$name.restore"
    mv -f "$CLIENT_DIR/$name.restore" "$CLIENT_DIR/$name"
  done
  rm -f "$APPLIED_LINK"
  printf 'PACS_PKI_CERTIFICATES_ROLLED_BACK=ready\n'
  printf 'PHP_FPM_RELOAD=not_performed\n'
}

case "$ACTION" in
  --dry-run) dry_run ;;
  --apply) apply_rotation ;;
  --rollback) rollback_rotation ;;
esac
