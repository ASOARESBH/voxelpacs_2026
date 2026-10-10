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

```bash
bash scripts/validate-runtime-drift.sh \
  --runtime-root /var/www/voxelpacs/app \
  --artifact-manifest /path/protegido/artifact.manifest.tsv \
  --baseline-manifest /var/lib/voxelpacs/baselines/<baseline-id>/manifest.tsv
```

Os caminhos devem ser fornecidos pelo workflow root-controlled de predeploy. O script não aceita opções de cópia, publicação, exclusão ou execução de serviço.

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
