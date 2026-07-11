# Workflow — Merge e Code Review

## Ao preparar um PR (ver `prompts/criar-pr.md`)

- Descrição inclui: objetivo, arquivos alterados, impacto, riscos, como testar, rollback.
- Nenhuma alteração fora do escopo declarado (se aparecer, separar em outro PR).

## Ao revisar código (próprio ou de terceiros)

Checklist mínimo, na ordem:

1. A alteração segue os padrões de `patterns/` para o tipo de arquivo tocado?
2. O raio de impacto foi considerado (`architecture/dependencias.md`)? Há algo consumindo o que foi alterado que não foi mencionado?
3. Os `diagnostics/` relevantes foram rodados (segurança sempre; DICOM/HL7 se tocado; performance se envolve query/loop)?
4. A documentação (`modules/`, `indexes/`, `architecture/`) foi atualizada quando a alteração introduziu algo novo?
5. Migrations presentes para toda alteração de schema?

## Merge

- Confirmar que a branch está sincronizada com a base antes de mergear.
- Preferir squash/merge conforme convenção do repositório (`[A confirmar convenção real]`).
