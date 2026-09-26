# Workflow — Deploy

> **Estado da documentação:** este runbook descreve o fluxo obrigatório e separa o que está documentado do que está comprovadamente implementado. A existência deste arquivo não prova que uma release foi implantada.

## 1. Fluxo oficial

```text
LOCALIZAR
→ ENTENDER
→ IMPACTAR
→ PLANEJAR
→ ALTERAR
→ VALIDAR
→ COMMIT
→ PUSH
→ PULL REQUEST
→ MERGE NA MAIN
→ CONFIRMAR SHA DA MAIN
→ BACKUP VERIFICÁVEL
→ COMPARAR COM PRODUÇÃO
→ DEPLOY CONTROLADO
→ SMOKE TEST
→ REGISTRAR SHA IMPLANTADO
```

Produção só pode receber código de um SHA conhecido, enviado ao GitHub e aprovado na `main`. Não fazer deploy de working tree sujo, branch de desenvolvimento, commit não enviado ou código editado diretamente em produção.

## 2. Pré-checagem do repositório

Antes de montar o artefato:

1. Confirmar que `origin/main` é a fonte oficial e registrar o SHA completo.
2. Criar checkout detached exatamente nesse SHA.
3. Confirmar `git status --porcelain` vazio.
4. Executar `composer validate --strict`.
5. Executar `composer install` somente no checkout do SHA.
6. Executar `scripts/verify-composer-tree.sh` e bloquear vendor ausente, externo, de outro SHA ou com `installed.php` divergente.
7. Executar PHP lint, regressões PDF e os testes disponíveis.
8. Registrar `PHPUNIT=NOT_CONCLUSIVE` quando zero testes forem descobertos; não converter essa situação em falso `PASS`.
9. Reinstalar vendor de produção, quando necessário, somente na mesma árvore validada antes do empacotamento.

## 3. Migrations e banco

Deploy normal não executa migrations automaticamente. Antes do deploy, verificar se há migration pendente relacionada ao código.

Se houver migration necessária, declarar:

```text
MIGRATION_REQUIRED = YES
```

e interromper o fluxo normal até haver autorização específica, backup compatível, plano de execução e rollback.

Sem autorização explícita, não executar SQL de alteração, migrations, `DROP`, `TRUNCATE`, `DELETE` em massa ou `ALTER` destrutivo.

## 4. Backup e comparação

Antes de alterar a produção:

- criar ou localizar backup root-only da release atualmente instalada;
- preservar arquivos, ownership, permissões, timestamps relevantes, symlinks e estrutura;
- gerar manifests de arquivos, SHA-256, ownership, permissões e symlinks;
- validar archive e manifestos;
- restaurar em área isolada e comparar hashes, permissões e symlinks;
- comparar o conjunto de runtime versionado com a produção;
- classificar separadamente arquivos públicos não versionados, sem removê-los automaticamente.

Se backup, restauração, paridade ou drift não puderem ser validados, classificar como `BLOCKED` ou `REQUIRES_REVIEW` e não promover.

## 5. Artefato e promoção

O artefato deve ser gerado exclusivamente do checkout detached do SHA confirmado. Deve excluir `.env`, storage, uploads, logs, backups e dados clínicos, salvo quando o plano de backup protegido determinar o contrário.

O payload de runtime não deve apagar ou substituir:

- `.env`;
- `storage`;
- uploads e arquivos públicos não versionados previamente classificados;
- logs e backups;
- DICOM, PDFs ou XML clínicos;
- configurações de infraestrutura, certificados e chaves.

O mecanismo versionado `scripts/deploy.sh` existe, mas a auditoria identificou que ele faz extração in-place, executa `composer install`, aplica permissões amplas em `storage/` e trata a falha do health check apenas como aviso. Portanto, ele é **mecanismo documentado, mas não prova um deploy atômico nem rollback reproduzível**. Não tratar essas propriedades como implementadas sem validação independente.

## 6. Validação pós-deploy

Após a promoção:

1. confirmar hashes do payload no destino;
2. confirmar que `.env`, storage, uploads e drift preservado não mudaram;
3. executar health check HTTPS bloqueante;
4. validar PHP-FPM, Nginx e workers conforme o escopo autorizado;
5. executar smoke tests dos fluxos impactados;
6. confirmar que não houve migration ou alteração de banco não autorizada;
7. registrar timestamp, ambiente, SHA, resultado dos gates, backup e resultado do smoke test.

Não imprimir PHI, segredos, tokens, cookies, chaves ou conteúdo de laudos na evidência.

## 7. Rollback

O rollback deve usar a release anterior cujo backup foi validado. Antes de restaurar:

- parar a promoção e preservar logs sanitizados;
- manter workers e integrações no estado seguro definido pelo escopo;
- restaurar somente a release de código, preservando `.env`, storage, uploads e drift;
- validar os mesmos hashes, permissões, symlinks e health check;
- registrar `ROLLBACK=YES` e a causa.

A auditoria atual não encontrou um mecanismo versionado de releases atômicas com ponteiros `current/previous` nem um comando de rollback reproduzível em `scripts/deploy.sh`. Essa capacidade permanece **não comprovada** até ser implementada e testada em ambiente isolado.

## 8. Evidência mínima da release

Registrar, em local protegido e sem dados clínicos:

```text
DEPLOYED_MAIN_SHA=<SHA completo>
DEPLOY_ARTIFACT_SHA256=<hash do artefato>
BACKUP_PATH=<referência protegida>
BACKUP_RESTORE_TEST=PASS|FAIL
PHP_LINT=PASS|FAIL
PDF_REGRESSIONS=PASS|FAIL
PHPUNIT=PASS|NOT_CONCLUSIVE|FAIL
HEALTH_CHECK=PASS|FAIL
DATABASE_CHANGED=NO|YES
MIGRATIONS_EXECUTED=NO|YES
ROLLBACK=NO|YES
```

Sem essa evidência, a relação `produção → SHA → main → commit` deve ser classificada como `MISSING_TRACEABILITY` ou `PARCIALMENTE_PROVADO`, nunca como provada por suposição.
