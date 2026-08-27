# Incidente — indisponibilidade da Worklist por permissão de arquivo

**Data:** 2026-08-27
**Componente:** rota autenticada de Estudos / Worklist

## Sintoma

A rota de Estudos apresentava a página genérica de erro interno antes de renderizar a Worklist.

## Causa confirmada

O autoloader localizou o controlador da Worklist, porém o processo PHP-FPM não tinha permissão de leitura para o arquivo. O controlador estava presente e tinha sintaxe válida, mas fora do conjunto de permissões legível pelo processo que atende a aplicação.

## Correção aplicada

Foi restaurada apenas a permissão de leitura do arquivo existente e o PHP-FPM foi recarregado. Não houve mudança de código, schema, dados, consultas clínicas, configurações de PACS ou regras de autorização.

## Validação

Após a recarga, a rota autenticada voltou a renderizar. O controlador passou a ser legível pelo processo de aplicação e manteve validação de sintaxe PHP. A análise de permissões de outros controladores confirmou que arquivos com modo restrito podem ser corretos quando pertencem ao grupo do pool PHP; portanto, nenhuma permissão adicional foi alterada preventivamente.

## Prevenção

Em futuras publicações, arquivos novos ou substituídos devem preservar a propriedade e o modo do destino. Quando não houver arquivo de referência, o modo precisa permitir leitura pelo usuário/grupo efetivo do pool PHP-FPM, antes da recarga do serviço.
