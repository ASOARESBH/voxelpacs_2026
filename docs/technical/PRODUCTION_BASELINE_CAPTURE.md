# Captura root-controlled do baseline de produção

## Objetivo

Executar a captura integral e fail-closed do baseline do Host 1 sem transformar a produção em workspace, sem aceitar paths arbitrários e sem expor conteúdo clínico ou configuração secreta.

## Escopo fixo

```text
origem: /var/www/voxelpacs/app
saída:  /var/lib/voxelpacs/baselines
```

A captura registra somente metadados, hashes, symlinks e classificações. `.env`, storage, uploads, logs, backups, PDFs, XMLs, DICOM e conteúdo clínico não são copiados.

## Provisionamento versionado

O provisionador exige checkout limpo, SHA exata da `main`, validação de `bash -n`, `visudo` e ausência dos destinos antes da instalação:

```bash
sudo bash scripts/provision-production-baseline-capture.sh \
  --expected-sha <SHA_DA_MAIN> --dry-run
sudo bash scripts/provision-production-baseline-capture.sh \
  --expected-sha <SHA_DA_MAIN> --install
```

Ele instala atomicamente, como `root:root`:

```text
/usr/local/sbin/voxelpacs-capture-production-baseline     0555
/usr/local/sbin/voxelpacs-production-baseline-capture      0555
/etc/sudoers.d/voxelpacs-production-baseline-capture      0440
```

A allowlist concede somente:

```bash
sudo -n /usr/local/sbin/voxelpacs-production-baseline-capture capture
```

Não há shell, path, source, destination, comando, SQL, systemd, Bridge, SMB, Worker ou operação clínica variável pelo caller.

## Execução e resultado

O wrapper chama o script versionado com `--require-complete`. Se alguma hash não puder ser lida, retorna `BASELINE_CAPTURE=INCOMPLETE` e não publica o diretório da captura. Em sucesso, retorna somente o identificador técnico do baseline, contagens e categorias de segurança.

O identificador do baseline é uma hash técnica do manifesto, não é SHA de commit. O backup root-only da release atual deve ser criado, validado e submetido a restore-test antes da captura integral.

## Rollback

O provisionamento não substitui destinos existentes. Se uma instalação falhar, os arquivos novos são removidos pelo cleanup fail-closed; nenhuma release da aplicação, `.env`, storage, banco ou serviço é tocada. Após a instalação, a reversão deve ser feita por procedimento administrativo aprovado, preservando a evidência e validando novamente `visudo`.

## Validação

```bash
bash -n scripts/capture-production-baseline.sh
bash -n scripts/provision-production-baseline-capture.sh
bash -n ops/deploy/voxelpacs-production-baseline-capture
visudo -cf ops/sudoers/voxelpacs-production-baseline-capture
bash tests/deploy/production_baseline_provisioner_static.sh
```

Estado funcional de produção só pode ser declarado após provisionamento, backup, captura integral e validação read-only do manifesto. O helper não inicia Worker, não chama Bridge, não acessa SMB e não transmite.
