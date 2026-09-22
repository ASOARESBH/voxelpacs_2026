# VOXEL PACS — Etapa 1: Philips Folder Delivery

## Objetivo e limite desta etapa

Esta etapa prepara uma entrega assíncrona de um **PDF imutável de laudo liberado** para uma pasta Philips remota por um caminho privado já aprovado. Ela não inclui XML Philips, Auto Ingestion, alteração do VOXEL Router Desktop, processamento DICOM, abertura de portas públicas ou ativação automática em produção.

> A implementação deve permanecer inerte até que a conectividade privada, o método de transferência e a identidade de serviço sejam confirmados pelo ambiente remoto.

## Diagnóstico da arquitetura atual

| Componente | Comportamento atual | Reaproveitamento na Etapa 1 |
| --- | --- | --- |
| `ReportService` | Atualiza o estado clínico e, após liberação, grava efeitos operacionais na mesma transação. | Não recebe lógica de rede ou cópia de arquivos. |
| `ReportDeliveryOutboxService` | Cria evento idempotente e jobs por destino após o commit clínico. | Continua sendo o único produtor de entregas. |
| `pacs_report_delivery_*` | Mantém destinos, outbox, jobs, tentativas e artefatos com tenant e hash. | Mantém os estados `queued`, `processing`, `delivered`, `retrying` e `dead_letter`. |
| `ReportDeliveryArtifactService` | Renderiza o PDF a partir da versão imutável e grava em armazenamento privado. | Será a única fonte do PDF Philips Folder. |
| Worker de Delivery | Faz lease, watchdog, backoff exponencial e conclui/falha o job de forma auditável. | Ganha um transporte adicional, sem alterar o ciclo de outros transportes. |
| Bridge privada atual | Foi projetada somente para DICOM Encapsulated PDF. | O padrão de mTLS, HMAC, allowlist e confirmação remota pode ser reaproveitado; o transporte DICOM não será alterado. |

O diagnóstico de operação realizado anteriormente encontrou o worker principal ativo, porém sem o túnel WireGuard e a bridge privada ativos no runtime. Portanto, nenhum endereço informado pelo ambiente remoto pode ser usado diretamente pelo aplicativo e não existe, neste momento, uma rota privada validada para SMB ou SFTP.

## Desenho proposto

```mermaid
flowchart LR
    A[Laudo liberado] --> B[ReportDeliveryOutboxService]
    B --> C[Outbox idempotente]
    C --> D[Job philips_folder]
    D --> E[Worker do Delivery Hub]
    E --> F[PhilipsFolderDeliveryService]
    F --> G[Gateway privado autenticado]
    G --> H[VPN existente]
    H --> I[Pasta Philips remota]
```

O novo transporte lógico será `philips_folder`. O worker continuará a gerar o PDF pelo serviço oficial a partir da versão imutável, calcular SHA-256, gravar o artefato em área privada e aplicar o mesmo lease, retry, backoff e idempotência já usados pelo Delivery Hub.

O `PhilipsFolderDeliveryService` não abrirá SMB, SFTP ou portas externas diretamente a partir do runtime do PACS. Ele entregará exclusivamente a uma bridge privada autenticada por mTLS e HMAC, com URL privada allowlisted. A bridge, mantida fora do repositório e configurada por política root-only, usa **SFTP como método preferencial** pela rota WireGuard Philips e pode usar SMB apenas como fallback de falhas transitórias, também pela mesma rota privada.

## Controles técnicos obrigatórios

| Controle | Regra da Etapa 1 |
| --- | --- |
| Feature flag | `PHILIPS_FOLDER_DELIVERY_ENABLED=false` por padrão. |
| Destino | Criação em homologação e desativado; nenhuma tela habilita esse transporte enquanto a feature flag estiver desligada. |
| Produção | Requer confirmação administrativa própria e uma autorização operacional separada; nunca é promovido por configuração padrão. |
| Origem do PDF | Exclusivamente `ReportDeliveryArtifactService`, com conteúdo da versão imutável do laudo. |
| Nome do arquivo | Determinístico, derivado de identificador de acesso e da versão do laudo, sem nome de paciente. |
| Staging | Escrita inicial em arquivo temporário privado; publicação remota somente por rename/move atômico confirmado pelo gateway. |
| Idempotência | Uma chave de job por outbox e destino; o gateway deve rejeitar conteúdo divergente com o mesmo nome e aceitar somente arquivo já presente com hash idêntico. |
| Integridade | SHA-256 e tamanho conferidos na API, no gateway e na confirmação de resposta. |
| Logs | Somente categoria, ids internos, tamanho, prefixo de hash, estado e instante; sem segredo, caminho local, endpoint, paciente ou conteúdo de laudo. |
| Retentativa | Reuso do backoff do worker; falhas de rede e transporte tornam o job `retrying` ou `dead_letter`, sem gerar novo PDF. |
| SFTP | Autenticação por chave, `known_hosts` root-only e verificação estrita de host key; nenhuma aceitação automática de fingerprint. |
| SMB fallback | Permitido somente após falha transitória de SFTP; credencial e share ficam em arquivo root-only na bridge. |

## Informações ainda necessárias para teste efetivo

O código não contém, e não deve inventar, os elementos abaixo. Eles devem ser registrados em configuração protegida ou na política root-only do gateway, nunca no repositório nem no chat.

| Informação requerida | Motivo |
| --- | --- |
| Método permitido: SFTP e, se aprovado, fallback SMB | Define o conector de saída no gateway privado. |
| Endereço privado roteado pela VPN | Impede conexão direta a endpoint público ou exposição de SMB. |
| Identidade de serviço e método de autenticação | Necessário para acesso mínimo à pasta remota. |
| Política de destino da pasta remota | Permite staging, rename atômico e verificação de colisão/hash. |
| Evidência de rota VPN e peer autorizado | Confirma que o tráfego sai pelo túnel existente, sem alterar WireGuard. |
| Janela de homologação e responsável remoto | Necessário antes de qualquer tentativa de escrita. |

## Rollback

A reversão de código deve deixar `PHILIPS_FOLDER_DELIVERY_ENABLED=false`, manter destinos Philips Folder desativados e cancelar apenas jobs desse transporte ainda não processados. Não deve remover outbox, tentativas, hashes ou artefatos já criados, pois esses elementos são registros auditáveis. A bridge privada, se instalada no futuro, deverá ser parada e sua política root-only removida separadamente, sem alterar o worker geral, WireGuard, listeners DICOM ou Router Desktop.

## Critério de avanço

É seguro implementar o runtime inerte após esta revisão. A conectividade ou entrega efetiva só pode começar depois de um teste de rota privado autorizado, do método SMB/SFTP confirmado e da política root-only de gateway aprovada. O primeiro teste deverá usar um PDF técnico sintético, sem dados clínicos, e uma autorização operacional explícita.
