# Validação versionada do drift do runtime

## Objetivo

`scripts/validate-runtime-drift.sh` compara, somente por leitura, o runtime efetivo com:

1. o manifesto do artefato oficial construído da SHA da `main`; e
2. o manifesto root-controlled do baseline aprovado.

O comparador não publica arquivos, não altera o runtime, não acessa banco, não inicia serviços e não chama Worker, Bridge, SMB, Philips ou DICOM.

## Política fail-closed

O gate retorna `DRIFT_GATE=PASS` somente quando:

- todos os arquivos esperados do artefato existem, são regulares e legíveis;
- os manifestos têm paths seguros, hashes válidos e nenhuma duplicidade;
- todo mismatch está coberto por uma exceção explícita;
- todo extra fora do artefato está no baseline e possui exatamente o mesmo hash;
- não há extra sem baseline, hash divergente, arquivo esperado ausente ou arquivo ilegível.

O comparador aceita também o manifesto root-controlled legado de oito colunas produzido antes da coluna explícita `READABLE`. Essa compatibilidade só é válida quando a linha tem exatamente oito colunas, o digest SHA-256 completo é válido e a classificação permanece permitida; o gate reporta `BASELINE_FORMAT=LEGACY_8_COLUMN`. Linhas com digest ausente, colunas extras, path inválido ou classificação desconhecida continuam bloqueadas.

## Exceção explícita

A única exceção de conteúdo prevista nesta versão é:

```text
PATH=vendor/composer/installed.php
BASELINE_CLASSIFICATION=COMPOSER_DEPENDENCY
RUNTIME_HASH=BASELINE_HASH
```

Isso reconhece que o metadata gerado pelo Composer pode diferir do arquivo produzido no artefato, mas exige identidade comprovada com o baseline root-controlled. Não é permitido aceitar a pasta `vendor/` genericamente nem ignorar o arquivo sem verificar path, tipo, legibilidade, hash e classificação.

## Extras históricos

Extras somente são aceitos quando:

```text
RUNTIME_PATH ∈ BASELINE_MANIFEST
RUNTIME_HASH = BASELINE_HASH
```

Um extra ausente do baseline ou com hash divergente mantém o gate bloqueado. O baseline não autoriza código novo nem substitui a `main` como fonte oficial.

## Uso

No checkout local, o comparador continua disponível para testes com manifestos já acessíveis:

```bash
bash scripts/validate-runtime-drift.sh \
  --runtime-root /var/www/voxelpacs/app \
  --artifact-manifest /path/protegido/artifact.manifest.tsv \
  --baseline-manifest /var/lib/voxelpacs/baselines/<baseline-id>/manifest.tsv
```

No Host 1, quando o baseline é root-owned, o fluxo oficial usa o gate root-controlled da PR #111 e fornece somente o manifesto do artefato por `stdin`:

```bash
cat artifact.manifest.tsv \
  | sudo -n /usr/local/sbin/voxelpacs-runtime-drift-gate \
      --baseline-id <baseline-id>
```

O gate chama o leitor fixo, valida checksum/ownership/formato e entrega a saída ao comparador instalado em caminho fixo. O caller não fornece path de runtime, baseline, comando ou destino. Nenhum dos modos aceita opções de cópia, publicação, exclusão ou execução de serviço.

## Saída sanitizada

A saída contém somente contagens, categorias e estados operacionais. Não imprime conteúdo de arquivos, hashes individuais, PHI, secrets, URLs privadas ou credenciais.

Estados relevantes:

```text
DRIFT_GATE=PASS|FAIL|BLOCKED
PRODUCTION_CHANGED=NO
DATABASE_CHANGED=NO
WORKER_STARTED=NO
BRIDGE_CALLED=NO
SMB_CALLED=NO
TRANSMISSION=NO
```

## Rollback

O comparador não executa rollback. Em caso de `FAIL`, o fluxo deve preservar o runtime e usar o backup root-only já validado somente por procedimento de deploy/rollback separado e autorizado.
