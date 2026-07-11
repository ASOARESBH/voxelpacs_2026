# Diagnóstico — Arquivos, Controllers e Funções Grandes

## Limiares práticos (ajustar conforme convenção real do projeto)

- Arquivo com mais de ~300 linhas: candidato a leitura por seção, não leitura completa — e candidato a considerar split.
- Controller com mais de ~5-7 métodos ou lógica de negócio visível: sinal de que lógica deveria estar no Service.
- Função/método com mais de ~40-50 linhas ou mais de ~4 níveis de indentação: candidato a quebra em funções menores.

## Como agir

- Ao localizar um arquivo grande via índice, não abrir o arquivo inteiro — usar grep para achar a função/classe relevante e ler só essa seção (com algumas linhas de contexto ao redor).
- Se o tamanho for um obstáculo real para a tarefa atual (não só uma observação de qualidade), registrar em `modules/<modulo>.md` como candidato a refatoração futura, mas não refatorar como "efeito colateral" de uma tarefa não relacionada (ver `workflows/refatoracao.md`).
