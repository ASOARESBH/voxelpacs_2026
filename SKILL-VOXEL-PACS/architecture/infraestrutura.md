# Arquitetura — Infraestrutura

## Filas e Jobs

- Sistema de filas: `[A confirmar — Redis + biblioteca?]`
- Ver `indexes/eventos-filas.md` para o mapa fila-a-fila.

## Cache

- Onde é usado (sessão, resultado de query, resposta de API externa?): `[A confirmar]`
- Estratégia de invalidação: `[A confirmar]`

## Uploads / Ingestão de arquivos

- Fluxo de upload de DICOM manual (fora do C-STORE): `[A confirmar]`
- Limites de tamanho/validações aplicadas: `[A confirmar]`
- Onde os arquivos ficam armazenados (disco local, S3-compatível, dentro do Orthanc?): `[A confirmar]`

## Logs e Auditoria

- Onde ficam os logs de aplicação: `[A preencher caminho]`
- Existe log de auditoria específico para acesso a dados de paciente/exame (requisito comum em PACS)? `[A confirmar — se não existir, sinalizar como gap]`

## Containers / Deploy

- Orquestração: `[A confirmar — Docker Compose, Kubernetes, outro?]`
- Arquivos de configuração relevantes: `[A preencher caminhos]`
- Ambientes existentes (dev/staging/produção) e diferenças relevantes: `[A confirmar]`

## Configurações e variáveis de ambiente

- Onde ficam: `[A preencher caminho]`
- Variáveis sensíveis conhecidas (sem valores, só nomes/propósito): `[A preencher conforme necessário para a tarefa]`

## Artefato e raiz efetiva do runtime

- O layout publicado preserva `app/*` em `APP_ROOT/app/*`; a extração no diretório pai cria uma árvore plana incorreta e pode deixar o bootstrap sem classes versionadas.
- `scripts/build-runtime-artifact.sh` é o builder determinístico e exclui `.env`, storage, uploads, logs, backups, testes, documentação, scripts e migrations.
- `scripts/deploy.sh` exige `APP_ROOT`, não substitui dados persistentes e não executa Composer remoto ou permissões recursivas.
- Backup root-only, reconciliação de drift, deploy, reload/restart e smoke de produção continuam sendo gates operacionais separados.

## Backup root-only de release

- `scripts/release_backup.sh` é o mecanismo versionado para criar/validar o backup da release com SHA explícito e restore-test isolado.
- O provisionamento root-owned é separado em `scripts/provision-release-backup.sh`; a allowlist fica em `ops/sudoers/voxelpacs-release-backup` e não concede shell ou comandos genéricos.
- O backup usa `/var/backups/voxelpacs/releases/<SHA>` e `/var/backups/voxelpacs/restore-tests/<SHA>`, não segue `.env`, `storage`, uploads, logs, backups ou dados clínicos.
- A capacidade é independente de `scripts/deploy.sh` e não foi provisionada ou executada em produção nesta alteração.

## Baseline inicial de produção

- `scripts/capture-production-baseline.sh` captura somente metadados e hashes, sem seguir symlinks e sem copiar `.env`, persistência, uploads, logs, backups ou dados clínicos.
- O modo padrão registra arquivos cujo hash não pôde ser lido como `HASH_READ_PERMISSION_DENIED`; `--require-complete` falha fechado para uma captura integral.
- O baseline é uma evidência inicial, não uma autorização de deploy. Após sua aprovação, o GitHub/main volta a ser a fonte oficial das alterações futuras.

O provisionamento root-controlled da captura usa `scripts/provision-production-baseline-capture.sh`, instala o wrapper fixo e o capturador em `/usr/local/sbin` e uma regra sudoers limitada às operações `capture`, `refresh-profile` e `rebind-profile`. O wrapper lê a SHA do perfil Composer somente da âncora root-only `/etc/voxelpacs-composer-profile/voxelpacs-composer-profile-approved-main-sha`; o capturador publica o sidecar sanitizado vinculado ao manifesto. O parent `/etc/voxelpacs-composer-profile` é dedicado, `root:root:0700`, e não compartilha entradas com o parent administrativo `/etc/voxelpacs`; o caller não fornece origem, destino, shell, comando ou SHA. O helper não acessa banco, serviços, Worker, Bridge ou SMB. Quando os destinos já existem, `--upgrade` exige proveniência comprovada no histórico completo da `origin/main`, owner/mode mínimos e conjunto completo; usa staging nos filesystems de `/usr/local/sbin` e `/etc/sudoers.d`, backups root-only temporários e restauração dos componentes anteriores se uma substituição parcial falhar. `refresh-profile <baseline-id-40hex>` é separado: exige ID explícito, valida manifesto/metadata, preserva backup root-only de `metadata.txt` e troca somente o sidecar e os marcadores de perfil. `rebind-profile <baseline-id-40hex>` só atualiza sidecar/metadata já existentes com a SHA da âncora, preserva o manifesto, mantém backup root-only dos dois arquivos e restaura ambos em falha parcial.

A aprovação inicial ou reconciliação dessa âncora é uma capacidade administrativa separada em `scripts/provision-composer-profile-approval.sh`. Seus modos fixos `inspect`, `dry-run`, `apply` e `reconcile` validam o checkout limpo, a `origin/main` e o remoto canônico antes de aceitar qualquer SHA. O modo `dry-run` não altera configuração; o modo `apply` só cria/reconcilia o parent dedicado `/etc/voxelpacs-composer-profile` e cria a âncora ausente sem substituir uma âncora existente. O modo `reconcile` aceita somente uma âncora divergente estruturalmente válida, cria backup root-only no path fixo, troca atomicamente e restaura em falha; o `apply` permanece bloqueado para divergência. O parent compartilhado `/etc/voxelpacs` não é tocado. Owner, group, mode, symlink, entradas inesperadas, backup inseguro, divergência não reconciliada e falhas de validação final são fail-closed. A capacidade não executa a captura, o drift gate ou qualquer operação clínica.

O auditor de perfil Composer reutiliza a âncora singular oficial no mesmo parent dedicado, em `/etc/voxelpacs-composer-profile/voxelpacs-composer-profile-approved-main-sha`. O provisioner valida essa âncora sem ler ou alterar o parent compartilhado `/etc/voxelpacs`; a ausência, divergência ou insegurança da aprovação bloqueia o provisionamento.

## Flag SMB read-only Non-DICOM

O helper `scripts/provision-philips-nondicom-smb-readonly-flag.sh` instala a interface fixa `ops/deploy/voxelpacs-philips-nondicom-smb-readonly-flag` e seu sudoers dedicado. O helper só opera sobre `PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED` no `.env` canônico, exige backup root-only, preserva o checksum do restante do arquivo e oferece `status`, `dry-run`, `apply` e rollback por `backup-id` hexadecimal fixo. Não aceita caminhos, chaves, valores ou comandos arbitrários e não acessa banco, serviços, Bridge, SMB, Worker ou DICOM.

## Drift e publicação

- O gate de comparação usa `scripts/validate-runtime-drift.sh` em modo somente leitura. Ele confronta o manifesto do artefato com o runtime e aceita extras apenas quando o path e o SHA-256 coincidem com o baseline root-controlled aprovado. `vendor/composer/installed.php` possui uma exceção explícita, condicionada à classificação `COMPOSER_DEPENDENCY` e ao hash do baseline; não existe exceção genérica para `vendor/`.
- Quando o manifesto do baseline é root-owned e não pode ser lido pelo caller SSH, o fluxo oficial usa `ops/deploy/voxelpacs-runtime-drift-manifest-reader` e `ops/deploy/voxelpacs-runtime-drift-gate`, provisionados por `scripts/provision-runtime-drift-manifest-reader.sh`. O leitor aceita somente `baseline-id` hexadecimal, valida checksum/ownership/formato e emite apenas o manifesto técnico permitido; o gate mantém o runtime e o comparador em caminhos administrativos fixos. Nenhum componente publica código, inicia serviços, acessa banco, Worker, Bridge, SMB ou DICOM.
- Para investigar especificamente o mismatch de `vendor/composer/installed.php`, `ops/deploy/voxelpacs-composer-drift-auditor` compara por leitura o hash do artefato recebido por stdin, o hash do runtime e o hash do baseline root-controlled. O alvo, baseline root, argumentos e sudoers são fixos; hashes completos e conteúdo não são emitidos. O provisioner é separado e não foi executado nesta PR.
- Para investigar extras individuais sem enfraquecer o gate, `ops/deploy/voxelpacs-runtime-extra-auditor` usa a árvore fixa `/var/www/voxelpacs/app`, o manifesto do artefato recebido por stdin e uma baseline root-controlled explicitamente identificada. Ele valida checksum, ownership, modo e completude da baseline; classifica apenas por contagens agregadas e estados `PASS`, `INCOMPLETE`, `FAIL` ou `BLOCKED`, sem emitir paths, hashes ou conteúdo. O provisioner separado é no-replace, instala somente o helper fixo e sua allowlist `root:root` (`0555`/`0440`) e não provisiona ou executa produção como parte da implementação.
