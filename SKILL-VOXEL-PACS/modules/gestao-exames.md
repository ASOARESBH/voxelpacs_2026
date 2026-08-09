# Módulo — Gestão de Exames

## Propósito
Tela `/gestao-exames` — reaproveita a Worklist de Estudos (`EstudosController::gestao()` → `renderWorklist(true)`, mesma view `app/Views/estudos/index.php`) para o fluxo administrativo de anexar/consultar "Pedido Médico" por estudo. Não tem Controller/View próprios de listagem — é literalmente a Worklist com uma coluna extra (PEDIDO) e sem as ações médicas (Abrir/Assumir/Laudo). Ver `docs/MODULO_GESTAO_EXAMES.md` (documentação funcional completa do fluxo de Pedido Médico) — este arquivo cobre só o que é relevante para navegação/edição de código.

## Arquivos principais
| Arquivo | Papel |
|---|---|
| `app/Controllers/EstudosController.php` (`gestao()`) | Reusa `renderWorklist(true)` — mesmos filtros, paginação, contadores e escopo multi-tenant do `/estudos`, com `LEFT JOIN` extra pros metadados do pedido |
| `app/Views/estudos/index.php` | Mesma view do worklist principal — ver `modules/worklist-estudos.md` |
| `app/Controllers/GestaoExamesController.php` | Upload/remoção/proxy do arquivo do pedido médico |
| `app/Views/layout/pacs_header.php` | Topbar (badges de status + sidebar), **compartilhado com todas as rotas do layout `'pacs'`** — não é parte da tela Gestão de Exames, é o layout em volta dela |

## Barra de badges de status do header (A LAUDAR/EM LAUDO/RASCUNHO/ASSINADO/PEER REVIEW) — visível só para perfil Médico (2026-08-08)

**Onde**: `app/Views/layout/pacs_header.php:206-254` (`#topbar-badges-wrap` + script de polling de `/api/estudos/contadores`).

**Achado-chave da análise**: essa barra **não é exclusiva de Gestão de Exames** — é renderizada pelo layout `'pacs'`, o mesmo usado por `/estudos`, `/agendamentos`, `/reports/{uid}`, `/medicos`, `/relatorios/*`, etc. Confirmado por grep: nenhuma outra referência a essas badges existe em `app/Views/estudos/index.php` nem em qualquer view específica — o pedido original ("aparece igual nas telas de print anexadas") bateu com o código. A correção foi feita **dentro do próprio componente compartilhado**, condicionada ao perfil do usuário — nunca duplicando o header por rota. Ver `architecture/dependencias.md` (linha do `pacs_header.php`).

**Dois sinais de "é médico" existem no código, e não são a mesma coisa** — achado que exigiu confirmação explícita com o usuário antes de codar:
- `bi_user_tenants.perfil === 'medico'` (ENUM `admin/medico/secretaria/analista/viewer`, tela de Usuários, migration `2026-07-25_usuarios_perfil_e_permissoes.sql`) — campo declarado no cadastro do usuário. **Escolhido para esta regra** (decisão do usuário).
- `$isMedicoLogado` em `EstudosController` — vínculo funcional ativo em `bi_medicos` (`usuario_id`+`tenant_id`, `ativo=1`). É o sinal que o próprio `pacs_header.php` já usava (linha 72) para ocultar o link "Gestão de Exames" do menu — mas só é calculado dentro de `EstudosController`, então fica indefinido (default: mostra o link) em qualquer outra rota que renderize este header. Gap pré-existente, não corrigido aqui (fora do escopo desta tarefa — só a barra de badges foi pedida).

**Implementação**: `App\Core\Auth::perfilAtual(): ?string` (novo, `app/Core/Auth.php`) — lê o perfil do tenant ativo a partir de `$_SESSION['user_tenants']`. Exigiu adicionar `ut.perfil` ao `SELECT` já existente em `Auth::login()` (antes só trazia `ut.role`, a coluna antiga de 3 valores admin/analista/viewer — nunca tinha sido atualizado depois que `perfil` foi criado em 2026-07-25). Isso evita 1 query nova a cada render do header (que roda em toda página do sistema) — o preço é que **sessões abertas antes deste deploy não têm `perfil` em cache** até o usuário deslogar/logar de novo; `perfilAtual()` retorna `null` nesse caso (mesmo comportamento seguro de "não é médico" — barra fica oculta até o próximo login).

`pacs_header.php`: bloco inteiro (markup do `#topbar-badges-wrap` **e** o `<script>` de polling) dentro de `<?php if (\App\Core\Auth::perfilAtual() === 'medico'): ?> ... <?php endif; ?>` — remoção real do DOM/render, não CSS. Isso também evita que perfis não-médicos façam a chamada `fetch('/api/estudos/contadores')` a cada 60s sem necessidade.

**Achado registrado, não corrigido (fora do escopo)**: `EstudosController::contadores()` (o endpoint que alimenta os números da barra) filtra só por `tenant_id`/`institution_name` — nunca por médico responsável. Os números mostrados ao perfil Médico são do **negócio inteiro**, não "só os meus estudos". Não é regressão desta tarefa (comportamento pré-existente), registrado em `diagnostics/pendencias-conhecidas.md`.

**Não tocado**: dropdown de filtro "A laudar (Todos)" na barra de busca do worklist (`app/Views/estudos/index.php`) — é um filtro de busca diferente da barra de badges do header, análise confirmou que o pedido original se referia só à segunda.

## Validação executada (2026-08-08)
- `php -l` limpo em `Auth.php` e `pacs_header.php`.
- `Auth::perfilAtual()` testado via sessão simulada: médico/admin/secretaria/analista/viewer no tenant ativo, perfil em outro tenant (não o ativo), sem tenant ativo, sessão antiga sem `perfil` em cache — todos os 5 casos bateram o esperado.
- Render completo do header via PHP CLI (`ob_start()`/`include`) para os 6 perfis possíveis (5 + `null`): badges aparecem só para `medico`; título "VOXEL PACS" e nome do usuário logado presentes e intactos em todos os 6 casos.
- **Não validado**: navegador real, múltiplas rotas ao vivo lado a lado (ambiente sem servidor rodando neste sandbox).

## Última análise
2026-08-08
