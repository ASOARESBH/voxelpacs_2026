# Contrato Philips `submission_document`

## Escopo

O profile `submission_document` é uma extensão **opt-in** do transporte `philips_non_dicom`. Ele associa o PDF imutável do laudo a um documento XML de submission no mesmo package lógico do Delivery Hub. O profile `pdf_only` continua sendo o padrão compatível e não muda de comportamento.

A extensão permanece separada do fluxo DICOM, do Orthanc, do HL7 e do disparo automático por liberação. O PACS continua sem acesso direto à pasta remota: o transporte externo permanece restrito à Philips Folder Bridge privada.

## Campos XML

O gerador produz os campos definidos pelo contrato Philips. Campos obrigatórios sem fonte explícita fazem a operação falhar de forma fechada; o sistema não preenche valores clínicos por heurística.

| Campo | Origem ou regra | Obrigatoriedade |
|---|---|---|
| `task_patient_id` | `patient_id` do snapshot do estudo | Obrigatório |
| `task_patient_humanname_family` | Componentes congelados em `report_versions`; DICOM estruturado e confirmação manual são fontes da versão; override request-scoped aprovado somente como fallback histórico | Obrigatório, exceto na política de homologação descrita abaixo |
| `task_patient_humanname_given` | Componentes congelados em `report_versions`; DICOM estruturado e confirmação manual são fontes da versão; override request-scoped aprovado somente como fallback histórico | Obrigatório, exceto na política de homologação descrita abaixo |
| `task_patient_humanname_middle` | Terceiro componente congelado na versão; ausente vira vazio | Opcional; omitido somente pela política de homologação descrita abaixo |
| `task_document_name` | Valor explícito configurado no destino | Obrigatório |
| `task_document_date` | `released_at` congelado no snapshot, normalizado para UTC e serializado como `YYYYMMDDHHMMSS` | Obrigatório |
| `task_image_date` | Combinação explícita de `study_date` e `study_time`; se incompleta, falha | Obrigatório |
| `task_file_path` | Diretório lógico explícito configurado e aprovado pelo receptor; o producer anexa o `task_file_name` dinâmico ao gerar o XML | Obrigatório |
| `task_file_name` | Nome de transporte VOXEL do PDF, validado pelo gerador | Obrigatório |
| `task_accession_number` | `accession_number` do snapshot do estudo | Obrigatório |
| `task_document_mimetype` | Constante `application/pdf` | Obrigatório |
| `task_patient_birthday` | `patient_birth_date` do snapshot | Obrigatório |
| `task_patient_gender` | `patient_sex` do snapshot, normalizado pelo gerador | Obrigatório |
| `task_site_id` | Valor explícito configurado no destino | Obrigatório |
| `task_patient_issuer` | `issuer_of_patient_id` do snapshot | Obrigatório |
| `task_author_id` | Valor explícito configurado; não é convertido de `released_by` | Obrigatório |
| `task_author_humanname_family` | Valor explícito configurado | Obrigatório |
| `task_author_humanname_given` | Valor explícito configurado | Obrigatório |
| `task_author_humanname_middle` | Valor explícito configurado; ausente vira vazio | Opcional |
| `task_modalities` | `modalities` do estudo no snapshot, sem conversão heurística de separadores | Obrigatório |
| `task_document_type` | `11502-2` quando `task_document_type_applicable` é verdadeiro | Condicional |
| `task_delete_file` | Booleano explícito configurado no destino | Obrigatório |

O parser de nome de paciente só aceita componentes DICOM estruturados separados por `^`. Na criação da versão, `report_versions.patient_name_family/given/middle/source` é congelado como fonte primária; quando o DICOM fornece PN válido, a origem é `dicom_pn`, e quando o PN é plano a tela exige confirmação independente com origem `manual_confirmation`. Um nome simples ou ambíguo não é dividido por espaço, vírgula ou qualquer outra heurística.

Depois de assinatura/liberação, os quatro campos estruturados são imutáveis. O snapshot tenant-scoped transporta somente esses componentes já congelados; a resolução do XML não relê nem altera o PatientName original. Versões antigas sem os campos permanecem compatíveis e continuam sujeitas à resolução DICOM/override histórica.

`task_document_date` representa o instante de liberação congelado no snapshot (`reports.liberado_em`). Timestamps com fração e offset são convertidos para UTC e então normalizados para `YYYY-MM-DD HH:MM:SS` antes de o gerador serializá-los como `YYYYMMDDHHMMSS`. Nenhuma nova data é criada e `released_by` não participa da resolução.

### Override request-scoped de PatientName

Quando `tags_raw.PatientName` e `patient_name_dicom` não possuem PN estruturado, `family`, `given` e o `middle` opcional podem ser fornecidos somente por um override explícito vinculado a uma `Delivery Request` individual. Na Fase 71, o override é aceito exclusivamente para `tenant_id=2`, `report_id=74`, `report_version=11`, `estudo_id=1704`, `destination_id=6`, `ambiente=homologacao` e `delivery_profile=submission_document`; qualquer divergência falha com `OVERRIDE_SCOPE_MISMATCH`. O operador fornece componentes independentes; o sistema nunca divide um nome completo.

A precedência é: componentes válidos e congelados em `report_versions`, PN estruturado do snapshot apenas para compatibilidade de versões antigas, override aprovado e íntegro somente como fallback, e então falha fechada. Uma fonte de versão válida sempre vence configuração administrativa e override. O override não pode alterar PatientID, AccessionNumber, datas, autoria, caminho, basename, site ou tipo documental. Os componentes ficam cifrados em tabela própria, com digest canônico, expiração máxima de 24 horas, `max_attempts=1`, aprovação explícita, trigger de imutabilidade pós-aprovação e `consumed_at` irreversível. O worker aplica esse limite à request ligada, portanto não agenda retry para o job do override; jobs históricos e requests sem override continuam usando o limite do destino. Digest inconsistente, escopo divergente, expiração, consumo anterior ou par `family/given` incompleto impede o XML e qualquer transporte. A auditoria registra somente `OVERRIDE_PRESENT`, `OVERRIDE_SCOPE_MATCH`, `OVERRIDE_PAIR_VALID`, `OVERRIDE_SOURCE`, `OVERRIDE_APPROVED`, `OVERRIDE_EXPIRES_AT` e `OVERRIDE_CONSUMED`; os componentes nunca entram em logs.

### Exceção homologatória PatientName-as-family

Quando a fonte `PatientName` for plana e o operador não tiver componentes estruturados congelados, a flag de processo `ALLOW_PATIENT_NAME_AS_FAMILY_FOR_HOMOLOGATION=1` pode ser usada somente em `tenant_id=2`, `destination_id=6`, `ambiente=homologacao`, `transport=philips_non_dicom` e `delivery_profile=submission_document`. Nessa janela, o valor integral da fonte DICOM `PatientName` é preservado em `task_patient_humanname_family`; `task_patient_humanname_given` e `task_patient_humanname_middle` são emitidos como nós vazios. O nome de exibição não é usado como substituto da fonte DICOM.

A flag é default-off, não é persistida no destino nem no banco e não altera `report_versions`, PatientName original ou versões clínicas. A exceção não vence componentes estruturados congelados nem override request-scoped aprovado. O package marca somente `patient_name_as_family=true` em metadata sanitizada; o cliente inclui um literal próprio na assinatura HMAC e a Bridge exige uma segunda flag local root-only (`PHILIPS_FOLDER_ALLOW_PATIENT_NAME_AS_FAMILY_FOR_HOMOLOGATION=1`), escopo compatível e os nós family/given/middle conforme a regra. As duas flags devem ser restauradas para `0` após a tentativa. Fora desse escopo, o modo falha fechado.

### Limite one-shot por Job

Para uma entrega manual explicitamente allowlisted, o caminho `bin/report_delivery_worker.php --job-id=N` habilita em memória um limite efetivo de uma tentativa somente para o Job `N`. Esse estado não é persistido, não altera `max_attempts` do Destination e não é ativado pelo worker global; jobs sem filtro continuam usando o limite normal do Destination. O processo one-shot deve ser encerrado após a tentativa e não deve ser reutilizado como mecanismo de retry.

### Exceção temporária de homologação do V11

Para o teste de chegada do pacote sintético do V11, a flag `ALLOW_MISSING_PATIENT_NAME_COMPONENTS_FOR_HOMOLOGATION` permanece ausente ou diferente de `1` por padrão. Quando explicitamente injetada somente no processo one-shot, ela permite a omissão dos três nós `task_patient_humanname_*` exclusivamente para `tenant_id=2`, `report_id=74`, `report_version=11`, `estudo_id=1704`, `destination_id=6`, `ambiente=homologacao`, `transport=philips_non_dicom` e `delivery_profile=submission_document`. A política exige que os três componentes estejam ausentes ou nulos; componentes parciais, valores não vazios, outro tenant, outro destino, produção ou outro relatório continuam falhando fechado.

O package marca apenas `patient_name_components_omitted=true` em metadata sanitizada. A aplicação autentica a exceção no HMAC do pacote junto com o contexto técnico acima; a Bridge exige adicionalmente a flag local default-off `PHILIPS_FOLDER_ALLOW_MISSING_PATIENT_NAME_COMPONENTS_FOR_HOMOLOGATION=1`, além do HMAC, contexto exato e allowlist do Job único. Essa flag da Bridge deve ser ativada somente durante a janela controlada e restaurada para `0` após a tentativa. A exceção não altera o PatientName original, não preenche valores sintéticos, não afeta `pdf_only`, não muda jobs históricos e não autoriza qualquer transporte sem os gates normais de homologação. Se qualquer flag não estiver ativa, o gerador ou a Bridge volta ao contrato obrigatório e falha fechado.

## Configuração administrativa

A tela de Report Delivery permite selecionar `pdf_only` ou `submission_document`. Ao selecionar o segundo, os campos explícitos de pasta lógica, SITE_ID, nome do documento, autoria, tipo documental e política `task_delete_file` ficam visíveis e são persistidos dentro de `philips_submission`. O `PhilipsSubmissionPackageProducer` combina a pasta configurada com o basename de transporte validado do PDF, usando o mesmo separador detectado na pasta, antes de gerar o XML.

O Controller valida o profile, o transporte SMB, a bridge privada, os campos obrigatórios, os booleanos, o tipo documental `11502-2` e a ausência de tipo quando ele não é aplicável. A credencial continua passando pelo fluxo existente de criptografia e preservação; nenhum segredo é incluído no XML, logs, snapshot ou documentação.

## Compatibilidade e ativação

Destinos com `delivery_profile=pdf_only` seguem o caminho já validado. Jobs históricos não são convertidos para o novo profile. O profile `submission_document` só deve ser habilitado após a aplicação da migration aditiva correspondente, validação do contrato externo e homologação específica da Bridge para os dois arquivos.

A configuração do destino não ativa produção, worker global, trigger automático, DICOM ou SMB. A execução continua sujeita aos gates operacionais de homologação, allowlist de job único, staging limpo, tentativa filtrada e validação física no receptor.

O package só pode retornar `PACKAGE_VERIFIED=PASS` depois de confirmar os hashes e tamanhos do PDF e do XML, XML bem-formado em bytes ISO-8859-1, estrutura `<submission><document>`, campos obrigatórios, `task_file_name` idêntico ao PDF, `task_file_path` vinculado por hash ao valor configurado, `application/pdf`, tipo documental aprovado e política explícita de `task_delete_file`. O arquivo final remoto não é removido pelo cleanup; somente arquivos `.part` temporários podem ser removidos automaticamente.

Novos jobs usam uma chave de idempotência que inclui tenant, relatório, versão, assinatura do artifact, destino e `delivery_profile`. Chaves de jobs históricos não são recalculadas nem modificadas.

## Falhas e rollback

A ausência de qualquer campo obrigatório gera `PhilipsXmlFieldUnresolvedException` com o nome técnico do campo, sem incluir seu valor. A falha ocorre antes da confirmação do package e impede o transporte parcial. O rollback da funcionalidade consiste em selecionar novamente `pdf_only` ou manter o destino desabilitado; não há alteração destrutiva de dados históricos.

A serialização também rejeita bytes de controle, incluindo NUL, e exige a declaração ISO-8859-1 e um XML bem-formado antes de o `PhilipsSubmissionDocument` ser entregue ao produtor de artifacts. O `ReportDeliveryPackage` repete a rejeição como defesa independente. Falhas do ledger registram somente a classe e o estágio técnico (`lock_job`, `create_attempt`, `update_job`, `refresh_outbox`, `sync_request` ou `commit`); SQL, parâmetros, payload, PHI e segredos não entram no log.

A migration de profile é aditiva e deve ser aplicada pelo procedimento de migrations do projeto. O rollback de banco deve remover apenas a coluna nova depois de confirmar que nenhum destino ou job ativo depende dela; não se deve apagar artifacts, jobs, outboxes ou arquivos remotos como parte do rollback.

## Referências

[1]: https://github.com/ASOARESBH/voxelpacs_2026 "VOXEL PACS — repositório do projeto"

[2]: ../docs/REPORT_DELIVERY_HUB.md "VOXEL Report Delivery Hub"

[3]: ../docs/PHILIPS_NON_DICOM_SMB_SECRET_ENROLLMENT.md "Philips Non-DICOM SMB Secret Enrollment"
