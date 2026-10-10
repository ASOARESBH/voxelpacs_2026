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

O provisionador versionado é `scripts/provision-restricted-drift-sync.sh`. Ele não é allowlisted no sudoers e exige root administrativo, checkout limpo e o SHA exato da `main` que contém o próprio provisionador. O `SYNC_TARGET_SHA` do executor continua fixo em `617e7d67bf88ba325773b220b78052d416eddb2b`; esse SHA-alvo não é necessariamente o SHA da release que instala o provisionador.

Com o checkout detached no SHA aprovado, executar primeiro somente:

```bash
PROVISIONER_MAIN_SHA=<SHA_DA_MAIN_COM_O_PROVISIONADOR>
sudo -n scripts/provision-restricted-drift-sync.sh \
  --expected-sha "$PROVISIONER_MAIN_SHA" \
  --dry-run
```

O `--dry-run` valida a árvore, os 11 hashes do manifesto, o contrato do executor, o sudoers com `visudo` e a ausência dos três destinos instalados; não cria diretórios, não copia arquivos e não altera produção.

Somente em etapa posterior, com autorização específica, o mesmo provisionador pode ser chamado com `--install`:

```bash
PROVISIONER_MAIN_SHA=<SHA_DA_MAIN_COM_O_PROVISIONADOR>
sudo -n scripts/provision-restricted-drift-sync.sh \
  --expected-sha "$PROVISIONER_MAIN_SHA" \
  --install
```

O `--install` publica somente:

1. executor em `/usr/local/sbin/voxelpacs-sync-restricted-drift`, `root:root`, modo `0555`;
2. manifesto em `/usr/local/share/voxelpacs/voxelpacs-restricted-drift-sync.manifest.tsv`, `root:root`, modo `0444`;
3. sudoers em `/etc/sudoers.d/voxelpacs-restricted-drift-sync`, `root:root`, modo `0440`.

Os arquivos são preparados em staging privado, validados e movidos individualmente. Em falha parcial, o trap remove somente os destinos que o próprio provisionador instalou nesta execução; não usa `rm -rf`, não substitui instalações prévias e não altera o checkout, o runtime da aplicação, `.env`, storage, banco, systemd, Worker, Bridge, SMB ou transmissão.

O provisionador não instala o source checkout fixo usado pelo executor. Esse checkout deve ser disponibilizado separadamente por administrador root, em `/var/lib/voxelpacs/restricted-drift-sync/source/617e7d67bf88ba325773b220b78052d416eddb2b`, como árvore Git limpa, não symlink. O provisionamento deve ser validado antes de executar o dry-run do executor.

Não instalar por cópia manual fora de Git nem conceder `NOPASSWD: ALL`. O `--install` não foi executado no Host 1 nesta etapa.

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
