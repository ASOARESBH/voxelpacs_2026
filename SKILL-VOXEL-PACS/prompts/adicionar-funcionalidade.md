# Prompt interno — Adicionar Funcionalidade

```
1. Consultar modules/ e indexes/ para checar se algo parecido já existe.
2. Identificar o padrão aplicável em patterns/ (Controller/Service/Repository/Componente/API).
3. Escrever plano: objetivo, arquivos a criar/alterar, dependências novas (registrar em architecture/dependencias.md), risco, rollback.
4. Implementar seguindo templates/ e patterns/. Toda string nova visível ao usuário em app/Views/ vai em t('modulo.tela.elemento'), nos 3 arquivos de lang/ (pt_BR/en/es) — ver patterns/padrao-i18n.md.
5. Rodar diagnostics/ relevantes (diagnostics/i18n.md se alguma view foi tocada).
6. Atualizar modules/, indexes/ e architecture/dependencias.md.
```

Ver `workflows/nova-funcionalidade.md` para o fluxo completo com mais contexto.
