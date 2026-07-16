# Modules — Índice e Template

Cada módulo/feature relevante do VOXEL PACS deve ter um arquivo aqui, no formato abaixo. Isso é o que permite que uma tarefa futura reutilize conhecimento em vez de reanalisar o código.

## Módulos já documentados

- `servidor-pacs` — dashboard/sync/roteamento do Orthanc global → `modules/servidor-pacs.md`
- `negocios` — CRUD de tenants/clientes, InstitutionNames, Unidades DICOM → `modules/negocios.md`
- `worklist-estudos` — tela `/estudos`, worklist principal do usuário final → `modules/worklist-estudos.md`
- `tenants` — multi-tenancy (TenantContext vs Auth::tenantId()), impersonação, o que NÃO existe ainda (médico↔unidade) → `modules/tenants.md`

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

## Módulos sugeridos com prioridade alta (candidatos óbvios dado o domínio PACS)

Estes são os módulos que provavelmente existem e valem análise prioritária, dado o escopo do sistema — confirmar nomes reais contra o repositório:

- `ingestao-dicom` — recepção e processamento de estudos vindos do Orthanc
- `laudos` — geração, assinatura e distribuição de laudos
- `hl7-integracao` — parsing e handling de mensagens ADT/ORM/ORU
- `viewer` — integração com OHIF/viewer DICOM
- `auth` — autenticação e sessão
- `permissoes` — controle de acesso por instituição/perfil
- `worklist` — lista de trabalho de exames pendentes
