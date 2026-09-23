# Mapa de reconciliação — VOXEL PACS

**Data:** 23 de setembro de 2026
**Branch:** `reconcile/production-2026-09-23`
**Base da branch:** `main` em `af2c1ee4808bc8504c66ce00fef19ff207ab76b0`
**Fonte funcional comparada:** `versao/1.1` em `a1fe32a4dcf4b14c53961a98689c79fc456a71c2`

## Objetivo

Esta branch reúne, para revisão, a base histórica da `main` e as alterações funcionais comprovadamente presentes na linha `versao/1.1`, sem importar a raiz de produção como se fosse um repositório Git. A produção permanece uma cópia de release e não foi editada.

## Código considerado versionável

| Classe | Tratamento | Justificativa |
|---|---|---|
| `app/` | `KEEP_VERSION_1_1` quando modificado e `KEEP_BOTH` quando preservado pela base | Código PHP de runtime, incluindo Worklist, Laudário, PDF, revisão operacional e serviços de domínio. |
| `public/` | `KEEP_VERSION_1_1` quando modificado e `KEEP_BOTH` quando preservado pela base | Assets e entrada pública necessários ao runtime. |
| `config/`, `routes/`, `bin/`, `bootstrap.php`, `composer.json`, `composer.lock`, `VERSAO.txt` | `KEEP_BOTH` ou `KEEP_VERSION_1_1` conforme o diff | Configuração versionável e artefatos de build; nenhum segredo foi importado. |
| `database/migrations/*.sql` | `KEEP_VERSION_1_1` | Quatro migrations aditivas de proveniência de PDF, aprovadas explicitamente como código versionado. Não foram aplicadas ao banco. |
| `database/seeds/*.sql` | `KEEP_BOTH` | Seeds já versionados e preservados por exceção explícita do `.gitignore`. Não são dumps de produção. |
| `tests/` e `scripts/` | `KEEP_VERSION_1_1` quando relacionados às mudanças auditadas | Regressões de Worklist/PDF e gate de CI; não são dados clínicos. |
| documentação `SKILL-VOXEL-PACS/` | `KEEP_VERSION_1_1` quando registra comportamento novo | Contexto durável de rota, unidade, template, snapshot e revisão. |
| `.github/workflows/ci.yml` | `KEEP_VERSION_1_1` para revisão | Workflow de CI; não executa deploy produtivo nesta etapa. |

## Dados e artefatos que permanecem fora do Git

| Classe | Tratamento | Motivo |
|---|---|---|
| `.env`, `.env.*` e credenciais | `IGNORE_FROM_GIT` | Segredos e configuração específica do ambiente. O exemplo público de ambiente permanece permitido. |
| `storage/`, uploads, logs, cache e sessões | `IGNORE_FROM_GIT` | Dados persistentes, clínicos ou gerados pelo runtime. A aplicação mantém apenas os arquivos estruturais mínimos necessários ao repositório. |
| backups e `bck_git_v1` | `IGNORE_FROM_GIT` | Cópias de recuperação não devem ser publicadas. O backup desta operação está fora do document root e com acesso root-only. |
| DICOM, PDFs clínicos, XML, artifacts e documentos de pacientes | `IGNORE_FROM_GIT` | Conteúdo clínico e operacional, sem justificativa para versionamento público. |
| arquivos temporários, releases e caches do servidor | `IGNORE_FROM_GIT` | Estado gerado ou específico de infraestrutura. |
| chaves/certificados privados e dumps | `IGNORE_FROM_GIT` | Material de infraestrutura ou exportação de banco. |

## Comparação objetiva

A comparação de trees remotos foi feita sem atualizar o clone local por conteúdo de produção:

- `main`: 962 blobs.
- `versao/1.1`: 969 blobs.
- Arquivos somente em `main`: nenhum caminho.
- Arquivos somente em `versao/1.1`: 7, todos migrations/testes/gate PDF.
- Arquivos comuns com conteúdo diferente: 30.
- Simulação de merge: sem conflitos textuais.
- Diff funcional inicial do merge: 37 arquivos, sendo 30 modificados e 7 adicionados; staging final: 40 arquivos após `.gitignore`, locale, harness de teste e este mapa.
- A produção não possui `.git`; metadados Git, CI e o estado de trabalho não são importados.
- Os arquivos funcionais comparados entre produção e `versao/1.1`, incluindo a view da Worklist, coincidem por SHA-256; as divergências restantes do inventário são documentação/testes não implantados e metadados de repositório.

## Regra de segurança aplicada

O `.gitignore` passa a bloquear, por padrão, extensões de chaves/certificados, dumps, backups, diretórios de infraestrutura e a árvore de storage. SQL e Markdown legítimos permanecem versionáveis; nenhum segredo ou arquivo clínico foi adicionado ao staging.

## Estado operacional

O backup root-only `bck_git_v1` foi criado e verificado antes da reconciliação. A origem não foi alterada: não houve migration, deploy, reload/restart, mudança de permissão, alteração de banco, alteração de DICOM/Orthanc/WireGuard/Bridge ou transmissão.

Estado final: `HEAD=7d6579e7bbbbbf3405e58708ef3fdd73ec010398`, branch `reconcile/production-2026-09-23`, status `clean`, PR `#9` aberto com destino `main`; merge não executado. Composer validate passou, o lint PHP não encontrou falhas, as regressões estáticas passaram e o gate PDF passou com 17/17 testes. O PHPUnit foi executado, mas não encontrou testes PHPUnit nesse projeto (`No tests executed!`), portanto o resultado é inconclusivo; o check CI agora falha deliberadamente para impedir falso sucesso. Nenhum pacote foi instalado nesta correção. A suíte anterior foi executada após `composer install` somente no worktree local.

## Classificação final

Não há conflito funcional identificado na simulação. A view da Worklist preserva `target="_blank"` e `rel="noopener noreferrer"`; as quatro migrations são aditivas, com rollback documentado e não foram aplicadas; o `.gitignore` mantém `.env.example`, migrations, seeds, documentação e SQL legítimo versionáveis, enquanto protege ambientes e artefatos operacionais. A `main` não foi alterada diretamente.

## Backup e PHPUnit

A validação read-only encontrou um archive íntegro, com 2.959 entradas de arquivo e 411 diretórios, owner/grupo `root:root`, sem permissões de escrita/leitura mundial e sem symlinks. A release atualmente instalada contém 2.958 arquivos e 411 diretórios; a diferença de um arquivo e a falha dos dois manifestos SHA-256 quando comparados ao contexto atual impedem afirmar equivalência exata da release sem uma revisão adicional. A restauração técnica em staging isolado é possível, mas nenhuma restauração foi executada.

O PHPUnit não descobriu testes (`No tests executed!`). O workflow CI foi endurecido para falhar quando a contagem descoberta for zero, evitando que esse estado seja reportado como sucesso. Checks atuais do PR: PHP Lint `SUCCESS`, PDF A4/QR `SUCCESS`, PHPUnit `FAILURE` pela guarda de zero testes. Classificação atual: `REQUIRES_REVIEW`; o PR permanece aberto e não foi mergeado.
