# Módulo Gestão de Exames e Pedido Médico

## Status

A feature está implementada no clone `voxelpacs_2026`. A tela `/gestao-exames` reutiliza a Worklist de Estudos, acrescenta a coluna **PEDIDO** ao lado de **SOLICITANTE** e mantém o fluxo médico separado. Em Gestão de Exames, o usuário não recebe as ações **Abrir**, **Assumir** ou **Laudo**; administradores e analistas recebem somente a ação **Pedido** para anexar, substituir, consultar e remover o documento.

> O arquivo físico do pedido permanece privado e fora de `public/`. O médico consulta o documento no report por um proxy autenticado, com o mesmo contexto de tenant do estudo.

## Componentes entregues

| Camada | Implementação |
|---|---|
| Navegação | Link traduzido **Gestão de Exames** abaixo de Agendamentos em `app/Views/layout/pacs_header.php`; o item é ocultado para usuários identificados como médicos. |
| Worklist | `EstudosController::gestao()` reutiliza filtros, paginação, resumo e escopo multi-tenant de `renderWorklist(true)`. O `LEFT JOIN` acrescenta metadados do pedido. |
| Interface | `app/Views/estudos/index.php` adiciona coluna PEDIDO, badge **Pedido anexado**, link de consulta, botão Pedido e modal responsiva com **Importar arquivo** e **Câmera**. O input de câmera usa `capture="environment"`. |
| Persistência | `database/migrations/2026-08-08_bi_pacs_estudos_pedidos.sql` cria `bi_pacs_estudos_pedidos`, com unicidade `(tenant_id, estudo_id)`, hash, MIME, tamanho, autoria e caminho privado. |
| Backend | `PedidoMedicoRepository` mantém todas as queries com tenant; `PedidoMedicoService` valida conteúdo real, extensão compatível, tamanho de até 15 MB, nome interno aleatório, SHA-256, substituição e remoção do arquivo. |
| HTTP | `GestaoExamesController` expõe anexar, remover e proxy de arquivo. Upload/remoção exigem `manage_pedidos` e CSRF; consulta exige autenticação e validação do tenant. |
| Permissão | `manage_pedidos` foi incluída para `admin`, `analista` e `superadmin`. Usuários `viewer` podem consultar pedidos existentes, mas não alterá-los. A autorização central também consulta `bi_medicos` e bloqueia alteração por conta vinculada a médico no tenant, mesmo se o papel global possuir a permissão nominal. |
| Report | `ReportService` carrega o pedido pelo `estudo_id` e tenant. O topo e o card do exame exibem status, nome, tamanho e link **Consultar**. O documento não é copiado para o texto do laudo nem para a camada do Copilot. |
| Segurança física | `storage/uploads/.htaccess` bloqueia acesso direto e `.gitignore` mantém documentos fora do versionamento. O proxy aplica `realpath`, validação de prefixo, `Content-Type`, `Content-Disposition: inline`, `no-store` e `nosniff`. |
| Idiomas | As chaves novas estão sincronizadas em `lang/pt_BR.php`, `lang/en.php` e `lang/es.php`. |

## Fluxo operacional

O operador abre **Worklist → Gestão de Exames**, localiza o estudo e seleciona **Pedido**. A modal informa o paciente, mostra o documento atual quando existente e oferece importação do computador ou captura por câmera. O navegador envia um `multipart/form-data` com CSRF para `/api/gestao-exames/estudos/{id}/pedido`. O servidor valida o MIME pelo conteúdo real, grava o documento em `storage/uploads/pedidos_medicos/{tenant_id}/{estudo_id}/`, registra os metadados e substitui o documento anterior de forma controlada.

Após o retorno, a Worklist é atualizada e a coluna PEDIDO passa a mostrar **Pedido anexado**. O operador pode consultar o documento pelo link ou removê-lo, caso tenha a permissão de gestão. A remoção apaga o registro e tenta apagar o arquivo físico, registrando falha de limpeza no log sem interromper a resposta principal.

Quando o médico abre `/reports/{study_uid}`, o report consulta os metadados do pedido dentro do tenant do estudo. Se houver documento, o topo e o card **Exame** exibem um atalho autenticado para visualização. O link não expõe o caminho físico nem permite acesso de outro tenant.

## Aplicação em ambiente de produção

A migration precisa ser executada uma vez no banco do ambiente antes de abrir a Worklist nova. Ela é compatível com MySQL 5.7/MariaDB do ambiente HostGator e usa `CREATE TABLE IF NOT EXISTS`.

```sql
-- executar database/migrations/2026-08-08_bi_pacs_estudos_pedidos.sql
DESCRIBE bi_pacs_estudos_pedidos;
```

O diretório efetivo de armazenamento deve ter permissão de escrita para o usuário do PHP. O limite de aplicação é 15 MB por pedido; o limite global de `upload_max_filesize` e `post_max_size` do PHP também precisa ser igual ou superior a esse valor mais o overhead do multipart.

## Verificações executadas

Foram executados lint PHP nos arquivos novos e alterados, teste estático de contrato em `tests/gestao_exames_static.php`, diagnóstico oficial de paridade i18n e `git diff --check`. O teste estático confirma as rotas, o branch administrativo sem ações médicas, a modal de importação/câmera, o contrato do upload, os filtros multi-tenant, a integração no report e a paridade das 364 chaves de idioma.

A validação contra dados reais e a execução da migration no banco de produção dependem das credenciais e do ambiente de implantação, que não estão presentes no clone local.

## Contexto efetivo no menu Gerenciar

O menu **Gerenciar** recebe somente o identificador do estudo no navegador. A aplicação não usa esse identificador como autorização: antes de carregar chat, descrição, prioridade, pedido ou sugestões de modalidade, `GestaoExamesController` resolve a empresa efetiva pelo próprio estudo em `GestaoExamesService::resolveTenantForStudy()`.

| Perfil e contexto | Regra de resolução | Resultado |
|---|---|---|
| Administrador ou analista com empresa ativa | O estudo deve pertencer ao `tenant_id` da sessão. | O contexto é carregado somente dentro da empresa selecionada. |
| Superadmin de plataforma fora de impersonação | O estudo pode ser localizado sem empresa em sessão, mas precisa ter `tenant_id` persistido. | O controlador usa o tenant real do estudo em todas as consultas e gravações subsequentes. |
| Médico ou perfil sem permissão administrativa | A autorização é negada antes de resolver o contexto. | Nenhum dado de gerenciamento é retornado. |
| Estudo sem empresa ou fora do escopo | Não existe tenant efetivo utilizável. | A operação é negada; para superadmin a mensagem orienta resolver o roteamento, sem liberar alterações. |

O padrão também é aplicado a anexo/remoção de pedido, prioridade, prévia e aplicação de descrição, alertas de prioridade e sugestões por modalidade. As camadas internas continuam recebendo um `tenant_id` obrigatório, o que evita ampliar consultas, writes ou auditorias entre empresas. O script do modal recebe versão própria para assegurar que a busca de sugestões também envie `estudo_id` e use o contexto resolvido no servidor.
