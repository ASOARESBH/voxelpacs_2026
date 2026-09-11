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

## Validações e rollback futuros

Antes de qualquer alteração, criar backup root-only dos três certificados atuais, sem copiar as chaves privadas, HMAC ou envelope para fora dos hosts. Após a atualização, repetir somente `openssl verify -x509_strict` para os propósitos de servidor e cliente, conferir SAN, EKU, Key Usage, correspondência certificado/chave e permissões. Um handshake mTLS, chamada de bridge ou teste SMB não faz parte dessa validação e exigirá autorização distinta.

Se o listener não recuperar estado ativo após a futura rotação autorizada, restaurar somente os três certificados de backup e reiniciar exclusivamente a unit da bridge sob autorização de incidente. Não apagar auditoria, estado, chaves ou arquivos clínicos.
