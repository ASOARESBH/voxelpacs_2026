# Correção formal: inspeção e reconciliação de transação de deploy

## Problema

O publicador `voxelpacs-deploy-runtime` recusava qualquer diretório já existente em `transactions/<SHA>` com `TRANSACTION_ALREADY_EXISTS`, mas não havia mecanismo oficial para distinguir uma transação ativa, concluída, abortada, stale ou desconhecida.

## Alteração

A branch adiciona:

- `ops/deploy/voxelpacs-deploy-transaction`: helper root-controlled com `inspect` read-only e `reconcile` fail-closed, incluindo a classificação histórica `HISTORICAL_PUBLISHED_PARTIAL`;
- `ops/sudoers/voxelpacs-deploy-transaction`: allowlist mínima para os dois modos e os dois callers autorizados;
- `scripts/provision-deploy-transaction-helper.sh`: provisionamento administrativo separado, com dry-run, SHA exata, `visudo` e modo `--upgrade` idempotente para instalações existentes;
- testes isolados para os estados e proteções;
- documentação operacional;
- integração no publicador existente para aceitar somente uma transação previamente reconciliada.

## Segurança

O helper não lê `.env`, banco, Jobs, PDF, XML, DICOM, uploads ou logs clínicos. `inspect` não cria arquivos. `reconcile` só cria evidência root-only dentro da transação existente e um marcador `reconciliation/approved`; nunca remove, renomeia ou sobrescreve a transação original.

A reconciliação exige simultaneamente:

- transação existente;
- SHA exata;
- nenhum processo de deploy;
- classificação explícita `ABORTED`, `STALE` ou `HISTORICAL_PUBLISHED_PARTIAL`;
- publicação parcial = `NO`, exceto quando a prova histórica do stage/previous é integral;
- rollback pendente = `NO`;
- lock = `NO`;
- owner root, modo `0700` e metadados sem symlink;
- ausência de reconciliação anterior.

Para uma transação legada criada pelo publicador anterior, `published` com `PARTIAL_PUBLICATION=YES` não é aceito por idade ou conveniência. A exceção administrativa exige prova objetiva de que `validated` e `previous` são árvores completas, têm o mesmo conjunto e SHA dos arquivos, são root-owned `0700`, não contêm symlinks, possuem os arquivos críticos e não possuem marcador manual de publicação parcial. Essa prova preserva a transação original e não altera o runtime.

Qualquer `UNKNOWN`, `ACTIVE`, `COMPLETED`, publicação parcial sem a prova histórica, lock ou pendência aborta.

O modo `--upgrade` não aceita cópia manual: valida a instalação anterior, materializa os dois novos arquivos com owner/mode mínimos, valida o sudoers antes da troca e mantém backups temporários root-only para restaurar a versão anterior se a atualização falhar no meio. O modo `--upgrade --dry-run` é somente leitura.

O inspector emite `TRANSACTION_HISTORICAL_PROOF_REASON` com uma categoria agregada da primeira pré-condição não comprovada, sem paths, conteúdo ou dados clínicos. `PROVEN` só aparece quando todas as validações de owner/mode, symlink, artefatos requeridos e igualdade `validated`/`previous` passam.

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
