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

O `.gitignore` passa a bloquear, por padrão, extensões de chaves/certificados, dumps, SQL, backups e a árvore de storage. Há exceções explícitas somente para `database/migrations/**/*.sql`, `database/seeds/**/*.sql` e o exemplo público de ambiente. Nenhum segredo ou arquivo clínico foi adicionado ao staging.

## Estado operacional

O backup root-only `bck_git_v1` foi criado e verificado antes da reconciliação. A origem não foi alterada: não houve migration, deploy, reload/restart, mudança de permissão, alteração de banco, alteração de DICOM/Orthanc/WireGuard/Bridge ou transmissão.

A branch está em merge sem commit para permitir revisão de diff, secret scan, lint, validação de migrations e testes. Composer validate passou, o lint PHP não encontrou falhas, as regressões estáticas passaram e o gate PDF passou com 17/17 testes. O PHPUnit foi executado, mas não encontrou testes PHPUnit nesse projeto (`No tests executed!`); nenhum pacote foi instalado em produção. A suíte foi executada apenas após `composer install` no worktree local.

## Classificação final

Não há conflito funcional identificado na simulação. A view da Worklist preserva `target="_blank"` e `rel="noopener noreferrer"`; as quatro migrations são aditivas, com rollback documentado e não foram aplicadas; o `.gitignore` não ignora migrations, seeds ou `.env.example`. O commit de reconciliação ainda depende da revisão final do diff staged e do secret scan; a `main` não será alterada diretamente.
