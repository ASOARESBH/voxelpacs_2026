---
name: voxel-pacs-context-engine
description: "Motor de engenharia de contexto para o repositório VOXEL PACS (PACS/RIS/HIS, DICOM, Orthanc, HL7). Use SEMPRE que a tarefa envolver localizar, entender, alterar, revisar, documentar ou planejar qualquer mudança dentro do codebase VOXEL PACS — mesmo que o pedido pareça pequeno (\"corrige esse bug no upload\", \"adiciona um campo na tela de laudo\", \"cria uma migration\", \"onde fica o controller de X\"). Ative também para perguntas de arquitetura (\"como funciona a fila de jobs\", \"quem chama esse service\"), para preparar commits/PRs, ou para qualquer tarefa em que reler o repositório inteiro seria caro em tokens e tempo. Não é uma skill de ensino de programação: é um mapa e um protocolo de navegação para reduzir drasticamente a leitura de arquivos, evitar reanálise, e aumentar a precisão das alterações. Compatível com Claude Code, Manus AI e agentes similares."
---

# VOXEL PACS — Context Engine

Você está trabalhando num codebase grande e sensível (PACS médico). Sua missão nesta skill **não é escrever código do zero pensando como um júnior lendo tudo** — é **navegar como alguém que já conhece o prédio**: sabe em qual andar fica cada sala, não abre porta por porta procurando, e só entra nas salas que a tarefa exige.

Esta skill existe para resolver um problema específico: modelos de linguagem tendem a "reler o projeto inteiro" a cada tarefa, o que é caro, lento e aumenta a chance de editar o arquivo errado. Os índices e mapas abaixo existem para que você **consulte antes de explorar**.

## Princípio central: Localizar → Entender → Impactar → Alterar

Nunca pule direto para "editar". A sequência importa porque cada etapa é mais barata que a anterior ser pulada e errada:

1. **Localizar** — use `indexes/` para achar o arquivo/módulo certo em 1-2 consultas, não uma varredura.
2. **Entender** — leia SÓ o que foi localizado, mais suas dependências diretas listadas em `architecture/` ou `modules/<modulo>.md`.
3. **Impactar** — antes de tocar em código, escreva (mentalmente ou na resposta) quais outros arquivos dependem do que você vai mudar.
4. **Alterar** — mude apenas o trecho necessário. Preservar é a regra; reescrever é a exceção.

Se em algum momento você perceber que está prestes a abrir um arquivo "só para ver o que tem", pare — isso é sinal de que um índice está faltando ou desatualizado. Nesse caso, **crie o índice** (ver seção Auto-Evolução) em vez de simplesmente ler o arquivo e seguir em frente sem registrar o que aprendeu.

## Como pensar (protocolo mental)

Antes de responder qualquer tarefa sobre o VOXEL PACS, percorra silenciosamente:

- **Que tipo de tarefa é essa?** Bugfix, feature nova, refactor, dúvida de arquitetura, criação de API/tela/migration, ou revisão de código? Isso decide qual prompt de `prompts/` e qual workflow de `workflows/` usar.
- **Eu já sei onde isso vive?** Consulte `indexes/` primeiro. Só se o índice não cobrir o caso, faça uma busca dirigida (grep/glob por nome de rota, nome de tabela, nome de componente) — nunca abra pastas inteiras "para explorar".
- **Eu já analisei esse módulo antes nesta sessão/projeto?** Consulte `memory/` e `modules/<modulo>.md`. Se sim, **reutilize** o que está documentado ali em vez de reler o arquivo fonte. Só releia o arquivo fonte se o índice indicar que ele mudou desde a última análise.
- **Qual o raio de impacto?** Toda alteração em Service/Repository/Controller/API deve ser checada contra quem consome aquilo (ver `architecture/dependencias.md` e o índice de eventos/filas). Em código de integração DICOM/HL7/Orthanc, o raio de impacto é tratado como alto por padrão — não presuma que é isolado.
- **Isso é modo Enterprise?** Ver seção abaixo — análise → planejamento → execução → validação → documentação, sempre, nessa ordem.

## Modo Enterprise (obrigatório para qualquer alteração de código)

Nunca execute uma alteração pulando direto para o código. As cinco etapas:

1. **Análise** — o que existe hoje, como funciona, que padrão segue (ver `patterns/`).
2. **Planejamento** — o que vai mudar, por quê, quais arquivos, qual o risco, qual o rollback.
3. **Execução** — implementar seguindo o padrão identificado na análise, tocando só nos arquivos do plano.
4. **Validação** — os `diagnostics/` relevantes passam? A alteração quebra DICOM/HL7/API/permissões?
5. **Documentação** — atualizar `memory/`, `modules/<modulo>.md` e, se for arquitetura nova, `architecture/`.

Para tarefas triviais e isoladas (ex: corrigir um texto, um typo, um log), as cinco etapas podem ser resumidas em uma frase cada — o ponto não é burocracia, é não esquecer nenhuma delas.

## Como responder

Estruture respostas de tarefas de código assim (adapte o nível de detalhe ao tamanho da tarefa — uma correção de uma linha não precisa das 5 seções completas):

```
## Localização
[onde está o código relevante, citando o índice usado]

## Análise
[o que o código faz hoje, dependências relevantes]

## Plano
[o que vai mudar, arquivos afetados, riscos, rollback]

## Implementação
[a mudança em si]

## Validação
[o que foi checado: diagnostics, padrões, impacto]

## Documentação atualizada
[quais arquivos de memory/modules/architecture foram atualizados, se algum]
```

Para perguntas puramente informativas ("onde fica X", "como funciona Y"), responda direto usando o índice — não force a estrutura acima.

## Onde encontrar cada coisa (mapa da skill)

| Preciso de... | Vá para |
|---|---|
| Achar onde vive uma tela, controller, service, model, API, migration, componente, permissão, integração, constante, helper, middleware, evento, fila ou worker | `indexes/mapa-indices.md` |
| Entender a arquitetura geral (frontend, backend, banco, DICOM/OHIF/Orthanc, HL7, RIS/HIS, filas, cache, uploads) | `architecture/visao-geral.md` e os arquivos específicos em `architecture/` |
| Ver o que já se sabe sobre um módulo específico (para não reanalisar) | `modules/<nome-do-modulo>.md` |
| Um prompt pronto para uma tarefa recorrente (analisar módulo, criar API, corrigir bug, criar migration, etc.) | `prompts/` |
| O padrão de código esperado (Controller, Service, Repository, SQL, JS, CSS, Componente, Rota, Segurança) | `patterns/` |
| O passo a passo de um tipo de trabalho (feature nova, hotfix, refactor, deploy, merge, code review) | `workflows/` |
| Um boilerplate para começar um arquivo novo já no padrão certo | `templates/` |
| Um checklist automático de qualidade (código morto, duplicação, arquivo grande, query lenta, dependência circular, segurança) | `diagnostics/` |
| Um exemplo real de execução completa de uma tarefa (para calibrar formato/profundidade) | `examples/` |
| Convenções, glossário e fatos permanentes do projeto | `memory/` |
| Explicação de cada pasta e como manter tudo sincronizado | `README.md` e `docs/` |
| O resumo vivo do projeto (arquitetura + módulos + convenções), pensado para ser a memória de longuíssimo prazo | `CLAUDE.md` |

## Economia de tokens — regras práticas

- **Nunca abra um arquivo grande "para entender o contexto".** Primeiro veja se `modules/` já resume esse arquivo. Se o arquivo tiver mais de ~300 linhas, leia por seção/função relevante, não o arquivo inteiro (use grep para achar a função/classe antes de abrir).
- **Pesquisa dirigida > exploração.** Prefira `grep -r "nomeDaRota"` ou `grep -r "nomeDaTabela"` a listar diretórios inteiros.
- **Resuma antes de carregar mais.** Se você acabou de entender um módulo, escreva o resumo em `modules/<modulo>.md` antes de seguir para o próximo passo — isso vira cache para você mesmo e para execuções futuras.
- **Reutilize, não reanalise.** Se `modules/<modulo>.md` já tem data de análise recente e nada no índice indica mudança, confie nele.
- **Altere o trecho, não o arquivo.** Prefira diffs cirúrgicos (ex.: `str_replace`) a reescrever arquivos inteiros.

## Auto-evolução (manter a skill viva)

Sempre que você descobrir algo que não estava documentado — um módulo novo, uma dependência não mapeada, uma convenção nova, uma pasta que os índices não cobriam — **atualize o arquivo relevante antes de terminar a tarefa**:

- Módulo novo/alterado → atualizar `modules/<modulo>.md` e, se necessário, `indexes/mapa-indices.md`
- Dependência nova entre módulos → `architecture/dependencias.md`
- Convenção ou padrão novo observado no código → `patterns/` (arquivo correspondente) e `memory/convencoes.md`
- Fluxo de trabalho novo (ex: novo tipo de deploy) → `workflows/`

Isso é o que faz a skill ficar mais barata de usar com o tempo em vez de degradar. Trate a atualização da documentação como parte da tarefa, não como um "extra" opcional — mas não infle os arquivos com detalhes irrelevantes: cada entrada deve ajudar uma execução futura a economizar uma leitura, não documentar por documentar.

## Compatibilidade com outros agentes

Esta estrutura é agnóstica de ferramenta: os índices e mapas são markdown puro, lidos igualmente por Claude Code, Manus AI, Cursor, Windsurf, Copilot ou qualquer agente que consiga ler arquivos do repositório. Não dependa de recursos específicos de uma ferramenta (ex: chamadas de API proprietárias) dentro dos arquivos de índice/arquitetura — mantenha-os como texto puro navegável por qualquer LLM.

## Quando o índice não é suficiente

É normal e esperado que, para tarefas muito novas, os índices não cubram tudo. Nesse caso: faça a busca dirigida mínima necessária, resolva a tarefa, e **feche o loop** documentando o que você aprendeu no lugar certo (ver Auto-evolução). Não trate a ausência de índice como licença para varrer o projeto inteiro "já que estou aqui" — regre ao princípio de localizar o mínimo necessário mesmo quando tiver que pesquisar manualmente.
