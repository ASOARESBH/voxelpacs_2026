# Sincronização restrita do drift versionado

## Objetivo

`voxelpacs-sync-restricted-drift` é um executor root-controlled **não genérico** para reconciliar somente os 11 arquivos `VERSIONED_REQUIRED` aprovados no preflight do SHA `617e7d67bf88ba325773b220b78052d416eddb2b`.

Ele não é um deploy completo e não deve ser usado para publicar outros arquivos, vendor gerado, `.env`, storage, uploads, logs, banco ou configurações de infraestrutura.

## Contrato fixo

- Source checkout: `/var/lib/voxelpacs/restricted-drift-sync/source/617e7d67bf88ba325773b220b78052d416eddb2b`.
- Destino fixo: `/var/www/voxelpacs/app`.
- Manifesto root-controlled: `/usr/local/share/voxelpacs/voxelpacs-restricted-drift-sync.manifest.tsv`.
- Executor: `/usr/local/sbin/voxelpacs-sync-restricted-drift`.
- Transação/rollback: `/var/lib/voxelpacs/restricted-drift-sync/transactions/617e7d67bf88ba325773b220b78052d416eddb2b`.
- Lock: `/run/lock/voxelpacs-restricted-drift-sync.lock`.

A allowlist é embutida no executor, repetida no manifesto e comparada por SHA-256 antes de qualquer publicação. O operador não pode fornecer `--path`, `--source`, `--destination`, `--file`, `--files`, `--include` ou `--exclude`.

## Allowlist

```text
app/Repositories/ReportDeliveryRepository.php
app/Repositories/ReportDeliveryWorkerRepository.php
app/Services/PhilipsFolderDeliveryService.php
app/Services/PhilipsFolderGatewayBridgeClient.php
app/Services/PhilipsSubmissionPackageProducer.php
bin/report_delivery_worker.php
.htaccess
app/Services/ActiveDestinationResolutionException.php
app/Services/ActiveDestinationResolver.php
composer.json
composer.lock
```

`vendor/composer/*` permanece bloqueado como `RUNTIME_GENERATED`. `VERSAO.txt` e `bin/philips_nondicom_production_diagnostic.php` permanecem fora da operação como `VERSIONED_OPTIONAL`.

## Operação

```bash
sudo -n /usr/local/sbin/voxelpacs-sync-restricted-drift --dry-run
sudo -n /usr/local/sbin/voxelpacs-sync-restricted-drift --execute
```

A execução sem `--execute` não altera produção. O modo `--execute` ainda exige:

1. root e caller `manus-admin` allowlisted;
2. source checkout no SHA fixo, limpo e com `git rev-parse HEAD` exato;
3. manifesto regular, não symlink, com exatamente 11 linhas e hashes esperados;
4. todos os arquivos source regulares, não symlinks, tracked e presentes no SHA;
5. `.env`, `storage` e raiz de runtime presentes;
6. validação de todos os arquivos antes de qualquer cópia;
7. staging temporário e validação dos hashes no staging;
8. snapshot dos 11 destinos atuais para rollback;
9. publicação por arquivo temporário no mesmo diretório e `mv` sem apagar extras;
10. validação pós-publicação e comparação da impressão sanitizada de estado persistente.

Em falha de publicação ou validação pós-publicação, o executor restaura os 11 destinos a partir do snapshot da transação e falha fechado. Ele nunca executa `rsync --delete`, `rm -rf`, limpeza global, Composer, migration, SQL, systemd, Worker, Bridge, SMB ou transmissão.

## Provisionamento root-controlled — etapa separada

Esta PR **não instala** o executor, o manifesto ou o sudoers no Host 1. O administrador root deverá, em uma etapa posterior e autorizada:

1. validar o checkout source no SHA exato;
2. instalar o executor como `/usr/local/sbin/voxelpacs-sync-restricted-drift`, `root:root`, modo `0555`;
3. instalar o manifesto como `/usr/local/share/voxelpacs/voxelpacs-restricted-drift-sync.manifest.tsv`, `root:root`, modo `0444` ou mais restrito;
4. disponibilizar o checkout limpo no source root fixo, sem symlink;
5. instalar o sudoers como `/etc/sudoers.d/voxelpacs-restricted-drift-sync`, `root:root`, modo `0440`;
6. executar `visudo -cf /etc/sudoers.d/voxelpacs-restricted-drift-sync`;
7. validar `stat`, `sha256sum`, `sudo -n -l` e somente depois executar o dry-run.

Não instalar por cópia manual fora de Git nem conceder `NOPASSWD: ALL`. A instalação/provisionamento não faz parte desta PR.

## Rollback

O executor mantém o estado dos 11 paths na transação. Se uma publicação falhar, faz rollback automático. O rollback não toca nos extras, `.env`, storage, uploads, logs, vendor, banco, Worker, Bridge ou serviços.

Uma transação incompleta deve ser preservada para perícia; não remover diretórios de transação automaticamente.

## Estado desta PR

```text
PRODUCTION_DEPLOYED=NO
PRODUCTION_SYNC=NO
WORKER=NO
BRIDGE=NO
SMB=NO
TRANSMISSION=NO
MIGRATION_REQUIRED=NO
```
