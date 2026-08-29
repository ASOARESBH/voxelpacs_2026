# Referência externa — protocolo Weasis e limite de URI no Windows

Fonte consultada em 2026-08-29:

1. https://weasis.org/en/getting-started/weasis-protocol/
2. https://weasis.org/en/basics/customize/integration/

A documentação oficial descreve o formato `weasis://?` seguido de comandos codificados, incluindo `$dicom:get -w` para carregar manifesto XML. A mesma documentação registra uma limitação conhecida no Windows: tokens extensos podem ser truncados pelo navegador na URI de protocolo e recomenda um conector/ViewerHub quando esse cenário ocorrer.

No VOXEL PACS, a adaptação equivalente é uma referência curta, opaca e aleatória no comando `voxel://`, resolvida no backend para um launch temporário. A referência é expirada e revogável; o manifesto permanece de uso único e as instâncias continuam protegidas por assinatura HMAC, revalidação de referência e verificação de pertencimento ao estudo.
