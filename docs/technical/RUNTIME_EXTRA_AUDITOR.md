# Auditor root-controlled sanitizado de extras do runtime

## Objetivo

`voxelpacs-runtime-extra-auditor` é um diagnóstico **read-only** para investigar arquivos regulares presentes no runtime e ausentes do manifesto do artefato. Ele não altera a aplicação, não trata um extra como permitido automaticamente e não muda a política do drift gate.

O helper confronta somente:

1. o manifesto do artefato recebido por `stdin`;
2. a árvore fixa `/var/www/voxelpacs/app`;
3. o manifesto e os metadados da baseline root-controlled identificada explicitamente.

O manifesto da baseline é validado por checksum, derivação do identificador, formato, completude, owner, grupo e modo. A entrada do artefato é armazenada apenas em arquivo temporário root-owned de modo `0600`, limitada por tamanho e número de linhas, e validada como TSV de dois campos com caminhos relativos seguros.

## Interface fixa

Após provisionamento autorizado e separado:

```bash
cat artifact.manifest.tsv \
  | sudo -n /usr/local/sbin/voxelpacs-runtime-extra-auditor \
      audit \
      --baseline-id <baseline-id-40hex> \
      --artifact-sha <sha-da-main-40hex>
```

`--baseline-id` e `--artifact-sha` são identificadores hexadecimais de 40 caracteres. O auditor comprova a integridade da baseline root-controlled; a proveniência do manifesto do artefato permanece uma responsabilidade do fluxo que construiu e entregou o artefato, indicada sanitizadamente como `CALLER_SUPPLIED_MANIFEST`. A saída do auditor nunca deve ser tratada como prova de deploy ou como autorização do drift gate.

Não são aceitos paths, comandos, origem, destino, shell, SQL, serviço ou argumento variável pelo caller.

## Categorias emitidas

A saída contém apenas contagens agregadas e estados técnicos:

| Categoria | Significado |
|---|---|
| `CATEGORY_EXPECTED_VERSIONED_ARTIFACT` | Arquivo presente no artefato e igual ao runtime |
| `CATEGORY_EXPECTED_ARTIFACT_MISMATCH` | Arquivo esperado no artefato com conteúdo diferente no runtime |
| `CATEGORY_KNOWN_RUNTIME_ARTIFACT` | Extra ausente do artefato, mas presente na baseline com conteúdo e metadados iguais e classificação permitida |
| `CATEGORY_BASELINE_EXTRA_UNCLASSIFIED` | Extra presente na baseline, porém sem classificação suficiente para uma conclusão |
| `CATEGORY_MISSING_FROM_BASELINE` | Extra presente no runtime e ausente da baseline |
| `CATEGORY_METADATA_MISMATCH` | Conteúdo de extra conhecido coincide, mas metadados não coincidem |
| `CATEGORY_RUNTIME_CONTENT_MISMATCH` | Caminho presente na baseline, mas conteúdo do runtime diverge |
| `CATEGORY_UNREADABLE_OR_UNVERIFIABLE` | Arquivo, symlink ou metadata não pode ser comprovado com segurança |
| `UNEXPECTED_DIRECTORIES` | Diretórios não derivados dos paths versionados conhecidos |

Os diretórios persistentes, secretos, temporários e de dados clínicos (`.env`, `storage`, `uploads`, `logs`, `backups`, `runtime`, `cache`, `sessions`, `tmp`) são excluídos do escopo, sem enumerar ou abrir seu conteúdo. A contagem de presença fora de escopo é apenas sanitizada.

## Estados fail-closed

- `AUDIT=PASS`: a enumeração terminou e todos os extras foram formalmente classificados como correspondentes à baseline com classificação permitida; isso **não** altera o drift gate.
- `AUDIT=INCOMPLETE`: a baseline possui extra não classificado; não é possível provar a proveniência permitida.
- `AUDIT=FAIL`: foi encontrado drift confirmado, extra não presente na baseline, divergência de metadata, arquivo ausente/ilegível ou diretório inesperado.
- `EXTRA_AUDIT=BLOCKED`: pré-condição estrutural, integridade, formato ou segurança não foi satisfeita.

Qualquer estado diferente de `AUDIT=PASS` interrompe a interpretação e exige reconciliação versionada. O helper nunca classifica por inferência, nunca remove extras e nunca os adiciona ao artefato.

## Não exposição e limites

A saída não contém:

- conteúdo de arquivos;
- hashes individuais completos ou parciais;
- paths individuais;
- `.env`, credenciais, tokens, certificados, PDFs, XMLs, DICOM ou PHI.

Também não executa Composer, PHP, banco, systemd, Worker, Bridge, SMB, DICOM/C-STORE, deploy, backup ou migration. Sempre emite:

```text
DRIFT_GATE_INTEGRATION=NOT_EXECUTED
READY_FOR_DEPLOY=NO
PRODUCTION_CHANGED=NO
DATABASE_CHANGED=NO
WORKER_STARTED=NO
BRIDGE_CALLED=NO
SMB_CALLED=NO
TRANSMISSION=NO
DICOM_CSTORE=NOT_CHANGED
```

## Provisionamento

O provisioner versionado é:

```text
scripts/provision-runtime-extra-auditor.sh
```

Dry-run e instalação posterior usam uma SHA exata de um checkout limpo:

```bash
sudo bash scripts/provision-runtime-extra-auditor.sh \
  --expected-sha <sha-da-main-com-o-helper> --dry-run
sudo bash scripts/provision-runtime-extra-auditor.sh \
  --expected-sha <sha-da-main-com-o-helper> --install
```

O modo `--dry-run` não escreve nos destinos. A instalação inicial é **no-replace** e publica apenas:

```text
/usr/local/sbin/voxelpacs-runtime-extra-auditor  root:root 0555
/etc/sudoers.d/voxelpacs-runtime-extra-auditor  root:root 0440
```

A regra sudoers permite somente o subcomando `audit` com os dois identificadores de 40 hexadecimais. Destinos preexistentes bloqueiam a instalação; não há sobrescrita silenciosa.

## Testes

```bash
bash -n ops/deploy/voxelpacs-runtime-extra-auditor
bash -n scripts/provision-runtime-extra-auditor.sh
visudo -cf ops/sudoers/voxelpacs-runtime-extra-auditor
bash tests/deploy/runtime_extra_auditor_static.sh
sudo -n env -u SUDO_USER bash tests/deploy/runtime_extra_auditor_isolated.sh
sudo -n env -u SUDO_USER bash tests/deploy/runtime_extra_auditor_provisioner_isolated.sh
```

Esta capacidade não foi provisionada no Host 1, não foi executada contra produção e não autoriza deploy ou operação clínica.
