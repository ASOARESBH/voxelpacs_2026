# Contrato Philips `submission_document`

## Escopo

O profile `submission_document` é uma extensão **opt-in** do transporte `philips_non_dicom`. Ele associa o PDF imutável do laudo a um documento XML de submission no mesmo package lógico do Delivery Hub. O profile `pdf_only` continua sendo o padrão compatível e não muda de comportamento.

A extensão permanece separada do fluxo DICOM, do Orthanc, do HL7 e do disparo automático por liberação. O PACS continua sem acesso direto à pasta remota: o transporte externo permanece restrito à Philips Folder Bridge privada.

## Campos XML

O gerador produz os campos definidos pelo contrato Philips. Campos obrigatórios sem fonte explícita fazem a operação falhar de forma fechada; o sistema não preenche valores clínicos por heurística.

| Campo | Origem ou regra | Obrigatoriedade |
|---|---|---|
| `task_patient_id` | `patient_id` do snapshot do estudo | Obrigatório |
| `task_patient_humanname_family` | Componentes estruturados configurados ou `patient_name_dicom` do snapshot com separador `^` | Obrigatório |
| `task_patient_humanname_given` | Componentes estruturados configurados ou `patient_name_dicom` do snapshot com separador `^` | Obrigatório |
| `task_patient_humanname_middle` | Terceiro componente estruturado; ausente vira vazio | Opcional |
| `task_document_name` | Valor explícito configurado no destino | Obrigatório |
| `task_document_date` | `released_at` congelado no snapshot | Obrigatório |
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

O parser de nome de paciente só aceita componentes DICOM estruturados separados por `^`. Um nome simples ou ambíguo não é dividido por espaço, vírgula ou qualquer outra heurística.

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

A migration de profile é aditiva e deve ser aplicada pelo procedimento de migrations do projeto. O rollback de banco deve remover apenas a coluna nova depois de confirmar que nenhum destino ou job ativo depende dela; não se deve apagar artifacts, jobs, outboxes ou arquivos remotos como parte do rollback.

## Referências

[1]: https://github.com/ASOARESBH/voxelpacs_2026 "VOXEL PACS — repositório do projeto"

[2]: ../docs/REPORT_DELIVERY_HUB.md "VOXEL Report Delivery Hub"

[3]: ../docs/PHILIPS_NON_DICOM_SMB_SECRET_ENROLLMENT.md "Philips Non-DICOM SMB Secret Enrollment"
