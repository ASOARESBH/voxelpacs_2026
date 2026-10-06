# VOXEL PACS — Auditoria read-only do Job 522

## Objetivo

`bin/philips_job_522_readonly_audit.php` é um diagnóstico técnico fixo para confirmar, no banco de produção, a identidade e a elegibilidade estrutural do Job `522` no tenant `2` e no Destination `7`.

O diagnóstico não deve ser usado para reprocessar, enviar ou alterar o Job. Ele retorna apenas estados técnicos sanitizados:

- Job, Outbox e, quando aplicável, Delivery Request;
- tenant, Destination, transport e profile;
- estado `queued`, contador de attempts e lock;
- existência estrutural do report, versão e estudo vinculado;
- `task_author_source=bi_medicos` e resolução tenant-scoped de `task_author_id`, sem materializar o nome;
- flags efetivas essenciais e estado read-only da unit do Worker.

## Limites

O helper e o diagnóstico:

- aceitam **zero argumentos**;
- têm Job, tenant e Destination compilados no código;
- usam somente `SELECT`/introspecção e uma transação `READ ONLY` revertida ao final;
- não executam claim, attempt, retry, update, insert, delete, migration ou alteração de configuração;
- não geram PDF/XML e não escrevem artefatos;
- não chamam Worker, Bridge, SMB ou DICOM/C-STORE;
- não exibem nomes de paciente, nome do médico, payload, hashes completos, credenciais ou conteúdo clínico;
- falham fechado em caso de identidade, schema, autor, digest, lock ou estado incompatível.

A regra `task_author_source=bi_medicos` só é aceita para `automatic_production`. A consulta do autor exige simultaneamente:

```text
bi_medicos.id = task_author_id
bi_medicos.tenant_id = 2
bi_medicos.ativo = 1
nome não vazio
```

A origem não é serializada no XML; o diagnóstico apenas verifica a pré-condição da resolução.

## Arquivos

| Arquivo | Função |
|---|---|
| `bin/philips_job_522_readonly_audit.php` | Diagnóstico PHP fixo e sanitizado |
| `ops/deploy/voxelpacs-philips-job-522-readonly-audit` | Executor root-only sem argumentos |
| `ops/sudoers/voxelpacs-philips-job-522-readonly-audit` | Allowlist mínima exclusivamente para `manus-admin` |
| `scripts/provision-philips-job-522-readonly-audit.sh` | Provisionamento atômico após merge, com SHA e worktree limpos |
| `tests/philips_job_522_readonly_audit_static.php` | Contratos de segurança e escopo |

## Provisionamento futuro

O provisionamento é uma fase operacional separada e requer autorização administrativa própria no Host 1. Deve ser executado somente a partir de um checkout limpo da `main` mergeada:

```text
sudo bash scripts/provision-philips-job-522-readonly-audit.sh \
  --expected-sha <SHA-MERGEADA>
```

O provisionador valida SHA, árvore Git limpa, sintaxe PHP/Bash, regras sudoers e `visudo` antes de instalar. Ele não executa o diagnóstico durante a instalação.

A instalação cria somente:

```text
/usr/local/libexec/voxelpacs/philips_job_522_readonly_audit.php
/usr/local/sbin/voxelpacs-philips-job-522-readonly-audit
/etc/sudoers.d/voxelpacs-philips-job-522-readonly-audit
```

## Execução futura

Após provisionamento e validação independente da allowlist, a execução deve ser exatamente:

```text
sudo -n /usr/local/sbin/voxelpacs-philips-job-522-readonly-audit
```

A saída esperada para uma pré-condição íntegra é `audit_status=PASS`. Qualquer `BLOCKED`, `UNKNOWN`, falha de conexão ou ausência de tabela deve interromper a fase B0.1.

A execução do diagnóstico **não autoriza** Worker, one-shot, Bridge, SMB, DICOM/C-STORE ou transmissão. Esses são gates separados e exigem autorização independente.

## Validação local

```text
php -l bin/philips_job_522_readonly_audit.php
php -l tests/philips_job_522_readonly_audit_static.php
bash -n ops/deploy/voxelpacs-philips-job-522-readonly-audit
bash -n scripts/provision-philips-job-522-readonly-audit.sh
php tests/philips_job_522_readonly_audit_static.php
```

Nenhum desses testes acessa banco, Host 1, Host 2, Bridge, SMB ou dados clínicos.
