# Captura root-controlled do baseline de produção

## Objetivo

Executar a captura integral e fail-closed do baseline do Host 1 sem transformar a produção em workspace, sem aceitar paths arbitrários e sem expor conteúdo clínico ou configuração secreta.

## Escopo fixo

```text
origem: /var/www/voxelpacs/app
saída:  /var/lib/voxelpacs/baselines
```

A captura registra somente metadados, hashes, symlinks, classificações e o perfil técnico sanitizado do Composer. O sidecar `composer-profile.tsv` contém apenas estados de referência e é vinculado ao manifesto do mesmo baseline. `.env`, storage, uploads, logs, backups, PDFs, XMLs, DICOM e conteúdo clínico não são copiados.

## Provisionamento versionado

O provisionador exige checkout limpo, SHA exata da `main`, remoto canônico, `origin/main` na mesma SHA, histórico Git completo, validação de `bash -n`, `visudo` e diretórios administrativos seguros.

Para uma instalação inicial, os destinos devem estar ausentes:

```bash
sudo bash scripts/provision-production-baseline-capture.sh \
  --expected-sha <SHA_DA_MAIN> --dry-run
sudo bash scripts/provision-production-baseline-capture.sh \
  --expected-sha <SHA_DA_MAIN> --install
```

Quando os destinos já existem, a instalação inicial permanece bloqueada por `no-replace`. A atualização controlada usa a mesma fonte versionada e exige que cada blob existente seja comprovadamente encontrado no histórico da `origin/main`, além de preservar `root:root` e os modos esperados:

```bash
sudo bash scripts/provision-production-baseline-capture.sh \
  --expected-sha <SHA_DA_MAIN> --upgrade --dry-run
sudo bash scripts/provision-production-baseline-capture.sh \
  --expected-sha <SHA_DA_MAIN> --upgrade --install
```

Ele instala atomicamente, como `root:root`:

```text
/usr/local/sbin/voxelpacs-capture-production-baseline     0555
/usr/local/sbin/voxelpacs-production-baseline-capture      0555
/etc/sudoers.d/voxelpacs-production-baseline-capture      0440
```

Antes da captura, a aprovação administrativa independente da SHA usada no perfil Composer deve existir em:

```text
/etc/voxelpacs-composer-profile/voxelpacs-composer-profile-approved-main-sha  root:root 0600
```

O arquivo contém exatamente uma SHA de `main` aprovada por linha. O provisioner apenas valida essa âncora; não a cria, altera ou infere do checkout.

No modo `--upgrade`, o provisioner cria backups temporários `root-only`, faz o staging no mesmo filesystem de cada destino e substitui os três componentes com movimentos atômicos. Se qualquer substituição posterior falhar, restaura os componentes já substituídos a partir dos backups e informa `ROLLBACK=PASS`; uma proveniência histórica não comprovada, symlink, owner/mode inesperado ou conjunto parcial de destinos bloqueia antes da escrita. O `--dry-run` nunca cria backup nem modifica destinos.

A allowlist concede somente:

```bash
sudo -n /usr/local/sbin/voxelpacs-production-baseline-capture capture
```

Não há shell, path, source, destination, comando, SQL, systemd, Bridge, SMB, Worker ou operação clínica variável pelo caller.

## Execução e resultado

O wrapper lê a SHA aprovada da âncora root-only e chama o script versionado com `--composer-profile-sha <SHA>` e `--require-complete`. O capturador lê somente o caminho fixo `vendor/composer/installed.php` e publica, junto do baseline, `composer-profile.tsv` com o contrato `official_root_controlled_baseline_profile_v1`. O sidecar contém `SOURCE_SHA`, `NULL`, `OTHER_SHA` ou `UNRESOLVED`, nunca o valor de referência nem o conteúdo do arquivo.

Se o `installed.php` não existir, não for regular, não puder ser lido ou tiver estrutura ambígua, retorna `BASELINE_CAPTURE=INCOMPLETE` e não publica o diretório da captura. Em sucesso, o wrapper retorna somente o identificador técnico do baseline, contagens, o estado `COMPOSER_PROFILE=PASS` e categorias de segurança.

O identificador do baseline é uma hash técnica do manifesto, não é SHA de commit. O backup root-only da release atual deve ser criado, validado e submetido a restore-test antes da captura integral.

## Rollback

O modo inicial não substitui destinos existentes; se uma instalação falhar depois de iniciar, os arquivos novos são removidos pelo cleanup fail-closed. O modo `--upgrade` só substitui destinos regulares, históricos e com permissões aprovadas; em falha parcial restaura os blobs anteriores e preserva seus modos. Nenhum modo altera release da aplicação, `.env`, storage, banco ou serviço. Após uma atualização bem-sucedida, qualquer reversão posterior deve ser feita por procedimento administrativo aprovado, preservando evidência e validando novamente `visudo`.

## Validação

```bash
bash -n scripts/capture-production-baseline.sh
bash -n scripts/provision-production-baseline-capture.sh
bash -n ops/deploy/voxelpacs-production-baseline-capture
visudo -cf ops/sudoers/voxelpacs-production-baseline-capture
bash tests/deploy/production_baseline_provisioner_static.sh
bash tests/deploy/production_baseline_provisioner_upgrade_isolated.sh
```

Estado funcional de produção só pode ser declarado após provisionamento, backup, captura integral e validação read-only do manifesto. O helper não inicia Worker, não chama Bridge, não acessa SMB e não transmite.
