# Índice de Eventos, Filas e Workers

> Este índice é o mais importante para evitar regressões silenciosas: alterar um Service que dispara um evento sem saber quem escuta é a causa clássica de bugs "fantasma" em produção.

## Eventos

| Evento | Disparado por | Listeners conhecidos | Efeito colateral | Última verificação |
|---|---|---|---|---|
| `[A preencher]` | | | | |

## Filas

| Fila | Alimentada por | Worker/Consumer | Idempotente? | Última verificação |
|---|---|---|---|---|
| `[A preencher]` | | | | |

## Jobs assíncronos

| Job | Dispara quando | O que faz | Pode falhar silenciosamente? | Última verificação |
|---|---|---|---|---|
| `[A preencher]` | | | | |

## Regra prática

Antes de alterar qualquer Service que dispara evento ou publica em fila, procure primeiro nesta tabela quem consome. Se a tabela não tiver a entrada, faça a busca dirigida (grep pelo nome do evento/fila) e preencha antes de prosseguir com a alteração — não é opcional, é o que evita quebrar um consumidor que você nunca leu.
