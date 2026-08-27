# Dependências entre Módulos

> Este é o arquivo mais importante para "avaliar impacto" antes de alterar algo. A pergunta que ele responde: **"se eu mudar X, o que mais pode quebrar?"**

## Como usar

Antes de alterar um Service/Repository/Model/evento, procure-o na coluna "Módulo" abaixo. A coluna "Consumido por" lista quem depende dele — isso deve ser checado (ou, no mínimo, mencionado no plano da tarefa) antes da alteração.

## Grafo de dependências (preencher conforme descoberto)

| Módulo/Componente | Depende de | Consumido por | Risco de mudança |
|---|---|---|---|
| `ServidorPacsController::getInstitutionStats()` (card "InstitutionNames no PACS") | `bi_pacs_estudos` (estudos sincronizados do Orthanc) **e** `bi_negocio_institution_names` (cadastro no módulo Negócios) | View `app/Views/platform/servidor_pacs/index.php` | Médio — depende de duas tabelas de módulos diferentes; se o módulo Negócios migrar de `bi_negocio_institution_names` para `bi_tenant_unidades_dicom` no futuro, este método precisa ser atualizado junto (ver `modules/servidor-pacs.md`) |
| Módulo Negócios (`bi_negocio_institution_names`, aba DICOM do form) | `bi_tenants` | `ServidorPacsController::getInstitutionStats()` (novo, desde 2026-07-10); `NegociosController::store/update/edit` | Alto — é dependência nova entre Negócios → Servidor PACS; qualquer mudança na tabela/coluna quebra o card do dashboard |
| `EstudosRepository::getUnidades()` (filtro de unidade no worklist `/estudos`) | `bi_tenant_unidades_dicom` **e** `bi_pacs_estudos` | `EstudosService`, view do worklist | Médio — já une as duas fontes com padrão parecido ao implementado agora em `getInstitutionStats()`, mas usa `bi_tenant_unidades_dicom` (não `bi_negocio_institution_names`) — as duas telas hoje leem fontes "Negócios" diferentes; ver observação em `modules/servidor-pacs.md` |
| `NegociosController` Unidades DICOM CRUD (`listarUnidades/criarUnidade/atualizarUnidade/excluirUnidade/getUnidade`) | `bi_tenant_unidades_dicom` | Rotas `/platform/negocios/{id}/unidades*` (sem consumidor de UI ainda — nenhuma view/JS chama essas rotas hoje) | Baixo — CRUD isolado, tenant-scoped em toda query; risco fica em não ter UI de verificação end-to-end nesta sessão (sem acesso a banco/servidor rodando) |
| `app/Views/layout/pacs_header.php` (topbar: badges de status, link "Gestão de Exames", nome do usuário) | `App\Core\Auth` (`perfilAtual()`, `tenantId()`, `user()`) e `/api/estudos/contadores` (`EstudosController::contadores()`, via JS) | **Todo** Controller que renderiza com o layout `'pacs'` — não é exclusivo de nenhuma rota (`/estudos`, `/gestao-exames`, `/reports/{uid}`, `/medicos`, `/relatorios/*`, etc. todos incluem este mesmo header) | Alto — qualquer condicional de visibilidade/permissão colocada aqui afeta todas as telas do sistema de uma vez; nunca assumir que é código exclusivo de uma tela sem confirmar via grep pelo `$this->view(..., 'pacs')` de quem chama. Ver `modules/gestao-exames.md` (barra de badges só para perfil Médico, 2026-08-08) |
| `app/Views/estudos/index.php` (view + `<style>`/`<script>` inline — tabela, `.wl-table-wrap`, `.wl-pagination`, `.wl-viewer-menu`) | Nada externo — é um arquivo autocontido (markup, CSS e JS da worklist, sem framework de componente) | `EstudosController::index()` (`/estudos`) **e** `EstudosController::gestao()` (`/gestao-exames`, via `renderWorklist(true)`) — **mesma view, sem cópia** (ver `modules/gestao-exames.md`) | Médio-Alto — qualquer mudança de CSS/JS aqui (ex.: layout do rodapé de paginação, dropdown "Abrir") vale para as duas rotas automaticamente; não há como mudar só uma sem duplicar o arquivo. Ver `modules/worklist-estudos.md` (rodapé fixo/`.wl-worklist-body`, 2026-08-11) |

## Dependências circulares conhecidas

`[A preencher se alguma for encontrada — ver diagnostics/dependencias-circulares.md]`

## Regra de manutenção

Toda vez que uma tarefa exigir entender "quem usa isso", e a resposta não estiver aqui, adicione a linha depois de descobrir — isso transforma uma investigação cara (grep + leitura) em uma consulta de uma linha para a próxima pessoa (ou para você mesmo, na próxima tarefa).
| `bi_tenant_orthanc_cells` + `PacsRoutingService` | `bi_pacs_servidor`, `bi_tenants`, `bi_pacs_estudos` | `PacsSyncService`, `ViewerTokenController`, `DesktopViewerService` | **Alto** — a tabela declara a fronteira de uma célula Orthanc exclusiva. Uma remoção ou alteração de status pode mudar o destino lógico dos estudos e a origem do viewer; requer backup, migration e validação sintética. |
| `ViewerTokenController` / `DesktopViewerService` para células exclusivas | `pacs_viewer_tokens`, `bi_pacs_estudos`, `bi_tenant_orthanc_cells`, `bi_viewer_desktop_config` | OHIF, VOXEL Desktop, RadiAnt e Weasis | **Alto** — ausência de tenant, URL do viewer ou configuração desktop deve falhar fechada; não reintroduzir fallback para URL, host, porta ou AE Title globais. |
