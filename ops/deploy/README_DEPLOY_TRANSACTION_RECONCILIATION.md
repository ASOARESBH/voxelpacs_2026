# Helper de inspeção e reconciliação de transações de deploy

## Objetivo

`voxelpacs-deploy-transaction` é um helper root-controlled para investigar uma transação do publicador de runtime sem abrir o `.env`, banco, Jobs, artefatos clínicos ou integrações. O modo `inspect` é somente leitura. O modo `reconcile` nunca apaga ou sobrescreve a transação original: ele cria evidência root-only e um marcador aprovado somente após comprovar estado `ABORTED`, `STALE` ou a classificação histórica `HISTORICAL_PUBLISHED_PARTIAL`.

## Interface

```text
sudo -n /usr/local/sbin/voxelpacs-deploy-transaction inspect --sha <40-hex-sha>
sudo -n /usr/local/sbin/voxelpacs-deploy-transaction reconcile --sha <40-hex-sha>
```

O helper aceita somente caller `manus-admin` ou `manus-deploy` via sudoers. Não aceita path, shell, comando, ambiente, motivo livre, banco, `systemctl`, `rm -rf`, `rsync` ou acesso a secrets.

## Estados

A classificação é fail-closed:

| Estado | Classificação | Reconcile |
|---|---|---|
| processo/lock ativo ou `publishing` | `ACTIVE` | bloqueado |
| `published`, runtime confere e sem pendências | `COMPLETED` | bloqueado |
| `aborted`/rollback explícito, sem publicação parcial | `ABORTED` | permitido |
| marcador explícito `stale`, sem publicação parcial | `STALE` | permitido |
| `published`, stage/previous completos, root-only, sem symlinks e sem marcador manual de partial | `HISTORICAL_PUBLISHED_PARTIAL` | permitido |
| qualquer evidência insuficiente | `UNKNOWN` | bloqueado |

Idade nunca é suficiente para classificar `STALE`. Publicação parcial não comprovada, rollback pendente, lock ativo, SHA divergente, owner/mode inválidos ou qualquer campo desconhecido bloqueiam a reconciliação. A classificação histórica só é aceita quando o status `published` é válido, o stage e o snapshot `previous` têm exatamente o mesmo conjunto e SHA dos arquivos, ambos são root-owned `0700`, não há symlinks, os arquivos críticos estão presentes e não existe `partial_publication` manual.

## Evidência preservada

O reconcile cria, dentro da transação existente:

```text
reconciliation/<timestamp>-<pid>/metadata.tsv
reconciliation/<timestamp>-<pid>/transaction-metadata.tsv
reconciliation/<timestamp>-<pid>/checksum.sha256
reconciliation/approved
```

A evidência contém somente estado técnico, SHA, timestamps, owner, hostname, PID/lock sanitizados e hashes de metadados da própria transação. O conteúdo da aplicação, `.env`, PDFs, XMLs, DICOM, uploads e banco não é incluído.

O marcador `reconciliation/approved` preserva a transação original e autoriza o publicador versionado a criar uma nova execução em `transactions/<SHA>/runs/<timestamp>-<pid>`, sem reutilizar ou sobrescrever os arquivos históricos da transação anterior. O publicador só aceita esse marcador quando ele é root-owned, modo restrito, aponta para o SHA solicitado, registra `runtime_changed=NO` e `original_preserved=YES`, e contém o SHA-256 do arquivo de checksum da evidência; os dois níveis de checksum são verificados antes da nova run. Nenhuma classificação autoriza o deploy por si só: backup, drift, artefato e SHA continuam sendo gates separados.

## Provisionamento

O provisionador não é executado por este PR em produção:

```text
sudo -n bash scripts/provision-deploy-transaction-helper.sh --expected-sha <SHA> --dry-run
sudo -n bash scripts/provision-deploy-transaction-helper.sh --expected-sha <SHA>
sudo -n bash scripts/provision-deploy-transaction-helper.sh --expected-sha <SHA> --upgrade --dry-run
sudo -n bash scripts/provision-deploy-transaction-helper.sh --expected-sha <SHA> --upgrade
```

Os dois primeiros comandos fazem instalação única. Os dois últimos são a atualização controlada de uma instalação existente: exigem helper e sudoers regulares, root-owned e com modos `0750`/`0440`, validam a nova fonte pela SHA do checkout, usam arquivos temporários e preservam cópias locais root-only para restauração se a segunda substituição falhar. `--dry-run` nunca escreve. Nenhum modo modifica o runtime da aplicação, transação, banco, Worker, Bridge ou Host2.

## Limites

Este mecanismo não executa deploy, rollback, migration, Worker, Job, Bridge, SMB, transmissão ou DICOM. A instalação em produção e qualquer `inspect`/`reconcile` de produção exigem autorização operacional separada.
