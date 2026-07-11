# Prompt interno — Modificar Banco de Dados

```
1. Nunca alterar schema sem migration — sem exceção, mesmo em dev.
2. Consultar indexes/tabelas-banco.md para entender a tabela e suas relações.
3. Se a tabela guarda dado DICOM/clínico, revisar architecture/banco-de-dados.md (seção Cuidados) e diagnostics/dicom.md.
4. Criar a migration seguindo patterns/padrao-sql.md.
5. Atualizar indexes/tabelas-banco.md e, se necessário, architecture/dependencias.md.
```

Ver `prompts/criar-migration.md` para o detalhe de como estruturar a própria migration.
