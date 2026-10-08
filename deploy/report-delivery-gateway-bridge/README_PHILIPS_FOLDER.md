# Philips Folder — Etapa 1

Este componente entrega o perfil legado **PDF-only** e o perfil `submission_document` **PDF + XML** por meio da bridge privada. O aplicativo PACS não conhece SMB, SFTP, uma pasta Windows, host remoto ou credenciais do receptor. Esses valores existem exclusivamente em configuração root-owned do gateway conectado à VPN aprovada.

## Unidade canônica e separação de transporte

O transporte Philips Non-DICOM usa exclusivamente a unidade versionada:

```text
voxelpacs-philips-folder-bridge.service
  → /opt/voxelpacs/report-delivery-gateway/philips_folder_bridge.py
  → /etc/voxelpacs/philips-folder-bridge.env
```

`voxelpacs-report-delivery-bridge.service` executa `bridge_server.py` e permanece reservado ao contrato DICOM/C-STORE. Ele não é o transportador do Destination 7 e não deve ser apontado para PDF+XML.

O código da unidade Philips é atualizado somente pelo publisher root-controlled `scripts/publish-philips-folder-bridge.sh`. O publisher exige o hostname canônico, SHA da `main`, SHA-256 do arquivo, validação AST Python e caminhos fixos; `--dry-run` não grava, `--apply` cria backup root-only e substitui atomicamente apenas `philips_folder_bridge.py`, e `--rollback-sha` restaura somente um backup validado. `--reload` reinicia exclusivamente `voxelpacs-philips-folder-bridge.service` e confirma uma instância ativa. O publisher não altera EnvironmentFile, allowlist, DICOM/C-STORE, Worker, banco, SMB ou transmissão.

Para o primeiro envio controlado do Destination 7, o fragmento `philips_folder_bridge.destination7.single_test.env.example` documenta somente o escopo não secreto `tenant=2`, `destination=7`, `mode=single_test` e o placeholder do Job. O Job real deve ser preenchido apenas no EnvironmentFile root-owned após preflight e autorização separada; o fragmento não é instalável diretamente.

## Limites obrigatórios

| Controle | Regra |
|---|---|
| Ativação no PACS | `PHILIPS_FOLDER_DELIVERY_ENABLED=false` por padrão. |
| Conteúdo | PDF oficial renderizado; no perfil `submission_document`, XML Philips correspondente; sem DICOM encapsulado ou reprocessamento de PDF. |
| Entrada da bridge | HTTPS privada, mTLS obrigatório, HMAC de curta duração, URL allowlisted e corpo máximo de 50 MB. |
| Escopo | A bridge exige `tenant_id`, `destination_id` e `job_id` allowlisted em PDF e PDF+XML; divergências são rejeitadas antes de staging/transporte. |
| Destino | A bridge conhece um único `destination_id`; o PACS não recebe a pasta remota nem credenciais. |
| Homologação | Modo `single_test`, com um único job explícito, uso idempotente por checksum e sem automação. |
| Gravação | Arquivo temporário no diretório final, `fsync`, SHA-256, `os.replace` e permissão 0600. |
| Auditoria | Apenas job, ambiente, categoria e prefixo do hash; nunca nomes de paciente, conteúdo, token, host ou caminho. |

## Pré-requisitos antes de iniciar

1. A interface WireGuard e a bridge privada precisam estar ativas e monitoradas. Não use endereço público, compartilhamento SMB exposto ou credenciais no repositório.
2. A equipe do receptor deve aprovar o método de gravação do gateway (SMB ou SFTP), a conta de serviço com privilégio mínimo e o diretório local gerenciado abaixo da raiz privada da bridge. Esses dados são configurados apenas no gateway.
3. Criar os arquivos de mTLS e HMAC em permissões root-only e preencher o arquivo baseado em `philips_folder_bridge.env.example` sem copiá-lo ao repositório.
4. O serviço de exemplo deve ser revisado pelo responsável de infraestrutura; ele não deve ser habilitado antes da confirmação explícita de conectividade e do job de teste.

## Homologação sem fila

1. Salvar o destino `philips_folder` no Delivery Hub com ambiente de homologação, desativado e sem disparo na liberação.
2. Configurar a bridge em `single_test` com `PHILIPS_FOLDER_ALLOW_TENANT_ID`, `PHILIPS_FOLDER_ALLOW_DESTINATION_ID`, `PHILIPS_FOLDER_DESTINATION_ID` iguais no destino autorizado e somente o ID do job manual autorizado.
3. Habilitar a feature flag exclusivamente para a janela de teste aprovada.
4. O worker processa o job já reservado, gera o pacote do perfil uma vez e o envia à bridge. A bridge confirma checksum e retorna referência de integridade.
5. Desabilitar novamente a feature flag e registrar o resultado sanitizado. O disparo automático requer uma decisão independente de arquitetura e segurança.

## Instrumentação temporária de homologação

`PHILIPS_FOLDER_STAGE_DIAGNOSTICS=1` é uma flag root-only, default-off, destinada somente a uma janela `single_test` de homologação com `PHILIPS_FOLDER_ALLOW_JOB_ID` igual ao Job autorizado. A Bridge ainda exige os headers assinados `homologacao`, `submission_document` e `philips_non_dicom`; fora dessa combinação, nenhuma telemetria de estágio é emitida.

Quando habilitada, a Bridge registra apenas `STAGE_ENTER`, `STAGE_EXIT`, `PACKAGE_OPEN`, `MANIFEST`, `PDF`, `XML`, `SMB_LIST`, `SMB_WRITE`, `SMB_RENAME` e `SMB_VERIFY`, com Job ID, timestamp, resultado, return code quando disponível e categoria de erro sanitizada. Não registra stdout, stderr, comandos, nomes de arquivo, caminhos, hashes, credenciais, tokens ou conteúdo clínico. A flag deve ser restaurada para `0` após a janela e não pode ser habilitada em produção ou em modo `destination`.

## Rollback

Para parar a capacidade de entrega sem perder a auditoria: desabilitar a feature flag no aplicativo, pausar a bridge pelo procedimento root-owned e manter jobs/artefatos para investigação. Nunca apagar artefatos clínicos ou registros de tentativa como forma de rollback.
