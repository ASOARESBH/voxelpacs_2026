# Philips Non-DICOM — Diagnóstico oficial de produção

## Objetivo

`bin/philips_nondicom_production_diagnostic.php` é um diagnóstico **somente leitura** para avaliar se a preparação técnica do primeiro envio PDF+XML está completa para o tenant `2`, Destination `7`, report `348`, versão `4`.

Ele não cria Request, Outbox ou Job; não arma filas; não executa Worker; não chama Bridge; não executa SMB; não gera PDF/XML; não faz transmissão; não inicia/reinicia serviços; não executa migration e não escreve no banco.

## Execução

O comando é instalado em contexto isolado e deve ser executado somente pelo mecanismo administrativo allowlisted definido para o runtime, como o usuário de aplicação apropriado. O modo sem argumentos preserva o contrato legado de `controlled_production`; o fluxo de laudo liberado sem input manual deve usar explicitamente `automatic_production`:

```text
php /opt/voxelpacs/diagnostics/philips-nondicom/philips_nondicom_production_diagnostic.php
php /opt/voxelpacs/diagnostics/philips-nondicom/philips_nondicom_production_diagnostic.php --dispatch-mode=automatic_production
```

Somente os valores fechados `automatic_production` e `controlled_production` são aceitos. Argumentos adicionais ou modos desconhecidos são rejeitados antes de carregar o diagnóstico.

O caminho acima é um contrato de instalação, não uma autorização para instalar ou executar em produção nesta PR. A instalação requer procedimento operacional separado, backup da configuração administrativa e validação da allowlist.

## Provisionamento administrativo independente

O componente instalado fora do artefato da aplicação é atualizado somente pelo provisioner versionado:

```text
scripts/provision-philips-nondicom-production-diagnostic.sh
```

O provisioner exige execução root-controlled, checkout limpo e `--expected-sha` igual ao `HEAD` do checkout. Além disso, compara o blob Git atual do diagnóstico e do próprio provisioner com os blobs da SHA esperada; isso bloqueia alterações locais escondidas por `assume-unchanged`. O alvo é fixo em `/opt/voxelpacs/diagnostics/philips-nondicom/philips_nondicom_production_diagnostic.php`; não aceita source, destination, comando ou caminho fornecido pelo operador. A atualização materializa o diagnóstico via `git show` da SHA validada, usa staging no mesmo diretório, valida `php -l`, compara o SHA publicado com a origem e cria backup root-only antes da substituição.

Interfaces:

```text
provision-philips-nondicom-production-diagnostic.sh --expected-sha <sha> --dry-run [--upgrade]
provision-philips-nondicom-production-diagnostic.sh --expected-sha <sha> --install [--upgrade]
provision-philips-nondicom-production-diagnostic.sh --rollback --backup-id <backup-id>
```

Esse mecanismo não instala sudoers, não altera `.env`, flags, banco, schema, Worker, Bridge, policy, SMB ou DICOM/C-STORE. Ele também não executa `systemctl`, Worker, `runOne`, Bridge ou transporte. O provisionamento do diagnóstico é administrativamente independente do deploy da aplicação, mas **não contorna nem transforma o drift gate em PASS**: `DRIFT_GATE=NOT_MODIFIED` é emitido pelo próprio mecanismo. A instalação/execução em produção continua exigindo autorização operacional separada e validação da SHA da `main`.

O diagnóstico não carrega `app/bootstrap.php`: evita sessão, criação de diretórios e os handlers web. Ele carrega apenas o autoloader da aplicação, lê o `.env` canônico sem emitir seus valores, usa PDO com `SELECT` parametrizado e consulta `systemctl` somente com argumentos fixos de leitura.

## Escopo fixo

| Item | Valor | Tratamento |
|---|---:|---|
| Tenant | `2` | Fixo no código |
| Destination | `7` | Fixo no código |
| Transport | `philips_non_dicom` | Deve corresponder |
| Ambiente | `producao` | Deve corresponder |
| Profile | `submission_document` | Deve corresponder |
| Modo | `controlled_production` (padrão) ou `automatic_production` | Define o contrato de `disparar_na_liberacao` |
| Report | `348` | Somente metadados |
| Versão | `4` | Somente existência estrutural |

Nenhum conteúdo clínico, PDF, XML, PatientName, segredo, senha, token, cookie, DSN ou credential materializa na saída.

## Gates

O resultado geral é `READY` somente quando todos os gates retornam `PASS`. Qualquer `UNKNOWN`, `BLOCKED` ou ausência de evidência resulta em `overall=BLOCKED`.

1. **Runtime** — flags efetivas do `ReportDeliveryRuntimeConfig`, incluindo Hub/Requests/Non-DICOM ativos, SMB test/read-only OFF e Worker kill switch OFF.
2. **Destination 7** — tenant, transport, produção, habilitado, profile, protocolo SMB, gateway bridge, credencial presente e vínculo PACS. Em `controlled_production`, `disparar_na_liberacao=0`; em `automatic_production`, `disparar_na_liberacao=1`. A segunda regra é a que permite que a liberação clínica encontre o Destination 7 e crie Outbox/Job sem input manual.
3. **Tenant/PACS** — servidor PACS ativo e autorizado para o tenant, com `task_site_id` canônico correspondente. O `task_site_id_alias` é um identificador técnico ASCII separado, obrigatório para o D7 controlado, e não substitui esse binding.
4. **Fila** — o gate usa somente Jobs tenant-scoped do Destination `7`; Jobs de outros destinos não bloqueiam D7. Outbox é exibido apenas como observação tenant-scoped, porque o schema não possui `destination_id` e não permite inferência de destino.
5. **Worker** — unit habilitada, inativa e sem processo.
6. **Bridge** — uma unidade oficial efetivamente proprietária do transporte. Se a propriedade remota não puder ser comprovada, o gate fica bloqueado; o diagnóstico não altera Host 2.
7. **Credential chain** — referência do Destination, referência do target Bridge e resolução do segredo. Presença nominal não é confundida com decifrabilidade; sem prova, `UNKNOWN` bloqueia.
8. **SMB read-only** — nesta primeira versão, permanece `NOT_EXECUTED` porque não existe um mecanismo seguro e dedicado de produção aprovado para autenticação/listagem sem ampliar o escopo. Isso bloqueia o envio até uma autorização e um mecanismo próprio.
9. **Report candidate** — report/version existem no tenant, estão liberados, possuem estudo vinculado e a versão exata está presente. Apenas metadados são lidos.

## Saída

A saída é JSON sanitizado. Os campos de configuração informam presença/estado, nunca valores. O campo `transmission_executed` é sempre `false` neste diagnóstico.

Exemplo de decisão (sem valores de ambiente):

```json
{
  "mode": "READ_ONLY",
  "overall": "BLOCKED",
  "environment": "production",
  "smb": {
    "status": "BLOCKED",
    "readonly_test": "NOT_EXECUTED"
  },
  "transmission_executed": false
}
```

## Segurança

- Tabelas consultadas são allowlisted no código: somente `pacs_report_delivery_outbox` e `pacs_report_delivery_jobs`, além das consultas estruturais fixas do Destination, PACS e report candidato.
- Não há SQL mutável.
- Não há chamada `smbclient`, `curl`, `scp`, `rsync` ou cliente de transporte.
- O comando de processo é restrito a `/usr/bin/systemctl` com `is-enabled`, `is-active` e `show --property=MainPID --value`.
- O processo aceita somente o argumento fechado `--dispatch-mode=automatic_production` ou `--dispatch-mode=controlled_production`; não aceita argumentos arbitrários.
- O diagnóstico não publica alteração em produção. Qualquer provisionamento, allowlist, backup ou deploy é uma fase operacional independente.

## Testes locais

### Probe pós-publicação de liberações

Para uma auditoria read-only do intervalo posterior a uma publicação, use o
probe versionado abaixo com o timestamp UTC da transação de deploy:

```text
php bin/philips_report_release_health_probe.php \
  --since=<timestamp-ISO-8601> --until=<timestamp-ISO-8601>
```

O probe consulta somente auditoria de assinatura/liberação, contagens
tenant-scoped de Outbox/Jobs dos Destinations 6/7 e padrões allowlisted dos
logs. `--until` fecha o intervalo com limite exclusivo; se omitido, o instante
de execução é usado. Ele informa `DATABASE_CHANGED=NO`, não cria sessão clínica,
não executa Worker, não chama Bridge/SMB e não imprime conteúdo clínico. A
categoria `author_signer_mismatch` é distinta de `AUTHOR_UNRESOLVED` e bloqueia
a entrega antes da Bridge. Os erros de log são também agrupados por tenant;
linhas sem contexto de tenant ficam em `UNKNOWN`.

Para testar somente a consulta de autoria publicada, sem gerar XML e ignorando
apenas o gate de data automática, use o probe dedicado:

```text
php bin/philips_author_signer_probe.php \
  --tenant-id=<tenant> --job-id=<job> --read-only
```

Esse probe abre uma transação PostgreSQL `READ ONLY`, executa
`PhilipsSubmissionAuthorRepository::findSignerForReportVersion()` e sempre faz
`ROLLBACK`. A saída é somente categórica: `SIGNER_PATH` (`SELF` ou `PRIOR_ASSINADO`), `SIGNER_FOUND`, `SIGNER_ID=REAL|UNRESOLVED`, `DUPLICATE`,
`SQLSTATE` e `DATABASE_CHANGED=NO`. Não imprime nome, ID numérico, payload,
PatientName, accession, PDF, XML ou segredo.

Para uma execução isolada autorizada, use somente o executor versionado, nunca
um wrapper ad hoc:

```text
sudo scripts/report-delivery-one-shot.sh --job-id=<job>
```

Ele exige Worker global parado, executa o preflight `READ ONLY` com rollback,
confirma que não há outro Job elegível no escopo, chama o PHP como `voxel` no
runtime oficial, preserva stdout/stderr completo em log root-only e propaga o
exit code do PHP sem `tee`/`grep`. O resumo do PHP usa
`RUNONE_RESULT=DELIVERED|NOT_CLAIMED|FAILED|UNCERTAIN`, `REASON`, `JOB_ID` e
`ATTEMPT_ID`; os códigos são `0`, `3` e `4`, respectivamente. O relógio de
elegibilidade é a data clínica `America/Sao_Paulo`, inclusive no `runOne` e na
expiração automática.

```text
php -l bin/philips_nondicom_production_diagnostic.php
php -l tests/philips_nondicom_production_diagnostic_static.php
php tests/philips_nondicom_production_diagnostic_static.php
bash tests/deploy/philips_nondicom_production_diagnostic_provisioner_static.sh
bash tests/deploy/philips_nondicom_production_diagnostic_provisioner_isolated.sh
```

Os testes são sintéticos: não acessam banco, Host 1, Host 2, Bridge, SMB ou dados clínicos. O teste estático verifica a origem por SHA, alvo fixo, backup/rollback, staging, separação do deploy e ausência de operações genéricas. O teste isolado verifica dry-run sem lock/backup, symlink quebrado fail-closed, instalação com backup, checksum e metadados root-only, bloqueio de rollback após alteração posterior do alvo, rollback válido e SHA inválida. A corrida necessária para forçar falha do rollback automático não é fabricada no fixture; o caminho é revisado estaticamente e emite `ROLLBACK=FAILED` sem ocultar o estado.
