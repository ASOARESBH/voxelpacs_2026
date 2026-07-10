#!/bin/bash
# ==============================================================================
# VOXEL PACS — deploy.sh
# Script de deploy para servidor de produção via SSH
# ==============================================================================
set -e

BOLD="\033[1m"
GREEN="\033[0;32m"
YELLOW="\033[1;33m"
RED="\033[0;31m"
RESET="\033[0m"

# Configurações (edite conforme necessário)
REMOTE_USER="${DEPLOY_USER:-ubuntu}"
REMOTE_HOST="${DEPLOY_HOST:-}"
REMOTE_PATH="${DEPLOY_PATH:-/var/www/voxelpacs}"

echo -e "${BOLD}======================================${RESET}"
echo -e "${BOLD}  VOXEL PACS — Deploy para Produção   ${RESET}"
echo -e "${BOLD}======================================${RESET}"

if [ -z "$REMOTE_HOST" ]; then
    echo -e "${RED}ERRO: Variável DEPLOY_HOST não configurada${RESET}"
    echo -e "Use: DEPLOY_HOST=seu-servidor.com ./scripts/deploy.sh"
    exit 1
fi

# 1. Gerar build
echo -e "\n${YELLOW}[1/4] Gerando build...${RESET}"
./scripts/build.sh
echo -e "${GREEN}✔ Build gerado${RESET}"

# 2. Fazer upload
echo -e "\n${YELLOW}[2/4] Fazendo upload para ${REMOTE_HOST}...${RESET}"
ZIPNAME=$(ls -t ~/voxelpacs_deploy_*.zip | head -1)
scp "${ZIPNAME}" "${REMOTE_USER}@${REMOTE_HOST}:/tmp/"
echo -e "${GREEN}✔ Upload concluído${RESET}"

# 3. Extrair no servidor
echo -e "\n${YELLOW}[3/4] Extraindo no servidor...${RESET}"
ssh "${REMOTE_USER}@${REMOTE_HOST}" "
    cd ${REMOTE_PATH} && \
    unzip -o /tmp/$(basename ${ZIPNAME}) && \
    composer install --no-dev --optimize-autoloader --no-interaction && \
    chmod -R 775 storage/ && \
    rm /tmp/$(basename ${ZIPNAME})
"
echo -e "${GREEN}✔ Extração concluída${RESET}"

# 4. Verificar saúde
echo -e "\n${YELLOW}[4/4] Verificando saúde da aplicação...${RESET}"
if curl -sf "http://${REMOTE_HOST}/health" > /dev/null 2>&1; then
    echo -e "${GREEN}✔ Aplicação respondendo corretamente${RESET}"
else
    echo -e "${YELLOW}⚠ Não foi possível verificar o health check${RESET}"
fi

echo -e "\n${GREEN}${BOLD}  Deploy concluído!${RESET}"
echo ""
