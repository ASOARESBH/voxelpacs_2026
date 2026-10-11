# Auditor root-controlled do `vendor/composer/installed.php`

## Objetivo

`voxelpacs-composer-drift-auditor` é um diagnóstico **read-only** para a divergência específica de `vendor/composer/installed.php` observada pelo drift gate.

Ele compara o caminho fixo nos três lados:

1. manifesto do artefato construído a partir de uma SHA aprovada da `main`, recebido por `stdin`;
2. arquivo efetivo do runtime em `/var/www/voxelpacs/app/vendor/composer/installed.php`;
3. linha correspondente do baseline root-controlled em `/var/lib/voxelpacs/baselines/<baseline-id>/manifest.tsv`.

O helper não recebe paths arbitrários, não abre shell, não edita arquivos e não executa deploy, Composer, PHP, banco, Worker, Bridge, SMB ou DICOM.

## Interface

Após provisionamento autorizado e separado:

```bash
cat artifact.manifest.tsv \
  | sudo -n /usr/local/sbin/voxelpacs-composer-drift-auditor \
      --baseline-id <baseline-id> \
      --artifact-sha <sha-da-main>
```

`--artifact-sha` identifica a SHA do checkout que produziu o artefato; a própria cadeia local de build deve comprovar que o manifesto veio dessa SHA. O helper valida o formato do manifesto recebido e a presença única do target, mas não transforma uma SHA fornecida pelo caller em prova de proveniência do artefato.

A allowlist sudoers fixa a ordem dos argumentos e aceita somente dois identificadores de 40 caracteres; o helper valida novamente o formato hexadecimal.

## Evidência emitida

A saída contém somente:

- igualdade entre hash do artefato, runtime e baseline;
- classificação registrada no baseline;
- elegibilidade da exceção restrita `COMPOSER_DEPENDENCY`;
- tamanho, modo e igualdade de metadados;
- diagnóstico de três vias;
- estados de não alteração.

Os valores dos hashes de conteúdo e o conteúdo do arquivo **não são impressos**. Isso preserva a regra operacional de não expor hashes, metadata Composer bruto, segredos ou dados clínicos.

## Classificações

O helper só considera elegível a exceção quando:

```text
PATH=vendor/composer/installed.php
BASELINE_CLASSIFICATION=COMPOSER_DEPENDENCY
RUNTIME_BASELINE_HASH_EQUAL=YES
```

Diagnósticos possíveis:

- `ALL_THREE_MATCH` — artefato, runtime e baseline coincidem;
- `GENERATED_METADATA_BASELINE_MATCH_ARTIFACT_DIFFERS` — runtime coincide com baseline, mas difere do artefato;
- `BASELINE_MISMATCH_RUNTIME_ARTIFACT` — runtime e artefato coincidem, baseline diverge;
- `RUNTIME_DIFFERS_FROM_ARTIFACT_AND_BASELINE` — runtime diverge de ambos;
- `CAUSE_UNDETERMINED` — os três valores são diferentes;
- `BASELINE_CLASSIFICATION_INSUFFICIENT` — a classificação não é `COMPOSER_DEPENDENCY`.

O diagnóstico nunca converte uma divergência em autorização de deploy e sempre emite `READY_FOR_DEPLOY=NO`. A decisão do drift gate permanece separada e fail-closed.

## Provisionamento

O provisioner versionado é:

```text
scripts/provision-composer-drift-auditor.sh
```

Pré-validação sem instalação:

```bash
scripts/provision-composer-drift-auditor.sh \
  --expected-sha <sha-da-main-com-o-helper> --dry-run
```

A instalação posterior exige autorização própria e publica somente:

```text
/usr/local/sbin/voxelpacs-composer-drift-auditor  root:root 0555
/etc/sudoers.d/voxelpacs-composer-drift-auditor  root:root 0440
```

Esta PR **não provisiona** o Host 1.

## Testes

```bash
bash -n ops/deploy/voxelpacs-composer-drift-auditor
bash -n scripts/provision-composer-drift-auditor.sh
visudo -cf ops/sudoers/voxelpacs-composer-drift-auditor
bash tests/deploy/composer_drift_auditor_static.sh
bash tests/deploy/composer_drift_auditor_isolated.sh
```

## Limites operacionais

```text
PRODUCTION_CHANGED=NO
DATABASE_CHANGED=NO
WORKER_STARTED=NO
BRIDGE_CALLED=NO
SMB_CALLED=NO
TRANSMISSION=NO
READY_FOR_DEPLOY=NO
```
