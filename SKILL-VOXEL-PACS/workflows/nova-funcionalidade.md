# Workflow — Nova Funcionalidade

1. **Localizar contexto** — consultar `indexes/` e `modules/` para achar módulos relacionados; não partir do zero se algo parecido já existe.
2. **Entender o padrão vigente** — ler os arquivos de `patterns/` relevantes (Controller/Service/Repository/Componente/API conforme o caso).
3. **Planejar** — usando o prompt `prompts/adicionar-funcionalidade.md`, escrever: objetivo, arquivos a criar/alterar, impacto em `architecture/dependencias.md`, risco, rollback.
4. **Confirmar plano** (se a postura do projeto exigir aprovação explícita antes de implementar — ver skill `voxel-techlead` se presente).
5. **Implementar** seguindo os templates de `templates/` e os padrões de `patterns/`. Se a funcionalidade cria ou altera qualquer view em `app/Views/`, toda string nova visível ao usuário vai em `t('modulo.tela.elemento')`, adicionada nos 3 arquivos de `lang/` (pt_BR/en/es) — nunca hardcoded (ver `patterns/padrao-i18n.md`, regra permanente desde 2026-07-15).
6. **Validar** — rodar os `diagnostics/` relevantes (ao menos duplicação e segurança; DICOM/HL7 se tocado; `diagnostics/i18n.md` se alguma view foi tocada).
7. **Documentar** — atualizar `modules/<modulo>.md`, `indexes/` afetados, e `architecture/dependencias.md` se novas dependências foram criadas.
8. **Preparar commit/PR** — usar `prompts/criar-commit.md` e `prompts/criar-pr.md`.
