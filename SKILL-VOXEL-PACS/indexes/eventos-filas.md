# Índice de Eventos, Filas e Workers

> Este índice é o mais importante para evitar regressões silenciosas: alterar um Service que dispara um evento sem saber quem escuta é a causa clássica de bugs "fantasma" em produção.

## Eventos

| Evento | Disparado por | Listeners conhecidos | Efeito colateral | Última verificação |
|---|---|---|---|---|
| `[A preencher]` | | | | |

## Filas

| Fila | Alimentada por | Worker/Consumer | Idempotente? | Última verificação |
|---|---|---|---|---|
| `[A preencher]` | | | | |

## Jobs assíncronos

| Job | Dispara quando | O que faz | Pode falhar silenciosamente? | Última verificação |
|---|---|---|---|---|
| Robô de Regras de SLA (`SlaRulesEngineService::executarParaTodosTenants()`) | Chamada `GET /api/sla-regras/executar?token=...` — endpoint público protegido por token (`bi_sla_robo_config.token`), pensado para ser chamado por um cron externo (ex: cron-job.org), já que este hosting compartilhado não tem crontab real | Para cada tenant ativo: avalia `bi_sla_regras` ativas (ORDER BY prioridade), busca estudos candidatos em `bi_pacs_estudos` via `EstudosRepository::buscarCandidatosSla()`, resolve médico alvo (`SlaRegrasRepository::resolverMedico*`) e reatribui via `EstudosRepository::reatribuirPorRobo()`, gravando cada remanejamento em `bi_sla_regras_execucoes` | Não — token inválido/robô desativado retornam JSON explícito (`{success:false, message}`); exceções por tenant são capturadas e logadas (`Logger::error`) sem interromper os demais tenants (ver detalhe em `modules/sla-regras.md`) | 2026-07-18 |

## Regra prática

Antes de alterar qualquer Service que dispara evento ou publica em fila, procure primeiro nesta tabela quem consome. Se a tabela não tiver a entrada, faça a busca dirigida (grep pelo nome do evento/fila) e preencha antes de prosseguir com a alteração — não é opcional, é o que evita quebrar um consumidor que você nunca leu.
