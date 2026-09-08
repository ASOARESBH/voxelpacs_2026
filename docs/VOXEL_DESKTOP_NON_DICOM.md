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
