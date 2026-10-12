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

## Fluxo obrigatório

Toda alteração pelo Git é feita **somente por commit direto na `main`** (`git commit` + `git push origin main`). Não há branch dedicada, Pull Request, revisão, merge, etapa de autorização, backup/drift ou deploy atrelados ao fluxo Git.

Antes do commit, atualize a `main` local (`git pull --ff-only origin main`). Nunca reescreva a `main` nem use force push. O corpo do commit deve deixar claros o motivo e, quando a mudança não for trivial, o risco e como reverter.

## Checklist antes de gerar o commit

- [ ] O escopo do commit corresponde exatamente aos arquivos alterados (sem mudanças "de carona")
- [ ] O corpo explica o motivo quando não for óbvio pelo resumo
- [ ] Testes relevantes executados antes do commit
- [ ] Riscos e forma de rollback (`git revert <sha>`) registrados quando a mudança não for trivial
