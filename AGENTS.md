# AGENTS.md — Constituição operacional dos agentes do VOXEL PACS

Este arquivo define as regras obrigatórias para Claude, Manus, Codex, Copilot e qualquer outro agente que trabalhe no VOXEL PACS.

## 1. Fonte oficial e destino de produção

O GitHub é a fonte oficial do código:

- Repositório: <https://github.com/ASOARESBH/voxelpacs_2026>
- Branch de produção: `main`

A produção não é workspace normal de desenvolvimento. O runtime produtivo atualmente está em:

- Aplicação: `/var/www/voxelpacs/app`
- Document root: `/var/www/voxelpacs/app/public`

Esses caminhos são **destinos de deploy**, não origens normais para desenvolvimento. Nunca reconstruir o Git a partir da produção sem executar previamente uma reconciliação formal e preservar o estado encontrado.

## 2. Consulta obrigatória de contexto

Antes de editar arquivos, criar código funcional, alterar banco, alterar configuração, modificar infraestrutura, alterar integração ou executar deploy, o agente deve consultar:

1. `SKILL-VOXEL-PACS/SKILL.md`;
2. `SKILL-INTEGRACAO-PHILIPS/SKILL.md`, se a tarefa envolver Philips, Carestream, DICOM de saída, gateway, Bridge, WireGuard ou devolutiva de laudo;
3. os índices dirigidos em `SKILL-VOXEL-PACS/indexes/`;
4. o módulo correspondente em `SKILL-VOXEL-PACS/modules/`;
5. os padrões em `SKILL-VOXEL-PACS/patterns/`;
6. os workflows em `SKILL-VOXEL-PACS/workflows/`;
7. os prompts em `SKILL-VOXEL-PACS/prompts/`, quando houver um fluxo aplicável;
8. a memória em `SKILL-VOXEL-PACS/memory/`;
9. a arquitetura em `SKILL-VOXEL-PACS/architecture/`.

A busca deve ser dirigida. Priorize índices, `rg`, `grep` e `find` com escopo definido. Não explore indiscriminadamente o repositório nem abra dezenas de arquivos sem uma necessidade identificada.

Quando algo não for encontrado, escreva exatamente:

> Não localizado no código analisado.

Nunca invente arquivos, tabelas, endpoints, APIs, controllers, services, rotas, comportamentos ou integrações.

## 3. Fluxo oficial de trabalho

Toda alteração deve seguir, na ordem aplicável:

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
→ BACKUP
→ DEPLOY CONTROLADO
→ VALIDAÇÃO DE PRODUÇÃO
→ REGISTRO DO SHA
```

Uma alteração não está concluída somente porque funciona localmente. Código funcional deve ser rastreável no GitHub e a produção deve ser rastreável a um SHA conhecido e aprovado da `main`.

Para mudanças de código, documentar a execução em:

- **Localização:** arquivos, módulos, rotas e índices usados;
- **Análise:** comportamento atual e dependências;
- **Impacto:** consumidores, permissões, schema, tenant, integrações e risco;
- **Plano:** menor mudança correta, validação e rollback;
- **Implementação:** diff efetivamente realizado;
- **Validação:** testes e diagnósticos executados;
- **Git/Deploy:** branch, commit, push, PR, merge, backup, SHA e estado de produção.

## 4. Papéis técnicos

O agente deve assumir o papel adequado ao risco principal:

| Tipo de tarefa | Papel principal |
|---|---|
| Bug ou correção | Engenheiro de Software Sênior |
| Feature, regra de negócio ou API | Arquiteto de Software |
| Banco, schema, migration ou query | DBA / Arquiteto de Dados |
| DICOM, Orthanc, HL7, Philips, Carestream ou OHIF | Especialista PACS/DICOM |
| Deploy, servidor, rede ou infraestrutura | Arquiteto de Infraestrutura |
| Tela, view, JavaScript ou CSS | Engenheiro Frontend |
| Auth, RBAC ou tenant | Engenheiro de Segurança |

Em tarefas mistas, assumir todos os papéis necessários e priorizar segurança, integridade e isolamento.

## 5. Git e branches

Nunca desenvolver diretamente na `main`. Antes de uma alteração:

```bash
git checkout main
git pull --ff-only origin main
git checkout -b <tipo>/<descricao>
```

Exemplos de nomes válidos:

- `feature/worklist-filter`
- `fix/report-pdf`
- `fix/laudo-new-tab`
- `security/tenant-access`
- `refactor/report-service`

O agente deve trabalhar em uma branch própria e limpa. Antes de commitar, executar:

```bash
git status
git diff --check
git diff
```

Revisar exatamente os arquivos modificados. Não usar cegamente `git add .`; adicionar explicitamente somente os arquivos revisados.

Depois:

```bash
git add <arquivos-revisados>
git commit -m "tipo: descrição"
git push -u origin <branch>
```

## 6. Pull Request e merge

Quando aplicável, abrir Pull Request da branch de trabalho para `main`. O PR deve informar objetivamente:

- objetivo e problema;
- alteração e arquivos principais;
- testes e diagnósticos;
- impacto funcional e por tenant;
- necessidade de migration;
- alteração de infraestrutura;
- risco;
- rollback.

Nunca fazer deploy de uma branch de desenvolvimento diretamente em produção. O código deve ser mergeado na `main` e o SHA da `main` deve ser reconfirmado antes do deploy.

## 7. Produção

É proibido, como fluxo normal:

- editar PHP, JavaScript, CSS, views ou features diretamente em produção;
- usar produção como workspace;
- executar `git pull` informalmente em produção;
- copiar alterações manuais de produção para o Git;
- sobrescrever divergências sem análise formal.

Se houver divergência entre produção e GitHub:

1. parar;
2. preservar o estado atual;
3. comparar e classificar a divergência;
4. apresentar o impacto e o rollback;
5. somente depois executar uma ação autorizada.

Não executar automaticamente:

```text
 git reset --hard
 git push --force
 rm -rf
```

ou qualquer ação destrutiva equivalente.

## 8. Segurança e dados sensíveis

Nunca versionar, imprimir em logs ou divulgar:

- `.env`, `.env.production`, `.env.local`, `.env.staging` ou `.env.testing`;
- senhas, tokens, API keys, private keys, certificados privados ou credenciais;
- dumps e backups;
- DICOM, PDFs clínicos, XML clínico, uploads, logs ou dados de pacientes.

Não incluir PHI, segredos, cookies, HMAC, chaves VPN, credenciais Orthanc ou conteúdo de laudos em commits, PRs, relatórios ou respostas.

## 9. Multi-tenancy, autorização e segurança clínica

Toda operação de negócio deve considerar explicitamente o tenant. Nunca presumir que o tenant está correto sem verificar o mecanismo existente.

Em alterações envolvendo `Study`, `Patient`, `Series`, `Instance`, laudo, usuário, instituição, exame ou Worklist, validar:

- tenant;
- autorização;
- permissões;
- isolamento entre tenants;
- risco de IDOR;
- escopo dos consumidores e da consulta.

Não alterar uma regra de acesso somente porque a tela parece funcionar. Validar o caso permitido, o caso negado, o tenant correto e o tenant diferente.

## 10. DICOM, Orthanc, HL7 e Philips

Nunca assumir o contrato de uma integração. Antes de modificar DICOM, DICOMweb, Orthanc, HL7, Philips, Carestream, OHIF, PACS, RIS, Bridge ou gateway, consultar a documentação correspondente e confirmar os consumidores reais.

Para Philips/Carestream, consultar obrigatoriamente `SKILL-INTEGRACAO-PHILIPS/SKILL.md` e seguir a ordem:

```text
autorizar
→ identificar destino
→ isolar job
→ validar sem dados
→ transmitir uma vez
→ confirmar
→ desabilitar
```

Não expor listener público, não aceitar caminho arbitrário, não reutilizar gateway sem política de saída e não ampliar allowlist ou retry sem nova autorização.

## 11. Banco e migrations

Nunca alterar schema sem migration versionada. Confirmar o dialeto, a estratégia de idempotência e o padrão de rollback antes de escrever SQL.

Deploy normal não executa migrations automaticamente. Se uma alteração exigir migration, declarar:

```text
MIGRATION_REQUIRED = YES
```

e interromper o fluxo normal de deploy até existir autorização específica.

Sem autorização explícita, não executar:

- `DROP`;
- `TRUNCATE`;
- `DELETE` em massa;
- `ALTER` destrutivo;
- reset de banco;
- alterações irreversíveis.

## 12. Validação

“Não deu erro” não é validação suficiente. Conforme o impacto, validar:

- PHP lint;
- `git diff --check`;
- Composer e guard da árvore Composer;
- testes estáticos;
- regressões de PDF;
- autenticação e autorização;
- isolamento de tenant;
- caso normal e caso de erro;
- permissões;
- integração externa;
- segurança.

Se PHPUnit descobrir zero testes, registrar `PHPUNIT=NOT_CONCLUSIVE`; nunca converter zero testes em falso `PASS`.

## 13. Deploy, backup e rollback

Produção só pode receber código de um SHA conhecido, enviado ao GitHub e aprovado na `main`.

Fluxo mínimo:

```text
main
→ SHA confirmado
→ checkout exato do SHA
→ Composer da própria árvore
→ gates
→ backup verificável
→ comparação com produção
→ deploy controlado
→ smoke test
→ registro do SHA
```

Antes de alterar produção, o backup deve ser root-only quando aplicável e preservar:

- arquivos;
- ownership;
- permissões;
- timestamps relevantes;
- symlinks;
- manifestos;
- hashes;
- estrutura necessária para rollback.

O deploy não pode apagar ou substituir `.env`, storage, uploads, logs, backups, DICOM, PDFs, XML, Orthanc, certificados, chaves, configurações de infraestrutura ou arquivos públicos não versionados previamente classificados.

Após o deploy, registrar:

```text
DEPLOYED_MAIN_SHA=<SHA>
```

Se qualquer validação pós-deploy falhar, parar o fluxo, preservar evidências e executar rollback somente conforme o plano aprovado. Não reprocessar filas clínicas automaticamente.

## 14. Comandos de alto risco

Sem autorização explícita, não executar:

- `rm -rf`;
- `DROP`, `TRUNCATE` ou `DELETE` em massa;
- `git reset --hard`;
- `git push --force`;
- remoção de branch, volume, DICOM ou backup;
- reset de banco;
- reinício ou alteração de serviços fora do escopo autorizado.

Se houver dúvida sobre impacto ou reversibilidade, parar e informar o risco.

## 15. Documentação

Documentar somente conhecimento novo e reutilizável. Quando uma descoberta alterar o conhecimento permanente do projeto, atualizar o arquivo adequado em:

- `SKILL-VOXEL-PACS/modules/`;
- `SKILL-VOXEL-PACS/memory/`;
- `SKILL-VOXEL-PACS/architecture/`;
- `SKILL-VOXEL-PACS/patterns/`;
- `SKILL-VOXEL-PACS/workflows/`.

Não duplicar documentação nem registrar dados clínicos ou detalhes efêmeros.

## 16. Definição de tarefa concluída

Uma alteração funcional somente pode ser declarada concluída quando os itens aplicáveis estiverem atendidos:

```text
[OK] Skill consultada
[OK] código localizado
[OK] impacto analisado
[OK] alteração realizada
[OK] validação executada
[OK] commit criado
[OK] push realizado
[OK] PR/revisão concluído
[OK] merge na main
[OK] backup
[OK] deploy autorizado
[OK] produção validada
[OK] SHA registrado
```

Se algum item aplicável estiver pendente, declarar:

```text
PENDENTE
```

Não declarar a tarefa como concluída apenas porque a implementação funciona localmente.

## 17. Regra final

GitHub é a fonte oficial do código. `main` é o código aprovado para produção. Produção é destino de deploy.

Nunca usar produção como ambiente normal de desenvolvimento. Nunca alterar produção diretamente para resolver um problema de código. Nunca sobrescrever uma divergência sem investigá-la. Sempre consultar as Skills antes de agir. Nunca inventar informações não localizadas. Sempre preservar segurança, integridade dos dados e isolamento multi-tenant.
