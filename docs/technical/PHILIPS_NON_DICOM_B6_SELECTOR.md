# Seletor B6.1 — primeiro Job Philips Non-DICOM

## Objetivo

O seletor root-controlled percorre somente os Jobs do `tenant_id=2` e `destination_id=7`, em ordem `created_at ASC, id ASC`, e retorna no máximo o primeiro Job que satisfaz os gates do fluxo automático de produção.

O Job `522` é sempre excluído.

## Escopo fixo

```text
TENANT_ID=2
DESTINATION_ID=7
TRANSPORT=philips_non_dicom
PROFILE=submission_document
ENVIRONMENT=producao
DISPATCH_MODE=automatic_production
EXCLUDED_JOB_ID=522
```

## Garantias

O diagnóstico:

- abre a transação como `READ ONLY`;
- usa prepared statements e filtros tenant-scoped;
- valida Job, Outbox, Destination, Report, versão e Study;
- valida `referring_physician_name` no JSON efetivamente persistido do Outbox;
- não reconstrói o campo consultando o estudo;
- não executa lookup `bi_medicos`;
- não gera PDF/XML;
- não cria attempt;
- não reclama Job;
- não inicia Worker;
- não chama Bridge ou SMB;
- não altera allowlist, serviço, Destination ou banco;
- retorna somente estados técnicos e IDs internos.

## Proveniência da PR #70

A SHA de referência é:

```text
1473783905d1ede4ef897fef909fdf6f8c29accf
```

O schema atual do Outbox não persiste a SHA do produtor por evento. Portanto, o
seletor não inventa uma chave nem aceita proveniência implícita. O estado é
reportado como:

```text
PR70_PROVENANCE=NOT_PERSISTED
PR70_PROVENANCE_NOTE=OUTBOX_DOES_NOT_PERSIST_PRODUCER_SHA
```

Não são aceitos como prova:

- `created_at` posterior à data do deploy;
- Job ID alto;
- `schema_version`;
- presença do campo somente em `bi_pacs_estudos`;
- SHA atual do runtime sem vínculo ao Outbox.

Se uma futura alteração versionada adicionar uma fonte formal de proveniência
por Outbox, ela deverá ser incorporada explicitamente ao contrato do seletor e
poderá produzir `PR70_PROVENANCE=CONFIRMED`. A ausência dessa evidência não
bloqueia um candidato quando o payload persistido comprovar o comportamento:

```text
PR70_PROVENANCE=NOT_PERSISTED
PAYLOAD_BEHAVIOR=CONFIRMED
READY_FOR_SINGLE_RUN=YES
```

## Instalação root-controlled

Após merge na `main`, usar checkout limpo da SHA mergeada e provisionar como root pelo procedimento aprovado:

```bash
sudo -n bash scripts/provision-philips-nondicom-b6-selector.sh \
  --expected-sha <SHA_MERGEADA>
```

O provisionador instala somente:

```text
/usr/local/libexec/voxelpacs/philips_nondicom_b6_selector.php
/usr/local/sbin/voxelpacs-philips-nondicom-b6-selector
/etc/sudoers.d/voxelpacs-philips-nondicom-b6-selector
```

A regra sudoers permite apenas o helper sem argumentos para `manus-admin`.

## Execução

```bash
sudo -n /usr/local/sbin/voxelpacs-philips-nondicom-b6-selector
```

O resultado esperado para um candidato é `CANDIDATE_FOUND=YES`,
`PAYLOAD_BEHAVIOR=CONFIRMED` e `READY_FOR_SINGLE_RUN=YES`. Qualquer payload
não confirmado, gate desconhecido ou estado `BLOCKED` interrompe o fluxo.

O seletor não prepara allowlist e não autoriza execução. Mesmo com `READY_FOR_SINGLE_RUN=YES`, a preparação da Bridge e o `runOne` exigem gates e autorização operacional separados.
