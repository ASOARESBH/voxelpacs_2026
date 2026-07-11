# Arquitetura — Banco de Dados

> Ver `indexes/tabelas-banco.md` para o índice tabela-a-tabela. Este arquivo é sobre a estratégia geral, não sobre cada tabela.

## SGBD e versão

`[A confirmar]`

## Estratégia de migrations

- Ferramenta usada: `[A confirmar]`
- Convenção de nomenclatura de migration: `[A confirmar]`
- Regra: **nenhuma alteração de schema sem migration correspondente** — mesmo em ambiente de desenvolvimento. Ver `patterns/padrao-sql.md`.

## Índices e performance conhecidos

| Tabela | Índice | Motivo | Observação |
|---|---|---|---|
| `[A preencher]` | | | |

## Relação com Orthanc

O Orthanc mantém seu próprio armazenamento de metadados DICOM. Documentar aqui:

- O banco da aplicação **espelha**, **referencia**, ou é **independente** dos metadados do Orthanc? `[A confirmar]`
- Existe processo de sincronização/reconciliação entre os dois? `[A confirmar — onde vive esse processo]`

## Cuidados

Tabelas ligadas a dados de paciente/estudo têm implicações de privacidade e regulatórias. Qualquer alteração de schema nessas tabelas deve ser tratada com o mesmo rigor de uma alteração DICOM (ver checklist de segurança em `diagnostics/seguranca.md`).
