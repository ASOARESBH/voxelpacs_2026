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

## Botões textuais e toolbar responsiva — 2026-08-13

`public/assets/css/pacs.css` diferencia dois componentes que não devem ser trocados:

| Classe | Uso correto |
|---|---|
| `.pacs-btn` | Ação compacta, normalmente apenas ícone, com área fixa de `26px × 26px`. |
| `.btn-pacs-primary` | Ação textual principal, com fundo institucional, `inline-flex`, alinhamento e `gap` entre ícone e texto. |
| `.btn-pacs-outline` | Ação textual secundária, com borda, `inline-flex`, alinhamento e `gap` entre ícone e texto. |

Na aba **Editar Médico → Máscaras**, o botão `Importar DOCX` usava incorretamente `.pacs-btn`, causando conteúdo comprimido e quebra de texto. A correção usa `.btn-pacs-outline` e a toolbar local `.medico-mascaras-toolbar`: `display:flex`, `gap:.6rem`, `flex-wrap:wrap` e `white-space:nowrap` nos botões. Até 680px, o grupo passa para a linha seguinte; até 420px, cada botão ocupa uma linha inteira. Não houve duplicidade ativa de `.pacs-btn`, `.btn-pacs-primary` ou `.btn-pacs-outline` no checkout validado, portanto o CSS global não foi removido nem alterado.
