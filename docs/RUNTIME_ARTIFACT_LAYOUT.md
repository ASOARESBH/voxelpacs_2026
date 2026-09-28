# VOXEL PACS — Contrato do artefato runtime

## Objetivo

O artefato de release deve transportar somente o código aprovado da `main` e as dependências Composer da mesma árvore Git. Ele não é uma cópia do workspace e não contém configuração operacional, dados persistentes ou conteúdo clínico.

## Mapeamento obrigatório

O destino de extração é `APP_ROOT` — a raiz efetiva da aplicação — e não o diretório pai.

| Origem no Git | Destino no runtime | Função |
|---|---|---|
| `app/*` | `APP_ROOT/app/*` | bootstrap, autoload, Config, Controllers, Services e Views |
| `public/*` | `APP_ROOT/public/*` | document root e entrypoint web |
| `bin/*` | `APP_ROOT/bin/*` | entrypoints CLI, incluindo o Worker |
| `routes/*` | `APP_ROOT/routes/*` | rotas versionadas |
| `lang/*` | `APP_ROOT/lang/*` | traduções |
| `composer.json`, `composer.lock` | `APP_ROOT/` | dependências e rastreabilidade |
| `vendor/*` | `APP_ROOT/vendor/*` | dependências instaladas a partir do mesmo SHA |

O entrypoint web e o Worker carregam `APP_ROOT/app/bootstrap.php`; o bootstrap calcula `BASE_PATH` como `APP_ROOT`. Portanto, a classe `ReportDeliveryRuntimeConfig` deve estar em `APP_ROOT/app/Config/ReportDeliveryRuntimeConfig.php`.

## Exclusões obrigatórias

O pacote não inclui `.env`, `storage`, `uploads`, logs, backups, testes, documentação, scripts de operação, migrations, DICOM, PDFs, XMLs clínicos, credenciais, certificados ou chaves privadas. Esses itens permanecem no runtime e são preservados pelo procedimento operacional de deploy.

## Gates locais

`scripts/build-runtime-artifact.sh` bloqueia quando:

- o checkout Git está sujo ou o SHA esperado não coincide;
- Composer/vendor não pertencem à árvore validada;
- o arquivo de configuração runtime aninhado está ausente;
- uma cópia plana legada da configuração aparece no artefato;
- top-level ou caminhos proibidos entram no payload;
- o ZIP não contém os entrypoints obrigatórios;
- o ZIP contém qualquer dado persistente ou operacional proibido.

`scripts/deploy.sh` é apenas o publicador do artefato já validado. Ele exige um destino terminado em `/app`, rejeita a raiz pai, extrai sem apagar arquivos e não executa Composer remoto, `chmod` recursivo, migration, reload, restart, Worker ou transmissão. Backup, drift, autorização e validação de produção continuam sendo gates externos e obrigatórios.

## Rollback

Rollback não é inferido pela existência do ZIP. Deve usar o backup root-only previamente validado, preservando `.env`, storage, uploads, logs, symlinks, ownership, permissões e arquivos públicos não versionados classificados. Se backup ou drift não puderem ser validados, o deploy fica bloqueado.

**MIGRATION_REQUIRED = NO**
**DATABASE_CHANGED = NO**
**PRODUCTION_CHANGED = NO**
**WORKER_STARTED = NO**
**TRANSMISSION = NO**
