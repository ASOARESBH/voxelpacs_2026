# VOXEL PACS — contrato de viewer OHIF compartilhado

## Objetivo

Usar uma única instância de OHIF no domínio `view.voxelpacs.com.br` para todos os tenants, sem criar container ou subdomínio de viewer por tenant.

## Invariantes obrigatórios

| Camada | Contrato |
|---|---|
| Abertura | A API gera token opaco, com validade e vínculo a estudo, tenant e servidor de origem. |
| Sessão | O token é enviado somente em cookie `HttpOnly`, `Secure`, `SameSite=Lax` para o domínio Voxel. |
| OHIF | A URL de abertura usa `/viewer/dicomweb`; não recebe IP, porta, credencial Orthanc ou chave de tenant. |
| Proxy DICOMweb | Nginx exige `auth_request` antes de toda solicitação e obtém o upstream privado e a autorização somente por cabeçalhos internos da API. |
| API interna | Valida token, tenant, estudo, servidor, status da célula e origem `https://view.voxelpacs.com.br`. Não devolve estudo, UID, dados de paciente ou credenciais ao navegador. |
| Personalização | Marca, opções do OHIF e configurações não clínicas são resolvidas por tenant em configuração controlada; parâmetros livres na URL não são aceitos. |
| Isolamento | Nunca há fallback para um Orthanc global quando o estudo possui `servidor_id`; uma falha de resolução retorna erro fechado. |
| Observabilidade | Logs registram somente códigos técnicos, cell key e resultado; sem token, UID, paciente, query string ou Authorization. |

## Migração do domínio

A implantação será reversível. O vhost e a configuração do viewer legado serão copiados em diretório root-only. A instância OHIF compartilhada existente será reconfigurada somente após uma rota de validação controlada para a célula A. O rollback recompõe o vhost e a configuração anteriores e recria somente o container OHIF compartilhado.

## Limite de escopo

Nenhuma imagem, série, laudo, DICOM object, estudo ou backup será movido durante a migração. B e C permanecem sem célula iniciada, sem viewer publicado e sem rota DICOM habilitada.
