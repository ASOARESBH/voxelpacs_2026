# VOXEL PACS — Adoção do baseline de produção

## Objetivo

Estabelecer uma captura inicial, sanitizada e verificável do runtime produtivo sem transformar a produção em workspace de desenvolvimento. Depois da aprovação do baseline, alterações novas seguem exclusivamente `branch → testes → commit → push → PR → merge na main → deploy controlado`.

## Escopo da captura

A captura é somente leitura sobre a raiz efetiva da aplicação e registra, por arquivo regular versionado ou operacionalmente relevante:

- caminho relativo;
- tamanho;
- SHA-256 quando legível;
- owner, group e modo POSIX;
- mtime em UTC;
- classificação técnica;
- estado `READABLE` ou `HASH_READ_PERMISSION_DENIED`.

Para `vendor/composer/installed.php`, a captura também produz o perfil sanitizado `composer-profile.tsv`, contendo somente os estados técnicos das referências `root` e `voxel/bi`. O perfil é vinculado ao checksum do manifesto e à SHA de `main` aprovada em âncora root-only; nenhum valor de referência ou conteúdo do arquivo é copiado.

Symlinks são registrados separadamente com alvo, owner, group, modo e mtime. O capturador não segue symlinks.

O capturador exclui e apenas contabiliza, sem copiar conteúdo:

- `.env` e variantes;
- `storage`, uploads, logs, backups, runtime, cache, sessões e temporários;
- PDFs, XMLs, DICOM, dados clínicos e credenciais.

## Mecanismo

O script versionado é `scripts/capture-production-baseline.sh`. Ele:

1. aceita somente raiz de origem e diretório de saída explicitamente delimitados;
2. rejeita saída dentro da origem;
3. usa `find -P`/`-xdev` e não segue symlinks;
4. cria a evidência em diretório temporário `0700` e publica atomicamente o diretório identificado pelo hash do manifesto;
5. grava somente metadados e hashes, nunca o conteúdo dos arquivos;
6. pode executar em modo parcial para registrar permissões insuficientes;
7. com `--require-complete`, falha fechado se algum hash não puder ser lido.

Para uma captura integral do Host 1, o script deve ser executado por um mecanismo root-controlled previamente aprovado. Não se deve ampliar `sudoers` de forma genérica nem copiar a chave privada do administrador.

O mecanismo versionado para essa execução é `scripts/provision-production-baseline-capture.sh`. Ele instala somente o capturador, o wrapper fixo e o sudoers dedicado, todos com ownership/mode mínimos e validação `visudo`; na captura, o wrapper exige a âncora root-only `/etc/voxelpacs-composer-profile/voxelpacs-composer-profile-approved-main-sha`, mantida em parent dedicado `root:root:0700`, e o caller pode executar apenas `capture`, `refresh-profile` ou `rebind-profile`, sem fornecer path, comando, shell, destino ou SHA. O parent compartilhado `/etc/voxelpacs` não é alterado. Se os destinos já existirem, o modo normal continua no-replace; o modo `--upgrade` só é aceito quando os três blobs existentes forem comprovados no histórico da `origin/main`, com owner/mode regulares, staging no mesmo filesystem e rollback fail-closed em falha parcial.

Quando a baseline íntegra já existe sem sidecar Composer, `refresh-profile <baseline-id-40hex>` é a única forma aprovada de completar essa lacuna: exige o ID técnico explicitamente validado, usa o capturador oficial em área temporária, preserva backup root-only de `metadata.txt` e faz troca atômica apenas de `composer-profile.tsv` e dos marcadores de perfil. Não enumera nem escolhe candidatas, não recria o baseline, não substitui o manifesto e bloqueia se houver sidecar, ID inválido ou estrutura insegura.

Quando o sidecar já existe, mas está vinculado a outra SHA aprovada, `rebind-profile <baseline-id-40hex>` é a operação versionada específica: lê a SHA somente da âncora root-only, valida a baseline e o sidecar existentes, gera o novo perfil pelo capturador oficial, preserva o manifesto e faz backup root-only de `metadata.txt` e `composer-profile.tsv` antes da troca atômica. Em falha parcial restaura os dois arquivos; não aceita SHA, path ou baseline escolhido pelo caller.

A configuração inicial ou reconciliação dessa âncora não é feita manualmente. O procedimento versionado separado é `scripts/provision-composer-profile-approval.sh`, documentado em `docs/technical/COMPOSER_PROFILE_APPROVAL.md`. Primeiro executa-se `--inspect` e `--dry-run`; `--apply` cria somente uma âncora ausente e permanece bloqueado diante de divergência. Para uma âncora divergente estruturalmente válida, a autorização administrativa específica usa `--reconcile`, que cria backup root-only no path fixo, troca atomicamente e restaura em falha. O helper aceita exclusivamente o parent dedicado e a âncora fixa, prova a `origin/main` no remoto canônico e falha fechado diante de owner, group, mode, symlink, entrada inesperada ou estado de backup inseguro.

## Backup protegido

Antes de qualquer proposta de baseline, deve existir backup root-only verificável da release atualmente instalada. O backup usa o helper oficial:

```text
voxelpacs-release-backup create --sha <baseline-id-40-hex>
voxelpacs-release-backup validate --sha <baseline-id-40-hex>
voxelpacs-release-backup restore-test --sha <baseline-id-40-hex>
```

Esse identificador é um ID técnico do baseline, não uma SHA de commit. O backup não inclui `.env`, storage, uploads, logs, backups ou conteúdo clínico.

## Preparação no Git

A branch dedicada deve partir da `origin/main` limpa:

```text
baseline/production-2026-10-10
```

A branch deve conter apenas:

- o capturador e seus testes;
- o contrato/documentação do baseline;
- alterações de código próprio comprovadamente presentes no runtime, somente após comparação e revisão;
- nenhuma configuração secreta, credencial, chave, certificado, token, log, upload, PDF, XML, DICOM ou dependência vendor importada indiscriminadamente.

O baseline não deve reescrever a `main`, fazer force push, remover arquivos do runtime ou executar deploy automaticamente.

## Gates

```text
PRODUCTION_BASELINE_CAPTURED=YES|NO|PARTIAL
BASELINE_MANIFEST_CREATED=YES|NO
SECRETS_SCAN=PASS|FAIL|NOT_RUN
BASELINE_BRANCH_CREATED=YES|NO
REMOTE_BRANCH_PUSHED=YES|NO
PR_CREATED=YES|NO
MAIN_CHANGED=NO
PRODUCTION_CHANGED=NO
DEPLOY_EXECUTED=NO
DATABASE_CHANGED=NO
WORKER=NOT_STARTED
TRANSMISSION=NO
```

Equivalência funcional não é inferida por coincidência de hashes. Ela exige validação do Composer, lint, testes de contrato, rotas, dependências, permissões e configuração obrigatória em ambiente seguro.

## Estado desta implementação

- O baseline foi preparado em branch própria a partir da `main` limpa e integrado à `main` após atualização com a base atual e checks remotos verdes.
- A primeira leitura como `manus-admin` encontrou arquivos legados sem permissão de leitura; isso é registrado como lacuna, não mascarado como sucesso.
- A captura integral deve usar o provisionador root-controlled, sem alterar a aplicação ativa; a ausência ou ambiguidade do `installed.php` bloqueia e não publica o baseline.
- O provisionamento do helper e a captura do baseline permanecem separados de deploy, migration, Worker, Job, Bridge, SMB, Philips e DICOM/C-STORE.
