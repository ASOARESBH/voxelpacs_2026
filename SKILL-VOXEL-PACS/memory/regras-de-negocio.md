# Memória — Regras de Negócio Conhecidas

> Regras que, se violadas, geram bug clínico/operacional grave — não erro de sintaxe, erro de confiança no sistema. Documentar aqui assim que confirmadas no código ou explicadas por alguém do time.

| Regra | Onde é aplicada (arquivo/módulo) | Consequência se violada |
|---|---|---|
| `[A preencher]` | | |

## Exemplos do tipo de regra que pertence aqui (preencher com as reais do projeto)

- Quem pode ver um estudo de qual instituição
- Quem pode assinar/liberar um laudo
- O que acontece se uma mensagem HL7 chega duplicada (idempotência)
- O que acontece se o Orthanc está indisponível no momento de uma consulta

## Regra de manutenção

Toda vez que uma tarefa revelar uma regra de negócio que não estava aqui — mesmo que descoberta "de lado" enquanto se fazia outra coisa — registre antes de encerrar a tarefa.
