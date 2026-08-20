# Viewer OHIF dedicado ao Portal

Esta pasta contém a configuração same-origin do Viewer estático do Portal. O pacote
estático do OHIF é publicado fora do repositório, em `/var/www/voxelpacs/portal-viewer`;
a configuração `app-config.js` deste diretório deve substituir o arquivo homônimo do
pacote antes da publicação.

A configuração usa apenas `/imagens/dicom-web`, bloqueia upload, exportação,
worklist e configuração dinâmica. O Viewer não deve ser publicado com qualquer URL
para o Orthanc clínico, o Viewer interno ou um serviço OHIF público.
