# Workflow — Correção de Bug

1. **Reproduzir/entender o sintoma** antes de procurar a causa — não adivinhar.
2. **Localizar o módulo responsável** via `indexes/` — evitar grep genérico no projeto inteiro; usar o termo mais específico possível (nome de rota, mensagem de erro, nome de campo).
3. **Ler só o necessário** — o arquivo apontado pelo índice e suas dependências diretas listadas em `architecture/dependencias.md`, não o módulo inteiro.
4. **Identificar causa raiz**, não só o sintoma — documentar em uma frase antes de alterar código.
5. **Avaliar raio de impacto** — quem mais chama essa função/rota/service? (ver `architecture/dependencias.md`)
6. **Corrigir o mínimo necessário** — resistir à tentação de "aproveitar e refatorar" junto; se o refactor for necessário, tratar como tarefa separada (ver `workflows/refatoracao.md`).
7. **Validar** com os `diagnostics/` relevantes.
8. **Documentar** — se a causa raiz revelou uma lacuna de índice/arquitetura, atualizar o arquivo correspondente para que o próximo bug parecido seja mais rápido de achar.
