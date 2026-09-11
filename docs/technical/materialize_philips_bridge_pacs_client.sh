#!/usr/bin/env bash
# Materializa somente referências privadas PACS/API para a bridge Philips.
# Não instala bridge, listener ou smbclient no PACS/API; não chama endpoint, não cria job e não transmite conteúdo clínico.
set -euo pipefail
umask 077

if [[ "${EUID}" -ne 0 || "$#" -ne 0 ]]; then
  printf 'Uso não permitido. Execute localmente como root, sem argumentos.\n' >&2
  exit 64
fi

readonly APP='/var/www/voxelpacs/app'
readonly BUNDLE='/root/philips-folder-client-bundle.tar'
readonly EXPECTED_BUNDLE_SHA256='667fb367552b216725437d4c86b22350f7444b66a51fbed4bb9257386decf659'
readonly CLIENT_DIR='/etc/voxelpacs/philips-folder-client'
readonly CLIENT_ENV='/etc/voxelpacs/philips-folder-client.conf'
readonly GATEWAY_PRIVATE_URL='https://10.0.0.4:8443'
readonly PROCESS_GROUP='voxel'

for command in sha256sum tar install getent openssl; do
  command -v "$command" >/dev/null 2>&1 || { printf 'DEPENDENCIA_AUSENTE=%s\n' "$command" >&2; exit 69; }
done
[[ -f "$APP/app/bootstrap.php" ]] || { printf 'PACS_APP=NAO_ENCONTRADO\n' >&2; exit 65; }
getent group "$PROCESS_GROUP" >/dev/null || { printf 'GRUPO_PROCESSO=NAO_ENCONTRADO\n' >&2; exit 65; }
[[ -f "$BUNDLE" && ! -L "$BUNDLE" ]] || { printf 'PACOTE_CLIENTE=AUSENTE\n' >&2; exit 66; }
[[ "$(sha256sum "$BUNDLE" | awk '{print $1}')" == "$EXPECTED_BUNDLE_SHA256" ]] || { printf 'PACOTE_CLIENTE=HASH_INVALIDO\n' >&2; exit 67; }
[[ ! -e "$CLIENT_DIR" && ! -e "$CLIENT_ENV" ]] || { printf 'MATERIALIZACAO_EXISTENTE=SIM\n' >&2; exit 73; }

workdir="$(mktemp -d)"
cleanup() { rm -rf "$workdir"; }
trap cleanup EXIT
tar --extract --file "$BUNDLE" --directory "$workdir" --no-same-owner
expected=(ca.crt client.crt client.key hmac envelope-public.b64)
for item in "${expected[@]}"; do
  [[ -f "$workdir/$item" && ! -L "$workdir/$item" ]] || { printf 'PACOTE_CLIENTE=CONTEUDO_INVALIDO\n' >&2; exit 68; }
done
[[ "$(find "$workdir" -maxdepth 1 -type f | wc -l)" -eq 5 ]] || { printf 'PACOTE_CLIENTE=CONTEUDO_EXCEDENTE\n' >&2; exit 68; }
openssl x509 -in "$workdir/ca.crt" -noout >/dev/null 2>&1 || { printf 'PACOTE_CLIENTE=CA_INVALIDA\n' >&2; exit 68; }
openssl x509 -in "$workdir/client.crt" -noout >/dev/null 2>&1 || { printf 'PACOTE_CLIENTE=CERTIFICADO_INVALIDO\n' >&2; exit 68; }
[[ "$(wc -c < "$workdir/hmac")" -ge 32 ]] || { printf 'PACOTE_CLIENTE=HMAC_INVALIDO\n' >&2; exit 68; }
[[ "$(wc -c < "$workdir/envelope-public.b64")" -ge 32 ]] || { printf 'PACOTE_CLIENTE=CHAVE_PUBLICA_INVALIDA\n' >&2; exit 68; }

install -d -o root -g "$PROCESS_GROUP" -m 0750 "$CLIENT_DIR"
for item in "${expected[@]}"; do
  install -o root -g "$PROCESS_GROUP" -m 0640 "$workdir/$item" "$CLIENT_DIR/$item"
done

{
  printf 'PHILIPS_FOLDER_BRIDGE_BASE_URL=%s\n' "$GATEWAY_PRIVATE_URL"
  printf 'PHILIPS_FOLDER_BRIDGE_HMAC='
  tr -d '\r\n' < "$CLIENT_DIR/hmac"
  printf '\n'
  printf 'PHILIPS_FOLDER_BRIDGE_CA_FILE=%s\n' "$CLIENT_DIR/ca.crt"
  printf 'PHILIPS_FOLDER_BRIDGE_CERT_FILE=%s\n' "$CLIENT_DIR/client.crt"
  printf 'PHILIPS_FOLDER_BRIDGE_KEY_FILE=%s\n' "$CLIENT_DIR/client.key"
  printf 'PHILIPS_NON_DICOM_GATEWAY_ENVELOPE_PUBLIC_KEY_FILE=%s\n' "$CLIENT_DIR/envelope-public.b64"
} > "$CLIENT_ENV"
chown root:"$PROCESS_GROUP" "$CLIENT_ENV"
chmod 0640 "$CLIENT_ENV"

printf '%s\n' 'PHILIPS_BRIDGE_PACS_CLIENT_MATERIALIZED'
printf 'PACS_CLIENT_BUNDLE_SHA256=%s\n' "$(sha256sum "$BUNDLE" | awk '{print $1}')"
printf 'PACS_ENVELOPE_PUBLIC_SHA256=%s\n' "$(sha256sum "$CLIENT_DIR/envelope-public.b64" | awk '{print $1}')"
