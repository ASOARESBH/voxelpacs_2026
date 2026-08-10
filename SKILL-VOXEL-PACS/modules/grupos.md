# Módulo — Grupos (Sistema → Usuários → Grupos)

## Propósito

**Fase 1 apenas** (2026-08-10): CRUD de grupos organizacionais do tenant + vínculo N:N de usuários a grupos. É a base de dados e a UI de cadastro — **não implementa nenhum uso funcional dos grupos ainda** (ver "Fora de escopo" abaixo). Segue o padrão Controller→Service→Repository de `MedicosController` (a referência de CRUD do projeto, ver `modules/medicos.md`), não o padrão de PDO direto do `UsuariosController`/`EstudosController`.

## Arquivos principais

| Arquivo | Papel |
|---|---|
| `app/Controllers/GruposController.php` | Fino: só orquestra Service + monta dados pra view. `tenantId()` é o guard central (mesmo padrão de `MedicosController::tenantId()` — nunca opera sem tenant). |
| `app/Services/GrupoService.php` | Regra de negócio: `validar()` (nome obrigatório 2-200 chars + duplicidade no tenant, descrição até 500 chars), `cadastrar()`, `atualizar()`, `toggleStatus()`, `adicionarMembros()` (com guard `usuarioPertenceAoTenant()` contra IDOR), `removerMembro()`. `SUGESTOES_NOME` = `['Médicos', 'Administrativo', 'Secretarias']` — sugestões de UI, não enum de banco. |
| `app/Repositories/GrupoRepository.php` | Toda SQL (PDO/prepared statements), toda query filtra por `tenant_id`. |
| `app/Views/grupos/index.php` | Listagem de grupos do tenant (nome, descrição, contagem de membros, status, ações). |
| `app/Views/grupos/form.php` | Único arquivo para criar/editar grupo. Em modo criação, mostra só os campos Nome/Descrição (painel de Membros exige grupo já salvo). Em modo edição, mostra também o painel de Membros (lista atual + seleção múltipla de usuários disponíveis para adicionar). |
| `app/Views/usuarios/index.php` | Ganhou a navegação por abas "Usuários" / "Grupos" no topo (link simples, não JS tab-switch — são páginas/rotas diferentes, não seções de um mesmo formulário como em Editar Médico). |

## Modelo de dados

- `bi_grupos` — `id`, `tenant_id`, `nome` (texto livre), `descricao` (opcional), `ativo` (soft delete), `created_at`/`updated_at`.
- `bi_grupo_usuarios` — pivot N:N `grupo_id` ↔ `usuario_id`, com `tenant_id` **denormalizado** (mesmo padrão defensivo de `bi_medico_unidades`/`bi_medico_assinaturas` — "nunca confiar só no JOIN via grupo_id pra escopo de tenant"), `UNIQUE (grupo_id, usuario_id)` evita duplicidade.

Migration: `database/migrations/2026-08-10_bi_grupos_module.sql` (idempotente, `CREATE TABLE IF NOT EXISTS`, InnoDB/utf8mb4 — executar manualmente no phpMyAdmin, mesmo processo do resto do schema `bi_*`).

## Rotas

```
GET  /usuarios/grupos                                    index (lista)
GET  /usuarios/grupos/novo                                novo (form vazio)
POST /usuarios/grupos                                     store
GET  /usuarios/grupos/{id}/editar                         editar (form preenchido + membros)
POST /usuarios/grupos/{id}/atualizar                       atualizar
POST /usuarios/grupos/{id}/excluir                         excluir (soft delete via ativo=0, toggle)
POST /usuarios/grupos/{id}/usuarios/adicionar               adicionarUsuarios (multi, checkbox usuario_ids[])
POST /usuarios/grupos/{id}/usuarios/{usuario_id}/remover    removerUsuario
```

Sem colisão com as rotas existentes de `/usuarios/{id}/edit` / `/usuarios/{id}/update` — `Router::dispatch()` casa por contagem de segmentos + literais exatos (`edit` vs `editar`, `update` vs `atualizar`), então a ordem de registro em `routes/web.php` não importa aqui.

## Isolação de tenant (deny-by-default)

Toda query em `GrupoRepository` filtra por `tenant_id` — inclusive `bi_grupo_usuarios`, que denormaliza `tenant_id` mesmo sendo alcançável via `grupo_id → bi_grupos.tenant_id`. `GrupoService::adicionarMembros()` chama `GrupoRepository::usuarioPertenceAoTenant()` antes de vincular cada `usuario_id` recebido do POST — guard explícito contra um usuário de outro negócio ser vinculado por manipulação de formulário (IDOR). `GruposController::tenantId()` é o mesmo guard central de `MedicosController::tenantId()`: sem tenant ativo, redireciona para `/selecionar-empresa` antes de tocar em qualquer query.

## i18n

Chaves novas em `lang/{pt_BR,en,es}.php`, namespace `usuarios.grupos.*` (+ `usuarios.tabs.*` para os rótulos da navegação Usuários/Grupos) — todas usadas via `t()` nas views novas, seguindo `patterns/padrao-i18n.md`. As 3 línguas têm exatamente as mesmas chaves (validado via script comparando `array_keys()` dos 3 arquivos).

## Fora de escopo nesta fase (TODO explícito, não implementado)

1. **Distribuição de relatórios por grupo** (ex.: SLA MÉDICOS, EXAMES) — depende dos relatórios do menu Relatórios existirem primeiro.
2. **Uso de grupos para restringir/conceder acesso a módulos ou dados** — precisa de decisão arquitetural separada sobre como Grupos convive com o sistema de Perfis atual (`bi_user_tenants.perfil`: admin/medico/secretaria/analista/viewer). **Decisão em aberto para Andre confirmar antes de qualquer Fase 2 que use Grupos para acesso/permissão**: grupo é puramente organizacional (notificação/relatório) ou também afeta o que o usuário vê/faz no sistema? A resposta muda a arquitetura da Fase 2 — não presumir nenhuma das duas opções até essa confirmação.

Nenhuma lógica de relatório ou de permissão foi tocada nesta entrega — `EstudosController`, `ReportsController`, `Auth::perfilAtual()`/`Permission` permanecem exatamente como estavam.

## Última análise
2026-08-10
