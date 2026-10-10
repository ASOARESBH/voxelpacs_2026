# Backup root-only de release

## Objetivo

`/usr/local/sbin/voxelpacs-release-backup` é um mecanismo dedicado para criar e validar o backup da release atualmente instalada antes de uma promoção. Ele existe para fornecer a capacidade administrativa mínima que o fluxo N2.2 exige, sem devolver `NOPASSWD: ALL` a `manus-admin`.

A implementação versionada é `scripts/release_backup.sh`. Ela usa somente raízes fixas:

- `APP_ROOT=/var/www/voxelpacs/app`;
- `BACKUP_ROOT=/var/backups/voxelpacs/releases`;
- `RESTORE_TEST_ROOT=/var/backups/voxelpacs/restore-tests`.

Não há argumentos de source, destination, root, command ou shell.

## Operações allowlisted

O executável aceita somente:

```text
voxelpacs-release-backup create --sha <40-hex-sha>
voxelpacs-release-backup validate --sha <40-hex-sha>
voxelpacs-release-backup restore-test --sha <40-hex-sha>
```

O SHA é obrigatório, validado no helper e usado como diretório fixo da release. A regra sudoers usa 40 posições bounded e o helper faz a validação hexadecimal final.

Nenhuma operação aceita comando arbitrário, path traversal, `eval`, ambiente de origem, shell ou execução de serviço.

Cada backup publicado em `BACKUP_ROOT/<SHA>/` contém somente os arquivos técnicos `release.tar.gz`, `manifest.tsv`, `release.sha256`, `source-sha`, `metadata` e `status`, todos root-owned e sem conteúdo de segredo.

## Conteúdo e preservação

O backup captura somente a árvore de release versionada/classificada, incluindo a árvore efetiva `app/` e a árvore plana legada existente quando presente. O arquivo `.env`, `storage`, `public/uploads`, `logs` e `backups` não são seguidos nem arquivados.

O helper registra no manifest:

- SHA da release;
- caminho relativo;
- tamanho;
- SHA-256 do arquivo;
- modo, UID, GID e mtime em POSIX epoch UTC;
- versão do mecanismo;
- exclusões explícitas de persistência e segredo.

O manifest e o metadata nunca contêm conteúdo do `.env`, segredos, PDFs, XML, DICOM ou uploads.

Antes e depois da criação, o mecanismo compara uma impressão sanitizada de `.env`, storage, symlink de storage, uploads, `report_delivery`, logs, backups e configuração flat legada. Qualquer mudança aborta com `PERSISTENT_STATE_CHANGED`.

## Atomicidade e integridade

1. A criação ocorre em diretório temporário root-owned `0700` dentro da raiz fixa.
2. O archive é gerado sem seguir symlinks, com ordem controlada, owners numéricos e gzip sem timestamp.
3. O checksum, manifest e SHA da origem são escritos com modo restrito.
4. O archive, manifest, checksum e metadados são validados antes da publicação.
5. O diretório `<SHA>` é publicado por `mv` atômico.
6. Um backup existente válido retorna `BACKUP=ALREADY_VALID`; um parcial ou corrompido falha fechado e não é removido automaticamente.

`validate` repete checksum, formato, allowlist de paths e paridade archive/manifest.

## Restore-test

`restore-test` extrai somente em `/var/backups/voxelpacs/restore-tests/<SHA>`. Nunca extrai em `APP_ROOT`, nunca toca em `storage`, não restaura `.env` e não altera produção.

O teste compara conjunto de arquivos/diretórios, tamanho, SHA-256, ownership, permissões e mtime. Qualquer divergência retorna falha antes de publicar a área isolada.

## Provisionamento administrativo

`scripts/provision-release-backup.sh` não é allowlisted no sudoers. Ele deve ser executado apenas por administrador root, a partir de checkout limpo, com SHA explícito:

```text
scripts/provision-release-backup.sh --expected-sha <SHA>
```

O procedimento:

1. confirma checkout Git limpo e SHA exato;
2. recusa instalação se o helper ou sudoers já existirem;
3. cria as raízes fixas root:root `0700`;
4. instala o helper como root:root `0755`;
5. instala o sudoers como root:root `0440`;
6. valida com `visudo -cf`;
7. publica os arquivos de forma atômica.

O provisionador não instala nada nesta PR e não é executado contra o Host 1 nesta etapa.
O resultado operacional desta etapa é `PRODUCTION_DEPLOYED=NO`.

## Sudoers

`ops/sudoers/voxelpacs-release-backup` contém somente três regras para `manus-admin`, uma por operação. Não concede shell, `tar`, `cp`, `rm`, `chmod`, `chown`, `rsync`, `systemctl` ou qualquer comando genérico. A instalação deve ser validada com `visudo -cf` antes da ativação.

## Integração com deploy

Esta PR não altera `scripts/deploy.sh`, o helper de publicação existente, banco, migration, Worker, Bridge, SMB, Report Delivery ou transmissão. Após merge e nova confirmação da `main`, a N2.2 deverá executar separadamente:

```text
sudo -n /usr/local/sbin/voxelpacs-release-backup create --sha <SHA>
sudo -n /usr/local/sbin/voxelpacs-release-backup validate --sha <SHA>
sudo -n /usr/local/sbin/voxelpacs-release-backup restore-test --sha <SHA>
```

Somente se todos os gates passarem será possível prosseguir para o deploy controlado autorizado.
