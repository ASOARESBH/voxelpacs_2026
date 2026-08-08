# Bugfix — botão Pedido sem ação

## Sintoma

Na tela `/gestao-exames`, o botão **Pedido** era renderizado, mas o clique não abria a modal de anexação. O console apresentava `Uncaught SyntaxError: Unexpected token '<'` na página da Gestão de Exames.

## Causa raiz

A view `app/Views/estudos/index.php` já possuía um bloco `<script>` principal iniciado antes do JavaScript da Worklist. A implementação da modal adicionou outro par de tags `<script>` dentro desse bloco, condicionado por PHP. Quando o modo Gestão era renderizado com permissão de gestão, o navegador recebia literalmente uma tag `<script>` no meio do JavaScript, interpretava o caractere `<` como código e interrompia o parse do bloco inteiro. Como consequência, nenhum listener `.pedido-trigger` era registrado e o clique parecia não executar ação.

## Correção

O par de tags aninhado foi removido. O callback da modal permanece condicionado pelo PHP, mas agora é JavaScript válido dentro do único bloco principal da view. Também foi corrigida a chamada de contadores da sidebar, que usava `/estudos/contadores` embora a rota existente seja `/api/estudos/contadores`; esse erro 404 era secundário e aparecia no mesmo console.

## Validação

A correção foi validada com lint PHP, teste de contrato da Gestão de Exames, teste de MIME/path privado e extração do JavaScript efetivamente emitido pela view seguida de `node --check`. O teste de contrato também impede o retorno de tags `<script>` aninhadas e da URL incorreta de contadores.

## Ajuste posterior — captura pela câmera

O botão **Câmera** agora utiliza um input exclusivo com `accept="image/*"` e `capture="environment"`. Isso evita que a lista mista de PDF e imagens seja interpretada pelo navegador móvel apenas como importação de arquivo. A foto selecionada é mantida em memória como `File` e inserida no `FormData` com `body.set('pedido', cameraFile, ...)`, preservando o endpoint e a validação de MIME existentes. O botão **Importar arquivo** continua usando o input geral para PDF e imagens.

Em iOS e Android, o navegador ainda pode solicitar autorização de câmera ou exibir um menu do próprio sistema conforme a política do dispositivo. A página precisa estar em HTTPS, como ocorre no ambiente publicado, e o usuário precisa permitir o acesso quando solicitado.
