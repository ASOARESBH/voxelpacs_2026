# Exemplo — Correção de Bug (formato de referência)

> Este é um exemplo **ilustrativo** do nível de profundidade e formato esperado ao seguir `workflows/correcao-bug.md`. Os nomes de arquivo abaixo são fictícios — substituir por casos reais assim que o primeiro bugfix real for resolvido usando esta skill, para que o exemplo passe a refletir o projeto de verdade.

## Tarefa
"Usuário do hospital B está conseguindo ver estudos do hospital A na tela de worklist."

## Localização
Consultado `indexes/mapa-indices.md` → Permissões / ACL. Localizado `WorklistService.listarEstudos()`.

## Análise
O método monta a query de estudos, mas o filtro de instituição só é aplicado se o parâmetro `institutionId` vier explicitamente da requisição — o frontend nem sempre envia esse parâmetro, e quando ausente, a query não filtra, listando de todas as instituições.

## Plano
- Arquivo afetado: `WorklistService.listarEstudos()` (1 arquivo).
- Mudança: aplicar o `institutionId` do usuário autenticado (não do parâmetro de request) como filtro obrigatório.
- Risco: baixo — torna o filtro mais restritivo, não mais permissivo. Nenhum outro consumidor deste método esperava o comportamento antigo (verificado em `architecture/dependencias.md`).
- Rollback: reverter o commit único, sem migration envolvida.

## Implementação
Filtro de instituição passa a vir de `usuarioAutenticado.institutionId`, ignorando qualquer `institutionId` vindo do request.

## Validação
- `diagnostics/seguranca.md` — item de autorização por recurso específico, confirmado corrigido.
- Testado manualmente com usuários de duas instituições diferentes.

## Documentação atualizada
- `memory/regras-de-negocio.md` — adicionada regra: "listagem de estudos sempre filtra por instituição do usuário autenticado, nunca por parâmetro de request".
- `modules/worklist.md` — nota sobre o bug e a correção.
