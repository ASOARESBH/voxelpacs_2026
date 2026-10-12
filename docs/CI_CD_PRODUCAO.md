# CI/CD de produção — VOXEL PACS

## Objetivo

O workflow `.github/workflows/deploy-production.yml` automatiza o deploy somente de commits que chegaram à `main`, reutilizando o builder, o drift gate, o backup root-only e o publisher versionado do projeto.

O fluxo é automático após a aprovação dos gates e da proteção do ambiente GitHub `production`. Ele **não edita o Host1 diretamente**, não executa `git pull` na produção e não contorna o drift gate.

## Fluxo

```text
push/merge em main
    ↓
checkout do SHA exato
    ↓
Composer validate + árvore Composer
    ↓
checagem de migrations
    ↓
lint + contratos de segurança
    ↓
artefato runtime determinístico
    ↓
SSH estrito + helpers remotos
    ↓
drift gate root-controlled
    ↓
backup root-only + validate + restore-test
    ↓
publisher versionado
    ↓
health check
    ↓
resumo sanitizado no GitHub Actions
```

## Proteção obrigatória do ambiente GitHub

Criar o ambiente `production` em **Settings → Environments** com:

1. pelo menos um **required reviewer**;
2. branch deployment restrita a `main`;
3. aprovação obrigatória antes do job `deploy-production`;
4. proibição de autoaprovação pelo autor do workflow, quando suportado pela política da organização;
5. secrets e variables disponíveis somente nesse ambiente.

O workflow não deve usar `pull_request_target` nem executar código de uma branch de Pull Request com credenciais de produção.

## Variables do ambiente `production`

```text
VOXELPACS_DEPLOY_HOST=server.voxelpacs.com.br
VOXELPACS_DEPLOY_USER=manus-admin
VOXELPACS_HEALTH_URL=https://server.voxelpacs.com.br/health
VOXELPACS_BASELINE_ID=<baseline root-controlled aprovado, 40 hex>
```

O `VOXELPACS_BASELINE_ID` deve apontar para o baseline vigente e validado. Não usar valor inventado, baseline incompleto ou baseline de outro Host.

## Secrets do ambiente `production`

```text
VOXELPACS_DEPLOY_SSH_PRIVATE_KEY=<chave privada dedicada do deploy>
VOXELPACS_DEPLOY_SSH_KNOWN_HOSTS=<entrada(s) de host key aprovadas>
```

Regras:

- nunca imprimir secrets nos logs;
- usar uma identidade dedicada e com menor privilégio possível;
- manter `StrictHostKeyChecking=yes` e `IdentitiesOnly=yes`;
- não usar `ssh -o StrictHostKeyChecking=no`;
- não conceder `NOPASSWD: ALL`;
- a allowlist remota deve aceitar apenas os helpers fixos já provisionados.

## Gates que bloqueiam o deploy

O job falha fechado quando:

- o ref não é `refs/heads/main`;
- o checkout não corresponde a `github.sha`;
- host, usuário, URL ou baseline têm formato inesperado;
- `composer validate`, Composer guard ou lint falham;
- a mudança contém `database/migrations/`;
- contratos estáticos ou regressões falham;
- qualquer helper remoto não existe ou não está disponível;
- o drift gate não retorna sucesso;
- backup, validação ou restore-test falham;
- o publisher versionado ou o health check falham.

## Migrations

O CD não executa migrations automaticamente. Se o diff entre o commit anterior e o novo contiver `database/migrations/`, o job termina com:

```text
MIGRATION_REQUIRED=YES
DEPLOY=BLOCKED
```

A migration deve seguir um fluxo separado, com revisão, backup, compatibilidade e autorização própria.

## Escopo que não é executado pelo CD

```text
WORKER_STARTED=NO
BRIDGE_RESTART=NO
SMB=NO
TRANSMISSION=NO
DICOM_CSTORE=NOT_CHANGED
```

O deploy publica somente o artefato runtime permitido pelo builder e pelo publisher. `.env`, `storage`, uploads, logs, backups, DICOM, PDFs, XMLs clínicos e configurações persistentes ficam fora do artefato.

## Estado inicial e pendências

A implementação do workflow não significa que o deploy já está pronto para execução. Antes do primeiro uso é necessário:

1. mergear a PR deste workflow na `main`;
2. criar e proteger o ambiente `production`;
3. cadastrar variables e secrets sem expô-los;
4. confirmar a allowlist dos helpers no Host1;
5. confirmar um baseline root-controlled atual;
6. executar o workflow em modo controlado e revisar o primeiro resultado;
7. manter o deploy bloqueado enquanto o drift gate atual continuar falhando.

A existência de um workflow verde não substitui a validação do Host1 nem autoriza ignorar divergências.
