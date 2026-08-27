# Contrato do MVP de Agendamentos

## Decisões aprovadas

O módulo será disponibilizado a usuários que já possuam o módulo **Agendamentos** autorizado pelo catálogo central. Não haverá, nesta entrega, uma segunda feature flag específica por empresa para cadastro de pacientes.

| Decisão | Contrato aprovado |
|---|---|
| Unidade de destino | Obrigatória e limitada ao tenant corrente. O servidor PACS é derivado da unidade, nunca recebido do navegador. |
| Paciente | Dados mínimos: nome completo e data de nascimento. Cada agendamento recebe um Patient ID novo, gerado no backend. |
| Procedimento | Modalidade obrigatória, escolhida entre os códigos DICOM autorizados/disponíveis ao tenant; data obrigatória e hora opcional. |
| Identificadores | Accession Number e Patient ID são gerados no backend com entropia criptográfica e constraints únicas no banco. |
| Médico solicitante | Fora deste MVP. |
| Worklist | Cadastro, listagem, cancelamento individual e rastreabilidade serão implementados. A publicação DICOM permanece em estado de preparação até que o plugin e a estação DICOM sejam configurados e aprovados. |

O cadastro administrativo de **Unidades** passou a oferecer o vínculo opcional a um servidor PACS. A lista contém somente servidores ativos já autorizados para a empresa e não exibe host, porta, AE Title ou credenciais. Para uma Unidade aparecer no formulário de Agendamentos, esse vínculo deve estar preenchido, tornando o destino do futuro pedido explícito e evitando seleção de servidor pelo navegador.

## Modelo de dados

Será criada uma tabela independente `bi_agendamentos`; estudos recebidos continuarão em `bi_pacs_estudos`. Isso preserva a distinção entre um pedido planejado e uma aquisição clínica efetivamente recebida.

| Campo | Propósito | Regra de isolamento |
|---|---|---|
| `tenant_id` | Empresa titular do agendamento. | Presente em toda leitura e mutação. |
| `unidade_id` e `pacs_id` | Unidade e servidor derivados de configuração do tenant. | Ambos são validados juntos no backend. |
| `accession_number` | Chave de correlação entre agenda e estudo recebido. | Única globalmente; não é aceita do navegador. |
| `patient_id` | Identificador DICOM do paciente para este agendamento. | Gerado no backend e não registrado em detalhes de auditoria. |
| `situacao` | `agendado`, `realizado` ou `cancelado`. | Transições validadas pelo serviço. |
| `mwl_status` | Estado operacional da futura publicação na Worklist. | Inicia como `aguardando_infraestrutura`; não anuncia publicação enquanto o plugin não existir. |

## Rotas e autorizações

| Rota | Ação | Proteções |
|---|---|---|
| `GET /agendamentos` | Exibe formulário e listagem do tenant. | Catálogo de módulo, sessão e filtros tenant-scoped. |
| `POST /agendamentos` | Cria agendamento. | CSRF, servidor/unidade do tenant, validação de modalidade, data e duplicidade por constraint. |
| `POST /agendamentos/{id}/cancelar` | Cancela apenas agendamento ainda aberto. | CSRF, tenant, idempotência e auditoria sanitizada. |

## Correlação posterior de estudo

Quando a sincronização receber um estudo que tenha Accession Number igual ao de um agendamento aberto, o vínculo será aceito somente se **tenant, servidor PACS e Accession Number** coincidirem. A atualização para `realizado` deve ser idempotente e não modifica o estudo recebido, o laudo ou dados DICOM.

## Limite da integração DICOM nesta etapa

O host analisado não possui serviço Orthanc ativo nem biblioteca do plugin oficial de Worklists identificada. Por essa razão, o MVP não instalará, ativará ou simulará uma Worklist e não realizará C-FIND. A etapa futura deverá cadastrar uma estação DICOM por unidade, instalar e configurar o plugin de Worklists, validar um artefato desidentificado e receber confirmação específica antes de atender consultas de equipamento.

## Auditoria e privacidade

Os eventos de criação, cancelamento, publicação futura e correlação serão emitidos pelo `AuditLogger`. Os detalhes retêm apenas estado, modalidade, unidade, identificadores internos e resultado. Nome do paciente, data de nascimento, Patient ID, Accession Number e conteúdo DICOM não devem ser gravados no campo de detalhes, logs ou mensagens de erro.

## Referências

[1]: https://orthanc.uclouvain.be/book/faq/worklist.html "Orthanc — Does Orthanc support worklists?"
[2]: https://orthanc.uclouvain.be/book/plugins/worklists-plugin-new.html "Orthanc — Worklists plugin"
