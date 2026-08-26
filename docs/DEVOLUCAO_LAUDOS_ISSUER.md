# Devolução de Laudos — Roteamento por Issuer

## Objetivo

O **VOXEL Report Delivery Hub** seleciona o destino de devolução de cada laudo liberado por uma identidade DICOM de origem. A regra evita que estudos de origens distintas, mas com nomes institucionais semelhantes, sejam enviados ao mesmo destino por engano.

## Fontes configuráveis por destino

| Fonte | Origem | Uso |
|---|---|---|
| **Issuer of Patient ID** | Tag DICOM `(0010,0021)` do estudo | Critério prioritário de roteamento. |
| **InstitutionName** | Tag DICOM de instituição do estudo | Fallback apenas quando o estudo não tiver Issuer utilizável. |

Os Issuers exibidos na tela de destino são obtidos dos estudos já recebidos pelos servidores PACS vinculados ao negócio e das regras ativas de Issuer por modalidade do mesmo negócio. O administrador da Plataforma pode vincular zero ou mais Issuers e InstitutionNames a cada destino; a configuração é sempre isolada por `tenant_id`.

> A presença de Issuer no estudo impede o fallback por InstitutionName. Se o Issuer estiver presente, mas não estiver vinculado a um destino habilitado, a devolução não cria job para esse destino.

## Algoritmo de decisão

| Condição do estudo | Fonte consultada | Resultado |
|---|---|---|
| Issuer válido presente | `pacs_report_delivery_destination_issuers` | Cria job somente para destinos habilitados vinculados ao Issuer normalizado. |
| Issuer ausente ou vazio | `pacs_report_delivery_destination_institutions` | Usa o InstitutionName canônico como fallback. |
| Nenhuma fonte corresponde | Nenhuma | A outbox recebe `no_destination`; não há tentativa de conexão DICOM, HL7, SFTP ou HTTPS. |

A normalização do Issuer usa o mesmo contrato do roteamento DICOM do PACS: remove espaços excedentes, converte para maiúsculas e trata acentos apenas para comparação. O valor original selecionado permanece armazenado para exibição e auditoria.

## Persistência e auditoria

A migration `2026-08-26_report_delivery_destination_issuers_{postgresql,mysql}.sql` cria a tabela `pacs_report_delivery_destination_issuers`, com unicidade por destino e Issuer normalizado, além de índice de lookup por tenant. As alterações de destino registram somente chaves técnicas de Issuer normalizado e InstitutionNames, sem conteúdo de paciente, laudo ou credenciais.

O evento de outbox inclui `issuer_of_patient_id_normalized` e `routing_basis` (`issuer`, `institution_name_fallback` ou `none`) para permitir rastreabilidade operacional sem alterar a carga clínica já encaminhada ao worker.

## Homologação

Configurar a origem na Plataforma não transmite laudos. A entrega única de homologação exige laudo liberado, token público opaco, CSRF e confirmação explícita do superadmin. A criação do job não equivale ao envio externo; o worker habilitado é a única camada autorizada a abrir a conexão do transporte.

Antes de iniciar uma homologação, confirme o destino, o Issuer ou InstitutionName aplicável, o ambiente e as credenciais técnicas. Nunca use dados de paciente em logs de diagnóstico ou em documentação.
