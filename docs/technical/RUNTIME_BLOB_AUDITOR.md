# Auditor root-controlled de blob de runtime

## Objetivo

`voxelpacs-runtime-blob-auditor` audita somente o arquivo fixo:

```text
bin/philips_nondicom_production_diagnostic.php
```

O objetivo é determinar se o arquivo atualmente instalado no Host 1 coincide com o arquivo preservado no backup root-only da release, sem copiar, remover ou alterar qualquer arquivo.

## Escopo fixo

```text
APP_ROOT=/var/www/voxelpacs/app
BACKUP_ROOT=/var/backups/voxelpacs/releases
TARGET=bin/philips_nondicom_production_diagnostic.php
```

O caller não pode fornecer `path`, `source`, `destination`, shell, comando, `rsync`, `cp`, `mv`, `rm`, Composer, PHP, systemd, banco, Bridge, SMB ou qualquer operação clínica.

O único argumento variável é o identificador hexadecimal de 40 caracteres do backup:

```bash
sudo -n /usr/local/sbin/voxelpacs-runtime-blob-auditor \
  audit --backup-sha <BACKUP_SHA>
```

## Provas executadas

O helper valida, sem publicar nada:

1. raiz e arquivos do backup root-owned, sem symlinks e sem permissões abertas;
2. checksum do `release.tar.gz`;
3. `source-sha` e `META source_sha` do manifesto;
4. formato do manifesto;
5. entrada única do target no manifesto;
6. entrada única do target no archive;
7. hash do target extraído do archive contra o manifesto;
8. hash do target do runtime contra o manifesto;
9. tamanho, modo, UID, GID e mtime do runtime contra o manifesto.

A saída nunca exibe o conteúdo, hash completo, PDF, XML, `.env`, credencial, log ou dado clínico. O identificador do backup e a hash são truncados na saída.

## Classificações

```text
PROVENANCE=BACKUP_MATCH
```

indica que o runtime coincide com o backup validado. Isso não prova que o arquivo coincide com a `main`; a saída mantém:

```text
MAIN_COMPARISON=NOT_AVAILABLE
```

Se o conteúdo ou metadados divergirem:

```text
PROVENANCE=UNKNOWN_RUNTIME_BLOB
AUDIT=BLOCKED
```

Nenhuma divergência é corrigida automaticamente.

## Provisionamento

O provisioner versionado é:

```text
scripts/provision-runtime-blob-auditor.sh
```

Ele exige checkout limpo, SHA exata e permite somente:

```bash
scripts/provision-runtime-blob-auditor.sh \
  --expected-sha <SHA_DA_MAIN_COM_O_HELPER> --dry-run
```

A instalação posterior, em etapa separada e com autorização específica, publica apenas:

```text
/usr/local/sbin/voxelpacs-runtime-blob-auditor     root:root 0555
/etc/sudoers.d/voxelpacs-runtime-blob-auditor     root:root 0440
```

O provisioner não altera o runtime da aplicação, banco, `.env`, storage, uploads, logs, Worker, Bridge, SMB, DICOM ou transmissão.

## Testes

```bash
bash -n ops/deploy/voxelpacs-runtime-blob-auditor
bash -n scripts/provision-runtime-blob-auditor.sh
visudo -cf ops/sudoers/voxelpacs-runtime-blob-auditor
bash tests/deploy/runtime_blob_auditor_static.sh
bash tests/deploy/runtime_blob_auditor_isolated.sh
```

## Estado operacional

Esta alteração cria código e testes somente. Ela não provisiona o Host 1 e não executa auditoria em produção.

```text
PROVISION=NOT_EXECUTED
PRODUCTION_CHANGED=NO
DATABASE_CHANGED=NO
WORKER=NOT_STARTED
BRIDGE=NOT_CALLED
SMB=NOT_CALLED
TRANSMISSION=NO
DICOM_CSTORE=NOT_CHANGED
MIGRATION_REQUIRED=NO
```
