# Leitor root-controlled do manifesto integral de drift

## Objetivo

O leitor permite que o workflow de predeploy consuma o manifesto root-owned do baseline sem copiar, expor ou editar arquivos protegidos no Host 1. Ele lê somente um baseline identificado por SHA técnica e retorna o manifesto validado por `stdout`; status e categorias são enviados por `stderr`.

O gate integrado usa esse leitor para alimentar `scripts/validate-runtime-drift.sh`. O comparador continua sendo a autoridade da decisão `DRIFT_GATE=PASS|FAIL`.

## Escopo fixo

```text
runtime:       /var/www/voxelpacs/app
baseline root: /var/lib/voxelpacs/baselines/<baseline-id>
reader:        /usr/local/sbin/voxelpacs-runtime-drift-manifest-reader
comparator:    /usr/local/sbin/voxelpacs-validate-runtime-drift
```

O caller não fornece arquivo, diretório, comando, shell, origem ou destino. O `baseline-id` aceita somente 40 caracteres hexadecimais e precisa ser o prefixo da SHA-256 do manifesto armazenado.

## Validações fail-closed

Antes de emitir qualquer linha, o leitor valida:

- diretório, baseline e arquivos `root:root` com permissões restritas;
- `baseline-id`, `manifest.sha256` e SHA real do manifesto;
- `metadata.txt`, incluindo `hash_unavailable=0` e `metadata_unavailable=0`;
- paths relativos sem traversal e sem raízes de segredo/persistência;
- tamanho, SHA-256, modo, classificação permitida e status `READABLE`;
- ausência de linhas duplicadas, colunas extras ou manifesto vazio.

O leitor nunca imprime conteúdo de arquivo, dados clínicos, PDF, XML, segredo, credencial, URL privada ou hash individual fora do próprio manifesto técnico necessário ao comparador.

## Gate oficial

Após provisionamento no Host 1, o fluxo root-controlled recebe por `stdin` somente o manifesto do último commit publicado (ver `RUNTIME_DRIFT_VALIDATION.md`):

```bash
cat publicado.manifest.tsv \
  | sudo -n /usr/local/sbin/voxelpacs-runtime-drift-gate \
      --baseline-id <baseline-id>
```

O gate grava entradas temporárias root-owned em `/var/tmp`, chama o leitor fixo, executa o comparador instalado e remove os temporários com segurança. Não publica código, não altera o baseline, não altera o runtime, não inicia serviços, não acessa banco, não chama Worker, Bridge, SMB, Philips ou DICOM.

## Provisionamento

O provisioner exige checkout limpo e SHA exata da árvore:

```bash
sudo bash scripts/provision-runtime-drift-manifest-reader.sh \
  --expected-sha <SHA_DA_MAIN> --dry-run
sudo bash scripts/provision-runtime-drift-manifest-reader.sh \
  --expected-sha <SHA_DA_MAIN> --install
```

Instala atomicamente:

```text
/usr/local/sbin/voxelpacs-runtime-drift-manifest-reader 0555
/usr/local/sbin/voxelpacs-runtime-drift-gate            0555
/usr/local/sbin/voxelpacs-validate-runtime-drift       0555
/etc/sudoers.d/voxelpacs-runtime-drift-manifest-reader 0440
```

A allowlist concede somente a leitura por `baseline-id` e o gate com `baseline-id`. Não concede shell, `cat` root genérico, acesso a path arbitrário, cópia ou operação de publicação.

## Upgrade de destinos já instalados

O instalador original falha fechado quando os quatro destinos já existem. Para atualizar uma versão instalada sem sobrescrita manual, existe o helper versionado separado:

```bash
sudo bash scripts/upgrade-runtime-drift-manifest-reader.sh \
  --expected-sha <SHA_DA_MAIN> --dry-run
sudo bash scripts/upgrade-runtime-drift-manifest-reader.sh \
  --expected-sha <SHA_DA_MAIN> --upgrade
```

O `--upgrade` exige os quatro destinos existentes como arquivos regulares `root:root` com modos `0555/0555/0555/0440`, valida a SHA exata do checkout, valida o comparador e o sudoers, cria backup root-only em raiz fixa, instala os quatro arquivos por staging e `mv` atômico individual e restaura o backup automaticamente se uma etapa posterior falhar. O backup não é removido automaticamente. O `--dry-run` não cria lock, backup ou staging.

Esse helper não aceita paths, comandos, shell, `systemctl`, cópia arbitrária ou alteração da aplicação. Ele não altera banco, baseline, Worker, Bridge, SMB ou transmissão.

## Rollback

O provisionamento não substitui destinos existentes. Em caso de falha, o cleanup remove somente os destinos recém-instalados. A reversão operacional deve remover os quatro destinos pelo procedimento administrativo aprovado, preservando evidência e sem tocar o runtime da aplicação.

## Validação

```bash
bash -n ops/deploy/voxelpacs-runtime-drift-manifest-reader
bash -n ops/deploy/voxelpacs-runtime-drift-gate
bash -n scripts/provision-runtime-drift-manifest-reader.sh
visudo -cf ops/sudoers/voxelpacs-runtime-drift-manifest-reader
bash tests/deploy/runtime_drift_manifest_reader_static.sh
bash tests/deploy/runtime_drift_manifest_reader_isolated.sh
```

A integração não autoriza deploy, Worker, Job, Bridge, SMB, transmissão ou alteração DICOM.
