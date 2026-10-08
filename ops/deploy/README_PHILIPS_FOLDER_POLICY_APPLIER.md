# Philips Folder Policy Applier

## Finalidade

`voxelpacs-philips-folder-policy-applier` é um helper root-only, versionado e fail-closed para administrar somente a policy operacional da **Philips Folder Bridge**. Ele prepara uma janela `single_test` com tenant, Destination, Job, transporte e profile explicitamente allowlisted.

Este mecanismo **não executa Jobs, não inicia Worker, não chama a Bridge, não acessa SMB, não transmite PDF/XML, não altera banco e não controla DICOM/C-STORE**.

## Padrão legado analisado

O mecanismo histórico foi `docs/technical/enable_philips_folder_controlled_job_272.sh`:

```text
LEGACY_JOB=272
LEGACY_DESTINATION=6
LEGACY_BACKUP_MECHANISM=backup root-owned de EnvironmentFile, com diretório 0700 e arquivo 0600
LEGACY_ROLLBACK_MECHANISM=restauração do backup apontado, com owner/mode verificados
LEGACY_POLICY_APPLIER=edição allowlisted de PHILIPS_FOLDER_ALLOW_JOB_ID
LEGACY_RELOAD_MECHANISM=nenhum; reload era uma etapa separada
LEGACY_VALIDATION=hostname, EnvironmentFile, mode, destination e material protegido
LEGACY_HARDCODED_VALUES=Job 272, Destination 6 e hostname histórico
```

Partes reutilizadas como padrão genérico: backup antes de apply, arquivo temporário, substituição atômica, owner/mode root-only, allowlist explícita, verificação pós-apply e rollback por backup. Job, Destination, hostname, caminhos de segredo e nomes de host são específicos do legado e não foram copiados cegamente.

## Arquitetura e parâmetros

A configuração do runtime permanece fora do Git. O helper recebe explicitamente:

```text
--env-file PATH
--allowlist PATH
--backup-root PATH
--expected-host NAME
--tenant-id N
--destination-id N
--job-id N|0
--transport philips_non_dicom --profile submission_document
--mode single_test|destination
--unit voxelpacs-philips-folder-bridge.service
```

O helper exige root, paths absolutos, EnvironmentFile root-owned `0600`, backup root-owned `0700` e uma allowlist sem chaves desconhecidas ou duplicadas.

O formato da allowlist é key-value sanitizado:

```text
tenant_id=2
destination_id=7
jobs=519
transport=philips_non_dicom
profile=submission_document
mode=single_test
```

Embora o primeiro uso previsto seja o Job 519/Destination 7, os valores são argumentos e a allowlist é validada em cada execução. O código não é hardcoded para um Job específico.

Para automação restrita por tenant/Destination, use uma allowlist separada:

```text
tenant_id=2
destination_id=7
jobs=0
transport=philips_non_dicom
profile=submission_document
mode=destination
```

`jobs=0` é um sentinela fechado: só é aceito junto com `mode=destination`. O modo `single_test` exige exatamente um Job positivo; o modo `destination` não aceita lista, wildcard ou Job positivo. Ambos continuam limitados ao tenant, Destination, transporte e profile informados.

## Fail-closed

A execução é bloqueada quando houver:

- EnvironmentFile ausente, symlink ou proteção diferente de `root:root:600`;
- host inesperado;
- allowlist ausente, vazia, duplicada, ambígua ou incompatível;
- tenant, Destination, Job, transporte, profile ou mode incompatíveis;
- `jobs=0` fora de `destination`, ou Job positivo dentro de `destination`;
- policy atual desconhecida, duplicada ou inconsistente;
- fallback ou diagnostics ativos;
- backup ausente, inválido, incompatível ou com checksum divergente;
- unit não identificada exatamente.

Nenhum default perigoso é aplicado.

## Backup

A ordem obrigatória de `--backup-only` é:

```text
validar policy atual
→ validar a identidade exata da unit Philips Folder
→ validar parâmetros e allowlist
→ criar backup isolado
→ verificar manifest/checksum/owner/mode
→ emitir o identificador do backup
```

`--backup-only` não altera a policy, allowlist, unit, serviço, banco ou transporte.

A ordem obrigatória de `--apply` é:

```text
validar policy atual
→ validar parâmetros e allowlist
→ exigir --backup-id explícito
→ verificar backup, manifest e checksum
→ verificar compatibilidade com Job/Destination solicitados
→ verificar que o backup corresponde ao estado atual
→ aplicar somente cinco chaves da policy
→ validar o resultado
```

O backup contém somente a policy operacional não secreta:

```text
policy.conf
manifest
```

O manifest registra `BACKUP_ID`, timestamp, componente, mode, tenant, Destination, Job, transporte, profile e checksum SHA-256 do arquivo de policy. O applier não copia `.env` completo, HMAC, private keys, certificados, credenciais ou dados clínicos.

## Comandos

### Dry-run

Não grava arquivos, não cria backup, não reinicia unit e não executa transporte:

```bash
sudo -n /usr/local/sbin/voxelpacs-philips-folder-policy-applier \
  --dry-run \
  --env-file /etc/voxelpacs/philips-folder-bridge.env \
  --allowlist /etc/voxelpacs/philips-folder-policy.allowlist \
  --backup-root /var/backups/voxelpacs/philips-folder-policy-applier \
  --expected-host <hostname-aprovado> \
  --tenant-id 2 --destination-id 7 --job-id 519 \
  --transport philips_non_dicom \
  --profile submission_document --mode single_test
```

Saída esperada: `DRY_RUN=PASS`, `WOULD_APPLY=YES`, `WOULD_RELOAD=YES` e `WOULD_TRANSMIT=NO`.

### Backup-only

Cria o backup root-only da policy efetivamente carregada, após verificar a unit Philips Folder. Não faz reload e não aplica nenhuma alteração:

```bash
sudo -n /usr/local/sbin/voxelpacs-philips-folder-policy-applier \
  --backup-only \
  --env-file /etc/voxelpacs/philips-folder-bridge.env \
  --allowlist /etc/voxelpacs/philips-folder-policy.allowlist \
  --backup-root /var/backups/voxelpacs/philips-folder-policy-applier \
  --expected-host <hostname-aprovado> \
  --tenant-id 2 --destination-id 7 --job-id 519 \
  --transport philips_non_dicom \
  --profile submission_document --mode single_test \
  --unit voxelpacs-philips-folder-bridge.service
```

Saída esperada: `BACKUP_ONLY=PASS`, `BACKUP_MANIFEST=PASS`, `BACKUP_CHECKSUM=PASS`, `POLICY_CHANGED=NO` e `RELOAD=NOT_PERFORMED`.

### Aplicação futura

A instalação e o `--apply` **não fazem parte desta entrega Git-only**. Após o `--backup-only`, instalação root-controlled e autorização operacional separada:

```bash
sudo -n /usr/local/sbin/voxelpacs-philips-folder-policy-applier \
  --apply [mesmos parâmetros explícitos do dry-run] \
  --backup-id <BACKUP_ID>
```

O `--apply` rejeita ausência de backup, backup incompatível com Job/Destination ou backup cujo checksum não corresponda ao estado atual. Ele não recarrega a unit automaticamente; a separação evita misturar alteração persistente com reinício operacional.

### Modo destination para automação restrita

O modo `destination` não executa Worker, não chama a Bridge, não acessa SMB e não transmite. Ele somente fornece o mecanismo root-controlled para aplicar, com backup verificável, a policy restrita à combinação tenant/Destination autorizada.

Pré-condições operacionais antes de qualquer aplicação:

1. piloto `single_test` aceito e encerrado;
2. nenhum Job legado elegível na fila;
3. novo Job automático atual, tenant-scoped e validado sem envio;
4. backup root-only da policy atual e validação do manifest/checksum;
5. allowlist `jobs=0`, `mode=destination`, sem chaves extras;
6. aplicação e validação pela interface oficial;
7. reload separado exclusivamente da Philips Folder Bridge;
8. Worker global somente após autorização operacional separada.

Comandos do mecanismo, sem aplicação nesta entrega:

```bash
COMMON=(
  --env-file <ENV_FILE_PATH>
  --allowlist <DESTINATION_ALLOWLIST_PATH>
  --backup-root <BACKUP_ROOT>
  --expected-host <hostname-aprovado>
  --tenant-id 2 --destination-id 7 --job-id 0
  --transport philips_non_dicom
  --profile submission_document --mode destination
)

sudo -n /usr/local/sbin/voxelpacs-philips-folder-policy-applier \
  --dry-run "${COMMON[@]}"

sudo -n /usr/local/sbin/voxelpacs-philips-folder-policy-applier \
  --backup-only "${COMMON[@]}" \
  --unit voxelpacs-philips-folder-bridge.service

sudo -n /usr/local/sbin/voxelpacs-philips-folder-policy-applier \
  --apply "${COMMON[@]}" --backup-id <BACKUP_ID>

sudo -n /usr/local/sbin/voxelpacs-philips-folder-policy-applier \
  --validate "${COMMON[@]}"
```

O `--reload` continua separado, exige a mesma policy efetiva e valida exclusivamente `voxelpacs-philips-folder-bridge.service`. Nenhuma etapa deste mecanismo inicia Worker, executa Job, chama Bridge, acessa SMB ou altera DICOM/C-STORE.

### Transição segura entre Jobs single-test

Quando a allowlist atual aponta para um Job predecessor e o alvo é outro Job do mesmo tenant/Destination, o `--apply` normal bloqueia corretamente com `TARGET_JOB_NOT_ALLOWLISTED`. Para essa troca existe um fluxo explícito de transição, que aceita somente uma origem e um alvo diferentes:

```bash
COMMON=(
  --env-file <ENV_FILE_PATH>
  --allowlist <ALLOWLIST_PATH>
  --backup-root <BACKUP_ROOT>
  --expected-host <hostname-aprovado>
  --tenant-id 2 --destination-id 7 --job-id 522
  --source-job-id 519
  --transport philips_non_dicom
  --profile submission_document --mode single_test
)
```

O fluxo obrigatório é:

```text
transition-dry-run
→ transition-backup-only
→ transition-apply
→ validate
→ reload separado da unit Philips Folder
```

Comandos:

```bash
sudo -n /usr/local/sbin/voxelpacs-philips-folder-policy-applier \
  --transition-dry-run "${COMMON[@]}"

sudo -n /usr/local/sbin/voxelpacs-philips-folder-policy-applier \
  --transition-backup-only "${COMMON[@]}" \
  --unit voxelpacs-philips-folder-bridge.service

sudo -n /usr/local/sbin/voxelpacs-philips-folder-policy-applier \
  --transition-apply "${COMMON[@]}" \
  --backup-id <TRANSITION_BACKUP_ID>
```

O backup de transição preserva separadamente `policy.conf` e `allowlist`, com checksums e manifesto root-only. O `transition-apply` só prossegue quando a policy e a allowlist ainda correspondem à origem; escreve os dois arquivos a partir de temporários protegidos e restaura o predecessor se a segunda instalação ou a validação final falhar. O reload continua separado:

```bash
sudo -n /usr/local/sbin/voxelpacs-philips-folder-policy-applier \
  --reload [parâmetros do alvo] \
  --unit voxelpacs-philips-folder-bridge.service
```

Rollback sem reload:

```bash
sudo -n /usr/local/sbin/voxelpacs-philips-folder-policy-applier \
  transition-rollback --dry-run [parâmetros do alvo] \
  --source-job-id 519 --backup-id <TRANSITION_BACKUP_ID>
```

O mecanismo rejeita tenant, Destination, transporte, profile, modo, origem ou alvo incompatíveis; a transição continua exclusiva de `single_test` para `single_test` e nunca aceita múltiplos Jobs, wildcard, `destination` mode, reload da unit DICOM/C-STORE, Worker, Bridge request, SMB, banco ou transmissão.

### Validação estrutural

```bash
sudo -n /usr/local/sbin/voxelpacs-philips-folder-policy-applier \
  --validate [mesmos parâmetros explícitos]
```

A validação exige que a policy efetiva no EnvironmentFile corresponda exatamente ao tenant, Destination, Job, transporte, profile e `single_test` solicitados.

### Rollback dry-run

```bash
sudo -n /usr/local/sbin/voxelpacs-philips-folder-policy-applier \
  rollback --dry-run [mesmos parâmetros explícitos] --backup-id <BACKUP_ID>
```

Valida existência, integridade, compatibilidade e checksum sem escrever.

### Rollback futuro

```bash
sudo -n /usr/local/sbin/voxelpacs-philips-folder-policy-applier \
  rollback [mesmos parâmetros explícitos] --backup-id <BACKUP_ID>
```

Restaura apenas as cinco chaves da policy alvo, preservando as demais configurações e segredos do EnvironmentFile. Exige que a policy atual corresponda ao alvo e que o backup seja compatível e íntegro. O reload continua separado.

### Reload futuro

O helper possui uma abstração explícita e restrita à unit:

```text
voxelpacs-philips-folder-bridge.service
```

Antes do reload, ele verifica `FragmentPath`, `ExecStart` contendo `philips_folder_bridge.py` e EnvironmentFile exatamente informado. Não aceita wildcard, não usa `systemctl restart *` e não conhece a unit DICOM/C-STORE.

```bash
sudo -n /usr/local/sbin/voxelpacs-philips-folder-policy-applier \
  --reload [parâmetros estruturais] \
  --unit voxelpacs-philips-folder-bridge.service
```

O reload exige autorização operacional separada e não foi executado nesta entrega.

## Provisionamento versionado no Host 2

```text
PROVISIONER=scripts/provision-philips-folder-policy-applier.sh
PROVISIONER_MODE=ROOT_CONTROLLED
POLICY_APPLY=NOT_PERFORMED
BRIDGE_RELOAD=NOT_EXECUTED
```

O provisioner deve ser executado a partir de um staging mínimo derivado de uma SHA aprovada da `main`. Ele exige a SHA do commit e os SHA-256 explícitos do próprio provisioner, helper e sudoers; não depende de checkout Git no Host2:

```text
sudo -n bash scripts/provision-philips-folder-policy-applier.sh \
  --expected-sha <MAIN_SHA> \
  --expected-helper-sha <HELPER_SHA256> \
  --expected-sudoers-sha <SUDOERS_SHA256> \
  --expected-provisioner-sha <PROVISIONER_SHA256> \
  --upgrade --dry-run

sudo -n bash scripts/provision-philips-folder-policy-applier.sh \
  --expected-sha <MAIN_SHA> \
  --expected-helper-sha <HELPER_SHA256> \
  --expected-sudoers-sha <SUDOERS_SHA256> \
  --expected-provisioner-sha <PROVISIONER_SHA256> \
  --upgrade
```

O upgrade cria backup root-only do helper/sudoers existentes e restaura o estado anterior se a instalação ou a validação final falhar. Arquivos instalados:

```text
/usr/local/sbin/voxelpacs-philips-folder-policy-applier  root:root 0750
/etc/sudoers.d/voxelpacs-philips-folder-policy-applier   root:root 0440
```

A regra sudoers deste provisioner permite somente `--dry-run` e `--validate` para tenant 2, Destination 7, `job_id=0`, `transport=philips_non_dicom` e `mode=destination`. Não permite `apply`, `backup-only`, rollback, reload, shell ou comando genérico. A allowlist e o EnvironmentFile permanecem nos locais root-owned existentes; nenhum segredo, certificado, HMAC ou material de mTLS é copiado.

O provisionamento deve validar checksum, `bash -n`, owner/mode, `visudo -cf` e o dry-run destination. A unit Philips Folder somente poderá ser recarregada após `UNIT_IDENTITY=VERIFIED` e autorização separada. O DICOM/C-STORE é totalmente fora do escopo.

## Limites

- Não altera `Report Delivery`, `automatic_production`, `controlled_production`, PDF, XML, resolvers, generators ou producer.
- Não altera Destination 7, Job 519, Outbox, banco, migration ou dados clínicos.
- Não habilita feature flags da aplicação.
- Não inicia Worker.
- Não executa request, mTLS handshake operacional, SMB, C-STORE ou transmissão.
- Não para, inicia, reinicia ou configura DICOM/C-STORE.
- Não altera certificados, WireGuard, AE Title ou portas.
- Não substitui o mecanismo de Report Delivery.

## Estado desta entrega

```text
GIT_ONLY=YES
PRODUCTION_ACCESS=NO
PRODUCTION_CHANGED=NO
HOST1_CHANGED=NO
HOST2_CHANGED=NO
JOB_EXECUTED=NO
TRANSMISSION=NO
DICOM_CONTROL=OUT_OF_SCOPE
PRODUCTION_RELOAD=NO
PRODUCTION_INSTALL=NO
```
