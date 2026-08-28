# Ponte DICOM de devolutiva pelo gateway

Este componente permite que a API entregue **um artefato DICOM Encapsulated PDF** ao gateway privado para uma associação DICOM de saída via WireGuard. Ele foi desenhado para uma operação controlada e não substitui o padrão multi-tenant definitivo de devolutiva.

## Limites de segurança

A ponte escuta apenas no IP privado do gateway e exige simultaneamente certificado de cliente emitido pela CA interna e uma assinatura HMAC com validade de 60 segundos. A configuração efetiva fica em arquivo root-only fora do repositório.

A política inicial deve preencher um único identificador de job, um IP/porta de destino, Calling AE e Called AE. O serviço rejeita qualquer path, job, destino, AE, tamanho, hash ou SOP Class fora dessa política. O segredo, certificados, chaves privadas, dados DICOM e logs detalhados não podem ser versionados.

O gateway registra somente o identificador interno do job, o prefixo do hash do artefato, timestamp e resultado técnico. O artefato é gravado em diretório temporário com permissão `0600`, transmitido para o container de gateway, removido do container e apagado localmente ao fim da operação.

## Fluxo permitido

| Etapa | Responsável | Controle |
| --- | --- | --- |
| 1 | API | Gera DICOM Encapsulated PDF em armazenamento privado e calcula SHA-256. |
| 2 | API → gateway | mTLS, HMAC, URL/IP privados fixos, job permitido e integridade do corpo. |
| 3 | Gateway | Verifica job, hash, tamanho, uso único e política estática. |
| 4 | Gateway → PACS | Abre associação via `wg0`, faz C-ECHO e só então C-STORE na mesma associação. |
| 5 | API | Marca o job entregue somente após resposta HTTP `201` do gateway. |

## Operação controlada

Antes de iniciar a unidade, valide que o peer WireGuard está ativo, que a rota ao receptor sai por `wg0`, que o container de gateway está saudável e que o C-ECHO com os AEs exatos é aceito. A execução deve usar `report_delivery_worker.php --job-id=<id>`; esse modo não consulta nem seleciona qualquer outro job da fila.

A unidade não reinicia automaticamente. Depois da tentativa unitária, pare e desabilite o serviço. A nova execução requer uma alteração explícita da política e uma confirmação operacional.

## Rollback

Para interromper antes de uma tentativa, pare e desabilite a unidade, remova a regra de firewall privada e apague o arquivo de política root-only. O worker geral permanece inativo. Não altere os listeners de entrada, a porta DICOM do gateway, peers B/C, Orthanc ou viewer.
