# Padrão — Rotas (Frontend e Backend)

## Rotas de Frontend

- Convenção de nomenclatura: `[A confirmar]`
- Rotas protegidas por autenticação: como são marcadas/agrupadas? `[A confirmar]`
- Lazy loading de rotas: usado? `[A confirmar]`

## Rotas de Backend (API)

Ver `patterns/padrao-api.md` e `indexes/rotas-api.md` para o detalhe — este arquivo foca só na convenção de definição de rota (arquivo de rotas, agrupamento, prefixos).

- Onde as rotas são registradas: `[A preencher caminho]`
- Agrupamento por middleware/prefixo: `[A confirmar]`

## Checklist ao adicionar uma rota nova

- [ ] Segue a convenção de nomenclatura e agrupamento já existente
- [ ] Middleware de autenticação/permissão aplicado corretamente (não esquecido por estar num grupo diferente do esperado)
- [ ] Adicionada ao índice relevante (`indexes/rotas-api.md` para backend)
