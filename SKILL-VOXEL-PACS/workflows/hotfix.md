# Workflow — Hotfix (Produção)

Um hotfix segue o mesmo raciocínio de `correcao-bug.md`, mas com restrições adicionais por ser produção:

1. **Escopo mínimo absoluto** — a alteração mais cirúrgica possível que resolve o problema. Nada de "aproveitar para ajustar mais uma coisa".
2. **Impacto e rollback documentados antes de tocar em código** — em produção, ter um rollback claro não é opcional.
3. **Priorizar reversibilidade** — preferir uma mudança fácil de reverter a uma "correção definitiva" mais arriscada; a correção definitiva pode vir depois como `workflows/nova-funcionalidade.md` ou `workflows/refatoracao.md`.
4. **Validação extra em áreas críticas** — se o hotfix toca DICOM, HL7, autenticação ou permissões, rodar o `diagnostics/` correspondente mesmo que pareça óbvio que não é necessário.
5. **Documentar imediatamente após o deploy** — atualizar `modules/` e, se aplicável, abrir uma nota em `memory/` sobre a causa raiz para evitar recorrência.
