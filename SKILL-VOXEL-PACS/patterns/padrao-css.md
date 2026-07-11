# Padrão — CSS / Estilo Visual

## Convenções (confirmar contra o código real)

- Metodologia: `[A confirmar — BEM, utility-first (Tailwind), CSS Modules, styled-components?]`
- Tokens de design (cores, espaçamento, tipografia) centralizados em: `[A preencher caminho, se existir]`
- Suporte a tema escuro/claro (relevante para viewer DICOM, onde contraste importa clinicamente): `[A confirmar]`

## Cuidado específico do domínio

Telas de visualização de imagem médica (viewer DICOM/OHIF) têm requisitos de contraste e cor que **não são só estéticos** — alterações de CSS na área do viewer devem preservar a fidelidade de exibição da imagem (nunca aplicar filtro CSS, overlay semi-transparente ou ajuste de brilho/contraste na área de renderização da imagem sem entender o impacto clínico).

## Checklist ao alterar CSS

- [ ] Segue os tokens/convenções já estabelecidos, não introduz valores mágicos novos sem necessidade
- [ ] Não afeta a área de renderização da imagem DICOM sem justificativa explícita
- [ ] Testado em pelo menos as resoluções/telas relevantes ao componente alterado
