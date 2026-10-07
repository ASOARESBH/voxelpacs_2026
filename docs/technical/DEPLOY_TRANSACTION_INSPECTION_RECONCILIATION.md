# Correção formal: inspeção e reconciliação de transação de deploy

## Problema

O publicador `voxelpacs-deploy-runtime` recusava qualquer diretório já existente em `transactions/<SHA>` com `TRANSACTION_ALREADY_EXISTS`, mas não havia mecanismo oficial para distinguir uma transação ativa, concluída, abortada, stale ou desconhecida.

## Alteração

A branch adiciona:

- `ops/deploy/voxelpacs-deploy-transaction`: helper root-controlled com `inspect` read-only e `reconcile` fail-closed;
- `ops/sudoers/voxelpacs-deploy-transaction`: allowlist mínima para os dois modos e os dois callers autorizados;
- `scripts/provision-deploy-transaction-helper.sh`: provisionamento administrativo separado, com dry-run, SHA exata e `visudo`;
- testes isolados para os estados e proteções;
- documentação operacional;
- integração no publicador existente para aceitar somente uma transação previamente reconciliada.

## Segurança

O helper não lê `.env`, banco, Jobs, PDF, XML, DICOM, uploads ou logs clínicos. `inspect` não cria arquivos. `reconcile` só cria evidência root-only dentro da transação existente e um marcador `reconciliation/approved`; nunca remove, renomeia ou sobrescreve a transação original.

A reconciliação exige simultaneamente:

- transação existente;
- SHA exata;
- nenhum processo de deploy;
- classificação explícita `ABORTED` ou `STALE`;
- publicação parcial = `NO`;
- rollback pendente = `NO`;
- lock = `NO`;
- owner root, modo `0700` e metadados sem symlink;
- ausência de reconciliação anterior.

Qualquer `UNKNOWN`, `ACTIVE`, `COMPLETED`, lock, publicação parcial ou pendência aborta.

## Integração com novo deploy

Após o marcador aprovado, o publicador mantém a transação histórica e cria uma execução nova em:

```text
/var/lib/voxelpacs/deploy/releases/transactions/<SHA>/runs/<timestamp>-<pid>
```

Sem marcador válido, o comportamento anterior `TRANSACTION_ALREADY_EXISTS` permanece.

## Escopo

```text
DATABASE_CHANGED=NO
MIGRATION_REQUIRED=NO
WORKER=NOT_EXECUTED
JOB=NOT_EXECUTED
BRIDGE=NOT_EXECUTED
SMB=NOT_EXECUTED
TRANSMISSION=NO
DICOM_CSTORE=NOT_CHANGED
PRODUCTION_INSTALL=NOT_EXECUTED
```
