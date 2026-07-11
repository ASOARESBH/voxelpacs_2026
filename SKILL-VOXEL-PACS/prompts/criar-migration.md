# Prompt interno — Criar Migration

```
1. Seguir a convenção de nomenclatura real do projeto (architecture/banco-de-dados.md).
2. Incluir rollback (down), a menos que haja motivo documentado para não ter.
3. Adicionar índice em toda coluna nova usada em WHERE/JOIN de queries frequentes.
4. Se a alteração afeta coluna correspondente a tag DICOM (ex: StudyInstanceUID), validar explicitamente compatibilidade antes de aplicar.
5. Atualizar indexes/tabelas-banco.md com a nova tabela/coluna.
```
