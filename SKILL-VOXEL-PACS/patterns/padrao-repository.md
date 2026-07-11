# Padrão — Repository

## Responsabilidade

Acesso a dados, e apenas isso. Não contém lógica de negócio nem decide *o que fazer* com os dados — só busca, persiste, atualiza e remove.

## Template mínimo

```
[A preencher com a sintaxe real do projeto — métodos típicos: find, findBy, create, update, delete,
seguindo a convenção de nomenclatura observada no código real]
```

## Exemplo real do projeto

`[A preencher com um Repository real e pequeno]`

## Checklist ao criar/alterar um Repository

- [ ] Nenhuma regra de negócio (if de decisão clínica/operacional) ficou aqui — isso pertence ao Service
- [ ] Queries usam o padrão do projeto para evitar SQL Injection (ver `patterns/padrao-sql.md` e `diagnostics/seguranca.md`)
- [ ] Se a tabela envolvida está em `indexes/tabelas-banco.md`, a entrada está atualizada
