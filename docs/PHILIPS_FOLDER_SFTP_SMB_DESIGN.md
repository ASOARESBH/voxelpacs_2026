# Philips Folder — SFTP primário e fallback SMB

**Estado:** desenho de runtime inerte. A feature flag permanece desligada; a bridge não é iniciada por esta documentação e nenhuma entrega de PDF, XML ou outro artefato é autorizada.

## Limite de confiança

O PACS e o worker continuam enviando o snapshot PDF imutável somente para a bridge privada por HTTPS, mTLS e HMAC. Eles não recebem host remoto, usuário, senha, chave privada SSH, `known_hosts`, compartilhamento SMB, caminho Windows ou configuração WireGuard. Esses itens pertencem exclusivamente ao arquivo de ambiente root-only do gateway.

```text
PACS/worker → bridge privada autenticada → transporte root-only → peer WireGuard Philips
```

> A seleção de SFTP ou SMB ocorre no gateway. O runtime do PACS não abre SFTP, SMB, porta pública ou rota direta ao Windows.

## Política de transporte

| Configuração root-only | Valores aceitos | Regra |
|---|---|---|
| `PHILIPS_FOLDER_TRANSPORT` | `sftp` ou `smb` | Define o transporte primário. |
| `PHILIPS_FOLDER_FALLBACK` | vazio ou `smb` | Permitido apenas quando o primário é SFTP. |
| `PHILIPS_SFTP_*` | host privado, porta, usuário, diretório, chave e `known_hosts` | SFTP usa autenticação por chave e verificação estrita da host key. |
| `PHILIPS_SMB_*` | host privado, share, diretório, identidade e secret protegido | SMB permanece exclusivamente pela rota privada aprovada. |

O fallback é tentado uma única vez somente para falhas de conectividade ou timeout. Falhas de autenticação, host key, permissão, I/O remoto, configuração, artefato ou integridade falham fechadas e retornam ao retry/backoff já existente do Delivery Hub.

## Integridade e idempotência

O gateway recebe o PDF em staging privado, calcula hash e registra o job de maneira idempotente. A saída remota sempre usa nome temporário e rename dentro do mesmo destino remoto. Arquivo final já existente somente é aceito se tamanho e hash coincidirem; qualquer divergência falha fechada, sem sobrescrever artefato.

| Etapa | Controle |
|---|---|
| SFTP | `BatchMode`, `StrictHostKeyChecking=yes`, arquivo `known_hosts` root-only e chave privada root-only. |
| SMB | Autenticação não interativa via arquivo root-only, montagem efêmera sem privilégios de execução e sem log de credencial. |
| Staging | Nome temporário, sync, verificação de tamanho/hash e rename atômico. |
| Auditoria | Somente evento, job interno, transporte, tentativa, tamanho, duração, categoria sanitizada e prefixo de hash. |

## Pré-requisitos para futura ativação

A ativação exigirá confirmação separada e prova de que a rota privada está ativa, o peer remoto tem identidade SSH registrada, as credenciais estão em arquivos root-only, o firewall limita portas ao túnel Philips e a bridge usa dependência de `wg-philips`, não `wg0` ou `wg-dicom`. A entrega inicial permanece limitada a um teste sintético autorizado; XML, automação e produção exigem novas aprovações.
