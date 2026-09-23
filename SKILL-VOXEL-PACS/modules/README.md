# Modules — Índice e Template

Cada módulo/feature relevante do VOXEL PACS deve ter um arquivo aqui, no formato abaixo. Isso é o que permite que uma tarefa futura reutilize conhecimento em vez de reanalisar o código.

## Módulos versionados

Esta lista é derivada dos arquivos `SKILL-VOXEL-PACS/modules/*.md` presentes na árvore oficial auditada. A presença de um arquivo não significa que seu conteúdo esteja completo ou que o comportamento esteja validado em produção.

| Módulo | Arquivo |
|---|---|
| Assinatura de médico | `assinatura-medico.md` |
| Ditado de voz | `ditado-voz.md` |
| Edição de médico | `editar-medico.md` |
| E-mail | `email.md` |
| Gestão de exames | `gestao-exames.md` |
| Grupos | `grupos.md` |
| Internacionalização | `i18n.md` |
| Máscaras de laudo | `mascaras-laudo.md` |
| Médicos | `medicos.md` |
| Negócios | `negocios.md` |
| Peer Review | `peer-review.md` |
| Relatórios da plataforma | `platform-reports.md` |
| Portal de resultados | `portal-resultados-pacientes.md` |
| Relatórios | `relatorios.md` |
| Report Delivery | `report-delivery.md` |
| Templates de relatório | `report-templates.md` |
| Reports | `reports.md` |
| Servidor PACS | `servidor-pacs.md` |
| Regras de SLA | `sla-regras.md` |
| Template de laudo | `template-laudo.md` |
| Tenants | `tenants.md` |
| Unidades | `unidades.md` |
| Usuários | `usuarios.md` |
| Worklist de estudos | `worklist-estudos.md` |

## Template para um módulo novo (`modules/<nome-do-modulo>.md`)

```markdown
# Módulo — <Nome>

## Propósito
[1-2 frases]

## Arquivos principais
| Arquivo | Papel |
|---|---|
| | |

## Dependências
- Depende de: [...]
- Consumido por: [...] (ver architecture/dependencias.md para o grafo completo)

## Padrões seguidos
[Referenciar patterns/ relevantes, não repetir o conteúdo]

## Riscos / pontos frágeis conhecidos
[Se algum]

## Última análise
[Data]
```

## Áreas ainda não confirmadas como módulos

Os nomes abaixo aparecem como referências arquiteturais ou áreas de interesse, mas não correspondem a arquivos de módulo presentes na árvore oficial auditada. Não tratá-los como módulos existentes nem criar documentação artificial sem localizar primeiro o código e obter uma decisão documentada:

- `ingestao-dicom` — recepção e processamento de estudos vindos do Orthanc
- `laudos` — geração, assinatura e distribuição de laudos
- `hl7-integracao` — parsing e handling de mensagens ADT/ORM/ORU
- `viewer` — integração com OHIF/viewer DICOM
- `auth` — autenticação e sessão
- `permissoes` — controle de acesso por instituição/perfil
- `worklist` — lista de trabalho de exames pendentes
