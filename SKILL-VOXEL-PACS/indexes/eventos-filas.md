# Índice de Eventos, Filas e Workers

> Este índice é o mais importante para evitar regressões silenciosas: alterar um Service que dispara um evento sem saber quem escuta é a causa clássica de bugs "fantasma" em produção.

## Eventos

| Evento | Disparado por | Listeners conhecidos | Efeito colateral | Última verificação |
|---|---|---|---|---|
| N/A — nenhum dispatcher/listener localizado no código analisado | — | — | — | 2026-09-23 |

## Filas

| Fila | Alimentada por | Worker/Consumer | Idempotente? | Última verificação |
|---|---|---|---|---|
| N/A — nenhuma fila/worker adicional localizado no código analisado | — | — | — | 2026-09-23 |

## Jobs assíncronos

| Job | Dispara quando | O que faz | Pode falhar silenciosamente? | Última verificação |
|---|---|---|---|---|
| Robô de Regras de SLA (`SlaRulesEngineService::executarParaTodosTenants()`) | Primariamente pelo cron interno `cron/sync-sla.php`, executado a cada 5 minutos com `flock`, sem token na URL. A rota `GET /api/sla-regras/executar?token=...` permanece documentada como rota HTTP legada durante a observação e não deve ser tratada como substituta do cron interno sem nova verificação | Para cada tenant ativo: avalia `bi_sla_regras` ativas (ORDER BY prioridade), busca estudos candidatos em `bi_pacs_estudos` via `EstudosRepository::buscarCandidatosSla()`, resolve médico alvo (`SlaRegrasRepository::resolverMedico*`) e reatribui via `EstudosRepository::reatribuirPorRobo()`, gravando cada remanejamento em `bi_sla_regras_execucoes` | Não — o script CLI registra sucesso/erro e retorna código de saída; o serviço captura exceções por tenant e registra o resumo. A rota HTTP legada mantém sua própria validação de token enquanto estiver ativa | 2026-09-23 |

## Evidência e limites

`docs/CRON_INTERNO_PACS_SLA_2026-08-21.md` e `cron/sync-sla.php` confirmam o mecanismo CLI interno no código e na documentação versionados. A existência desses arquivos não prova, sozinha, que o crontab ou o serviço estejam ativos em produção; essa confirmação exige auditoria operacional read-only autorizada.

## Regra prática

Antes de alterar qualquer Service que dispara evento ou publica em fila, procure primeiro nesta tabela quem consome. Se a tabela não tiver a entrada, faça a busca dirigida (grep pelo nome do evento/fila) e preencha antes de prosseguir com a alteração — não é opcional, é o que evita quebrar um consumidor que você nunca leu.
