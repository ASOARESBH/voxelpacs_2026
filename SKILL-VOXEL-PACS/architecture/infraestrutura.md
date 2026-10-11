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

O provisionamento root-controlled da captura usa `scripts/provision-production-baseline-capture.sh`, instala o wrapper fixo e o capturador em `/usr/local/sbin` e uma regra sudoers limitada à operação `capture`. O caller não fornece origem, destino, shell ou comando; o helper não acessa banco, serviços, Worker, Bridge ou SMB.

## Flag SMB read-only Non-DICOM

O helper `scripts/provision-philips-nondicom-smb-readonly-flag.sh` instala a interface fixa `ops/deploy/voxelpacs-philips-nondicom-smb-readonly-flag` e seu sudoers dedicado. O helper só opera sobre `PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED` no `.env` canônico, exige backup root-only, preserva o checksum do restante do arquivo e oferece `status`, `dry-run`, `apply` e rollback por `backup-id` hexadecimal fixo. Não aceita caminhos, chaves, valores ou comandos arbitrários e não acessa banco, serviços, Bridge, SMB, Worker ou DICOM.

## Drift e publicação

- O gate de comparação usa `scripts/validate-runtime-drift.sh` em modo somente leitura. Ele confronta o manifesto do artefato com o runtime e aceita extras apenas quando o path e o SHA-256 coincidem com o baseline root-controlled aprovado. `vendor/composer/installed.php` possui uma exceção explícita, condicionada à classificação `COMPOSER_DEPENDENCY` e ao hash do baseline; não existe exceção genérica para `vendor/`.
- Quando o manifesto do baseline é root-owned e não pode ser lido pelo caller SSH, o fluxo oficial usa `ops/deploy/voxelpacs-runtime-drift-manifest-reader` e `ops/deploy/voxelpacs-runtime-drift-gate`, provisionados por `scripts/provision-runtime-drift-manifest-reader.sh`. O leitor aceita somente `baseline-id` hexadecimal, valida checksum/ownership/formato e emite apenas o manifesto técnico permitido; o gate mantém o runtime e o comparador em caminhos administrativos fixos. Nenhum componente publica código, inicia serviços, acessa banco, Worker, Bridge, SMB ou DICOM.
- Para investigar especificamente o mismatch de `vendor/composer/installed.php`, `ops/deploy/voxelpacs-composer-drift-auditor` compara por leitura o hash do artefato recebido por stdin, o hash do runtime e o hash do baseline root-controlled. O alvo, baseline root, argumentos e sudoers são fixos; hashes completos e conteúdo não são emitidos. O provisioner é separado e não foi executado nesta PR.
