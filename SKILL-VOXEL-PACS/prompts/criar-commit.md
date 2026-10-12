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

O commit é feito **diretamente na `main`**, sem branch dedicada, Pull Request ou merge:

1. `git pull --ff-only origin main` com a árvore de trabalho limpa;
2. testar localmente;
3. commit na `main` e `git push origin main`;
4. pedir autorização explícita para o deploy;
5. backup e verificação de drift;
6. deploy controlado.

Nunca reescrever a `main` nem usar force push. Como não há revisão por PR, o corpo do commit deve deixar claros o motivo, o impacto, o risco e como reverter.

## Checklist antes de gerar o commit

- [ ] O escopo do commit corresponde exatamente aos arquivos alterados (sem mudanças "de carona")
- [ ] O corpo explica o motivo quando não for óbvio pelo resumo
- [ ] Testes relevantes executados antes do commit
- [ ] Riscos e forma de rollback (`git revert <sha>`) registrados quando a mudança não for trivial
