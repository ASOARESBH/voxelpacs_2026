# Diagnóstico — Código Morto e Duplicação

## Código morto

Sinais a procurar (busca dirigida, não varredura completa):

- Funções/métodos/rotas que não aparecem referenciados em nenhum outro lugar do código (checar com grep pelo nome antes de concluir que está morto — nomes genéricos dão falso positivo).
- Componentes de frontend não importados em nenhuma tela.
- Migrations criando colunas/tabelas que nenhum Model/Repository referencia.
- Feature flags sempre desligadas há muito tempo, com código condicional nunca executado.

Antes de remover: confirmar que não é chamado dinamicamente (reflection, string de nome de método, rota registrada por convenção) — comum em integrações DICOM/HL7 que usam despacho por tipo de mensagem.

## Duplicação

Sinais a procurar:

- Dois Services/Repositories fazendo praticamente a mesma query ou validação.
- Componentes de frontend visualmente idênticos com nomes diferentes.
- Lógica de validação de permissão repetida em múltiplos Controllers em vez de centralizada em middleware.

## Ao encontrar duplicação ou código morto

1. Documentar em `modules/<modulo>.md` antes de agir, não apenas remover silenciosamente.
2. Se for remover, tratar como uma mudança de escopo próprio (não misturar com uma feature/bugfix não relacionado) — ver `workflows/refatoracao.md`.
