# Padrão — Rodapé Fixo em Listagens (paginação sempre ancorada embaixo)

## Contexto
O layout geral do sistema (`#pacs-wrapper`/`#pacs-content`/`#pacs-page` em `public/assets/css/pacs.css`) usa `min-height: 100vh` em vez de `height: 100vh` — ou seja, é um layout de **fluxo normal do documento** (a janela/`body` rola), não um layout de altura fixa com scroll interno em todo lugar. Só a tabela da worklist (`.wl-table-wrap`) tem scroll interno próprio (`max-height:calc(100vh - 230px)`).

Isso significa que qualquer elemento "de rodapé" colocado como último filho de uma lista (ex.: `.wl-pagination` depois de `.wl-table-wrap`) fica em fluxo normal — se o conteúdo acima dele for curto (poucos resultados), ele aparece logo depois, **no meio da tela**, não ancorado embaixo. `position: sticky` sozinho **não resolve isso**: sticky só "segura" um elemento numa borda durante o scroll, mas não empurra um elemento pra baixo quando não há scroll nenhum acontecendo (conteúdo mais curto que a viewport).

## Solução (aplicada em `.wl-worklist-body`, `app/Views/estudos/index.php`, 2026-08-11)

Combinação de duas técnicas, cada uma resolvendo um caso diferente:

1. **Container flex-column com `min-height` até o fim da viewport** (`.wl-worklist-body{display:flex;flex-direction:column;min-height:calc(100vh - 230px);}`) envolvendo a tabela + a barra de paginação. `min-height` é um piso, não um teto — quando o conteúdo é maior que isso, o container cresce normalmente.
2. **`margin-top:auto` no elemento de rodapé** (`.wl-pagination`) — clássico truque de "sticky footer" via flexbox: com o container acima tendo `min-height` suficiente, `margin-top:auto` empurra o rodapé para a última posição disponível dentro do container, resolvendo o caso de **poucos resultados** (a tabela é curta, mas o rodapé fica na borda inferior da área útil mesmo assim).
3. **`position:sticky; bottom:0;` + `background` opaco no mesmo elemento** — resolve o caso complementar, de **muitos resultados**: quando o conteúdo empurra o container além da altura da viewport e a página precisa rolar, sticky mantém o rodapé colado na borda inferior da viewport durante essa rolagem, em vez de deixá-lo subir/descer com o scroll normal.

As duas técnicas não conflitam — cobrem casos diferentes (conteúdo mais curto que a viewport vs. mais alto) e coexistem no mesmo elemento sem problema.

## Anti-padrão a evitar: `position:absolute`/`sticky` sem length definido no ancestral

Não adianta aplicar `position:sticky;bottom:0` num elemento cujo container pai não tem altura mínima suficiente — o sticky não tem "onde" segurar. Sempre garantir que o ancestral relevante tenha `min-height` (ou `height`) que alcance de fato a borda que se quer ancorar.

## Efeito colateral encontrado e corrigido junto: `overflow` de ancestral recorta dropdowns `position:absolute`

`.wl-table-wrap` tem `overflow-x:auto` — pela [regra de overflow computado do CSS](https://www.w3.org/TR/css-overflow-3/#overflow-properties) (se um eixo não é `visible` e o outro é, o navegador força o outro pra `auto` também), isso faz `.wl-table-wrap` recortar (clip) qualquer descendente `position:absolute` que ultrapasse sua caixa — inclusive um dropdown como `.wl-viewer-menu` (menu "Abrir ▾": Voxel View/VOXEL Desktop/RadiAnt/Weasis) abrindo para baixo a partir de uma linha perto do fim da tabela. Com poucos resultados (tabela curta), isso deixa o dropdown praticamente inacessível — o menu tenta renderizar fora da caixa visível e é cortado, independente de `z-index` (overflow recorta antes do z-index entrar em jogo).

**Correção**: trocar `position:absolute` (relativo a `.wl-viewer-wrap`) por `position:fixed` (relativo à viewport, escapa do clipping do ancestral) com posição calculada via JS a partir de `trigger.getBoundingClientRect()` no momento do clique — inclusive com flip para cima (`abrirParaCima`) quando não há espaço suficiente abaixo do botão. Fecha ao rolar (`window.addEventListener('scroll', fechar, true)`, capture:true para pegar o scroll interno de `.wl-table-wrap` também) em vez de reposicionar em tempo real — é um dropdown de vida curta, a mesma simplicidade do fechar-ao-clicar-fora já existente.

**Regra geral**: sempre que um dropdown/menu `position:absolute` estiver dentro de um ancestral com `overflow` diferente de `visible` (scroll, tabela com scroll interno, card com `overflow:hidden`, etc.), considerar `position:fixed` + JS de posicionamento em vez de `absolute` — é a causa mais comum de "menu corta/some perto da borda do container".

## Onde isso se aplica hoje

`app/Views/estudos/index.php` — usado por `/estudos` **e** `/gestao-exames` (mesma view, `EstudosController::gestao()` → `renderWorklist(true)`, ver `modules/gestao-exames.md`). Qualquer tela nova de listagem com paginação que enfrentar o mesmo sintoma (rodapé "sobe" com poucos resultados) deve reusar esta mesma combinação de técnicas, não reinventar.

## Última análise
2026-08-11
