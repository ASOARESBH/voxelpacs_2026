# SKILL-VOXEL-PACS

Skill de **engenharia de contexto** para o repositório VOXEL PACS. Não ensina programação — ensina um agente de IA (Manus AI, Claude Code, Cursor, Windsurf, Copilot, etc.) a se orientar dentro de um codebase grande e sensível (PACS/RIS/HIS, DICOM, Orthanc, HL7) sem precisar reler tudo a cada tarefa.

## Por que isso existe

Em projetos grandes, o custo dominante de um agente de IA não é "pensar" — é **explorar**: abrir arquivos, listar pastas, reler o mesmo service três vezes em três tarefas diferentes. Esta skill substitui exploração por **consulta a índices e memória documentada**, reduzindo tokens, tempo e — o mais importante — a chance de alterar o arquivo errado.

## Estrutura

```
SKILL-VOXEL-PACS/
├── SKILL.md          # O cérebro: como pensar, localizar, decidir, responder
├── README.md         # Este arquivo
├── CLAUDE.md         # Memória viva do projeto (arquitetura + módulos + convenções, resumido)
│
├── prompts/          # Prompts internos prontos para tarefas recorrentes
├── docs/             # Documentação de como manter e usar a skill
├── memory/           # Memória de longo prazo: convenções, glossário, nomenclatura, regras
├── architecture/     # Mapa da arquitetura (frontend, backend, banco, DICOM, HL7, integrações...)
├── modules/          # Um arquivo por módulo/feature já analisado (evita reanálise)
├── indexes/          # Índices "onde fica X" — o primeiro lugar a consultar
├── patterns/         # Padrões de código esperados (Controller, Service, SQL, JS, CSS...)
├── workflows/         # Passo a passo por tipo de trabalho (feature, hotfix, deploy, review...)
├── templates/        # Boilerplates prontos já no padrão do projeto
├── diagnostics/      # Checklists/scripts de qualidade (código morto, duplicação, segurança...)
└── examples/         # Exemplos reais de execução de tarefas, para calibrar formato e profundidade
```

## Como usar

1. O agente lê `SKILL.md` primeiro — é o ponto de entrada e o protocolo de raciocínio.
2. Para qualquer tarefa concreta, `SKILL.md` aponta para o arquivo certo em `indexes/`, `architecture/`, `modules/`, `patterns/` ou `workflows/`.
3. Ao final de uma tarefa, o agente atualiza os arquivos relevantes (ver seção "Auto-evolução" em `SKILL.md`) para que a próxima execução seja ainda mais barata.

## Como manter isso atualizado

Esta skill só cumpre seu papel se continuar refletindo a realidade do código. Regras simples:

- **Nunca deixe conhecimento só na cabeça do agente** — se algo foi aprendido durante uma tarefa, ele deve terminar registrado em `modules/`, `architecture/` ou `indexes/`.
- **Prefira atualizar a criar de novo.** Se um módulo já tem arquivo em `modules/`, edite-o em vez de criar um segundo arquivo divergente.
- **Se um índice ficar claramente desatualizado** (aponta para arquivo que não existe mais, ou não cobre uma pasta nova), corrija-o na hora — não deixe para depois.
- Revisões humanas periódicas são recomendadas: um engenheiro do time revisando `architecture/` e `indexes/` a cada poucas semanas evita deriva silenciosa.

## Relação com a skill `voxel-techlead`

Este repositório também pode conter a skill `voxel-techlead`, que define a **postura** (Tech Lead cauteloso, checklist de segurança/performance, formato de resposta). As duas são complementares:

- `voxel-techlead` = **como se comportar** (postura, checklist, formato de resposta, nunca implementar sem plano aprovado).
- `SKILL-VOXEL-PACS` (esta) = **como navegar e economizar contexto** (onde estão as coisas, o que já foi analisado, quais padrões seguir).

Quando as duas estiverem ativas juntas, use a postura de `voxel-techlead` e o mapa/índices desta skill — elas não competem, uma fornece o "temperamento" e a outra o "GPS".
