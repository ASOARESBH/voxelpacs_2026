# CLAUDE.md — Memória Viva do VOXEL PACS

> Este arquivo é o resumo de mais alto nível do projeto — o que um Tech Lead novo no time leria primeiro. Ele deve ser **curto o suficiente para caber sempre em contexto** e **atualizado a cada mudança estrutural relevante**. Detalhe fino vive em `architecture/`, `modules/` e `indexes/`; aqui só o essencial para orientação imediata.

> **Status:** este arquivo é um esqueleto inicial. Ele foi criado antes de uma varredura completa do código real do VOXEL PACS. A primeira tarefa de qualquer agente que abrir este repositório deve ser: ler o código real, preencher as seções abaixo com fatos verificados, e remover este aviso. Nunca preencha estas seções com suposições — deixe `[A confirmar no código]` até verificar.

## O que é o VOXEL PACS

Sistema PACS multi-tenant (SaaS — "Negócios" = tenants/clientes) com armazenamento via Orthanc, viewer DICOM (OHIF, não aprofundado ainda), worklist de estudos com SLA, e módulo de laudos. Área `/platform/*` é o superadmin (gestão de todos os tenants); confirmado via `App\Core\Router::dispatch()`, que bloqueia `/platform/*` para quem não é `Auth::isPlatformAdmin()`.

## Stack (confirmado no repositório real em 2026-07-10)

- Backend: PHP puro com arquitetura própria (não é Laravel/Symfony) — `App\Core\Router`, `App\Core\Controller`, `App\Core\Database`, `App\Core\View`, `App\Core\Auth` em `app/Core/`. Sem ORM — acesso a dados via PDO direto (prepared statements) espalhado nos Controllers, com Service/Repository só em alguns módulos (ex: `EstudosService`/`EstudosRepository`)
- Frontend: Views PHP server-rendered (`app/Views/`) + Bootstrap + JS vanilla/fetch (sem framework SPA)
- Banco: MySQL 5.7 / MariaDB (migrations usam procedure `vp_add_col` para `ALTER TABLE` idempotente, já que essa versão não suporta `ADD COLUMN IF NOT EXISTS` nativamente)
- Cache/fila: [A confirmar — nenhuma fila/worker assíncrono encontrado até agora; sincronização Orthanc é síncrona via request HTTP]
- DICOM: Orthanc via REST (`App\Services\OrthancService`) — OHIF mencionado em `app/Views/estudos/viewer.php`, não aprofundado
- HL7: [A confirmar — não localizado ainda]
- Infra: `Dockerfile` na raiz — orquestração não confirmada

## Módulos principais (link rápido para `modules/`)

| Módulo | Resumo de uma linha | Arquivo de detalhe |
|---|---|---|
| Servidor PACS | Dashboard/config/sync do Orthanc global, roteamento InstitutionName → Negócio | `modules/servidor-pacs.md` |
| Negócios | CRUD de tenants, InstitutionNames DICOM, Unidades DICOM (schema novo, CRUD de API pronto, UI pendente) | `modules/negocios.md` |
| Worklist Estudos | Tela `/estudos` — worklist principal do usuário final, filtros, abertura no OHIF | `modules/worklist-estudos.md` |

## Convenções que todo agente deve saber antes de tocar em código

- Nunca alterar banco sem migration (ver `patterns/padrao-sql.md`).
- Nunca colocar lógica de negócio em Controller (ver `patterns/padrao-controller.md`).
- Nunca quebrar compatibilidade DICOM (Study/Series/Instance/SOP UID/Transfer Syntax) sem validação explícita.
- Toda alteração em integração HL7 é tratada como alto risco por padrão.

## Como este arquivo se mantém sincronizado

Toda vez que um agente descobre um módulo novo, uma tecnologia nova na stack, ou uma convenção nova:

1. Atualiza a tabela de módulos ou a seção de stack aqui.
2. Garante que o detalhe completo está em `architecture/` ou `modules/`, e que este arquivo só tem o resumo de uma/duas linhas apontando para lá.
3. Remove qualquer `[A confirmar no código]` que já tenha sido verificado.

Se este arquivo cresce além de ~150 linhas, é sinal de que detalhe demais está entrando aqui — mova para `architecture/` ou `modules/` e deixe só o índice/resumo.
