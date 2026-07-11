# Workflow — Deploy

> Preencher os passos reais assim que o pipeline de CI/CD do projeto for analisado (`[A confirmar]` abaixo).

1. Pré-checagem: migrations pendentes identificadas e aplicadas na ordem certa (`[A confirmar processo]`).
2. Pré-checagem: `diagnostics/` de segurança e performance rodados na branch a ser deployada, não só localmente.
3. Deploy propriamente dito: `[A preencher — pipeline/ferramenta usada]`.
4. Pós-deploy: smoke test dos fluxos críticos listados em `architecture/visao-geral.md` (ingestão DICOM, laudo, HL7, autenticação).
5. Rollback: `[A preencher — como reverter rapidamente se algo falhar]`.
