# Philips Non-DICOM — Fase 1 PDF-only por SMB

## Finalidade

Esta fase comprova o caminho técnico controlado de um único laudo já liberado: PDF imutável, outbox/job do Delivery Hub, bridge privada existente e SMB no gateway. O XML Philips não é gerado, inferido ou transmitido nesta fase. O disparo automático por liberação permanece desativado.

## Componentes reutilizados

| Responsabilidade | Componente |
|---|---|
| Configuração administrativa | `VoxelDesktopController`, `VoxelDesktopRepository`, `voxel_desktop.php` |
| Cifragem da senha | `ReportDeliveryCryptoService` |
| Fila, retry e reenvio | `ReportDeliveryOutboxService`, `ReportDeliveryRepository`, `ReportDeliveryWorkerRepository`, `report_delivery_worker.php` |
| Teste de um laudo | `ReportDeliveryManualQueueService` e controles de homologação do Delivery Hub |
| PDF imutável | `ReportDeliveryArtifactService` e `ReportPdfService` |
| Canal privado | `PhilipsFolderGatewayBridgeClient` e `philips_folder_bridge.py` |
| Tela de entregas | `ReportDeliveryController` e `report_delivery.php` |

## Contrato de artefatos

`NonDicomArtifactProducer` representa um produtor de artefato por job. Na Fase 1, `PdfNonDicomArtifactProducer` é o único produtor habilitado e gera somente o PDF privado da versão imutável. A interface permite acrescentar um produtor XML na Fase 2, mas nenhum produtor XML é fornecido até que haja contrato Philips aprovado.

## Segredo SMB

O formulário recebe a senha somente em campo `password`. O Control Plane cifra o valor com `ReportDeliveryCryptoService` antes da persistência e expõe apenas `credential_configured`. Nenhuma listagem, API, auditoria ou log contém a senha.

O PACS nunca executa SMB. Quando uma operação é autorizada, o serviço entrega um envelope de credencial de uso único ao gateway pelo canal HTTPS mTLS/HMAC da bridge. O gateway abre o envelope com chave privada root-only, usa um arquivo de autenticação `0600` apenas enquanto `smbclient` executa e remove o arquivo antes da resposta. A senha não entra em URL, cabeçalho, log ou argumentos de processo.

## Testes distintos

| Ação | Pré-condição | O que faz | O que não faz |
|---|---|---|---|
| Testar conexão SMB | Destino salvo, homologação, credencial cifrada | Gateway valida TCP, autenticação, share, escrita e remoção de artefato temporário. | Não cria outbox/job, PDF, XML ou entrega clínica. |
| Testar entrega Non-DICOM | Destino homologação habilitado, automático desligado, laudo liberado selecionado | Cria um único job manual do Delivery Hub, produz PDF imutável e envia pela bridge. | Não gera XML, não seleciona laudo histórico automaticamente e não habilita produção. |

## Estados e rollback

O Delivery Hub preserva `queued`, `processing`, `delivered`, `retrying` e `dead_letter`, com tentativa e categoria sanitizada. O reenvio permanece manual para jobs terminais. O rollback da Fase 1 é desligar sua feature flag e manter a bridge parada; nenhum DICOM, Orthanc, WireGuard, firewall ou SSHD é alterado por esta implementação.
