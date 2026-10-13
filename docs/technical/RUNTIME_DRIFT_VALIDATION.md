# Validação versionada do drift do runtime

## Objetivo

O drift gate responde a uma única pergunta: **alguém alterou a produção fora do Git?**

Para isso, `scripts/validate-runtime-drift.sh` (versão 3) compara, somente por leitura, o runtime efetivo com:

1. o manifesto do **último commit publicado** em produção, construído pelo builder oficial a partir desse SHA; e
2. o manifesto root-controlled do baseline aprovado, usado apenas para os arquivos que estão no runtime mas fora do commit publicado (extras históricos).

O código novo que vai ser publicado **não participa** da comparação. Uma alteração legítima commitada na `main` nunca aparece como drift, e não existe mais lista manual de "deltas aprovados" para editar a cada deploy.

O comparador não publica arquivos, não altera o runtime, não acessa banco, não inicia serviços e não chama Worker, Bridge, SMB, Philips ou DICOM.

## Qual é o "último commit publicado"

É o SHA usado no último `voxelpacs-deploy-runtime --sha <sha>` bem-sucedido. O builder grava esse mesmo SHA em `vendor/composer/installed.php` (campo `root.reference`), então o próprio gate o informa em toda execução:

```text
RUNTIME_PUBLISHED_SHA=<sha>|UNAVAILABLE
```

O fluxo é:

1. Descobrir o SHA publicado: executar o gate uma vez (com o manifesto do commit que se acredita estar publicado) e ler `RUNTIME_PUBLISHED_SHA`, ou consultar o registro de transações do deploy em `/var/lib/voxelpacs/deploy/releases/transactions/<sha>/` com `status=published`.
2. Construir o manifesto desse SHA, em checkout limpo e detached desse commit:

   ```bash
   git checkout --detach <sha-publicado>
   bash scripts/build-runtime-artifact.sh \
     --expected-sha <sha-publicado> \
     --output /tmp/publicado.zip \
     --manifest /tmp/publicado.manifest.tsv \
     --checksum /tmp/publicado.sha256
   ```

3. Executar o gate com esse manifesto (ver "Uso").
4. Se `DRIFT_GATE=PASS`, publicar o SHA novo da `main` normalmente. Depois da publicação, o SHA novo passa a ser o "último commit publicado" da próxima verificação.

## Política fail-closed

O gate retorna `DRIFT_GATE=PASS` somente quando:

- todos os arquivos do commit publicado existem no runtime, são regulares e legíveis;
- o conteúdo de cada um é idêntico ao do commit publicado (exceção: metadata Composer, abaixo);
- os manifestos têm paths seguros, hashes válidos e nenhuma duplicidade;
- todo arquivo do runtime fora do commit publicado está no baseline e possui exatamente o mesmo hash.

Qualquer arquivo editado direto no servidor, arquivo novo criado fora do Git, arquivo removido ou extra divergente do baseline resulta em:

```text
DRIFT_GATE=FAIL
REASON=RUNTIME_CHANGED_OUTSIDE_PUBLISHED_COMMIT
```

O comparador aceita também o manifesto de baseline legado de oito colunas, produzido antes da coluna `READABLE`. Essa compatibilidade só vale quando a linha tem exatamente oito colunas, o digest SHA-256 completo é válido e a classificação é permitida; o gate reporta `BASELINE_FORMAT=LEGACY_8_COLUMN`.

## Exceção explícita do metadata Composer

```text
PATH=vendor/composer/installed.php
BASELINE_CLASSIFICATION=COMPOSER_DEPENDENCY
RUNTIME_HASH=BASELINE_HASH
```

Quando o manifesto é construído do mesmo SHA publicado, o `installed.php` normalmente coincide, porque o builder grava o `root.reference` desse SHA. A exceção cobre apenas runtimes cujo metadata ainda é o do baseline root-controlled. Não existe exceção genérica para `vendor/`.

A allowlist fixa de deltas de código da versão 2 (`PhilipsSubmissionMetadataResolver.php` e `ReportVersionPatientNameService.php`) foi removida: com a referência no commit publicado, ela deixou de ser necessária.

## Extras históricos

Extras somente são aceitos quando:

```text
RUNTIME_PATH ∈ BASELINE_MANIFEST
RUNTIME_HASH = BASELINE_HASH
```

Um extra ausente do baseline ou com hash divergente mantém o gate bloqueado.

## Uso

No checkout local, para testes com manifestos já acessíveis:

```bash
bash scripts/validate-runtime-drift.sh \
  --runtime-root /var/www/voxelpacs/app \
  --published-manifest /tmp/publicado.manifest.tsv \
  --baseline-manifest /var/lib/voxelpacs/baselines/<baseline-id>/manifest.tsv
```

No Host 1, com baseline root-owned, o gate root-controlled recebe por `stdin` somente o manifesto do commit publicado:

```bash
cat /tmp/publicado.manifest.tsv \
  | sudo -n /usr/local/sbin/voxelpacs-runtime-drift-gate \
      --baseline-id <baseline-id>
```

O caller não fornece path de runtime, baseline, comando ou destino. A allowlist de sudo não muda em relação à versão 2.

## Instalação da versão 3 no Host 1

Os helpers instalados em `/usr/local/sbin` são atualizados pelo procedimento existente, a partir de checkout limpo da `main` com a SHA exata:

```bash
sudo bash scripts/upgrade-runtime-drift-manifest-reader.sh --expected-sha <sha-da-main> --dry-run
sudo bash scripts/upgrade-runtime-drift-manifest-reader.sh --expected-sha <sha-da-main> --upgrade
```

O upgrade faz backup root-only dos helpers atuais e restaura automaticamente em caso de falha.

## Saída sanitizada

A saída contém somente contagens, categorias, o SHA publicado e os paths divergentes. Não imprime conteúdo de arquivos, hashes individuais, PHI, secrets, URLs privadas ou credenciais.

```text
MECHANISM_VERSION=3
REFERENCE=LAST_PUBLISHED_COMMIT
RUNTIME_PUBLISHED_SHA=<sha>|UNAVAILABLE
DRIFT_GATE=PASS|FAIL|BLOCKED
PRODUCTION_CHANGED=NO
DATABASE_CHANGED=NO
WORKER_STARTED=NO
BRIDGE_CALLED=NO
SMB_CALLED=NO
TRANSMISSION=NO
```

## Rollback

O comparador não executa rollback. Em caso de `FAIL`, preservar o runtime e investigar os `MISMATCH_PATHS`: a correção de um arquivo alterado fora do Git é commitar a alteração na `main` (se for legítima) ou republicar o commit publicado (se não for).
