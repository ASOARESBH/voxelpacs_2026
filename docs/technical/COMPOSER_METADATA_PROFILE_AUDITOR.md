# Auditor root-controlled do perfil técnico Composer

## Objetivo

`voxelpacs-composer-metadata-profile-auditor` é um diagnóstico read-only para comparar, sem expor valores, somente os estados técnicos de:

- `root.reference`;
- `versions['voxel/bi'].reference`;

no metadata fixo `vendor/composer/installed.php`.

O helper recebe por `stdin` um perfil sanitizado produzido localmente para o artefato, lê o arquivo efetivo no runtime e procura um sidecar root-only opcional na baseline.

## Interface

O perfil de artefato tem exatamente três linhas TSV:

```text
profile_artifact_sha\t<sha-da-main>
root_reference_state\tSOURCE_SHA|NULL|OTHER_SHA|UNRESOLVED
package_reference_state\tSOURCE_SHA|NULL|OTHER_SHA|UNRESOLVED
```

Ele é gerado pelo checkout limpo da SHA com:

```bash
scripts/build-composer-metadata-profile.sh \
  --input /caminho/local/vendor/composer/installed.php \
  --expected-sha <sha-da-main> \
  --output /caminho/protegido/profile.tsv
```

No Host 1, após provisionamento autorizado e separado:

```bash
cat composer-profile.tsv \
  | sudo -n /usr/local/sbin/voxelpacs-composer-metadata-profile-auditor \
      --baseline-id <baseline-id> \
      --artifact-sha <sha-da-main>
```

A SHA do commit-fonte identifica a origem do artefato. Valores de `reference`, hashes de arquivo e conteúdo não são impressos.

## Sidecar de baseline

A captura histórica atual registra manifesto, hashes e metadados, mas não preserva o conteúdo de `installed.php`. Logo, o helper **não inventa** o perfil da baseline.

Quando disponível, o sidecar fixo deve ser:

```text
/var/lib/voxelpacs/baselines/<baseline-id>/composer-profile.tsv
```

com modo `root:root 0600`, dentro de diretório `root:root 0700`, e exatamente o contrato verificável:

```text
profile_source\tofficial_root_controlled_baseline_profile_v1
profile_baseline_id\t<baseline-id>
profile_manifest_sha256\t<sha-256-do-manifest.tsv>
profile_artifact_sha\t<sha-da-main>
root_reference_state\tSOURCE_SHA|NULL|OTHER_SHA|UNRESOLVED
package_reference_state\tSOURCE_SHA|NULL|OTHER_SHA|UNRESOLVED
```

O sidecar somente é considerado verificável quando produzido pelo mecanismo root-controlled oficial, com `profile_source` fixo, `profile_baseline_id` igual ao diretório da captura, checksum do `manifest.tsv` e base do artefato explícita. O helper valida `manifest.tsv`, `manifest.sha256`, o `baseline-id`, ownership/modos root-only e a igualdade do checksum declarado no sidecar com o checksum do manifesto. Sem o manifesto íntegro, o resultado é `BLOCKED`; sem o sidecar comprovadamente vinculado, o resultado é:

```text
BASELINE_PROFILE=UNVERIFIED
PROFILE_COMPARISON=INCOMPLETE
PROFILE_DIAGNOSIS=BASELINE_PROFILE_UNAVAILABLE
AUDIT=INCOMPLETE
READY_FOR_DEPLOY=NO
```

Comparação incompleta retorna código de saída não-zero (`65`). Nunca deve ser interpretada como auditoria concluída.

O helper não cria, atualiza, captura ou altera a baseline.

### Limitação de proveniência da captura

O mecanismo atual `scripts/capture-production-baseline.sh` produz `manifest.tsv`, `manifest.sha256`, `baseline-id`, symlinks, exclusões e metadados sanitizados, mas não produz ainda o `composer-profile.tsv` com o contrato acima. Portanto, a captura atual não é tratada como prova implícita do perfil Composer: sem um sidecar gerado por mecanismo oficial compatível, o resultado obrigatório é `BASELINE_PROFILE=UNVERIFIED`, `AUDIT=INCOMPLETE` e código `65`. Não é permitido criar o sidecar manualmente para fazer o diagnóstico passar.

## Estados e diagnóstico

Os valores possíveis são apenas `SOURCE_SHA`, `NULL`, `OTHER_SHA` e `UNRESOLVED`. As comparações retornam `MATCH`, `DIFFER`, `INDETERMINATE` ou `NOT_PROVABLE`.

A semântica de saída é fail-closed:

- `AUDIT=PASS`, código `0`: comparação completa e os três estados técnicos coincidem;
- `AUDIT=FAIL`, código `66`: comparação completa, mas há divergência;
- `AUDIT=INCOMPLETE`, código `65`: evidência ausente, ambígua ou estruturalmente insuficiente;
- bloqueio de contrato/segurança, código `64`: a operação diagnóstica não pôde começar com segurança.

Todos os estados mantêm `READY_FOR_DEPLOY=NO`.

O diagnóstico pode ser:

- `ALL_THREE_PROFILE_STATES_MATCH`;
- `PROFILE_STATES_DIFFER`;
- `PROFILE_FIELDS_INDETERMINATE`;
- `BASELINE_PROFILE_UNAVAILABLE`.

A capacidade é diagnóstica. Ela nunca modifica a política do drift gate, nunca aceita `installed.php` automaticamente e sempre emite `READY_FOR_DEPLOY=NO`.

## Segurança e limites

- alvo e caminhos são fixos;
- não aceita shell, path, comando, Composer, PHP, SQL, serviço ou transporte;
- não acessa banco, Worker, Bridge, SMB, Philips ou DICOM;
- não altera runtime, baseline ou configuração;
- não expõe conteúdo, valores de referência ou hashes individuais;
- o sudoers permite somente os dois identificadores hexadecimais fixos;
- a origem do perfil é validada pela SHA exata do artefato informado.
- referências duplicadas, pacote `voxel/bi` duplicado, nested ou fora do bloco estrutural exato resultam em `UNRESOLVED` e comparação incompleta;
- o provisioner exige também uma aprovação administrativa root-only independente do checkout local;
- o provisioner exige o remoto canônico, `origin/main` apontando para a SHA exata e worktree limpo;
- os diretórios administrativos precisam ser root-owned e não graváveis por grupo/outros;
- o staging é criado no filesystem de cada destino e a instalação usa link sem sobrescrita;
- falha parcial remove os arquivos recém-instalados, sem substituir destinos existentes.

## Provisionamento

O provisioner versionado é:

```text
scripts/provision-composer-metadata-profile-auditor.sh
```

Ele suporta `--dry-run` e `--install`, mas esta implementação não provisiona o Host 1 automaticamente.

Antes de qualquer modo, o provisioner exige a âncora administrativa externa:

```text
/etc/voxelpacs/voxelpacs-composer-metadata-profile-approved-main-shas  root:root 0600
```

O diretório pai também deve ser root-owned e não gravável por grupo/outros. O arquivo contém uma SHA de `main` aprovada por processo administrativo independente por linha; a SHA fornecida ao caller somente é aceita quando aparece exatamente nessa allowlist. A ausência, formato inválido ou divergência bloqueia (`EXPECTED_SHA_NOT_APPROVED`). Essa âncora não é criada pelo provisioner e não é inferida do checkout local.

Destinos previstos:

```text
/usr/local/sbin/voxelpacs-composer-metadata-profile-auditor  root:root 0555
/etc/sudoers.d/voxelpacs-composer-metadata-profile-auditor    root:root 0440
```

## Testes

```bash
bash -n ops/deploy/voxelpacs-composer-metadata-profile-auditor
bash -n scripts/provision-composer-metadata-profile-auditor.sh
bash -n scripts/build-composer-metadata-profile.sh
visudo -cf ops/sudoers/voxelpacs-composer-metadata-profile-auditor
bash tests/deploy/composer_metadata_profile_auditor_static.sh
sudo -n env -u SUDO_USER bash tests/deploy/composer_metadata_profile_auditor_isolated.sh
sudo -n env -u SUDO_USER bash tests/deploy/composer_metadata_profile_provisioner_isolated.sh
```

Os testes cobrem igualdade, divergência, referências duplicadas, pacote duplicado, referências ambíguas, pacote ausente, campos ausentes, estruturas truncadas, vínculo de baseline, baseline não verificável, escopo do sudoers, origem Git, aprovação administrativa, diretórios inseguros, instalação real isolada, no-replace, preservação de destinos preexistentes, limpeza de falha parcial e não exposição de valores.

## Estado operacional

```text
PRODUCTION_CHANGED=NO
DATABASE_CHANGED=NO
WORKER_STARTED=NO
BRIDGE_CALLED=NO
SMB_CALLED=NO
TRANSMISSION=NO
READY_FOR_DEPLOY=NO
```
