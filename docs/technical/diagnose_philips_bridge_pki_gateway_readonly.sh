#!/usr/bin/env bash
# Diagnóstico fechado e somente leitura da PKI mTLS da bridge Philips.
# Não altera certificados, chaves, serviço, listener, WireGuard, firewall ou SMB.
set -euo pipefail

if [[ "${EUID}" -ne 0 || "$#" -ne 0 ]]; then
  printf 'Uso não permitido. Execute localmente como root, sem argumentos.\n' >&2
  exit 64
fi

readonly PKI_DIR='/etc/voxelpacs/philips-folder'
readonly CA_CERT="$PKI_DIR/ca.crt"
readonly CA_KEY="$PKI_DIR/ca.key"
readonly SERVER_CERT="$PKI_DIR/server.crt"
readonly SERVER_KEY="$PKI_DIR/server.key"
readonly CLIENT_CERT="$PKI_DIR/client.crt"
readonly CLIENT_KEY="$PKI_DIR/client.key"
readonly HMAC_FILE="$PKI_DIR/hmac"
readonly ENVELOPE_PRIVATE_KEY="$PKI_DIR/envelope-private.b64"
readonly BRIDGE_UNIT='voxelpacs-philips-folder-bridge.service'

for command in openssl stat systemctl; do
  command -v "$command" >/dev/null 2>&1 || {
    printf 'DEPENDENCIA_AUSENTE=%s\n' "$command" >&2
    exit 69
  }
done

protected_file_state() {
  local path="$1" owner mode
  if [[ ! -f "$path" || -L "$path" ]]; then
    printf 'absent'
    return
  fi
  owner="$(stat -c '%U:%G' "$path" 2>/dev/null || true)"
  mode="$(stat -c '%a' "$path" 2>/dev/null || true)"
  if [[ "$owner" == 'root:root' && "$mode" == '600' ]]; then
    printf 'root_only'
  else
    printf 'noncompliant'
  fi
}

certificate_state() {
  local certificate="$1"
  if [[ ! -f "$certificate" || -L "$certificate" ]]; then
    printf 'absent'
  elif openssl x509 -in "$certificate" -noout >/dev/null 2>&1; then
    printf 'parseable'
  else
    printf 'invalid'
  fi
}

extension_state() {
  local certificate="$1" extension="$2" pattern="$3"
  if [[ "$(certificate_state "$certificate")" != 'parseable' ]]; then
    printf 'unavailable'
  elif openssl x509 -in "$certificate" -noout -ext "$extension" 2>/dev/null | grep -Eqi "$pattern"; then
    printf 'present'
  else
    printf 'missing'
  fi
}

keypair_state() {
  local certificate="$1" key="$2" certificate_hash key_hash
  if [[ "$(certificate_state "$certificate")" != 'parseable' || "$(protected_file_state "$key")" == 'absent' ]]; then
    printf 'unavailable'
    return
  fi
  certificate_hash="$(openssl x509 -in "$certificate" -noout -pubkey 2>/dev/null | openssl pkey -pubin -outform DER 2>/dev/null | sha256sum | awk '{print $1}')"
  key_hash="$(openssl pkey -in "$key" -pubout -outform DER 2>/dev/null | sha256sum | awk '{print $1}')"
  if [[ -n "$certificate_hash" && "$certificate_hash" == "$key_hash" ]]; then
    printf 'match'
  else
    printf 'mismatch'
  fi
}

strict_chain_state() {
  local purpose="$1" certificate="$2"
  if [[ "$(certificate_state "$CA_CERT")" != 'parseable' || "$(certificate_state "$certificate")" != 'parseable' ]]; then
    printf 'unavailable'
  elif openssl verify -x509_strict -purpose "$purpose" -CAfile "$CA_CERT" "$certificate" >/dev/null 2>&1; then
    printf 'valid'
  else
    printf 'invalid'
  fi
}

validity_state() {
  local certificate="$1"
  if [[ "$(certificate_state "$certificate")" != 'parseable' ]]; then
    printf 'unavailable'
  elif openssl x509 -in "$certificate" -checkend 86400 -noout >/dev/null 2>&1; then
    printf 'valid_over_24h'
  else
    printf 'expiring_or_invalid'
  fi
}

printf '%s\n' '=== PHILIPS_BRIDGE_PKI_READONLY ==='
printf 'PKI_SCHEMA=1\n'
printf 'CA_CERT=%s\n' "$(certificate_state "$CA_CERT")"
printf 'CA_KEY=%s\n' "$(protected_file_state "$CA_KEY")"
printf 'CA_BASIC_CONSTRAINTS_CA_TRUE=%s\n' "$(extension_state "$CA_CERT" basicConstraints 'CA:TRUE')"
printf 'CA_KEY_USAGE_KEY_CERT_SIGN=%s\n' "$(extension_state "$CA_CERT" keyUsage 'Certificate Sign|keyCertSign')"
printf 'CA_KEY_USAGE_CRL_SIGN=%s\n' "$(extension_state "$CA_CERT" keyUsage 'CRL Sign|cRLSign')"
printf 'CA_VALIDITY=%s\n' "$(validity_state "$CA_CERT")"
printf 'SERVER_CERT=%s\n' "$(certificate_state "$SERVER_CERT")"
printf 'SERVER_KEY=%s\n' "$(protected_file_state "$SERVER_KEY")"
printf 'SERVER_KEYPAIR=%s\n' "$(keypair_state "$SERVER_CERT" "$SERVER_KEY")"
printf 'SERVER_BASIC_CONSTRAINTS_CA_FALSE=%s\n' "$(extension_state "$SERVER_CERT" basicConstraints 'CA:FALSE')"
printf 'SERVER_KEY_USAGE_DIGITAL_SIGNATURE=%s\n' "$(extension_state "$SERVER_CERT" keyUsage 'Digital Signature|digitalSignature')"
printf 'SERVER_EKU_SERVER_AUTH=%s\n' "$(extension_state "$SERVER_CERT" extendedKeyUsage 'TLS Web Server Authentication|serverAuth')"
printf 'SERVER_SAN_IP=%s\n' "$(extension_state "$SERVER_CERT" subjectAltName 'IP Address')"
printf 'SERVER_VALIDITY=%s\n' "$(validity_state "$SERVER_CERT")"
printf 'CLIENT_CERT=%s\n' "$(certificate_state "$CLIENT_CERT")"
printf 'CLIENT_KEY=%s\n' "$(protected_file_state "$CLIENT_KEY")"
printf 'CLIENT_KEYPAIR=%s\n' "$(keypair_state "$CLIENT_CERT" "$CLIENT_KEY")"
printf 'CLIENT_BASIC_CONSTRAINTS_CA_FALSE=%s\n' "$(extension_state "$CLIENT_CERT" basicConstraints 'CA:FALSE')"
printf 'CLIENT_KEY_USAGE_DIGITAL_SIGNATURE=%s\n' "$(extension_state "$CLIENT_CERT" keyUsage 'Digital Signature|digitalSignature')"
printf 'CLIENT_EKU_CLIENT_AUTH=%s\n' "$(extension_state "$CLIENT_CERT" extendedKeyUsage 'TLS Web Client Authentication|clientAuth')"
printf 'CLIENT_VALIDITY=%s\n' "$(validity_state "$CLIENT_CERT")"
printf 'SERVER_CHAIN_STRICT=%s\n' "$(strict_chain_state sslserver "$SERVER_CERT")"
printf 'CLIENT_CHAIN_STRICT=%s\n' "$(strict_chain_state sslclient "$CLIENT_CERT")"
printf 'HMAC=%s\n' "$(protected_file_state "$HMAC_FILE")"
printf 'ENVELOPE_PRIVATE_KEY=%s\n' "$(protected_file_state "$ENVELOPE_PRIVATE_KEY")"
printf 'BRIDGE_UNIT=%s\n' "$(systemctl is-active --quiet "$BRIDGE_UNIT" 2>/dev/null && printf 'active' || printf 'not_active')"
printf '%s\n' 'PHILIPS_BRIDGE_PKI_READONLY_OK'
