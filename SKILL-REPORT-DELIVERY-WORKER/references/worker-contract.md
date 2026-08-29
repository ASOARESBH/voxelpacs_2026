# Contrato operacional do Worker do Delivery Hub

Consulte esta referência ao alterar ou operar o Worker de laudos DICOM Encapsulated PDF. Ela registra invariantes duráveis; os valores específicos de tenant, host, AE, rota, credenciais e IDs ativos pertencem ao control-plane e a políticas root-only, nunca a esta habilidade.

## Componentes de referência

| Componente | Responsabilidade |
|---|---|
| `app/Services/ReportPdfSnapshotService.php` | Gera e registra o snapshot PDF binário imutável no momento da liberação clínica. |
| `app/Services/ReportPdfRevisionLedgerService.php` | Mantém a cadeia ORIGINAL/REV de snapshots sem alterar a outbox entregue. |
| `app/Services/ReportDeliveryOutboxService.php` | Cria outbox e seleciona destinos compatíveis por tenant, origem PACS e identidade institucional. |
| `app/Repositories/ReportDeliveryRepository.php` | Persiste destinos e fornece a visão administrativa da versão atual de entrega. |
| `app/Repositories/ReportDeliveryWorkerRepository.php` | Faz claim/lease do job com joins explícitos de tenant e regras de elegibilidade. |
| `app/Services/ReportDeliveryArtifactService.php` | Lê snapshot imutável e falha fechada quando o artefato não é válido. |
| `app/Services/ReportDeliveryGatewayBridgeClient.php` | Envia DICOM apenas à bridge privada, com URL allowlisted, mTLS/HMAC e job/tenant/destino autenticados. |
| `bin/report_delivery_worker.php` | Processa jobs unitários ou o loop automático; encapsula o snapshot e registra resultado técnico. |
| `deploy/report-delivery-gateway-bridge/bridge_server.py` | Aplica policy root-only, trava por job, C-ECHO e C-STORE pelo caminho VPN. |
| `tests/report_delivery_production_routing_contract.php` | Garante estaticamente o vínculo por origem PACS, HMAC, Series Description, kill switch e elegibilidade automática. |

## Invariantes de persistência

1. O destino de produção inclui `servidor_pacs_id` positivo e é selecionado no mesmo tenant do estudo, outbox e job.
2. A autorização de tenant↔servidor PACS vem de `bi_negocio_servidor_pacs`; não trate presença no catálogo como autorização.
3. O destino automático exige origem PACS idêntica e Issuer compatível. InstitutionName é fallback somente quando Issuer não está presente.
4. O snapshot usado na entrega é o binário da outbox; o worker não renderiza um substituto em runtime.
5. A descrição de série deve existir no objeto DICOM final; o receptor pode apresentar `ND` quando `(0008,103E)` estiver ausente.
6. Uma tentativa externa é persistida por job; retries não podem gerar associação duplicada sem política e autorização próprias.
7. O painel agrega a maior versão de outbox por laudo, mas snapshots e revisões anteriores permanecem preservados.

## Escopos de worker

| Escopo | Uso | Regra |
|---|---|---|
| `--check` | Validação local de binários e bootstrap | Não consulta artefato nem transmite. |
| Job unitário | Piloto autorizado ou recuperação manual | Exige ID explícito, policy de job/tenant/destino e bridge temporária. |
| Loop automático | Novas liberações de produção | Exige feature flag, destino ativo, produção, data clínica corrente, `automatic_production`, origem PACS igual e `max_attempts=1`. |

## Serviços persistentes

O worker da API deve operar como usuário de serviço sem privilégio. Se o `.env` for protegido, use `EnvironmentFile` root-owned e grupo do serviço com leitura restrita. Em CLI/systemd, variáveis herdadas podem existir em `getenv()` e não em `$_ENV`; o bootstrap precisa hidratar chaves de ambiente antes do carregamento do `.env`, sem imprimir valores.

A bridge só pode escutar no endereço privado destinado à API. Use `tenant_destination` em produção; `controlled_job` é exclusivo de piloto e homologação. O kill switch deve deixar o sistema fail-closed: worker inativo, bridge inativa, listener ausente e flag de automação removida da policy.

## Sequência de validação

```text
1. Validar snapshot/outbox/destino por estados sanitizados.
2. Validar links tenant→servidor PACS→estudo e Issuer.
3. Validar policy privada, mTLS/HMAC, container e WireGuard.
4. Validar worker --check e contrato estático.
5. Confirmar que não existe job histórico elegível.
6. Para piloto: autorização explícita, C-ECHO e um C-STORE.
7. Para automação: autorização separada e kill switches instalados.
```

## Evidência operacional permitida

Registre somente: ID interno do job, versão, estado, tentativa, timestamp, estado C-ECHO/C-STORE, estado do worker/bridge, listener privado, handshake observado e contagens agregadas. Não registre conteúdo de PDF ou atributos DICOM identificáveis.
