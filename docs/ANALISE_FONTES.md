# Fontes consultadas durante a análise

## Repositório

A análise foi iniciada no repositório público informado pelo usuário: [ASOARESBH/voxelpacs_2026](https://github.com/ASOARESBH/voxelpacs_2026). A branch analisada foi `main`, commit inicial `81da289` (`fix(topbar): contadores usa InstitutionResolverService igual a worklist — corrige badges zerados no multi-tenant`).

## Documentação de engenharia consultada

Foram lidos `SKILL-VOXEL-PACS/SKILL.md`, `SKILL-VOXEL-PACS/CLAUDE.md`, `SKILL-VOXEL-PACS/modules/worklist-estudos.md`, `SKILL-VOXEL-PACS/modules/relatorios.md`, `SKILL-VOXEL-PACS/architecture/auth-e-permissoes.md`, `SKILL-VOXEL-PACS/architecture/visao-geral.md`, `SKILL-VOXEL-PACS/patterns/padrao-controller.md`, `SKILL-VOXEL-PACS/patterns/padrao-i18n.md` e `SKILL-VOXEL-PACS/patterns/padrao-sql.md`.

Os pontos determinantes foram: a Worklist real é `/estudos` e usa `EstudosController`/`app/Views/estudos/index.php`; o isolamento normal é por tenant e InstitutionName, com tratamento especial para superadmin impersonando; a abertura do viewer e o laudo são ações médicas que não devem aparecer no modo administrativo; novas strings de interface precisam existir nos três catálogos de idioma; e arquivos sensíveis devem ficar fora de `public/` com proxy autenticado.

## Material compartilhado do projeto

Também foi consultado o arquivo de visão do projeto `PROMTP PACS MANUS.rtf`, compartilhado em `/home/ubuntu/projects/voxel-copilot-24f10614`. A decisão aplicada nesta feature mantém o PACS como fonte oficial do estudo/paciente e vincula o pedido médico ao estudo sem copiar o documento para a camada de inteligência do Copilot.
