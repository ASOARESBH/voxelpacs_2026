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

- O baseline foi preparado em branch própria a partir da `main` limpa.
- A primeira leitura como `manus-admin` encontrou arquivos legados sem permissão de leitura; isso é registrado como lacuna, não mascarado como sucesso.
- A captura integral deve usar root-controlled, sem alterar a aplicação ativa.
- Não há autorização nesta etapa para merge, deploy, migration, Worker, Job, Bridge, SMB, Philips ou DICOM/C-STORE.
