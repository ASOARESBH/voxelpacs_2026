# Padrão — Componentes de Frontend

## Convenções (confirmar contra o código real)

- Nomenclatura de arquivo/componente: `[A confirmar]`
- Organização (por feature ou por tipo de componente): `[A confirmar]`
- Como props/tipos são definidos: `[A confirmar]`
- Gerenciamento de estado local vs global: `[A confirmar quando usar cada um]`

## Antes de criar um componente novo

1. Consultar `architecture/frontend.md` (tabela de componentes reutilizáveis mais usados).
2. Se algo parecido já existir, estender/parametrizar em vez de duplicar.
3. Se for realmente novo, seguir o template em `templates/` correspondente.

## Checklist ao criar/alterar um componente

- [ ] Não duplica um componente já existente (checado contra `architecture/frontend.md`)
- [ ] Segue a convenção de nomenclatura e organização do projeto
- [ ] Se for reutilizável, foi adicionado à tabela de componentes em `architecture/frontend.md`
