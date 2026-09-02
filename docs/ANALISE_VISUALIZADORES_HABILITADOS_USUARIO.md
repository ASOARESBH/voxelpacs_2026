# Análise — Visualizadores habilitados por usuário

## Objetivo

Criar uma camada de restrição por usuário para as opções de abertura de estudo apresentadas na Worklist. A restrição deve ser aplicada tanto na interface quanto no ponto de abertura protegido no backend, sem ampliar a disponibilidade definida para o tenant.

## Requisitos confirmados da solicitação

O padrão inicial é permissivo: usuários sem configuração individual mantêm acesso aos visualizadores disponíveis. A alteração deliberada por administrador deve ser isolada por tenant, protegida por CSRF, auditada e não pode substituir as regras existentes de módulo, unidade, perfil médico ou acesso ao estudo.

## Mapeamento confirmado

O menu da Worklist contém quatro ações de abertura: Voxel View, VOXEL Desktop, RadiAnt Viewer e Weasis Viewer. As quatro passam por emissor autenticado no `EstudosController`; os tokens/launchers subsequentes não são a origem da autorização e não foram alterados.

## Decisões aprovadas

Administradores do negócio e superadmins mantêm todos os visualizadores habilitados. Itens indisponíveis no tenant ficam cinza e não editáveis no formulário. A mudança se aplica somente a novas aberturas; URLs ou launchers já emitidos seguem o ciclo de expiração e segurança preexistente.

## Contrato implementado

`ViewerRegistry` é a fonte única das chaves aceitas. `ViewerAccess` aplica o modelo opt-out: a ausência de linha em `bi_user_viewers` preserva o acesso legado, e apenas exceções desabilitadas são gravadas por `(user_id, tenant_id, viewer_key)`. A Worklist só renderiza opções na interseção entre disponibilidade do tenant e permissão individual; os emissores autenticados de Voxel View, VOXEL Desktop, RadiAnt e Weasis repetem a mesma guarda antes de emitir token, URI ou manifesto. A publicação técnica deve incluir o arquivo de migration PostgreSQL no schema operacional antes de qualquer configuração individual ser salva.

A disponibilidade de RadiAnt e Weasis é resolvida pela configuração existente do tenant, com o fallback legado já controlado pelo `DesktopViewerService`. A tela administrativa somente usa o estado cinza quando a indisponibilidade pode ser determinada com segurança; a abertura continua falhando fechada pela validação de configuração existente para células exclusivas.

## Segurança, auditoria e rollback

O formulário de criação e edição de usuário passou a enviar token CSRF e os dois handlers validam esse token. A alteração de exceções é registrada em `bi_audit_logs` na categoria `acesso`, sem dados clínicos. A migration é aditiva e não altera estudos, laudos, DICOM, tenant, unidade ou configuração de conexão. O rollback consiste em remover a tabela `bi_user_viewers`; a ausência dela é tratada como acesso legado habilitado.
