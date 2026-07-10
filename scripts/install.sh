#!/bin/bash
# ==============================================================================
# VOXEL PACS — install.sh
# Instalação inicial do ambiente de desenvolvimento
# ==============================================================================
set -e

BOLD="\033[1m"
GREEN="\033[0;32m"
YELLOW="\033[1;33m"
RED="\033[0;31m"
RESET="\033[0m"

echo -e "${BOLD}======================================${RESET}"
echo -e "${BOLD}  VOXEL PACS — Instalação Inicial     ${RESET}"
echo -e "${BOLD}======================================${RESET}"

# 1. Verificar PHP
echo -e "\n${YELLOW}[1/5] Verificando PHP...${RESET}"
if ! command -v php &> /dev/null; then
    echo -e "${RED}ERRO: PHP não encontrado. Instale PHP >= 8.1${RESET}"
    exit 1
fi
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
echo -e "${GREEN}✔ PHP $PHP_VERSION encontrado${RESET}"

# 2. Verificar Composer
echo -e "\n${YELLOW}[2/5] Verificando Composer...${RESET}"
if ! command -v composer &> /dev/null; then
    echo -e "${RED}ERRO: Composer não encontrado. Instale em https://getcomposer.org${RESET}"
    exit 1
fi
echo -e "${GREEN}✔ Composer encontrado${RESET}"

# 3. Instalar dependências
echo -e "\n${YELLOW}[3/5] Instalando dependências PHP...${RESET}"
composer install --no-dev --optimize-autoloader --no-interaction
echo -e "${GREEN}✔ Dependências instaladas${RESET}"

# 4. Configurar .env
echo -e "\n${YELLOW}[4/5] Configurando ambiente...${RESET}"
if [ ! -f ".env" ]; then
    cp .env.example .env
    echo -e "${GREEN}✔ Arquivo .env criado a partir de .env.example${RESET}"
    echo -e "${YELLOW}  ATENÇÃO: Edite o arquivo .env com suas configurações antes de continuar!${RESET}"
else
    echo -e "${GREEN}✔ Arquivo .env já existe${RESET}"
fi

# 5. Configurar permissões
echo -e "\n${YELLOW}[5/5] Configurando permissões...${RESET}"
chmod -R 775 storage/
echo -e "${GREEN}✔ Permissões configuradas${RESET}"

echo -e "\n${BOLD}======================================${RESET}"
echo -e "${GREEN}${BOLD}  Instalação concluída!               ${RESET}"
echo -e "${BOLD}======================================${RESET}"
echo -e "\nPróximos passos:"
echo -e "  1. Edite o arquivo ${BOLD}.env${RESET} com suas configurações"
echo -e "  2. Configure o banco de dados e execute as migrations"
echo -e "  3. Execute: ${BOLD}php -S localhost:8000 -t public/${RESET}"
echo ""
