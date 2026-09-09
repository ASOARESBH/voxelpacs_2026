# Catálogo de Downloads do VOXEL Desktop

O catálogo administra pacotes ZIP do VOXEL Desktop sem expor o armazenamento de upload diretamente na web. Somente superadmins fora de impersonação podem enviar, publicar ou arquivar uma versão. O upload cria um rascunho; publicar uma versão arquiva a versão publicada anterior da mesma plataforma e canal.

Os arquivos são validados por origem de upload, extensão, assinatura ZIP, integridade do arquivo central, quantidade de entradas, caminhos internos e tamanho expandido. Cada pacote é armazenado fora de `public/`, com nome aleatório e checksum SHA-256. A rota `/desktop/download` entrega somente a versão publicada, força download e registra contagem agregável sem registrar conteúdo clínico. Uma falha de telemetria não interrompe a entrega de um pacote já publicado.

> O primeiro pacote deve ser validado por upload controlado. Nenhuma versão é publicada automaticamente pela migration, pelo deploy ou pela criação do catálogo.

O servidor precisa disponibilizar a extensão PHP `ZipArchive`; quando ela não existir, o upload falha de forma fechada com uma mensagem controlada e nenhum arquivo é persistido. Antes de publicar, o sistema confirma novamente a existência do ZIP privado. Upload, publicação e arquivamento geram eventos de auditoria administrativa contendo somente versão, plataforma, canal, status, checksum e tamanho; não são gravados conteúdo do pacote, arquivos internos, nomes de pacientes ou dados clínicos.

O CTA **Baixar VOXEL Desktop** na Worklist é independente da visibilidade do visualizador no menu `Abrir`: ele restaura o acesso ao instalador sem alterar as políticas de acesso a estudos. A telemetria de downloads preserva `user_id` e `tenant_id` quando houver sessão autenticada; para atualizações públicas do aplicativo, somente hashes de IP e user-agent são gravados quando há segredo de aplicação configurado. Sem pacote publicado, o manifesto informa indisponibilidade e não anuncia uma URL histórica inexistente.
