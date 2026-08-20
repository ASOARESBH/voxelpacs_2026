# OHIF Viewer dedicado ao Portal — referências de build e configuração

A instância do Viewer para o Portal deve ser estática, same-origin e configurada por `app-config.js`. A documentação oficial do OHIF informa que o build de produção gera os ativos em `platform/app/dist` e seleciona o arquivo de configuração a partir da variável `APP_CONFIG`.[1]

Para a configuração DICOMweb, o OHIF usa as raízes `qidoRoot`, `wadoRoot` e `wadoUriRoot`, com `dataSources` declarados no arquivo de configuração.[2] Na implementação do VOXEL PACS, as três raízes são limitadas ao gateway same-origin `/imagens/dicom-web`; a configuração proíbe upload, exportação, worklist e configuração dinâmica.

O fallback de build local foi inviabilizado por repetidas falhas transitórias de download de dependências no registro de pacotes. Para homologação, foram espelhados os ativos estáticos distribuídos publicamente pelo OHIF em `https://viewer.ohif.org/`; antes da publicação, a configuração pública será substituída pela configuração local restrita do Portal. Nenhuma requisição clínica será enviada à origem pública.

## Referências

[1]: https://docs.ohif.org/deployment/build-for-production/ "OHIF — Build for Production"
[2]: https://docs.ohif.org/configuration/configurationfiles/ "OHIF — Configuration Files"
