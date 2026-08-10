# Padrão — Cores de Status (`bi_pacs_estudos.situacao`)

## Regra inegociável
**Vermelho (`#dc2626` texto / `#fef2f2` fundo) é o token já estabelecido para "precisa de atenção urgente"** — usado por `.sit-urgente`, `.mod-CT`, `.sla-vermelho` e `.wl-prio-emergencia` (variante sólida) em `app/Views/estudos/index.php`. Qualquer status novo com essa semântica (bloqueio, atraso, pendência crítica) deve reusar exatamente esses dois valores, não inventar um vermelho novo.

## Onde a cor de cada status é definida — 3 lugares independentes, NÃO derivados de uma fonte única

Não existe hoje uma função/mapa único de "status → cor" compartilhado entre os três componentes abaixo. Cada um mantém seu próprio mapa manualmente, e **já divergiram entre si** para os status que existem nos três ao mesmo tempo (achado desta tarefa, 2026-08-10 — registrado como débito em `docs/PENDENCIAS_CONHECIDAS.md`, não corrigido, fora do escopo pedido).

### 1) Pill da coluna SITUAÇÃO (`app/Views/estudos/index.php`, função `situacaoBadge()`, linhas ~47-60)
Mapa `$sit → [classe CSS, label]`; a classe resolve a cor via CSS logo abaixo (`~linha 930`). Fallback para status sem entrada no mapa: `['sit-novo', strtoupper(str_replace('_',' ',$sit))]` — cai no cinza de "NOVO" com o label auto-gerado a partir do valor bruto (foi assim que `PENDENTE` apareceu sem cor antes desta correção).

| Status | Classe | Fundo | Texto |
|---|---|---|---|
| `novo` | `.sit-novo` | `#f1f5f9` | `#475569` (cinza) |
| `aberto` | `.sit-aberto` | `#eff6ff` | `#1d4ed8` (azul) |
| `pendente` | `.sit-pendente` | `#fef2f2` | `#dc2626` (**vermelho** — novo, 2026-08-10) |
| `a_laudar` | `.sit-a-laudar` | `#fff7ed` | `#c2410c` (laranja) |
| `em_laudo` | `.sit-em-laudo` | `#f5f3ff` | `#7c3aed` (roxo) |
| `rascunho` | `.sit-rascunho` | `#fefce8` | `#a16207` (âmbar) |
| `assinado` | `.sit-assinado` | `#ecfdf5` | `#065f46` (verde escuro) |
| `liberado` | `.sit-liberado` | `#f0fdf4` | `#059669` (verde) |
| `urgente` | `.sit-urgente` | `#fef2f2` | `#dc2626` (vermelho) |

**`peer_review` não tem entrada aqui** — cai no mesmo fallback cinza que `pendente` caía antes desta tarefa. Não corrigido (fora do escopo pedido, que era só `pendente`), mas é o próximo candidato óbvio se alguém reclamar da cor.

### 2) Badges do topbar (`app/Views/layout/pacs_header.php`, `#topbar-badges-wrap`, ~linha 210)
Cor inline por `<span style="background:...;color:...;">`, sem classe/mapa nenhum — cada badge é hardcoded individualmente.

| Status | Fundo | Texto |
|---|---|---|
| `pendente` | `#fef2f2` | `#dc2626` (**vermelho** — novo, 2026-08-10, mesmo token do item 1) |
| `a_laudar` | `#fff7ed` | `#ea580c` |
| `em_laudo` | `#eff6ff` | `#2563eb` |
| `rascunho` | `#f5f3ff` | `#7c3aed` |
| `assinado`+`liberado` (somados) | `#f0fdfa` | `#0d9488` |
| `peer_review` | `#fdf4ff` | `#a21caf` |

**Divergência confirmada vs. o mapa da pill (item 1)**: `a_laudar` (`#ea580c` vs `#c2410c`), `em_laudo` (azul `#2563eb` vs roxo `#7c3aed`), `rascunho` (roxo `#7c3aed` vs âmbar `#a16207`), `assinado` (`#0d9488` vs `#065f46`) — **nenhum dos 4 bate exatamente entre topbar e pill**, embora sejam o mesmo status semântico. Pré-existente, não introduzido por esta tarefa. `pendente` foi adicionado igual nos dois (única entrada 100% consistente entre os dois componentes) porque partiu do mesmo token vermelho já estabelecido — não porque havia um mapa compartilhado guiando a escolha.

### 3) Dropdowns de filtro de situação (`app/Views/estudos/index.php`, `#selectSituacao` e `select[name=situacao_rapida]`, ~linhas 302-333)
Sem cor — só `<option>` com `value`/label. Mas a **lista de valores** é mantida manualmente aqui, separada do ENUM real da coluna `bi_pacs_estudos.situacao` (ver `database/migrations/`, valor mais recente adicionado em `2026-08-10_reports_chat.sql`) — foi exatamente essa divergência (lista do filtro desatualizada em relação ao ENUM) que deixou `pendente` de fora do filtro por 0 dias (o status e o filtro foram criados/corrigidos no mesmo dia, mas por tarefas diferentes). Ver nota em `docs/PENDENCIAS_CONHECIDAS.md`.

Nem toda entrada do ENUM aparece nesses dropdowns por decisão de produto (`revisao` e `peer_review` também estão ausentes) — não presumir que "ENUM completo = dropdown completo" é a meta; é só que a lista precisa ser atualizada deliberadamente a cada novo status relevante para filtro, não fica sincronizada sozinha.

## Checklist ao adicionar um status novo

- [ ] Adicionar ao ENUM `bi_pacs_estudos.situacao` via migration idempotente (ver `patterns/padrao-sql.md`)
- [ ] Decidir a cor: reusar um token já existente desta tabela se a semântica bater (vermelho = urgente/bloqueio, verde = concluído/positivo, laranja = aguardando ação, roxo/azul = em andamento) — só criar cor nova se nenhuma semântica existente servir
- [ ] Adicionar entrada em **todos os 3 lugares** desta tabela que forem relevantes para esse status (nem todo status precisa aparecer nos 3 — decisão de produto) — não presumir que corrigir um propaga para os outros
- [ ] Se adicionar em mais de um componente, usar os MESMOS valores hex nos dois — não repetir a divergência já existente em `a_laudar`/`em_laudo`/`rascunho`/`assinado`
- [ ] Atualizar esta tabela

## Última análise
2026-08-10
