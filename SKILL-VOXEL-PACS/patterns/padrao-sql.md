# Padrão — SQL / Migrations

## Regra inegociável

**Nenhuma alteração de schema sem migration.** Isso vale mesmo para ajustes "rápidos" em desenvolvimento — a ausência de uma migration correspondente é a causa mais comum de dessincronia entre ambientes num projeto PACS de longa duração.

## Convenções (confirmar contra o código real)

- Ferramenta de migration: `[A confirmar]`
- Convenção de nomenclatura de arquivo de migration: `[A confirmar]`
- Uso de índices: adicionar índice em toda coluna usada em `WHERE`/`JOIN` de queries frequentes — confirmar se o projeto já segue isso ou se há débito técnico aqui (registrar em `diagnostics/performance.md` se houver).

## Queries manuais (fora do ORM)

- Sempre usar prepared statements / parâmetros bindados — nunca concatenar valor de usuário em string SQL. Ver `diagnostics/seguranca.md` para o checklist de SQL Injection.
- Se uma query manual for necessária para performance, documentar o motivo ao lado dela (comentário no código) e considerar registrar em `indexes/tabelas-banco.md`.

## Checklist ao alterar schema

- [ ] Migration criada (não alteração manual direto no banco)
- [ ] Migration é reversível (tem down/rollback) ou o motivo de não ser está documentado
- [ ] `indexes/tabelas-banco.md` atualizado
- [ ] Se a tabela guarda dado clínico/DICOM, `architecture/banco-de-dados.md` (seção "Cuidados") foi revisada
