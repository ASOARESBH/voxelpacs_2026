#!/bin/bash
# ==============================================================================
# VOXEL PACS — build.sh
# Gera ZIP de deploy para Hostgator (sem vendor, sem .env, sem logs)
# ==============================================================================
set -e

BOLD="\033[1m"
GREEN="\033[0;32m"
YELLOW="\033[1;33m"
RESET="\033[0m"

TIMESTAMP=$(date +%Y%m%d_%H%M%S)
ZIPNAME="voxelpacs_deploy_${TIMESTAMP}.zip"
OUTPUT_DIR="${HOME}"

echo -e "${BOLD}======================================${RESET}"
echo -e "${BOLD}  VOXEL PACS — Build para Deploy      ${RESET}"
echo -e "${BOLD}======================================${RESET}"

# 1. Instalar dependências de produção
echo -e "\n${YELLOW}[1/3] Instalando dependências de produção...${RESET}"
composer install --no-dev --optimize-autoloader --no-interaction
echo -e "${GREEN}✔ Dependências de produção instaladas${RESET}"

# 2. Gerar ZIP
echo -e "\n${YELLOW}[2/3] Gerando arquivo ZIP...${RESET}"
zip -r "${OUTPUT_DIR}/${ZIPNAME}" . \
    --exclude "*.git*" \
    --exclude "*/.git/*" \
    --exclude "*/node_modules/*" \
    --exclude "*/.env" \
    --exclude "*/storage/logs/*" \
    --exclude "*/storage/sessions/*" \
    --exclude "*/storage/cache/*" \
    --exclude "*/tests/*" \
    --exclude "*/.github/*" \
    --exclude "*/scripts/*" \
    --exclude "*.zip" \
    -q

echo -e "${GREEN}✔ ZIP gerado: ${OUTPUT_DIR}/${ZIPNAME}${RESET}"

# 3. Mostrar informações
echo -e "\n${YELLOW}[3/3] Informações do build...${RESET}"
ls -lh "${OUTPUT_DIR}/${ZIPNAME}"
echo -e "${GREEN}✔ Build concluído${RESET}"

echo -e "\n${BOLD}======================================${RESET}"
echo -e "${GREEN}${BOLD}  Build concluído!                    ${RESET}"
echo -e "${BOLD}======================================${RESET}"
echo -e "\nArquivo: ${BOLD}${OUTPUT_DIR}/${ZIPNAME}${RESET}"
echo -e "Próximo passo: Faça upload do ZIP para o Hostgator via FTP/cPanel"
echo ""
