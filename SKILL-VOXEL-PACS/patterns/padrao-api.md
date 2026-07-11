# Padrão — API / Endpoints

## Convenções (confirmar contra o código real)

- Formato de resposta de sucesso: `[A confirmar — envelope tipo { data, meta }? Resposta crua?]`
- Formato de resposta de erro: `[A confirmar — código, mensagem, campo de validação?]`
- Versionamento: `[A confirmar — prefixo /v1/, header customizado, nenhum?]`
- Paginação: `[A confirmar — padrão limit/offset, cursor, page/per_page?]`
- Convenção de nomenclatura de rota: `[A confirmar — REST puro (plural, substantivo) ou RPC-like?]`

## Checklist ao criar um endpoint novo

- [ ] Segue o formato de resposta padrão (sucesso e erro)
- [ ] Está registrado em `indexes/rotas-api.md`
- [ ] Autenticação e permissão aplicadas via middleware (ver `patterns/padrao-seguranca.md`)
- [ ] Se retorna dados de paciente/estudo, a permissão de acesso àquele recurso específico foi checada (não só autenticação genérica)
- [ ] Documentado (mesmo que brevemente) para consumidores do frontend/integrações externas
