# Voxel Desktop — Não-DICOM Philips/VUE

## Escopo inicial

Este módulo cria uma outbox **tenant-scoped** de PDF e XML Philips `submission/document` depois da liberação de uma versão de laudo. A outbox é gravada na mesma transação clínica, mas não faz rede, não gera artefato e não altera o resultado da assinatura.

O piloto nasce com destino **desativado** em homologação. Logo, a publicação do código e da migration não cria trabalhos de entrega nem habilita o Router Desktop.

## Contrato de entrega

O Router Desktop autentica com token por destino, reivindica um job por pull e recebe um PDF privado da versão imutável. O Router gera o XML `submission/document` localmente, grava PDF e XML por rename atômico no diretório configurado localmente e informa somente estados allowlisted: `artifact_ready`, `package_submitted`, `receiver_completed` ou `receiver_failed`.

O PACS central não recebe nem armazena diretórios Windows, credenciais locais ou parâmetros de rede do receptor.

## Revisões e idempotência

Cada versão liberada cria uma chave de idempotência baseada em tenant, laudo, versão, hash de assinatura e destino. Uma revisão posterior cria novo job; a mesma versão não duplica entrega.

## Operação

Antes de ativar qualquer destino são necessários: homologação com artefatos sintéticos, validação do XML no VUE PACS, conferência de permissão dos diretórios locais no Router e autorização específica para ativar a transmissão. A instalação de serviço, configuração de token e diretórios ocorre somente no Router Desktop do receptor.

## Salvamento desativado e diagnóstico técnico

O formulário do control-plane aceita somente um destino em homologação, com `enabled=0`. Router ID e Site ID são identificadores administrativos livres, com limite de armazenamento de 120 caracteres, e precisam corresponder literalmente aos valores configurados no Router Desktop. O roteamento exige Issuer ou InstitutionName; quando há Issuer, ele tem precedência.

Em PostgreSQL, a criação do destino usa `RETURNING id`; não depende de `lastInsertId()`. Falhas de validação são registradas na auditoria por código sanitizado, sem token, segredo, caminho local, URL, payload de laudo ou identificadores clínicos.

A tela tenant-scoped apresenta, abaixo dos destinos, um log técnico para superadmin. Ele combina apenas eventos de auditoria do próprio destino e estados allowlisted do Router. O painel não lê artefatos, payloads, caminhos, tokens, referências remotas ou dados de pacientes. Salvar um destino ou consultar esse log não ativa o Router, não cria job e não transmite conteúdo.

## Teste de conectividade

O Router consulta um endpoint autenticado de status antes de qualquer operação de pull. Ele exige o par Router ID/Site ID e o token correspondente, mas aceita o destino ainda desativado e responde apenas com o estado de configuração. A chamada não reivindica job, não acessa artefato, não gera XML/PDF e não altera fila. Um retorno `configured_disabled` confirma a comunicação de leitura e mantém a ativação como decisão separada.
