# Incidente — Duplicação de abas + conteúdo vazio em Editar Médico (2026-08-07/08)

## Contexto
`app/Views/medicos/form.php` (rota `GET /medicos/{id}/edit`, `MedicosController::edit()`) foi reorganizado em 3 abas (Dados do Médico / Copilot do Laudo / Máscaras) no commit `228cf5c`, usando o mecanismo Bootstrap 5 (`data-bs-toggle="tab"`) — ver `modules/medicos.md` para a estrutura original. Depois disso, o commit `413e9fe` ("feat(mascaras): módulo completo de máscaras/templates de laudo") implementou o CRUD real de Máscaras (`TemplatesController`, `report_templates`), mas **sem saber que a barra de abas Bootstrap já existia neste mesmo arquivo** — construiu um segundo sistema de abas paralelo do zero. Isso causou dois bugs visuais reportados via screenshot em 2026-08-07/08.

## Sintomas
1. **Barra de abas duplicada** — com "Dados do Médico" ativa, a barra de abas aparecia duas vezes empilhada; ao trocar de aba, a duplicação sumia.
2. **Conteúdo vazio ao trocar de aba** — "Copilot do Laudo" e "Máscaras" ficavam 100% em branco (sem placeholder, sem erro visível). Copilot era regressão (funcionava antes da reorganização); Máscaras tinha uma explicação alternativa possível (era placeholder documentado) que **acabou não sendo o caso real** — ver achado abaixo.

## Causa raiz
Dois sistemas de tabs coexistindo no mesmo arquivo:

| | Bloco A (Bootstrap, original) | Bloco B (legado, commit `413e9fe`) |
|---|---|---|
| Mecanismo | `data-bs-toggle="tab"` + `.tab-pane` | `data-tab="..."` + `addEventListener('click')` manual |
| Localização | Dentro de `<form id="formMedico">` | Fora do `<form>`, antes dele |
| Classe do botão | `.medico-tab-btn` | `.medico-tab-btn` (**mesma classe**, coincidência não intencional) |

O JS do Bloco B fazia `document.querySelectorAll('.medico-tab-btn')` — como os botões dos dois blocos compartilhavam a classe, o mesmo handler ficava preso em ambos. Ao clicar em qualquer botão com `data-tab !== 'dados'`, o handler fazia `document.getElementById('formMedico').style.display = 'none'` — escondendo o `<form>` inteiro, que continha o Bloco A por dentro. Resultado:
- Em "Dados do Médico" (estado inicial): as duas barras apareciam (nada tinha escondido o form ainda) → **Bug 1**.
- Ao trocar de aba: o form inteiro sumia — inclusive o conteúdo real do Copilot (`#aba-copilot`, nunca removido, só ficou soterrado) e o placeholder de Máscaras (`#aba-mascaras`) → **Bug 2**, mesma causa raiz para as duas abas.

**Achado extra**: o conteúdo real de Máscaras (busca, filtros, lista, importar DOCX — implementado no mesmo commit `413e9fe`) vivia num `<div id="tab-mascaras" style="display:none;">` **fora do form**, com **ID duplicado** em relação ao botão da aba no Bloco A (que também usava `id="tab-mascaras"`). `document.getElementById('tab-mascaras')` sempre resolvia pro botão (primeiro no DOM), nunca pro conteúdo real — então mesmo que o Bloco B "funcionasse", a feature de Máscaras já implementada nunca ficaria visível. Ou seja: **Máscaras não era mais um placeholder sem funcionalidade — a funcionalidade real já existia, só estava desconectada.**

## Correção aplicada (2026-08-08)
Um arquivo só, `app/Views/medicos/form.php` (25 inserções / 110 remoções):
1. Removido o Bloco B por completo: HTML (`#medicoTabsNav`), CSS duplicado (`.medico-tabs-nav`/1ª definição de `.medico-tab-btn`), e o listener JS (`DOMContentLoaded` com `querySelectorAll('.medico-tab-btn')`).
2. Copilot do Laudo: nenhuma mudança de conteúdo necessária — resolvido automaticamente ao remover o Bloco B, já que o conteúdo nunca tinha sido removido do Bloco A.
3. Máscaras: o conteúdo real (busca/filtros/lista/botões Nova Máscara e Importar DOCX) foi movido de dentro do `<div id="tab-mascaras">` órfão (fora do form) para dentro de `#aba-mascaras` (Bloco A), substituindo o placeholder estático. O `id="tab-mascaras"` duplicado foi resolvido mantendo-o só no botão da aba (padrão `tab-dados`/`tab-copilot`/`tab-mascaras` já usado pelos outros dois). O modal de edição (`#modalMascara`) e o de importação (`#modalImportar`) continuam como overlays fixos fora do form — não precisam estar aninhados na tab-pane pra funcionar.
4. `carregarMascaras()` agora é chamado via `document.getElementById('tab-mascaras').addEventListener('shown.bs.tab', ...)` — o evento nativo do Bootstrap disparado quando a aba fica ativa — em vez do handler manual removido.
5. **Efeito colateral consciente**: o suporte a `?tab=mascaras` na URL (do Bloco B) foi removido; `?aba=mascaras` (mecanismo do Bloco A, já existente desde a reorganização original) é o substituto — mesmo padrão de nome usado pelas outras 2 abas. Nenhum link/`href` no projeto apontava para `?tab=mascaras` (grep confirmou).

## O que NÃO mudou
- Nenhuma rota, controller, service ou tabela do banco foi tocada — bug era 100% de view/frontend.
- Lógica de negócio do Copilot (gerar/regenerar/revogar token, toggle Laudário Interno) e do CRUD de Máscaras (`TemplatesController`) — inalteradas, só a montagem visual.
- `/medicos/create` (novo médico, sem abas) — não afetado, confirmado por render via PHP CLI antes e depois da correção.

## Validação executada
- `php -l` limpo.
- Render via PHP CLI (`app/autoload.php` + `include` direto, mesmo método usado em tarefas anteriores desta sessão, sem navegador real disponível neste ambiente):
  - Create mode: nenhuma barra de abas presente (`id="medicoTabs"` ausente) — inalterado.
  - Edit mode: exatamente 1 `id="tab-mascaras"` no HTML renderizado (zero colisão), exatamente 3 `<button class="medico-tab-btn"` no total (uma barra só).
  - `?aba=mascaras`: ativa a `tab-pane` certa e mostra o conteúdo real (botão "Nova Máscara" presente).
  - `?aba=copilot`, médico sem token: ativa a `tab-pane` certa e mostra "Gerar Token Copilot" (fluxo de médico recém-criado, sem token ainda).
- **Não validado nesta sessão** (sem navegador real disponível): clique físico entre abas, chamada de rede real de `carregarMascaras()`/`GET /api/medicos/{id}/templates` ao abrir a aba, e o evento `shown.bs.tab` disparando de fato no Bootstrap ao vivo (o mecanismo é padrão/documentado da lib, já usado em outras 2 abas sem problema, mas o novo listener específico de Máscaras não foi clicado fisicamente).

## Lição para quem tocar nesta tela de novo
Antes de adicionar qualquer coisa relacionada a "abas" em `medicos/form.php`, **procurar por `data-bs-toggle="tab"` e `.medico-tabs-bar` primeiro** — o mecanismo de abas desta tela já existe e é o Bootstrap 5 nativo (mesmo padrão de `app/Views/platform/negocios/form.php`). Não criar um segundo sistema de tab-switching manual. Ver `modules/medicos.md` para a estrutura completa das 3 abas.

## Última análise
2026-08-08
