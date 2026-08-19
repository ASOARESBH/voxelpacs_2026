# Responsividade e PWA — VOXEL PACS

## Objetivo

A camada responsiva centraliza o comportamento de telas estreitas sem alterar regras clínicas, filtros multitenant ou operações de assinatura. O CSS compartilhado está em `public/assets/css/mobile-responsive.css` e deve ser carregado pelos layouts autenticados, de autenticação, Laudário, BI e Plataforma.

## Breakpoints

| Faixa | Regra de uso |
|---|---|
| Até 575 px | Telefone: menu lateral como drawer, filtros avançados recolhidos, Worklist em cartões e alvos de toque com pelo menos 44 px. |
| 576–900 px | Tablet: filtros podem usar mais colunas, sidebar permanece sobreposta e tabelas administrativas podem rolar horizontalmente. |
| Acima de 900 px | Desktop: preserva a estrutura atual de sidebar, tabelas e filtros completos. |

## Worklist

A Worklist conserva a mesma tabela e o mesmo fluxo de dados. Em telefones, a marcação é renderizada em cartão via CSS usando `data-label` nas células, para preservar paciente, unidade, modalidade, prioridade, estudo, médico, solicitante, pedido, situação e SLA. O JavaScript não altera a consulta, os filtros ou as ações clínicas.

O botão **Mostrar filtros** exibe os filtros avançados no telefone. A seleção de modalidades no telefone não submete imediatamente o formulário, permitindo selecionar várias opções e aplicar a combinação pelo botão Buscar. Em desktop o comportamento de submissão imediata é preservado.

## PWA

O manifesto permite `orientation: any`, mantendo suporte a retrato e paisagem. O Service Worker possui cache versionado para distribuir o CSS responsivo. Safe areas são aplicadas por `viewport-fit=cover` e variáveis CSS nas telas que usam os layouts compatíveis.

## Diretrizes para novas telas

1. Não ocultar dados clínicos relevantes somente por largura de tela.
2. Para tabelas administrativas, usar contêiner horizontal rolável em telas estreitas.
3. Para Worklist ou listas clínicas de alta frequência, preferir cartões CSS com `data-label`.
4. Usar CSS e JavaScript vanilla; não introduzir framework de frontend.
5. Manter alterações de estado e permissões no backend existente.
6. Atualizar `ASSET_VERSION`, a regressão `tests/responsive_mobile_static.php` e o cache do Service Worker ao alterar os assets compartilhados.
