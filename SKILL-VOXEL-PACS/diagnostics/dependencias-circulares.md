# Diagnóstico — Dependências Circulares

## Sinais

- Módulo A importa/chama Módulo B, que importa/chama Módulo A (direta ou indiretamente através de um terceiro módulo).
- Erros de inicialização intermitentes ou "depende da ordem de import" são um sintoma clássico.

## Como investigar sem varrer o projeto inteiro

1. Partir de `architecture/dependencias.md` — se o grafo já estiver preenchido para os módulos envolvidos, a circularidade costuma ficar visível ali.
2. Se não estiver documentado, rastrear apenas a cadeia de imports/chamadas do módulo em questão (não o projeto inteiro).

## Ao encontrar uma dependência circular

1. Registrar em `architecture/dependencias.md` (seção "Dependências circulares conhecidas").
2. Resolver como uma refatoração própria (ver `workflows/refatoracao.md`) — não como parte de uma feature/bugfix não relacionado, a menos que a circularidade seja o próprio bug.
