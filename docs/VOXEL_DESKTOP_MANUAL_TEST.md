# Voxel Desktop — Ativação e Teste Manual Isolado

## Objetivo

O control-plane permite editar um destino tenant-scoped e habilitá-lo de forma
explícita em **homologação** ou **produção**, sem vincular a ação a disparo
automático após a liberação de um laudo. O fluxo de teste manual é separado da
outbox e dos jobs de produção.

## Estados de destino

| Ambiente | Pode habilitar | Disparo após liberação | Uso permitido nesta fase |
|---|---:|---:|---|
| Homologação | Sim, mediante confirmação explícita | Sempre desativado | Teste de conectividade e um teste manual preparado |
| Produção | Sim, com confirmação adicional | Sempre desativado nesta fase | Somente conectividade; não cria envio automático |

O valor de `disparar_na_liberacao` permanece `false` em todo salvamento,
edição ou habilitação desta entrega. A automação futura é uma alteração
independente e requer avaliação, testes e autorização próprios.

## Teste manual sem fila

O superadmin pode preparar **um único teste** para um destino habilitado de
homologação, usando um laudo liberado daquele mesmo tenant. A preparação cria
um registro de uso único com expiração curta e auditoria sanitizada. Ela não
cria registros em `pacs_voxel_desktop_outbox` nem em
`pacs_voxel_desktop_jobs`.

O Router autenticado consulta uma rota dedicada de teste, recebe somente o
teste associado ao seu par Router/Site e baixa o PDF por rota protegida. A
geração do XML e a escrita local continuam no Router. O teste só é marcado
como concluído após a confirmação explícita do Router.

## Guardas obrigatórias

1. Somente superadmin sem impersonação pode editar ou habilitar.
2. A edição exige CSRF, tenant existente, token protegido e rota de origem.
3. Habilitar homologação exige checkbox de confirmação; produção exige uma
   confirmação adicional e não habilita disparo automático.
4. O teste manual exige destino habilitado **de homologação**, confirmação de
   uso único e laudo liberado do mesmo tenant.
5. A API do Router exige Router ID, Site ID, token e lease do teste; não aceita
   IDs, caminhos, tokens ou artefatos arbitrários.
6. Logs e auditoria registram somente IDs técnicos, ambiente, estado, hash
   truncado e timestamps. Eles não registram pacientes, conteúdo de laudo,
   PDF, XML, URL, token, diretório local ou referência do receptor.
7. Não há retry automático, polling adicional, worker novo ou gatilho na
   liberação de laudo nesta entrega.
