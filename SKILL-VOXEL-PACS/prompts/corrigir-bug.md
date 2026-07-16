# Prompt interno — Corrigir Bug

```
1. Entender o sintoma relatado antes de procurar código.
2. Localizar módulo via indexes/mapa-indices.md com o termo mais específico disponível.
3. Ler o arquivo apontado + dependências diretas (architecture/dependencias.md) — não o projeto inteiro.
4. Identificar causa raiz em uma frase.
5. Avaliar quem mais consome o código a ser alterado.
6. Corrigir o mínimo necessário. Se a correção introduzir texto novo visível ao usuário em app/Views/, ele vai em t('modulo.tela.elemento') nos 3 idiomas (ver patterns/padrao-i18n.md) — mesmo sendo "só um bugfix".
7. Rodar diagnostics/ relevantes (diagnostics/i18n.md se alguma view foi tocada).
8. Se a busca revelou lacuna em indexes/ ou architecture/, preencher antes de encerrar.
```

Ver `workflows/correcao-bug.md` para o fluxo completo.
