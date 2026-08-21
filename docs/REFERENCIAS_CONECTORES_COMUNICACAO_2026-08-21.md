# Referências oficiais — Conectores de Comunicação

## Telegram Bot API

Fonte: <https://core.telegram.org/bots/api>

A API de bots é HTTP/HTTPS. As chamadas seguem `https://api.telegram.org/bot<token>/METHOD_NAME`, aceitam POST com JSON, e retornam um objeto JSON com o campo booleano `ok`, além de `description` e `error_code` quando houver falha. A implementação do VOXEL PACS deve usar `getMe` apenas para teste da credencial e `sendMessage` para notificações. O token nunca pode ser gravado em logs, URLs de interface ou respostas de erro.

## Evolution API

Fonte: <https://evolutionapi-evolution-api-90.mintlify.app/api/overview>

A Evolution API segue REST com payload JSON e cabeçalho `apikey`. O endpoint de mensagem está na família `/message`; a documentação do produto indica o uso de instância por operação e cita `/instance/connectionState/{instance}` para verificar estado. A URL base deve ser validada para HTTPS/HTTP permitido pelo administrador, sem aceitar hosts locais, credenciais na URL ou redirecionamentos inseguros. A integração deve usar timeouts curtos, sanitizar números de destino e falhar de forma isolada.

## Decisões de implementação

- Notificações somente após a confirmação da transação do laudo.
- Cada conector possui tratamento de exceção próprio; falha externa não interfere na assinatura ou liberação.
- Logs guardam metadados, HTTP status e respostas truncadas/sanitizadas; nunca tokens ou API keys.
- As configurações são globais da plataforma e exclusivas de superadmin.
- A configuração inicial permanece inativa até teste e ativação explícita do superadmin.
