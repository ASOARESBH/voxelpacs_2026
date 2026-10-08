# Helper de inspeção e reconciliação de transações de deploy

## Objetivo

`voxelpacs-deploy-transaction` é um helper root-controlled para investigar uma transação do publicador de runtime sem abrir o `.env`, banco, Jobs, artefatos clínicos ou integrações. O modo `inspect` é somente leitura. O modo `reconcile` nunca apaga ou sobrescreve a transação original: ele cria evidência root-only e um marcador aprovado somente após comprovar estado `ABORTED`, `STALE` ou a classificação histórica `HISTORICAL_PUBLISHED_PARTIAL`.

## Interface

```text
sudo -n /usr/local/sbin/voxelpacs-deploy-transaction inspect --sha <40-hex-sha>
sudo -n /usr/local/sbin/voxelpacs-deploy-transaction reconcile --sha <40-hex-sha>
sudo -n /usr/local/sbin/voxelpacs-deploy-transaction reconcile-historical --sha d9abd9dec1302654afa2a6f556064212d503c0bc
sudo -n /usr/local/sbin/voxelpacs-deploy-transaction reconcile-partial --sha <40-hex-sha>
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
| `published`, publicação parcial, árvores root-only seguras, divergência agregada comprovada e comando versionado explícito | `HISTORICAL_PUBLISHED_PARTIAL_RECONCILED` | permitido pelo `reconcile-partial` |
| qualquer evidência insuficiente | `UNKNOWN` | bloqueado |

Idade nunca é suficiente para classificar `STALE`. Publicação parcial não comprovada, rollback pendente, lock ativo, SHA divergente, owner/mode inválidos ou qualquer campo desconhecido bloqueiam a reconciliação. A classificação histórica só é aceita quando o status `published` é válido, o stage e o snapshot `previous` têm exatamente o mesmo conjunto e SHA dos arquivos, ambos são root-owned `0700`, não há symlinks, os arquivos críticos estão presentes e não existe `partial_publication` manual.

O modo `inspect` também retorna `TRANSACTION_HISTORICAL_PROOF_REASON` como categoria técnica agregada, sem caminho ou conteúdo. Os valores incluem `PROVEN`, `STAGE_PREVIOUS_MISMATCH`, `REQUIRED_ARTIFACT_MISSING`, `VALIDATED_STAGE_NOT_SAFE`, `PREVIOUS_STAGE_NOT_SAFE`, `VALIDATED_STAGE_OWNER_MODE_INVALID`, `PREVIOUS_STAGE_OWNER_MODE_INVALID`, `VALIDATED_CONTAINS_SYMLINK`, `PREVIOUS_CONTAINS_SYMLINK`, `TRANSACTION_ROOT_OWNER_MODE_INVALID` e `EXPLICIT_PARTIAL_MARKER`.

Quando a comparação alcança as árvores, `inspect` também retorna `TRANSACTION_PROOF_SET_MISMATCHES` (`YES`/`NO`/`NOT_EVALUATED`) e `TRANSACTION_PROOF_HASH_MISMATCHES` (contagem não negativa ou `NOT_EVALUATED`). Nenhum valor contém nomes de arquivos.

Quando o conjunto coincide, a contagem total é subdividida em `TRANSACTION_PROOF_HASH_MISMATCH_APP`, `TRANSACTION_PROOF_HASH_MISMATCH_PUBLIC`, `TRANSACTION_PROOF_HASH_MISMATCH_VENDOR_COMPOSER`, `TRANSACTION_PROOF_HASH_MISMATCH_VENDOR_OTHER` e `TRANSACTION_PROOF_HASH_MISMATCH_OTHER`. São contagens agregadas; qualquer divergência continua bloqueando a prova histórica normal.

O modo `reconcile-historical` é uma exceção versionada, root-only e exclusiva do SHA D9. Ele aceita somente a assinatura formalmente correlacionada ao delta da PR74 e ao metadata Composer gerado: conjunto stage/previous igual, 3 mismatches de hash, sendo 2 em `app/`, 0 em `public/`, 1 em `vendor/composer/`, 0 em `vendor/` restante e 0 em outras áreas. Exige `published`, sem processo, lock ou rollback pendente, e registra a razão agregada `historical_d9_expected_delta_app2_vendor_composer1`. Outro SHA, contagem, área ou conjunto divergente permanece bloqueado.

O modo `reconcile-partial` é o procedimento administrativo versionado para uma publicação parcial já reconhecida operacionalmente, quando a prova histórica normal não pode exigir igualdade entre `validated` e `previous`. Ele aceita qualquer SHA somente quando o status é `published`, não há processo de deploy, lock ou rollback pendente, a transação e as árvores `validated`/`previous` são root-owned `0700`, não existem symlinks, os artefatos críticos estão presentes, não existe arquivo manual `partial_publication` e a comparação agregada demonstra divergência de conjunto ou pelo menos uma divergência de hash. O comando não recebe caminho, manifesto, motivo, shell ou parâmetro livre; a autorização operacional fica restrita ao caller e ao SHA no sudoers.

Esse modo **não altera** `status`, `pid`, `rollback.pending`, `state.tsv`, `validated` ou `previous`. Ele somente cria a evidência root-only e o marcador `reconciliation/approved` através do helper versionado, com classificação `HISTORICAL_PUBLISHED_PARTIAL_OPERATOR_APPROVED`, `runtime_changed=NO` e `original_preserved=YES`. O publicador usa esse marcador apenas para abrir uma nova run; backup verificável, artefato, drift e SHA da `main` continuam sendo gates independentes.

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

Este mecanismo não executa deploy, rollback, migration, Worker, Job, Bridge, SMB, transmissão ou DICOM. A instalação em produção e qualquer `inspect`/`reconcile`/`reconcile-partial` de produção exigem autorização operacional separada. Após uma reconciliação parcial aprovada, repetir backup e drift compare antes de iniciar o deploy integral.
