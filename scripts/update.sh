#!/bin/bash
# ==============================================================================
# VOXEL PACS — update.sh
# Atualização do ambiente de desenvolvimento
# ==============================================================================
set -e

BOLD="\033[1m"
GREEN="\033[0;32m"
YELLOW="\033[1;33m"
RESET="\033[0m"

echo -e "${BOLD}======================================${RESET}"
echo -e "${BOLD}  VOXEL PACS — Atualização            ${RESET}"
echo -e "${BOLD}======================================${RESET}"

# 1. Atualizar código
echo -e "\n${YELLOW}[1/3] Atualizando código do repositório...${RESET}"
git pull origin $(git rev-parse --abbrev-ref HEAD)
echo -e "${GREEN}✔ Código atualizado${RESET}"

# 2. Atualizar dependências
echo -e "\n${YELLOW}[2/3] Atualizando dependências PHP...${RESET}"
composer install --no-dev --optimize-autoloader --no-interaction
echo -e "${GREEN}✔ Dependências atualizadas${RESET}"

# 3. Verificar permissões
echo -e "\n${YELLOW}[3/3] Verificando permissões...${RESET}"
chmod -R 775 storage/
echo -e "${GREEN}✔ Permissões verificadas${RESET}"

echo -e "\n${GREEN}${BOLD}  Atualização concluída!${RESET}"
echo ""
