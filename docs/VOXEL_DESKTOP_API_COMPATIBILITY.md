# VOXEL Desktop — Contrato de API e Compatibilidade

## Objetivo

Este documento registra o contrato vigente entre a Worklist do VOXEL PACS e o VOXEL Desktop. O fluxo abre somente o estudo autorizado em um aplicativo local compatível com o protocolo Weasis, sem expor URL, credencial ou topologia do Orthanc ao navegador ou ao computador do usuário.

## Fluxo vigente

| Etapa | Componente | Controle aplicado |
|---|---|---|
| 1 | Worklist | O usuário seleciona **VOXEL Desktop** em um estudo para o qual possui `view_exames`. |
| 2 | `EstudosController@abrirVoxelDesktop` | Busca tenant-scoped, respeita escopo de modalidade e registra a auditoria do viewer. |
| 3 | `DesktopStudyLaunchService::create()` | Cria token opaco de 256 bits, armazena somente o hash, vincula tenant, usuário, estudo, servidor PACS e expiração de 120 segundos. |
| 4 | Browser | Aciona `voxel://` com comando `$dicom:get -w` para o manifesto HTTPS. A URI `weasis://` é disponibilizada apenas como compatibilidade para instalações legadas. |
| 5 | Manifesto público | Exige token, assinatura HMAC e expiração. O manifesto é consumível uma única vez e descreve somente as séries e instâncias daquele estudo. |
| 6 | Instâncias públicas | Cada solicitação revalida o launch e confirma que a instância pertence ao `orthanc_study_id` autorizado. O DICOM é transmitido em streaming autenticado pelo proxy da API. |

## Controles de segurança

O servidor valida o estudo antes de emitir o launch e não permite fallback global em sessões tenant-scoped. O token não contém estudo, paciente, servidor ou credenciais; tais informações permanecem no banco e são resolvidas somente depois da validação. As rotas públicas retornam `Cache-Control: no-store`, `Referrer-Policy: no-referrer` e não encaminham cabeçalhos internos do Orthanc.

O proxy de instância fixa `Content-Type: application/dicom`, elimina o carregamento integral de instâncias em memória e não registra identificadores DICOM em logs técnicos. Se o token expirar, for reutilizado no manifesto, estiver revogado ou a instância não pertencer ao estudo, a operação falha fechada.

## Pacote de 2026-08-26

O pacote recebido em 2026-08-26 contém uma primeira versão do mesmo conceito, baseada em MySQL/Hostgator e nas rotas `/api/desktop/manifest/*` e `/api/desktop/instance/*`. Ele **não deve ser aplicado diretamente** ao ambiente vigente: o VOXEL PACS utiliza PostgreSQL, schema `voxelpacs_mysql_source`, tabela exclusiva `bi_desktop_study_launches` e rotas `/desktop-launch/*`.

| Elemento do pacote | Situação na API vigente |
|---|---|
| Enum `viewer = voxel` | Já coberto por migration PostgreSQL aditiva. |
| Token opaco por estudo | Já implementado em tabela dedicada, com tenant e servidor de origem. |
| Manifesto Weasis | Implementado, com token assinado, consumo único e validação de UIDs. |
| Proxy de instância | Atualizado para streaming, eliminando o buffer integral da instância. |
| Protocolo `voxel://` | Estabelecido como protocolo primário; `weasis://` permanece como compatibilidade documentada. |
| SQL anexado | Incompatível com PostgreSQL e, por isso, não foi executado. |
| Grants da tabela de launches | A migration PostgreSQL `2026-08-29_voxel_desktop_launch_grants_postgresql.sql` concede ao papel da aplicação somente acesso ao schema, tabela e sequência de launches; ela não altera estudos, instâncias, tokens existentes ou auditorias. |

## Homologação obrigatória

Use uma conta clínica autorizada e estudo de homologação desidentificado. Verifique, sem inspecionar dados desnecessários, que o clique na Worklist abre uma única instância do VOXEL Desktop, que o estudo correto é carregado e que uma URL de manifesto expirada não é aceita. Não copie URIs de protocolo, token, URLs de manifesto ou URLs de instância para tickets, capturas ou repositórios.

## Rollback

O rollback do patch consiste em restaurar os três arquivos PHP de backup e remover o teste/documentação adicionados. Os grants da tabela de launches podem permanecer, pois apenas permitem ao papel da aplicação operar launches temporários já previstos; revogue-os somente em rollback explícito da funcionalidade, após confirmar que não há abertura Desktop em curso. A tabela de launches e o valor `voxel` no enum podem permanecer, pois são aditivos e não afetam OHIF, RadiAnt ou Weasis. Não remova o enum sem antes confirmar que não há auditorias históricas desse viewer.
