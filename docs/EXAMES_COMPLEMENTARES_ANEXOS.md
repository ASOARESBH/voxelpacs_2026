# Exames complementares vinculados a estudos

## Escopo

Esta funcionalidade adiciona **um anexo complementar independente por estudo**. Ela não altera, substitui ou torna público o Pedido médico já existente. A opção aparece ao acionar o botão de anexo da Gestão de Exames; o usuário escolhe explicitamente entre **Pedido médico** e **Exames complementares** antes de abrir o respectivo fluxo de importação ou câmera.

> O escopo inicial é um documento complementar por estudo, com substituição explícita do arquivo anterior. Isso reproduz o comportamento já consolidado do Pedido médico e evita introduzir coleção clínica ou histórico de conversas sem definição operacional própria.

| Elemento | Contrato |
|---|---|
| Persistência | Tabela própria, com unicidade por `tenant_id` e `estudo_id`, metadados mínimos e hash SHA-256. |
| Armazenamento | Diretório privado separado de Pedido médico; nenhum binário fica sob `public/`. |
| Formatos e tamanho | Mesmo limite e validação por MIME real do Pedido médico: PDF, JPG, PNG, WebP, HEIC e HEIF, até 15 MB. |
| Gestão de Exames | Sessão autenticada, módulo `gestao_exames`, CSRF, tenant efetivo e escopo de modalidades são obrigatórios. |
| Worklist | Apenas indicador visual de anexo; não há link, preview ou download na tela de Estudos. |
| Report | Exames complementares aparece ao lado de Pedido e pode ser aberto somente por médico autorizado no escopo clínico do Report. |
| Auditoria | Registrar anexação, substituição, remoção e visualização com identificadores técnicos, tipo MIME, tamanho e tenant; não registrar binário ou conteúdo do arquivo. |

## Precedência de acesso

O backend identifica o estudo pelo par `tenant_id` e `estudo_id`, e reaplica a validação de modalidade antes de cada upload, remoção ou download administrativo. No Report, o acesso requer sessão e validação pelo token opaco do laudo, usando a autorização clínica existente; o token não contém identificador sequencial de estudo.

## Impacto e rollback

As migrations são somente aditivas. O rollback de aplicação deve restaurar os arquivos substituídos a partir do backup de rollout; a tabela e os arquivos privados não devem ser removidos automaticamente, pois podem constituir evidência operacional. Uma remoção corretiva posterior precisa de aprovação explícita e de plano de retenção.

## Publicação e validação inicial

A publicação foi precedida por snapshot estrutural, sem dados clínicos, e backup dos arquivos substituídos. A migration aditiva foi aplicada no schema operacional e a aplicação foi recarregada após validação de sintaxe PHP. A conferência posterior confirmou a estrutura da tabela, o índice de isolamento por tenant e estudo, a legibilidade dos arquivos pelo runtime e a saúde do serviço PHP.

Foi confirmada visualmente, em um estudo de homologação autorizado e sem gravar dados, a etapa inicial que oferece as opções **Pedido médico** e **Exames complementares**. Nenhum arquivo foi selecionado, anexado, removido ou visualizado durante essa validação. O teste ponta a ponta de upload, indicador posterior na Worklist, acesso médico no Report, cabeçalhos de não armazenamento e eventos de auditoria permanece condicionado a autorização específica para uso de artefato desidentificado.
