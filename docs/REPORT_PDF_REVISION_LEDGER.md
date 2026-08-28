# Trilha de Segurança — Histórico de PDF por Liberação e Peer Review

## Objetivo

Manter uma trilha longitudinal e imutável de cada PDF clínico liberado para um estudo, sem alterar o contrato da outbox de devolutiva ou o worker DICOM. O artefato binário continua no storage privado; este processo adicional registra a cadeia de custódia, a versão clínica, o ciclo de peer review e a data de emissão.

> O PDF usado em devolutiva permanece sendo o snapshot já ligado à outbox. O ledger não reenfileira, não entrega e não altera destinos DICOM.

## Fonte de verdade e escopo

A chave clínica da trilha é `(tenant_id, estudo_id, report_id, report_version)`. O tenant é obrigatório em toda operação. `estudo_id` é mantido para consulta longitudinal, mas nenhum identificador de paciente, texto clínico, UID DICOM, caminho público ou binário é persistido no ledger.

| Cenário | Entrada no ledger | Rótulo interno | PDF associado |
|---|---|---|---|
| Primeira liberação | Uma linha por versão liberada | `ORIGINAL` | Snapshot de `report_pdf_snapshots` da mesma versão |
| Reabertura de peer review | Nenhuma nova linha de PDF | Mantém a versão liberada anterior | O PDF anterior permanece imutável |
| Nova assinatura/liberação após peer review | Uma nova linha vinculada ao ciclo concluído | `REV 1`, `REV 2`, ... | Novo snapshot da nova versão |
| Falha de snapshot | Nenhuma linha | Liberação aborta quando o fluxo exige devolutiva | Nenhum PDF parcial é aceito |

## Invariantes

A tabela complementar deve referenciar o snapshot já existente por chave lógica, armazenar o SHA-256 e tamanho copiados do snapshot para auditoria e impedir duplicidade por versão. Um número de revisão é calculado apenas para liberações após peer review concluído; o rótulo é interno e não é inserido no PDF entregue ao cliente, preservando o documento clínico assinado.

A escrita ocorrerá na mesma transação que cria o snapshot e a outbox. Falha do ledger aborta a liberação, pois permitir um PDF liberado sem cadeia de custódia prejudicaria a trilha clínica. A leitura para tela de histórico deverá passar por `ReportAccessService` antes de retornar metadados de versão.

## Retenção, permissões e rollback

Os PDFs continuam em `storage/report_pdf_snapshots`, fora de `public/`, com arquivo `0600` e diretório `0700`. O banco armazena somente metadados técnicos mínimos. A retenção clínica não será abreviada por esta alteração; qualquer exclusão futura exigirá política formal, aprovação e verificação de dependências de delivery.

O rollback de código remove somente a escrita/consulta do ledger. O rollback da tabela só é permitido após confirmar que não há registros, snapshots ou auditorias que dependam dela. Nenhum comando de rollback deve apagar PDF clínico.

## Observabilidade

A auditoria registra apenas `tenant_id`, `report_id`, `estudo_id`, `report_version`, `revision_number`, `peer_review_cycle`, `snapshot_sha256` e o resultado técnico. Logs não podem conter conteúdo de laudo, paciente, UID, caminho de storage ou binário.
