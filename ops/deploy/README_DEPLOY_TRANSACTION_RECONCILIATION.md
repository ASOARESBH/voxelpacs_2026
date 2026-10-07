# Helper de inspeção e reconciliação de transações de deploy

## Objetivo

`voxelpacs-deploy-transaction` é um helper root-controlled para investigar uma transação do publicador de runtime sem abrir o `.env`, banco, Jobs, artefatos clínicos ou integrações. O modo `inspect` é somente leitura. O modo `reconcile` nunca apaga ou sobrescreve a transação original: ele cria evidência root-only e um marcador aprovado somente após comprovar estado `ABORTED` ou `STALE`.

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
| qualquer evidência insuficiente | `UNKNOWN` | bloqueado |

Idade nunca é suficiente para classificar `STALE`. Publicação parcial, rollback pendente, lock ativo, SHA divergente, owner/mode inválidos ou qualquer campo desconhecido bloqueiam a reconciliação.

## Evidência preservada

O reconcile cria, dentro da transação existente:

```text
reconciliation/<timestamp>-<pid>/metadata.tsv
reconciliation/<timestamp>-<pid>/transaction-metadata.tsv
reconciliation/<timestamp>-<pid>/checksum.sha256
reconciliation/approved
```

A evidência contém somente estado técnico, SHA, timestamps, owner, hostname, PID/lock sanitizados e hashes de metadados da própria transação. O conteúdo da aplicação, `.env`, PDFs, XMLs, DICOM, uploads e banco não é incluído.

O marcador `reconciliation/approved` preserva a transação original e autoriza o publicador versionado a criar uma nova execução em `transactions/<SHA>/runs/<timestamp>-<pid>`, sem reutilizar ou sobrescrever os arquivos históricos da transação anterior. O publicador só aceita esse marcador quando ele é root-owned, modo restrito, aponta para o SHA solicitado, registra `runtime_changed=NO` e `original_preserved=YES`, e contém o SHA-256 do arquivo de checksum da evidência; os dois níveis de checksum são verificados antes da nova run.

## Provisionamento

O provisionador não é executado por este PR em produção:

```text
sudo -n bash scripts/provision-deploy-transaction-helper.sh --expected-sha <SHA> --dry-run
sudo -n bash scripts/provision-deploy-transaction-helper.sh --expected-sha <SHA>
```

O segundo comando exige operação administrativa separada. A instalação cria somente o helper e o arquivo sudoers mínimo; não modifica o runtime, transação, banco, Worker, Bridge ou Host2.

## Limites

Este mecanismo não executa deploy, rollback, migration, Worker, Job, Bridge, SMB, transmissão ou DICOM. A instalação em produção e qualquer `inspect`/`reconcile` de produção exigem autorização operacional separada.
