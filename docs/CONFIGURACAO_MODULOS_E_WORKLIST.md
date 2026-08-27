# Configuração global de módulos e atualização parcial da Worklist

## Objetivo

Este documento define o controle funcional global do VOXEL PACS. A configuração é administrada exclusivamente pelo **superadmin** no Painel da Plataforma e se aplica a toda a aplicação. O administrador de negócio continua responsável apenas por usuários e permissões individuais da própria empresa; ele não visualiza, não acessa e não altera controles globais de módulos ou de atualização da Worklist.

> **Princípio de segurança:** uma permissão individual nunca reativa um módulo bloqueado globalmente. Grupos não concedem módulos e continuam limitando somente o escopo clínico já estabelecido.

## Precedência de acesso

| Ordem | Camada | Efeito |
|---|---|---|
| 1 | Catálogo central | Reconhece somente chaves de módulo e rotas oficialmente mapeadas. |
| 2 | Sessão autenticada | Exige sessão válida antes de qualquer decisão. |
| 3 | Trava global da Plataforma | Um `globally_enabled = 0` bloqueia menu e rota do módulo para toda a aplicação, inclusive para superadmin. |
| 4 | Permissão individual legada | Usa `Auth::hasModule()` e as permissões existentes em `bi_user_permissoes`. |
| 5 | Regras clínicas existentes | Preservam tenant efetivo, grupos, modalidades, médico responsável, 2F e os controles específicos de cada fluxo. |

Não há camada de disponibilidade por empresa nesta funcionalidade. A separação multi-tenant clínica e administrativa continua sendo aplicada pelos controles preexistentes do PACS, sem dar aos grupos ou ao administrador de negócio poder sobre a disponibilidade global da aplicação.

## Administração exclusiva da Plataforma

O superadmin acessa `GET /platform/configuracao-modulos`. As rotas de gravação são `POST /platform/configuracao-modulos/salvar` e `POST /platform/configuracao-modulos/estudos/salvar`. O roteador protege qualquer caminho `/platform/*` antes da resolução do controller com `Auth::isPlatformAdmin()`.

As rotas antigas `/configuracoes/modulos/*` foram removidas da área PACS. Portanto, o menu lateral do administrador de negócio não exibe Configuração de Módulos e a URL antiga não oferece uma superfície administrativa alternativa. Toda gravação exige CSRF e registra apenas chave de módulo, booleanos de antes/depois e parâmetros técnicos não clínicos. Eventos de auditoria nunca devem conter pacientes, laudos, pedidos, conversas, anexos, credenciais ou tokens.

## Catálogo de módulos

| Chave central | Módulo apresentado | Permissão individual legada | Prefixos de rota protegidos |
|---|---|---|---|
| `estudos` | Estudos / Worklist | `estudos` | `/estudos`, `/api/estudos`, `/api/download-lote`, `/desktop/download`, `/api/desktop` |
| `agendamentos` | Agendamentos | `agendamentos` | `/agendamentos` |
| `gestao_exames` | Gestão de Exames | `gestao_exames` | `/gestao-exames`, `/api/gestao-exames` |
| `pacs_exames` | Imagens DICOM | `imagens_dicom` | `/pacs/exames` |
| `pacs_modalidades` | Modalidades PACS | `imagens_dicom` | `/pacs/modalidades` |
| `cad_medicos` | Médicos | `medicos` | `/medicos`, `/api/medicos`, `/api/templates` |
| `cad_unidades` | Unidades | `medicos` | `/unidades`, `/api/unidades` |
| `cad_modalidades` | Modalidades | `medicos` | `/modalidades` |
| `sla_regras` | SLA / Regras | `sla` | `/sla-regras` |
| `rel_exames` | Relatório de Exames | `relatorios` | `/relatorios/exames` |
| `rel_medicos` | Relatório de Médicos | `relatorios` | `/relatorios/medicos` |
| `rel_sla_medicos` | SLA Médicos | `relatorios` | `/relatorios/sla-medicos` |
| `rel_auditoria` | Auditoria | `relatorios` | `/relatorios/auditoria` |
| `usuarios` | Usuários | `usuarios` | `/usuarios` |
| `configuracoes` | Configurações | `configuracoes` | `/configuracoes` |

Algumas permissões legadas abrangem mais de um submódulo. O catálogo pode restringir cada submódulo globalmente, mas nunca amplia a autorização acima da permissão individual já existente.

## Persistência e migrations

| Tabela | Escopo | Uso |
|---|---|---|
| `bi_system_module_config` | Plataforma | Estado global habilitado ou bloqueado de cada chave de módulo. |
| `bi_system_config` | Plataforma | Preferências globais de atualização de Estudos. |

As migrations `2026-08-26_configuracao_modulos_{postgresql,mysql}.sql` criam somente a tabela global de módulos. As migrations `2026-08-26_configuracao_global_plataforma_{postgresql,mysql}.sql` criam a tabela global de preferências. A execução PostgreSQL é controlada por aplicador com transação e `commit` explícito.

Uma tabela tenant-scoped de uma tentativa anterior pode continuar presente em ambientes já atualizados, porém não é lida nem gravada pelo código atual e não deve ser removida automaticamente em produção. Qualquer descarte futuro exige inventário, backup aprovado e migration específica.

## Guard de rotas e menus

`ModuleAccess::canAccessUri()` é executado pelo roteador antes do controller. O guard avalia trava global e permissão individual para as rotas mapeadas, enquanto o menu usa a mesma decisão. Prefixos só correspondem à rota inteira ou a início seguido de `/`, evitando colisões entre caminhos.

Rotas públicas, como a validação pública de auditoria, permanecem fora do catálogo. A configuração de módulos é uma rota administrativa da Plataforma e é protegida pelo guard específico de superadmin, não pelo próprio catálogo de módulos.

## Grupos e escopo clínico

Grupos **não concedem módulos**. Eles continuam limitando apenas o escopo clínico por modalidade e os filtros associados. Assim, um vínculo de grupo não concede acesso à Worklist, relatórios, cadastros ou Gestão de Exames, nem alarga a visibilidade entre empresas.

## Atualização parcial da Worklist

O endpoint autenticado `GET /api/estudos/worklist-fragmento` reutiliza consulta, filtros e escopo da Worklist. O navegador preserva a query atual, usa credenciais same-origin e substitui somente `#wl-table-body` e `#wl-pagination`; não há reload completo, escrita de dados ou mudança de filtro durante o ciclo.

O refresh de Estudos é global, habilitado por padrão a cada **60 segundos**, e pode ser ajustado pelo superadmin entre **15 e 600 segundos**. A preferência é armazenada em `bi_system_config` e se aplica a todos os usuários autorizados. A arquitetura permite adicionar outras preferências globais, sem reutilizar a chave de Estudos.

| Situação | Comportamento do refresh |
|---|---|
| Página oculta | Pausa a solicitação. |
| Campo de filtro em foco | Pausa para não interromper a digitação. |
| Seleção em massa ou linha marcada | Pausa para preservar o contexto operacional. |
| Modal, offcanvas, dropdown ou menu de ação aberto | Pausa até o encerramento da interação. |
| Ação AJAX de assumir estudo em andamento | Pausa por `window.__worklistActionInProgress` até a conclusão da chamada. |
| Falha transitória de rede | Mantém a tabela exibida e tenta novamente no ciclo seguinte. |

## Validação operacional mínima

| Verificação | Resultado esperado |
|---|---|
| Superadmin em `/platform/configuracao-modulos` | Vê módulos globais, refresh global e explicação de precedência. |
| Administrador de negócio no PACS | Não vê o atalho de módulos; `/platform/configuracao-modulos` retorna 403. |
| URL antiga `/configuracoes/modulos` | Não apresenta tela administrativa e retorna 404. |
| Módulo globalmente bloqueado em teste revertido | Menu e rota protegida são negados, inclusive para superadmin. |
| Validação pública de auditoria | Continua fora do guard e acessível sem sessão. |
| Worklist em repouso | Uma chamada parcial autenticada é feita após o intervalo; URL, filtros e paginação são preservados. |
| Worklist em interação | Não há atualização enquanto houver foco, seleção, modal, menu ou ação AJAX mutável. |

## Arquivos de referência

Os contratos estão concentrados em `app/Core/Access/ModuleCatalog.php`, `app/Core/Access/ModuleAccess.php`, `app/Core/SystemConfig.php`, `app/Controllers/Platform/ModuloConfiguracoesController.php`, `app/Core/Router.php`, `app/Controllers/EstudosController.php`, `app/Views/platform/configuracao_modulos/index.php`, `app/Views/estudos/index.php`, `public/assets/js/worklist-auto-refresh.js` e nas migrations globais de módulos e configuração.
