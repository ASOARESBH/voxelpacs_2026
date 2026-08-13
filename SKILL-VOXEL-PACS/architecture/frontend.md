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

## Tradução / i18n (desde 2026-07-15)

Views são PHP server-rendered (sem framework SPA — ver `CLAUDE.md`). Todo texto visível ao usuário deve passar pela função global `t('modulo.tela.elemento')` em vez de string hardcoded — ver `patterns/padrao-i18n.md` para a convenção completa e `modules/i18n.md` para o que já foi migrado (hoje: só `app/Views/platform/negocios/index.php`, como piloto) e o inventário do que falta. Idioma efetivo é resolvido uma vez por request em `TenantMiddleware`, a partir de `bi_tenants.idioma_padrao` do tenant ativo — não há troca de idioma via UI/JS no browser, é sempre configuração do Negócio.

## Cadastro médico — aba Máscaras (2026-08-13)

A interface é **PHP renderizado no servidor**, com Bootstrap 5 e JavaScript vanilla; não há SPA. A aba real de Máscaras fica em `app/Views/medicos/form.php`, no painel `#aba-mascaras`. Seu CRUD é atendido pelo `TemplatesController`; não reutiliza o modal legado de Reports (`app/Views/reports/partials/_modal_templates.php` e `public/assets/js/reports/reports-templates.js`), que permanece um componente separado do laudário.

O cabeçalho da aba usa `.medico-mascaras-header` e `.medico-mascaras-toolbar`. Os botões são `.btn-pacs-outline` para importar DOCX e `.btn-pacs-primary` para criar máscara, com comportamento responsivo definido localmente na própria view.
