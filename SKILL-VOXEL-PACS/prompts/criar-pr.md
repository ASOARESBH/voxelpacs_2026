# Prompt interno — Pull Request (descontinuado)

O VOXEL PACS **não usa Pull Request nem merge**. Toda alteração vai por commit direto na `main`, seguido de deploy controlado:

```
testes → commit direto na main → push → autorização explícita → backup/drift → deploy controlado
```

Use `prompts/criar-commit.md`. As informações que antes iam na descrição do PR (objetivo, impacto, riscos e rollback, como testar, documentação atualizada) devem constar no corpo do commit quando a mudança não for trivial.
