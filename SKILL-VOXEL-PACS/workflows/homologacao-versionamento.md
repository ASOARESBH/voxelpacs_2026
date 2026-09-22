# Workflow — Homologação por versão e host próprio

## Decisão

A homologação da aplicação será isolada por **host próprio**, e não por prefixo de caminho. O host planejado é `homolog.voxelpacs.com.br`, separado de `server.voxelpacs.com.br`. A raiz produtiva não deverá ser modificada para ativar esta fase.

A escolha evita a refatoração transversal exigida por um prefixo literal como `/1.0`. A aplicação possui assets, actions de formulários e redirects absolutos iniciados por `/`. O bootstrap também usa sessão por host sem `cookie_path` específico. Um host próprio fornece uma fronteira de cookie mais previsível e preserva os caminhos absolutos existentes.

## Identidade da versão

A versão `1.0` deve ser uma cópia rastreável do commit efetivamente servido antes da homologação. O preflight identificou o commit `4b3ea8d0fff6d64f4e94170e2d76e06351012a51` como referência do checkout produtivo. O worktree local `versao/1.0` foi criado a partir desse commit, e contém `VERSAO.txt` com o valor `1.0`.

A branch `origin/main` está 226 commits à frente dessa referência. Portanto, a branch principal atual não pode ser usada como prova de equivalência byte a byte com o runtime produtivo. Qualquer sincronização de código deverá resolver primeiro o drift medido no checkout real.

O marcador de versão é lido somente quando existe e contém uma versão semântica curta válida. Na ausência do arquivo, a raiz mantém o comportamento anterior e não exibe uma versão inventada.

## Resultado do preflight do runtime

O checkout produtivo em `/var/www/voxelpacs/app` está na branch `main`, mas possui 306 entradas no `git status`. A comparação por hash Git entre os 725 arquivos rastreados pelo commit de produção encontrou 623 coincidências, 102 divergências e nenhum arquivo rastreado ausente. A divergência inclui arquivos rastreados modificados e arquivos extras em áreas de aplicação, banco, documentação, testes, storage e deployment.

Esse estado impede declarar que um novo worktree baseado apenas em `origin/main` representa o runtime atual. O processo correto é identificar os arquivos de código que constituem mudanças legítimas, registrar os que devem entrar no Git e manter dados de runtime, segredos, logs e storage fora do repositório.

## Topologia planejada

```text
homolog.voxelpacs.com.br
        |
        v
Nginx server block separado
        |
        v
 /var/www/voxelpacs/versoes/current/public
        |
        v
worktree versao/1.0
        |
        v
PostgreSQL compartilhado, somente com migrations aditivas e compatíveis
```

O diretório `versoes/` ainda não existe no servidor e não foi criado nesta fase. A criação remota exigirá uma janela operacional própria, backup validado, preflight do Nginx, certificado TLS e plano de rollback.

## Limites desta fase

Esta fase não cria DNS, vhost, certificado, diretório remoto, symlink, `.env`, pool FPM, worker, migration, banco, job, e-mail ou alteração da raiz produtiva. Também não copia o `.env` produtivo para o worktree. Segredos e storage permanecem fora do Git.

O deploy oficial atual aceita apenas commits alcançáveis por `origin/main`, publica somente os arquivos alterados no commit recebido e recarrega o PHP-FPM. Ele não executa Composer, migration, Nginx, worker ou invalidação explícita de OPcache. A homologação por versão deverá ganhar uma etapa explícita de publicação somente depois que o drift for resolvido e revisado.

## Banco e processos assíncronos

O PostgreSQL possui a base `voxelpacs_homolog` com os schemas `homologacao`, `public` e `voxelpacs_mysql_source`. A existência desses objetos não autoriza aplicar migrations. Como a raiz e a homologação compartilham o mesmo servidor de banco, migrations da homologação devem ser aditivas e compatíveis com o código produtivo, ou a homologação deverá receber uma base isolada em decisão posterior.

O worker de delivery global não é iniciado por este workflow. O `tenant-agent` existente continua sendo um processo do host e não é duplicado para a versão. Fluxos assíncronos serão considerados dependência compartilhada até existir isolamento explícito por versão.

## Próximos gates

O próximo gate é a reconciliação do drift produtivo sem expor ou versionar dados sensíveis. Em seguida, a branch da versão deve receber o marcador, o código de exibição da versão e testes estáticos. Só depois poderão ser preparados, separadamente, um pacote de release, uma configuração Nginx de homologação e um plano de aplicação.

A ativação operacional exige validação de DNS, TLS, permissões privadas do worktree, sessão isolada, smoke tests de autenticação e autorização, além da confirmação de que a raiz continua respondendo com seu código e seus assets originais. Rollback deve consistir em remover ou desativar o vhost de homologação e apontar o symlink da versão para o release anterior, sem apagar dados clínicos.

## Referências

[1]: ../architecture/infraestrutura.md "Arquitetura de infraestrutura do VOXEL PACS"
[2]: ../../scripts/deploy.sh "Script versionado de deploy do projeto"
[3]: ../../scripts/update.sh "Script versionado de atualização do projeto"
