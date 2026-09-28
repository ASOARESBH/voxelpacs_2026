# VOXEL PACS — Reconciliação Git → runtime do Report Delivery

**Data:** 2026-09-28
**Escopo:** mapeamento versionado do layout e correção do alvo do aplicador de flags
**Estado:** implementação em branch própria; sem deploy, sem alteração de ambiente real e sem operação de banco

## Causa raiz

O bootstrap define `BASE_PATH` como o diretório pai de `app/bootstrap.php` e lê a configuração canônica em `BASE_PATH/.env`. O runtime efetivo publica a árvore Git sob o diretório de aplicação; por isso, no layout produtivo observado, a fonte canônica é o `.env` dentro dessa raiz de aplicação.

O aplicador versionado, porém, tinha um caminho padrão apontando para um nível acima da raiz efetivamente usada pelo bootstrap. Assim, uma aplicação aprovada poderia alterar um arquivo diferente daquele lido pelos consumidores PHP. Isso explicava a divergência entre o estado previsto pelo aplicador e o estado efetivo do Report Delivery.

## Mapeamento canônico

| Origem versionada | Destino no runtime | Evidência |
|---|---|---|
| `app/bootstrap.php` | `<runtime-app-root>/app/bootstrap.php` | `public/index.php` e Worker carregam esse bootstrap a partir da árvore publicada |
| `public/index.php` | `<runtime-app-root>/public/index.php` | entrypoint versionado usa `dirname(__DIR__) . '/app/bootstrap.php'` |
| `app/Config/ReportDeliveryRuntimeConfig.php` | `<runtime-app-root>/app/Config/ReportDeliveryRuntimeConfig.php` | classe carregada pelo autoloader da aplicação |
| `.env` operacional | `<runtime-app-root>/.env` | `BASE_PATH/.env` no bootstrap e em `canonicalEnvironmentFile()` |
| `scripts/configure-report-delivery-runtime.sh` | somente control-plane/versionado | script não é a fonte de configuração nem deve ser publicado como `.env` |

O placeholder `<runtime-app-root>` representa a raiz de aplicação confirmada no preflight operacional; o valor real permanece fora do Git como configuração de infraestrutura.

## Correção aplicada

- O `DEFAULT_ENV_FILE` do aplicador foi alinhado ao arquivo `.env` da raiz efetiva do runtime.
- O aplicador continua allowlisted, reversível e sem override arbitrário de caminho.
- O backup continua root-only e fora da árvore versionada.
- Nenhum `.env`, segredo, credencial, log, upload, PDF, XML ou dado de paciente foi adicionado ao Git.
- Nenhum serviço é reiniciado pelo aplicador; a aplicação operacional permanece uma etapa separada e autorizada.

## Proteções de regressão

O contrato estático agora exige que:

1. o aplicador use o `.env` da raiz efetiva do runtime;
2. o alvo antigo, um nível acima da raiz de aplicação, não reapareça;
3. não exista override secundário por variável de ambiente;
4. a configuração PHP e o aplicador continuem apontando para a mesma fonte canônica.

## Validação e limites

Esta alteração deve ser validada com PHP lint, contratos estáticos, `composer validate --strict`, guard do Composer, regressões PDF A4/QR, detector PHPUnit e `git diff --check`.

A existência deste documento e do aplicador corrigido **não prova** que qualquer configuração foi aplicada em produção. Backup, drift, deploy, reload/restart, consulta de fila e transmissão Philips permanecem fora desta alteração.

**MIGRATION_REQUIRED = NO**
**DATABASE_CHANGED = NO**
**RUNTIME_CHANGED = NO**
**WORKER_STARTED = NO**
**TRANSMISSION = NO**
