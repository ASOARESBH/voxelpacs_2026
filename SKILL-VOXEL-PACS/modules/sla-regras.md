# Módulo — Regras de SLA

## Propósito
Cadastros > Regras de SLA (`/sla-regras`) — Fase 2 do módulo SLA. A Fase 1 (`SlaConfig`, `2026-07-08_bi_pacs_estudos_sla.sql`) só captura/exibe os contadores SLA Estudo e SLA Médico na worklist, sem nenhuma ação automática. Este módulo adiciona regras condicionais configuráveis ("se SLA Médico > 2h20min, remaneje para outro médico") e um robô que aplica essas regras periodicamente, reatribuindo de verdade o médico responsável por um estudo (`bi_pacs_estudos.assumido_por`).

## Arquivos principais
| Arquivo | Papel |
|---|---|
| `app/Controllers/SlaRegrasController.php` | CRUD das regras + telas de histórico e config do robô. Todo método checa `Auth::can('manage_sla_regras')` no construtor (não há guard de role automático fora de `/platform`) |
| `app/Controllers/SlaRoboController.php` | Endpoint público `GET /api/sla-regras/executar` — dispara o robô, protegido por token |
| `app/Services/SlaRulesEngineService.php` | O motor/"robô": avalia regras ativas por tenant, resolve o médico alvo, aplica o remanejamento, controla o lock de concorrência |
| `app/Repositories/SlaRegrasRepository.php` | Acesso a dados do motor: tenants ativos, regras ativas, resolução de médico elegível por tipo de ação, histórico |
| `app/Repositories/EstudosRepository.php` | Métodos `buscarCandidatosSla()` e `reatribuirPorRobo()` (adicionados nesta tarefa, reaproveitando a semântica de `assumirEstudo()` já existente) |
| `app/Models/SlaRegra.php` | Model fino do CRUD (`bi_sla_regras`) |
| `app/Models/Medico.php` | Ganhou `findUsuariosVinculaveis()`, `getUnidadesVinculadas()`, `sincronizarUnidades()` |
| `app/Views/sla_regras/*.php` | `index`, `form`, `execucoes` (histórico), `robo` (config/token) |

## Dependências
- Depende de: `bi_sla_regras`, `bi_sla_regras_execucoes`, `bi_sla_robo_config`, `bi_medico_unidades`, `bi_medicos` (+ coluna `usuario_id`), `bi_pacs_estudos`, `bi_users`, `EstudosRepository::getUnidades()` (fonte do filtro de unidade, reaproveitada do worklist)
- Consumido por: nenhum outro módulo lê este diretamente — o robô é quem escreve em `bi_pacs_estudos` (mesmas colunas que `ReportsController::assumir()` já escreve)
- Ver `indexes/tabelas-banco.md`, `indexes/rotas-api.md` e `indexes/eventos-filas.md` para as entradas detalhadas

## Decisão estrutural: por que `bi_medicos` precisou de `usuario_id`
`bi_pacs_estudos.assumido_por` sempre guarda um `bi_users.id` (quem clicou "Assumir" no laudo), nunca um `bi_medicos.id` — os dois cadastros ("Médico" e "Usuário do sistema") eram desconectados antes desta tarefa. Sem um vínculo explícito, uma regra que aponta para "o médico X do cadastro" não teria nenhum efeito real na worklist. Resolvido com `bi_medicos.usuario_id` (migration `2026-07-17_bi_medicos_vinculo_usuario_e_unidades.sql`, `UNIQUE(tenant_id, usuario_id)`). **Consequência prática**: um médico cadastrado sem essa conta vinculada nunca é elegível para nenhuma regra — a tela de Médicos mostra um badge "Sem vínculo" para isso.

## Decisão estrutural: "unidade" é `institution_name`, não `bi_unidades`
A tabela legada `bi_unidades` (módulo BI antigo) está desconectada da worklist PACS. A "unidade" real da worklist é o texto DICOM `institution_name` em `bi_pacs_estudos`, já usado pelo filtro de unidade existente (`EstudosRepository::getUnidades()`). O vínculo médico↔unidade novo (`bi_medico_unidades`) segue essa mesma fonte, por `institution_name` exato — sem FK formal, porque nem `bi_tenant_unidades_dicom` nem `bi_pacs_estudos` garantem unicidade/estabilidade desse texto (ver `indexes/tabelas-banco.md`, seção "Cuidados especiais para tabelas clínicas/DICOM").

## Motor de regras (`SlaRulesEngineService`)
Fluxo por tenant, em `executarParaTenant()`:
1. Busca regras ativas ordenadas por `prioridade` (menor primeiro).
2. Para cada regra, busca estudos candidatos (`EstudosRepository::buscarCandidatosSla()`), calculando `TIMESTAMPDIFF(MINUTE, assumido_em|recebido_em, NOW())` conforme a métrica da regra — `metrica`/`operador` são resolvidos por whitelist fixa em PHP antes de montar o SQL, nunca concatenados de input livre.
3. Um estudo já remanejado nesta mesma execução (por outra regra de prioridade menor) **não é reavaliado** no mesmo ciclo — evita cascata de regras diferentes trocando o mesmo estudo várias vezes seguidas.
4. Resolve o médico alvo conforme `tipo_acao` (`SlaRegrasRepository`): `especifico` usa o médico configurado na regra; `aleatorio`/`menor_carga` escolhem entre médicos elegíveis (`ativo=1`, `usuario_id IS NOT NULL`, dentro do tenant, fora do responsável atual, filtrados por `bi_medico_unidades` quando a regra tem `filtro_institution_name`). Empate de carga é desempatado por `RAND()`.
5. Reatribui via `EstudosRepository::reatribuirPorRobo()` — mesma semântica de colunas de `assumirEstudo()` (`situacao='em_laudo', assumido_por, assumido_em=NOW(), usuario_responsavel_id`), mas **sem** a restrição `situacao IN ('novo','aberto')`, porque o robô também precisa poder trocar um estudo já `em_laudo` (é justamente o caso de estouro de SLA Médico). Estudos `assinado`/`liberado` nunca são candidatos, em nenhuma métrica.
6. Grava o remanejamento em `bi_sla_regras_execucoes` (visível em `/sla-regras/execucoes`).

## Lock de concorrência
`bi_sla_robo_config.lock_adquirido_em` — `adquirirLock()` faz um `UPDATE` condicional (só avança se `lock_adquirido_em IS NULL` ou mais velho que `SlaRulesEngineService::LOCK_TTL_MINUTES`, hoje 15 min) e checa `rowCount() === 1`. Isso é deliberadamente diferente do antigo `PacsController::cronPing()` (revertido, sem lock nenhum) — aqui é obrigatório porque o robô muda atribuições médicas reais, e duas chamadas sobrepostas do cron externo poderiam remanejar o mesmo estudo duas vezes em sequência.

## Disparo do robô — sem crontab real
Ambiente é hosting compartilhado sem crontab (ver `docs/SYNC_AUTOMATICO_PACS.md` para o histórico do mecanismo equivalente do módulo PACS, que foi revertido silenciosamente). O robô é disparado por `GET /api/sla-regras/executar?token=...`, pensado para ser chamado por um serviço de cron externo (ex: cron-job.org). **Precisa estar público em dois lugares** — achado replicado do caso de `/api/orthanc/ping`: `App\Core\Router::$publicRoutes` e `public/index.php::$rotasPublicas` são duas listas independentes; faltar em uma delas faz a chamada externa cair no redirect de login em vez de responder JSON.

## Permissão
`manage_sla_regras` (`app/Core/Permission.php`), restrita a `admin`/`superadmin` — é a primeira permissão do array `Permission::$permissions` a ser efetivamente checada por um Controller (`Auth::can()` existia mas estava órfã antes desta tarefa). `analista`/`viewer` não têm acesso, por ser ação administrativa sensível.
