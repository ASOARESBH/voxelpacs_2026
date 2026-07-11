# Explicação de Cada Pasta

| Pasta | Responde à pergunta | Quando consultar |
|---|---|---|
| `prompts/` | "Qual o roteiro para este tipo de tarefa?" | No início de qualquer tarefa recorrente (bugfix, feature, migration, PR...) |
| `docs/` | "Como esta skill funciona e se mantém?" | Ao configurar, revisar ou explicar a skill a alguém |
| `memory/` | "Quais são as convenções e regras permanentes do projeto?" | Antes de qualquer alteração, como pano de fundo |
| `architecture/` | "Como o sistema se encaixa, camada por camada?" | Ao entender um fluxo novo ou avaliar impacto |
| `modules/` | "O que já se sabe sobre este módulo específico?" | Sempre antes de reler um arquivo-fonte |
| `indexes/` | "Onde fica X no código?" | Primeiro passo de qualquer localização |
| `patterns/` | "Como este tipo de arquivo deve ser escrito aqui?" | Ao criar ou alterar Controller/Service/SQL/JS/CSS/etc |
| `workflows/` | "Qual o passo a passo deste tipo de trabalho?" | Ao iniciar feature/hotfix/refactor/deploy/review |
| `templates/` | "Existe um boilerplate pronto para isso?" | Ao criar um arquivo novo do zero |
| `diagnostics/` | "O que checar antes/depois de uma alteração?" | Na etapa de validação de qualquer tarefa |
| `examples/` | "Como ficou uma execução completa parecida?" | Ao calibrar formato/profundidade de resposta |

Este arquivo é deliberadamente curto — o detalhe de "como usar" cada pasta já está descrito na tabela do `SKILL.md` principal; aqui é só a referência de consulta rápida.
