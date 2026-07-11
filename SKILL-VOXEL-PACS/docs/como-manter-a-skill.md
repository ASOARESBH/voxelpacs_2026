# Como Manter Esta Skill Atualizada

Esta skill perde valor rapidamente se os índices/mapas ficarem desatualizados — um índice errado é pior que nenhum índice, porque gera confiança falsa. Regras práticas:

## Durante uma tarefa normal

- Sempre que localizar algo que não estava em `indexes/`, adicione a linha antes de encerrar a tarefa.
- Sempre que entender um módulo a fundo, escreva/atualize `modules/<modulo>.md`.
- Sempre que descobrir uma dependência entre módulos, registre em `architecture/dependencias.md`.
- Sempre que confirmar (ou corrigir) algo marcado como `[A confirmar]` em `CLAUDE.md` ou `architecture/`, atualize na hora.

## Revisão periódica (recomendado, humano)

A cada poucas semanas ou após um período de muitas mudanças no código:

1. Revisar `architecture/dependencias.md` — ainda reflete a realidade?
2. Revisar `indexes/mapa-indices.md` — algum caminho aponta para arquivo que não existe mais?
3. Revisar `CLAUDE.md` — ainda é um resumo fiel e enxuto, ou cresceu demais e precisa empurrar detalhe para `architecture/`/`modules/`?

## Sinais de que a skill está desatualizada (parar e corrigir, não ignorar)

- Um índice aponta para um caminho que não existe.
- Um agente precisou explorar o projeto do zero para achar algo que deveria estar indexado.
- `modules/<modulo>.md` descreve um comportamento que o código não tem mais.

Quando qualquer um desses sinais aparecer, a correção do índice/documentação é parte da tarefa em andamento, não um item de backlog para depois.
