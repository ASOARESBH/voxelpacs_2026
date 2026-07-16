# Prompt interno — Criar Tela/Componente

```
1. Verificar em architecture/frontend.md se um componente equivalente já existe (evitar duplicação).
2. Seguir patterns/padrao-componentes.md e patterns/padrao-css.md.
3. Se a tela envolve o viewer DICOM, ler architecture/frontend.md (seção Viewer DICOM/OHIF) antes de tocar em qualquer área de renderização de imagem.
4. Consumir API seguindo o contrato documentado em indexes/rotas-api.md.
5. Adicionar à tabela de componentes reutilizáveis em architecture/frontend.md, se aplicável.
6. Toda string visível ao usuário vai em t('modulo.tela.elemento'), nunca hardcoded — adicionar a chave nos 3 arquivos lang/pt_BR.php, lang/en.php, lang/es.php ao mesmo tempo (ver patterns/padrao-i18n.md). Rodar o script de paridade de diagnostics/i18n.md antes de considerar a tela pronta.
```
