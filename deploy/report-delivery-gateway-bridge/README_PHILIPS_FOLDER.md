# Philips Folder — Etapa 1

Este componente entrega **somente PDF imutável de laudo** por meio da bridge privada. O aplicativo PACS não conhece SMB, SFTP, uma pasta Windows, host remoto ou credenciais do receptor. Esses valores existem exclusivamente em configuração root-owned do gateway conectado à VPN aprovada.

## Limites obrigatórios

| Controle | Regra |
|---|---|
| Ativação no PACS | `PHILIPS_FOLDER_DELIVERY_ENABLED=false` por padrão. |
| Conteúdo | Somente PDF oficial renderizado da versão imutável do laudo na Etapa 1; a bridge aceita extensão XML apenas como preparação estrutural, sem gerar, autorizar ou transmitir XML. |
| Entrada da bridge | HTTPS privada, mTLS obrigatório, HMAC de curta duração, URL allowlisted e corpo máximo de 50 MB. |
| Destino | A bridge conhece um único `destination_id`; o PACS não recebe a pasta remota nem credenciais. |
| Homologação | Modo `single_test`, com um único job explícito, uso idempotente por checksum e sem automação. |
| Gravação | Arquivo temporário no diretório final, `fsync`, SHA-256, `os.replace` e permissão 0600. |
| Auditoria | Apenas job, ambiente, categoria e prefixo do hash; nunca nomes de paciente, conteúdo, token, host ou caminho. |
| SFTP | Transporte preferencial com chave privada e `known_hosts` root-only; a host key nunca é aceita automaticamente. |
| SMB | Fallback opcional e somente para falha transitória de SFTP; acesso via montagem efêmera com arquivo de credencial root-only. |

## Pré-requisitos antes de iniciar

1. A interface isolada `wg-philips` e a bridge privada precisam estar aprovadas e monitoradas. Não use endereço público, `wg0`, compartilhamento SMB exposto ou credenciais no repositório.
2. A equipe do receptor deve aprovar SFTP como método preferencial, a conta de serviço com privilégio mínimo, a host key registrada em `known_hosts` e, se necessário, o fallback SMB. Esses dados são configurados apenas no gateway.
3. Criar os arquivos de mTLS e HMAC em permissões root-only e preencher o arquivo baseado em `philips_folder_bridge.env.example` sem copiá-lo ao repositório.
4. O serviço de exemplo depende de `wg-philips` e deve ser revisado pelo responsável de infraestrutura; ele não deve ser habilitado antes da confirmação explícita de conectividade e do job de teste.

## Homologação sem fila

1. Salvar o destino `philips_folder` no Delivery Hub com ambiente de homologação, desativado e sem disparo na liberação.
2. Configurar a bridge em `single_test` com somente o ID do job manual autorizado.
3. Habilitar a feature flag exclusivamente para a janela de teste aprovada.
4. O worker processa o job já reservado, gera o PDF oficial uma vez e o envia à bridge. A bridge confirma checksum e retorna referência de integridade.
5. Desabilitar novamente a feature flag e registrar o resultado sanitizado. O disparo automático requer uma decisão independente de arquitetura e segurança.

## Rollback

Para parar a capacidade de entrega sem perder a auditoria: desabilitar a feature flag no aplicativo, pausar a bridge pelo procedimento root-owned e manter jobs/artefatos para investigação. Nunca apagar artefatos clínicos ou registros de tentativa como forma de rollback.
