# Cron Interno — Sincronização PACS e Robô de SLA

**Data:** 21/08/2026
**Ambiente:** CPX22, `/var/www/voxelpacs/app`
**Responsável técnico:** Manus AI

## Finalidade

A sincronização automática dos estudos PACS e a avaliação das regras de SLA passam a possuir execução interna no CPX22. Os scripts usam diretamente os serviços PHP da aplicação e, portanto, não enviam token em URL, não dependem de uma chamada HTTP externa e preservam os locks, cursores incrementais e logs já existentes no banco.

> A máscara de execução interna não substitui a regra de negócio dos serviços. `PacsSyncService` continua sendo responsável pelo cursor, lock por servidor e registros em `bi_pacs_sync_log`; `SlaRulesEngineService` continua responsável pelo lock global e pelo resumo do robô de SLA.

| Processo | Script CLI | Frequência | Log operacional | Serviço chamado diretamente |
|---|---|---:|---|---|
| Sincronização PACS | `cron/sync-pacs.php` | A cada 2 minutos | `/var/log/voxel/pacs-sync.log` | `PacsSyncService::executarParaTodosServidores()` |
| Regras de SLA | `cron/sync-sla.php` | A cada 5 minutos | `/var/log/voxel/sla-sync.log` | `SlaRulesEngineService::executarParaTodosTenants()` |

## Instalação aplicada no CPX22

Os scripts executam sob o usuário de aplicação `voxel`, identificado no pool PHP-FPM do servidor. O diretório de scripts e o diretório de logs são restritos ao usuário da aplicação.

```cron
# BEGIN VOXEL PACS INTERNAL CRON
# Chamadas diretas aos serviços CLI: não usam token ou rota HTTP.
*/2 * * * * cd /var/www/voxelpacs/app && /usr/bin/flock -n /var/log/voxel/pacs-sync.lock /usr/bin/php cron/sync-pacs.php >/dev/null 2>&1
*/5 * * * * cd /var/www/voxelpacs/app && /usr/bin/flock -n /var/log/voxel/sla-sync.lock /usr/bin/php cron/sync-sla.php >/dev/null 2>&1
# END VOXEL PACS INTERNAL CRON
```

| Recurso | Proprietário | Permissão |
|---|---|---:|
| `/var/www/voxelpacs/app/cron` | `voxel:voxel` | `0750` |
| `cron/sync-pacs.php` e `cron/sync-sla.php` | `voxel:voxel` | `0750` |
| `/var/log/voxel` | `voxel:voxel` | `0750` |
| Arquivos `.log` e `.lock` | `voxel:voxel` | `0640` |

Cada linha de log contém horário, marcador do processo e JSON do resumo. O script PACS retorna código `1` quando algum Orthanc termina em estado `erro` ou `offline`; isso evita que uma indisponibilidade clínica seja registrada como sucesso técnico. O lock de execução usa compatibilidade PostgreSQL e não utiliza sintaxe exclusiva do MySQL.

## Rotas HTTP legadas durante observação

As rotas abaixo **permanecem ativas temporariamente**. Elas não foram removidas nem alteradas nesta migração:

| Rota legada | Finalidade | Regra atual |
|---|---|---|
| `/api/servidor-pacs/sync-robo` | Sincronização PACS via HTTP | Manter durante a observação |
| `/api/sla-regras/executar` | Robô de SLA via HTTP | Manter durante a observação |

O `cron-job.org` não deve ser removido enquanto não houver confirmação de estabilidade. A recomendação é manter a coexistência somente pelo período necessário, pois duas chamadas podem ocorrer no mesmo intervalo; os locks no banco e o `flock` protegem contra ciclos concorrentes, mas não eliminam a necessidade de validação operacional.

## Validação e monitoramento

Execute os comandos abaixo como `root` no CPX22 quando for necessário verificar o estado da automação. Eles não expõem credenciais nem tokens.

```bash
# Estado do daemon de cron e agenda do usuário da aplicação
systemctl is-active cron
crontab -u voxel -l

# Últimos ciclos internos
tail -n 20 /var/log/voxel/pacs-sync.log
tail -n 20 /var/log/voxel/sla-sync.log

# Execução manual controlada
su -s /bin/bash voxel -c 'cd /var/www/voxelpacs/app && /usr/bin/php cron/sync-pacs.php'
su -s /bin/bash voxel -c 'cd /var/www/voxelpacs/app && /usr/bin/php cron/sync-sla.php'

# Evidências no banco por meio do bootstrap da aplicação
cd /var/www/voxelpacs/app
php -r 'require "app/bootstrap.php"; $pdo = \App\Core\Database::getInstance(); print_r($pdo->query("SELECT id, servidor_id, status, origem, iniciado_em, finalizado_em FROM bi_pacs_sync_log ORDER BY id DESC LIMIT 10")->fetchAll(\PDO::FETCH_ASSOC));'
```

A retirada do cron externo somente deve ocorrer depois de **24 a 48 horas** de observação, com ciclos PACS concluídos sem erro, avanço do cursor em `bi_pacs_servidor`, atualizações em `sync_ultima_execucao` e novas linhas concluídas em `bi_pacs_sync_log` com origem `automatico`.

## Estado conhecido durante a implantação

O cron interno, o bootstrap CLI, o banco PostgreSQL e o robô de SLA foram validados no CPX22. Durante a validação do PACS, a rede privada alcançou o Orthanc em `10.0.0.3:8042` e o endpoint `/system` respondeu a autenticação imediatamente. Porém, a chamada autenticada a `/changes` apresentou erro transitório do banco Orthanc e, em tentativas posteriores, timeout de 30 segundos.

> Enquanto `/changes` não voltar a concluir, mantenha o cron externo ativo e não considere a migração PACS finalizada. O cron interno continuará registrando o erro corretamente, com código de saída `1`, sem mascarar a indisponibilidade.

A investigação do Orthanc deve verificar o serviço e o banco de dados no CPX32, especialmente tempo de resposta da rota `/changes`, saúde do banco Orthanc, espaço em disco e logs do serviço. A conectividade de rede privada entre CPX22 e CPX32 não é a causa inicial observada, pois a porta TCP e o endpoint `/system` responderam.

## Reversão

Caso seja necessário retornar imediatamente ao agendamento externo, remova apenas o bloco delimitado da agenda do usuário `voxel` e mantenha os scripts e logs para auditoria.

```bash
TMP=$(mktemp)
(crontab -u voxel -l 2>/dev/null || true) | sed '/^# BEGIN VOXEL PACS INTERNAL CRON$/,/^# END VOXEL PACS INTERNAL CRON$/d' > "$TMP"
crontab -u voxel "$TMP"
rm -f "$TMP"
```

Depois da remoção, reative ou mantenha o job correspondente no cron-job.org. As rotas HTTP legadas continuam disponíveis durante o período de observação, portanto a reversão não exige alteração de código ou banco de dados.

## Desativação definitiva do cron externo

Após os critérios de estabilidade serem cumpridos, desative o job no painel do cron-job.org. Não é necessário apagar as rotas HTTP de imediato; a etapa posterior deve ser uma mudança separada, com auditoria e, preferencialmente, restrição de origem ou remoção controlada dos endpoints legados.
