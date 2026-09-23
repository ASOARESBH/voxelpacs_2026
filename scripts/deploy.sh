#!/bin/bash
# ==============================================================================
# VOXEL PACS — deploy.sh
# Deploy controlado para o runtime produtivo via SSH.
# ============================================================================
set -Eeuo pipefail

BOLD="\033[1m"
GREEN="\033[0;32m"
YELLOW="\033[1;33m"
RED="\033[0;31m"
RESET="\033[0m"

REMOTE_USER="${DEPLOY_USER:-ubuntu}"
REMOTE_HOST="${DEPLOY_HOST:-}"
# A raiz contém app/ e public/ no formato produzido por scripts/build.sh.
REMOTE_PATH="${DEPLOY_PATH:-/var/www/voxelpacs}"
REMOTE_WEB_USER="${DEPLOY_WEB_USER:-www-data}"
REMOTE_APP_MODE="${DEPLOY_APP_MODE:-0751}"
REMOTE_PUBLIC_MODE="${DEPLOY_PUBLIC_MODE:-0755}"
REMOTE_SCHEME="${DEPLOY_SCHEME:-https}"
HEALTH_TIMEOUT="${DEPLOY_HEALTH_TIMEOUT:-30}"

printf '%b\n' "${BOLD}======================================${RESET}"
printf '%b\n' "${BOLD}  VOXEL PACS — Deploy para Produção   ${RESET}"
printf '%b\n' "${BOLD}======================================${RESET}"

if [[ -z "$REMOTE_HOST" ]]; then
    printf '%b\n' "${RED}ERRO: Variável DEPLOY_HOST não configurada${RESET}" >&2
    printf 'Use: DEPLOY_HOST=seu-servidor.com ./scripts/deploy.sh\n' >&2
    exit 1
fi

if [[ "$REMOTE_PATH" != /* || "$REMOTE_PATH" == "/" ]]; then
    printf '%b\n' "${RED}ERRO: DEPLOY_PATH deve ser um diretório absoluto de runtime${RESET}" >&2
    exit 1
fi

for mode in "$REMOTE_APP_MODE" "$REMOTE_PUBLIC_MODE"; do
    if [[ ! "$mode" =~ ^0[0-7]{3}$ ]]; then
        printf '%b\n' "${RED}ERRO: modo de runtime inválido: $mode${RESET}" >&2
        exit 1
    fi
done

if [[ ! "$HEALTH_TIMEOUT" =~ ^[1-9][0-9]*$ ]]; then
    printf '%b\n' "${RED}ERRO: DEPLOY_HEALTH_TIMEOUT inválido${RESET}" >&2
    exit 1
fi

# 1. Gerar build
printf '\n%b\n' "${YELLOW}[1/4] Gerando build...${RESET}"
./scripts/build.sh
printf '%b\n' "${GREEN}✔ Build gerado${RESET}"

# 2. Fazer upload
printf '\n%b\n' "${YELLOW}[2/4] Fazendo upload para ${REMOTE_HOST}...${RESET}"
ZIPNAME="$(find "$HOME" -maxdepth 1 -type f -name 'voxelpacs_deploy_*.zip' -printf '%T@ %p\n' | sort -nr | head -n1 | cut -d' ' -f2-)"
if [[ -z "$ZIPNAME" || ! -f "$ZIPNAME" ]]; then
    printf '%b\n' "${RED}ERRO: pacote de deploy não localizado${RESET}" >&2
    exit 1
fi
ZIP_BASENAME="$(basename "$ZIPNAME")"
scp "$ZIPNAME" "${REMOTE_USER}@${REMOTE_HOST}:/tmp/${ZIP_BASENAME}"
printf '%b\n' "${GREEN}✔ Upload concluído${RESET}"

# 3. Extrair e validar no servidor
printf '\n%b\n' "${YELLOW}[3/4] Extraindo e validando o runtime...${RESET}"
ssh "${REMOTE_USER}@${REMOTE_HOST}" bash -s -- deploy \
    "$REMOTE_PATH" \
    "$ZIP_BASENAME" \
    "$REMOTE_APP_MODE" \
    "$REMOTE_PUBLIC_MODE" \
    "$REMOTE_WEB_USER" <<'REMOTE_SCRIPT'
set -Eeuo pipefail

remote_root="$1"
zip_name="$2"
app_mode="$3"
public_mode="$4"
web_user="$5"
zip_path="/tmp/$zip_name"
app_path="${remote_root%/}/app"
public_path="$app_path/public"

[[ -d "$remote_root" && ! -L "$remote_root" ]] || { printf 'DEPLOY_RUNTIME=root_path_invalid\n' >&2; exit 10; }
[[ -f "$zip_path" ]] || { printf 'DEPLOY_PACKAGE=absent\n' >&2; exit 11; }

unzip -o "$zip_path" -d "$remote_root" >/dev/null
[[ -f "$app_path/composer.json" ]] || { printf 'DEPLOY_RUNTIME=composer_manifest_absent\n' >&2; exit 12; }
[[ -d "$public_path" && ! -L "$public_path" ]] || { printf 'DEPLOY_RUNTIME=public_path_invalid\n' >&2; exit 13; }
[[ -f "$public_path/index.php" ]] || { printf 'DEPLOY_RUNTIME=public_index_absent\n' >&2; exit 14; }

# Contrato explícito: somente os diretórios de entrada recebem modos previsíveis.
# Não aplicar chmod recursivo em storage, uploads, logs ou arquivos clínicos.
chmod "$app_mode" "$app_path"
chmod "$public_mode" "$public_path"

id "$web_user" >/dev/null 2>&1 || { printf 'DEPLOY_RUNTIME=web_user_absent\n' >&2; exit 15; }
sudo -u "$web_user" test -x "$app_path" || { printf 'DEPLOY_RUNTIME=web_user_cannot_traverse_app\n' >&2; exit 16; }
sudo -u "$web_user" test -r "$public_path/index.php" || { printf 'DEPLOY_RUNTIME=web_user_cannot_read_front_controller\n' >&2; exit 17; }
[[ "$(stat -c '%a' "$app_path")" == "$app_mode" ]] || { printf 'DEPLOY_RUNTIME=app_mode_mismatch\n' >&2; exit 18; }
[[ "$(stat -c '%a' "$public_path")" == "$public_mode" ]] || { printf 'DEPLOY_RUNTIME=public_mode_mismatch\n' >&2; exit 19; }

composer install --no-dev --optimize-autoloader --no-interaction
rm -f "$zip_path"
printf 'DEPLOY_RUNTIME_PERMISSIONS=PASS\n'
REMOTE_SCRIPT
printf '%b\n' "${GREEN}✔ Runtime extraído e permissões validadas${RESET}"

# 4. Verificar saúde real: /health é necessário, mas não suficiente.
printf '\n%b\n' "${YELLOW}[4/4] Verificando saúde da aplicação...${RESET}"
BASE_URL="${REMOTE_SCHEME}://${REMOTE_HOST}"
HEALTH_CODE="$(curl -ksS -o /dev/null -w '%{http_code}' --max-time "$HEALTH_TIMEOUT" "$BASE_URL/health")"
LOGIN_CODE="$(curl -ksS -o /dev/null -w '%{http_code}' --max-time "$HEALTH_TIMEOUT" "$BASE_URL/login")"
case "$HEALTH_CODE" in
    2??) ;;
    *) printf '%b\n' "${RED}ERRO: /health retornou HTTP ${HEALTH_CODE}${RESET}" >&2; exit 30 ;;
esac
case "$LOGIN_CODE" in
    2??|3??) ;;
    *) printf '%b\n' "${RED}ERRO: front controller /login retornou HTTP ${LOGIN_CODE}${RESET}" >&2; exit 31 ;;
esac
printf '%b\n' "${GREEN}✔ /health=${HEALTH_CODE}; /login=${LOGIN_CODE}${RESET}"
printf '%b\n' "${GREEN}${BOLD}  Deploy concluído!                    ${RESET}"
