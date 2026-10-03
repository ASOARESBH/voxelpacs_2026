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
