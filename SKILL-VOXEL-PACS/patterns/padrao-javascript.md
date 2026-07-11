# Padrão — JavaScript

## Convenções (confirmar contra o código real)

- Estilo (linter/formatter usado): `[A confirmar — ESLint config? Prettier?]`
- Módulos: `[A confirmar — ESM, CommonJS?]`
- Padrão async: `[A confirmar — async/await consistente, ou mistura com callbacks/promises antigas?]`
- Tratamento de erro padrão em chamadas assíncronas: `[A confirmar]`

## Checklist ao escrever/alterar JS

- [ ] Segue o estilo já configurado no projeto (rodar linter se disponível, não confiar só em leitura visual)
- [ ] Erros assíncronos são tratados explicitamente, não deixados propagar sem handling
- [ ] Nenhuma duplicação de helper que já existe (checar `indexes/mapa-indices.md` → Helpers/Utilitários antes de criar um novo)
