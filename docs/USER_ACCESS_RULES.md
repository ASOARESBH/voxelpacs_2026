# Regras de Acesso por Usuário

## Objetivo

As regras de acesso controlam **quando** e **de onde** uma conta tenant-scoped pode manter uma sessão no VOXEL PACS. Elas complementam, mas não substituem, papéis, permissões de módulo, grupos, 2FA e isolamento clínico por tenant e unidade.

## Configuração

As regras ficam em **Sistema → Usuários → Regras de Acesso**. O administrador do tenant pode configurar somente contas não administrativas e não pode alterar a própria regra. O superadmin da Plataforma pode administrar regras enquanto estiver no contexto de um negócio. Superadmin sem impersonação não recebe regra tenant-scoped.

| Controle | Efeito |
|---|---|
| Timeout por inatividade | Encerrar a sessão na próxima requisição após 5 a 480 minutos sem atividade. |
| Origem permitida | Aceitar somente IP exato, rede CIDR ou `localhost` definidos por linha; validado no login e em toda requisição autenticada. |
| Horário e dias | Bloquear novos logins fora da janela no fuso `America/Sao_Paulo`; sessões já abertas não são encerradas por horário. |

Nenhum controle fica ativo sem configuração explícita. Usuários sem registro em `bi_user_regras_acesso` mantêm o comportamento anterior.

## Enforcement e falha fechada

O fluxo valida IP e horário depois das credenciais e novamente antes de concluir o 2FA. A criação de sessão também revalida as regras para impedir bypass por consumidores legados de `Auth::completeLogin()`. A escolha de empresa é validada novamente no tenant selecionado.

O Router aplica timeout e IP antes do controller. Falha ao consultar a regra, IP não resolvido ou origem fora da lista bloqueiam a requisição; em caso de bloqueio a sessão é encerrada. A janela de horário é deliberadamente avaliada somente em novos logins, conforme a política aprovada.

## Auditoria

As alterações e os bloqueios são gravados na categoria `acesso`, sempre com `tenant_id` explícito. A auditoria não armazena IP permitido, CIDR, horários completos ou outros valores sensíveis da regra; registra somente o tipo de controle e contagens necessárias para rastreabilidade operacional.

## Dados e migrations

`bi_user_regras_acesso` possui unicidade em `(user_id, tenant_id)`, chaves para usuário e tenant, e índices de consulta por tenant. As migrations PostgreSQL e MySQL/MariaDB são aditivas e idempotentes. O rollback de uma regra é feito desativando os controles pela interface, não removendo a tabela em produção.

## Validação

Antes da implantação, valide sintaxe PHP, paridade de i18n e as regras de normalização. O teste salvo `test/testar_logica_regras_acesso.php` cobre IP/CIDR válido e inválido, uma janela diária válida e a ausência de regras automáticas em contas existentes. Testes de bloqueio real devem usar uma conta administrativa de homologação e origem previamente autorizada; não criar usuários clínicos de teste.
