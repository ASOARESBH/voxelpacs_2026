# Philips Non-DICOM — Segredo SMB e teste controlado

## Estado desta proposta

Este documento descreve um desenho de implementação. Ele não habilita destino, não inicia bridge, não altera `wg0` ou `wg-philips`, não faz conexão SMB e não transmite PDF ou XML. A entrega de pares PDF/XML permanece bloqueada até que o contrato Philips `submission/document` seja fornecido e aprovado.

## Limites de confiança

O Control Plane pode receber uma senha SMB digitada pelo administrador em campo `password`. O valor será cifrado por `ReportDeliveryCryptoService` antes da persistência e nunca será devolvido por listagem, API, auditoria, mensagens de erro ou logs. O PACS não executa `smbclient`, não monta compartilhamento e não abre conexão SMB.

O gateway permanece como a única borda com capacidade SMB. O gateway recebe uma credencial somente durante uma operação autorizada, por canal mTLS+HMAC já existente, dentro de um envelope cifrado para chave pública root-only do gateway. A chave privada correspondente não sai do gateway.

## Protocolo proposto

| Etapa | Control Plane | Gateway | Persistência e logs |
|---|---|---|---|
| Salvar destino | Cifra senha com a chave de aplicação e armazena o valor em `configuration_secret`. | Não recebe segredo. | A auditoria recebe apenas `smb_credential_configured`. |
| Testar conexão | Exige destino em homologação já salvo, desativado e sem disparo automático. Decifra apenas no request e sela a senha para a chave pública do gateway. | Valida mTLS, HMAC, expiração, destino, peer privado e política `single_test`; cria arquivo de credencial `0600` em área temporária root-only. | Apenas categoria sanitizada: `connectivity`, `timeout`, `authentication`, `permission`, `configuration` ou `remote_io`. |
| Teste de escrita | Não envia PDF, XML, outbox ou job clínico. | Usa `smbclient` com arquivo de autenticação, cria um arquivo temporário de nome aleatório, confirma a escrita e o remove antes da resposta. | Não registra senha, nome do arquivo temporário, caminho remoto ou saída do processo. |
| Entrega futura | Cria job idempotente somente após schema XML aprovado e autorização própria. Encapsula o segredo apenas durante a chamada da bridge. | Usa o segredo em arquivo efêmero somente para a operação SMB e remove o arquivo imediatamente. | Registra IDs técnicos, estado e hash truncado; não registra conteúdo ou segredos. |

> Para reduzir o risco de ação sem destino auditável, o teste de conexão deve ocorrer **depois de salvar o destino desativado em homologação**. Salvar não habilita o destino, não ativa disparo automático e não transmite conteúdo.

## Regras de configuração

Os parâmetros públicos do destino são transporte `smb`, porta `445`, compartilhamento e usuário. O destino privado é aceito somente quando corresponde ao peer do túnel Philips validado pela política root-only do gateway. A interface deve exibir apenas `Credencial configurada` e, em edição, deixar o campo de senha vazio; a senha anterior permanece válida se o campo não for preenchido.

A migration proposta deverá acrescentar somente indicadores não secretos, como `smb_credential_configured_at`, `smb_last_validation_at` e `smb_last_validation_category`. A senha cifrada continua em `configuration_secret`; nenhum campo de senha em texto aberto será criado.

## Regras de bloqueio

Não implementar a geração ou entrega de XML até existir XSD, exemplo validado ou contrato de campos Philips. Não inferir `task_document_type`, encoding, schema, nomenclatura final, vínculo PDF/XML ou campos clínicos. O teste de conectividade SMB não pode criar PDF, XML, outbox, job, worker recorrente ou entrega clínica.

## Ativação futura

Antes de ativar a bridge ou executar o teste de conectividade serão necessários: auditoria read-only aprovada do gateway, configuração root-only de chave privada para envelopes, política de peer privado, dependência de `wg-philips`, credenciais mTLS/HMAC e confirmação operacional específica para a única tentativa. A ativação automática continua fora de escopo.
