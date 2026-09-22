# Philips Folder Bridge — proposta de correção PKI mTLS

## Estado e escopo

Esta proposta corrige exclusivamente a cadeia PKI usada pelo canal privado mTLS entre PACS/API e a bridge Philips. Ela foi elaborada após a evidência de que a CA instalada possui `Basic Constraints: CA:TRUE`, mas não declara `Key Usage`, condição que pode ser recusada pela validação estrita usada por OpenSSL 3/Python.

Não autoriza nem executa rotação de certificados, conexão mTLS, chamada à bridge, teste SMB, geração ou transferência de PDF, XML, criação de job/outbox, reprocessamento ou automação. Também não altera WireGuard, firewall, SSHD, DICOM, Orthanc, PostgreSQL, HMAC ou chaves de envelope.

## Diagnóstico estático

O listener da bridge exige certificado de cliente e carrega a CA de confiança com `ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)`, `CERT_REQUIRED` e `load_verify_locations`. O cliente PACS/API usa HTTPS com verificação de peer e hostname habilitada e aponta para CA, certificado e chave mTLS externos. Portanto, a rejeição da CA ocorre no handshake TLS, antes de o handler validar HMAC, destino, envelope, job ou de a bridge poder chamar `smbclient`.

O instalador atualmente gera a CA com `openssl req -x509` sem arquivo de extensões e emite os certificados de servidor e cliente apenas com `extendedKeyUsage`. A ausência de extensão `Key Usage` na CA explica a falha reportada e também torna a cadeia incompleta para validação estrita.

## Diagnóstico read-only proposto

O arquivo `diagnose_philips_bridge_pki_gateway_readonly.sh` avalia localmente, sem conexão de rede, os seguintes marcadores: presença e permissões root-only, Basic Constraints, Key Usage, EKU, SAN IP do servidor, correspondência certificado/chave, validade e verificação `openssl verify -x509_strict` para os propósitos `sslserver` e `sslclient`.

O diagnóstico mostra apenas estados sanitizados. Ele não imprime certificados, chaves, HMAC, envelope, caminhos de destino, URLs, nomes de pacientes ou logs brutos.

## Certificados a reemitir

| Artefato | Ação proposta | Chave associada | Extensões obrigatórias |
|---|---|---|---|
| CA privada da integração | Reemitir o certificado da CA. | Preservar `ca.key` existente se o diagnóstico confirmar propriedade root-only e integridade. | `basicConstraints=critical,CA:TRUE`; `keyUsage=critical,keyCertSign,cRLSign`; identificador de chave de assunto. |
| Certificado TLS do servidor da bridge | Reemitir e assinar com a CA corrigida. | Preservar `server.key` se o pareamento for válido; não transferir a chave. | `basicConstraints=critical,CA:FALSE`; `keyUsage=critical,digitalSignature`; `extendedKeyUsage=serverAuth`; SAN com o IP privado autorizado da bridge. |
| Certificado TLS do cliente PACS/API | Reemitir e assinar com a CA corrigida. | Preservar `client.key` se o pareamento for válido e manter permissões mínimas. | `basicConstraints=critical,CA:FALSE`; `keyUsage=critical,digitalSignature`; `extendedKeyUsage=clientAuth`; identificador de chave de autoridade. |

Preservar as chaves existentes é a correção mínima porque a falha demonstrada é de extensão do certificado, não de comprometimento de chave. Caso o diagnóstico encontre chave sem proteção, par incompatível ou validade inválida, a rotação de chave correspondente deverá ser proposta separadamente antes de continuar.

## Atualização coordenada futura

No gateway, gerar todos os certificados em diretório de staging root-only, usando a CA corrigida, sem substituir arquivos em uso até que cada par e cadeia sejam validados localmente. Preservar sem alteração HMAC, chave privada/pública de envelope, política SMB, destino, serviço, unit, estado e diretórios da bridge.

No PACS/API, atualizar somente a cópia da CA e o certificado do cliente pelo pacote root-only administrativo. A chave do cliente permanece protegida pelo grupo de execução já aprovado. Os caminhos de configuração do cliente permanecem os mesmos; nenhuma senha SMB é incluída.

Depois de validação criptográfica local, substituir atomica e coordenadamente a CA/certificados no gateway e a CA/certificado no PACS/API. Um reinício controlado apenas da unit da bridge será necessário para o listener recarregar o certificado do servidor; isso requer autorização específica futura. O PACS/API não requer reinício para substituição de arquivos de certificado, pois o cliente cURL os abre a cada chamada, mas nenhuma chamada será feita nesta etapa.

## Artefatos de rotação preparados em modo seco

Os scripts de rotação têm comandos separados para inspeção, staging, aplicação e rollback. Nenhum deles é executado nesta proposta. O comando `--dry-run` valida os pré-requisitos atuais e declara o inventário de mudança sem criar, substituir ou recarregar qualquer componente.

| Host | Script | Operação futura controlada | Arquivos substituídos | Arquivos preservados |
|---|---|---|---|---|
| Gateway | `rotate_philips_bridge_pki_gateway.sh` | `--stage` gera e valida certificados em staging root-only; `--apply` substitui apenas certificados após autorização; `--rollback` restaura somente certificados. | `ca.crt`, `server.crt`, `client.crt`. | `ca.key`, `server.key`, `client.key`, HMAC, envelope privado/público, policy, unit e estado da bridge. |
| PACS/API | `rotate_philips_bridge_pki_pacs_client.sh` | `--dry-run` mostra escopo; `--apply` consome pacote administrativo validado; `--rollback` restaura certificados. | `ca.crt`, `client.crt`. | `client.key`, HMAC, chave pública do envelope, configuração de cliente e aplicação. |

O pacote administrativo futuro contém apenas a nova CA e o novo certificado de cliente. Ele não contém senha SMB, HMAC, chave privada do envelope, chave privada da CA, chave do servidor ou chave do cliente.

## Prévia coordenada de aplicação

A prévia é uma operação local, somente leitura, disponível como `--preview-apply` nos dois scripts. No gateway, ela exige o staging previamente validado, verifica a CA e ambos os certificados de staging, compara os pares com as chaves atuais e mostra os hashes previstos. No PACS/API, ela exige que o pacote de atualização tenha sido copiado por canal administrativo protegido, verifica o conteúdo e confirma a cadeia do novo certificado de cliente contra a nova CA. Nenhuma prévia substitui arquivos, cria backup, recarrega processo ou abre conexão.

| Ordem futura | Host | Ação após autorização própria | Marcadores esperados |
|---|---|---|---|
| 1 | Gateway | Conferir prévia e aplicar somente CA, certificado do servidor e certificado do cliente, com backup root-only. | `PKI_GATEWAY_CERTIFICATES_REPLACED=ready`, `GATEWAY_BACKUP=ready`, `GATEWAY_ROLLBACK=ready`, hashes aplicados e cadeias estritas válidas. |
| 2 | PACS/API | Copiar o pacote de CA/certificado de cliente, conferir prévia e aplicar apenas esses dois certificados, com backup root-only. | `PACS_PKI_CERTIFICATES_REPLACED=ready`, `PACS_BACKUP=ready`, `PACS_ROLLBACK=ready`, hashes aplicados, cadeia estrita e par de cliente válidos. |
| 3 | Gateway | Recarga exclusiva da unit da bridge, somente após autorização separada. | Não faz parte de `--apply`; exige decisão explícita posterior. |
| 4 | PACS/API e Gateway | Handshake mTLS e teste SMB, ambos com autorizações próprias e separadas. | Fora do escopo da rotação PKI. |

Durante as etapas 1 e 2, nenhuma chamada à bridge é permitida. Isso evita que uma troca parcialmente aplicada seja confundida com uma indisponibilidade do receptor. O listener mantém a configuração anterior até a recarga isolada autorizada da unit da bridge.

## Perfil X.509 reprodutível

| Papel | Basic Constraints | Key Usage | Extended Key Usage | SAN |
|---|---|---|---|---|
| CA | `critical, CA:TRUE, pathlen:0` | `critical, keyCertSign, cRLSign` | Não aplicável. | Não aplicável. |
| Servidor da bridge | `critical, CA:FALSE` | `critical, digitalSignature` | `serverAuth` | Somente o IP privado autorizado da bridge. |
| Cliente PACS/API | `critical, CA:FALSE` | `critical, digitalSignature` | `clientAuth` | Não aplicável. |

Antes de uma futura troca, os scripts exigirão: correspondência de cada certificado com sua chave já existente; validade mínima de 24 horas; CA e certificados parseáveis; e aprovação de `openssl verify -x509_strict` com propósito `sslserver` e `sslclient`. O script não reduz nem desativa verificação estrita de certificados.

## Validações e rollback futuros

Antes de qualquer alteração, criar backup root-only dos três certificados atuais, sem copiar as chaves privadas, HMAC ou envelope para fora dos hosts. Após a atualização, repetir somente `openssl verify -x509_strict` para os propósitos de servidor e cliente, conferir SAN, EKU, Key Usage, correspondência certificado/chave e permissões. Um handshake mTLS, chamada de bridge ou teste SMB não faz parte dessa validação e exigirá autorização distinta.

Se o listener não recuperar estado ativo após a futura rotação autorizada, restaurar somente os três certificados de backup e reiniciar exclusivamente a unit da bridge sob autorização de incidente. Não apagar auditoria, estado, chaves ou arquivos clínicos.
