# Templates

Boilerplates prontos, já no padrão do projeto, para começar um arquivo novo sem reinventar a estrutura. Diferente de `patterns/` (que explica o *porquê* e o *checklist*), aqui ficam os arquivos-modelo prontos para copiar.

## Como preencher

Assim que um padrão real for confirmado no código (via `patterns/`), extraia um exemplo mínimo e real, remova o específico da tarefa, e salve aqui como template. Um template bom é aquele que alguém (humano ou agente) pode copiar e só substituir nomes/campos.

## Templates a manter

- `controller.template` — esqueleto de Controller seguindo `patterns/padrao-controller.md`
- `service.template` — esqueleto de Service seguindo `patterns/padrao-service.md`
- `repository.template` — esqueleto de Repository seguindo `patterns/padrao-repository.md`
- `migration.template` — esqueleto de migration seguindo `patterns/padrao-sql.md`
- `componente.template` — esqueleto de componente de frontend seguindo `patterns/padrao-componentes.md`
- `pull-request.template` — mesmo conteúdo de `prompts/criar-pr.md`, para acesso direto

> Nenhum template de código foi criado ainda porque isso depende de uma primeira análise real do repositório (para extrair a sintaxe exata da linguagem/framework usados). Ver `CLAUDE.md` — este é um dos primeiros itens a resolver na primeira sessão real de trabalho no projeto.
