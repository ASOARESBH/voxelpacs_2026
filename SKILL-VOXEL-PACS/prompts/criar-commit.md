# Prompt interno — Criar Commit

## Formato

```
<tipo>(<escopo>): <resumo em uma linha, imperativo>

<corpo opcional: por quê, não apenas o quê>
```

Tipos sugeridos: `feat`, `fix`, `refactor`, `docs`, `chore`, `test`, `perf`, `security`.

## Exemplo

**Input:** Adicionada validação de permissão por instituição no endpoint de listagem de estudos
**Output:**
```
feat(estudos): validar permissão por instituição na listagem

Usuários conseguiam ver estudos de outras instituições quando o filtro
de instituição não era aplicado explicitamente no service. Agora a
checagem é feita no Service, não depende do frontend enviar o filtro.
```

## Checklist antes de gerar o commit

- [ ] O escopo do commit corresponde exatamente aos arquivos alterados (sem mudanças "de carona")
- [ ] O corpo explica o motivo quando não for óbvio pelo resumo
