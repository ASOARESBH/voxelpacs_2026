# Integração segura do VOXEL Desktop

## Escopo implementado

O botão da Worklist para **VOXEL Desktop** passa a gerar um launch temporário para o cliente atual, que reconhece o protocolo padrão **Weasis**. A URI é transferida diretamente por redirecionamento HTTP, contém apenas um comando Weasis e uma referência opaca de curta duração. Nenhum identificador clínico, credencial, endereço Orthanc ou configuração DICOM é colocado na URI, no DOM ou na auditoria.

> O esquema proprietário personalizado não foi emitido nesta etapa. O checkout atual do cliente reconhece `weasis://`; a criação ou alteração do instalador Windows permanece uma etapa posterior.

| Componente | Responsabilidade | Limite de segurança |
|---|---|---|
| Ação protegida da Worklist | Reaplica permissão de Estudos, tenant e escopo de modalidade antes da emissão. | Falha fechada se estudo, tenant, servidor ou contexto forem inválidos. |
| Registro de launch | Persiste somente hash do token, assinatura, contexto mínimo de autorização e expiração curta. | O token original não é salvo. |
| Manifesto Weasis | É produzido sob demanda uma única vez para um único estudo e aponta para o proxy interno. | Nenhuma origem Orthanc é divulgada ao cliente. |
| Proxy de instância | Revalida token, assinatura, expiração e pertencimento da instância ao estudo autorizado. | Sem redirects externos; bytes retornados como `application/dicom` e `no-store`. |
| Auditoria | Usa o viewer `voxel` após a migration aditiva. | Falha de auditoria não impede a abertura; não armazena URI, token ou configuração DICOM. |

## Contrato do cliente

O cliente utiliza o fluxo oficial `$dicom:get -w` para recuperar um manifesto XML. No schema embarcado pelo próprio cliente, cada `Instance` aceita `DirectDownloadFile`; o gerenciador de download concatena esse valor ao `baseUrl` do manifesto. Por isso, o backend produz referências relativas assinadas para o proxy, e não URLs Orthanc.

Os dados clínicos indispensáveis para a renderização do exame existem apenas no manifesto temporário protegido e no processo local do viewer. Eles não aparecem na página intermediária, no comando de protocolo, em logs de aplicação ou em documentação de versionamento.

## Operação e validação

O rollout inclui migrations PostgreSQL e MySQL aditivas. No PostgreSQL, a tabela de launches deve pertencer explicitamente ao schema da aplicação; a migration já usa o nome qualificado. Um backup dos arquivos substituídos e do schema relevante é criado antes do rollout.

| Validação | Estado | Observação |
|---|---:|---|
| Parser do cliente Weasis | Concluída | Confirmado suporte a `weasis://` e comando `$dicom:get -w`. |
| Sintaxe PHP | Concluída | Verificada no runtime PHP do servidor. |
| Paridade pt_BR/en/es | Concluída | As chaves adicionadas são idênticas nos três catálogos. |
| Migration e schema | Concluída | Auditoria aceita `voxel` e a tabela de launches está no schema da aplicação. |
| Token sintético inválido | Concluída | Recusado sem criar launch ou consultar estudo. |
| Manifesto e abertura real | Pendente | Exige artefato desidentificado e confirmação explícita antes de gerar tráfego clínico. |
| Instalador Windows | Pendente | Depende de pacote instalador ou pasta vinculada em computador Windows. |

## Rollback

Para rollback de código, restaurar exclusivamente os arquivos do backup criado no rollout e recarregar o PHP-FPM. A tabela e o valor aditivo de auditoria podem permanecer sem afetar os demais fluxos; não remover dados ou tipos em produção como parte de um rollback emergencial.

## Referências

[1]: https://weasis.org/en/getting-started/weasis-protocol/ "Weasis Protocol"
[2]: https://weasis.org/en/basics/customize/integration/ "Weasis Integration"
