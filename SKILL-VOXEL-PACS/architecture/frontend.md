# Arquitetura — Frontend

> Preencher conforme análise real do repositório. Estrutura sugerida abaixo — ajuste às pastas reais encontradas.

## Stack e organização

- Framework: `[A confirmar — Vue.js/React/outro]`
- Gerenciamento de estado: `[A confirmar]`
- Roteamento: `[A confirmar]`
- Estrutura de pastas real: `[A preencher — cole a árvore relevante aqui, resumida]`

## Viewer DICOM / OHIF

- Como o viewer é embutido (iframe, biblioteca integrada, microfrontend?): `[A confirmar]`
- Como o viewer recebe o `StudyInstanceUID`/lista de instâncias a exibir: `[A confirmar]`
- Customizações feitas sobre o OHIF padrão (plugins, extensões, tema): `[A confirmar]`
- Arquivo(s) de configuração do viewer: `[A preencher caminho]`

## Componentes reutilizáveis mais usados

| Componente | Caminho | Usado em |
|---|---|---|
| `[A preencher]` | | |

## Convenções de nomenclatura e organização

`[A preencher conforme observado — ex: PascalCase para componentes, pasta por feature vs pasta por tipo]`

## Onde procurar antes de criar algo novo

Antes de criar um componente novo, confira `patterns/padrao-componentes.md` e esta lista de componentes reutilizáveis — duplicar componente existente é o erro mais comum e mais caro em tokens (gera dois lugares para manter).
