# Homologação por versão — preflight da Opção B

## Conclusão

A Opção B foi publicada em host próprio. O worktree `versao/1.0` foi criado a partir do commit efetivamente identificado no checkout produtivo, e não a partir de `origin/main`. A release foi publicada separadamente, com banco `voxelpacs_homolog` e integrações de delivery desabilitadas.

O preflight encontrou drift relevante entre o checkout produtivo e seu commit Git de referência. A homologação não substitui nem limpa o checkout produtivo; ela executa a versão rastreada em release própria e mantém o drift documentado para reconciliação futura.

## Estado local

| Item | Resultado |
|---|---|
| Branch de implementação | `feat/homolog-versioning-option-b-20260922` |
| Base da implementação | `origin/main` em `9871122c0f120e241272393c20ffef47b3752b1d` |
| Branch da versão | `versao/1.0` |
| Base fiel do worktree da versão | `4b3ea8d0fff6d64f4e94170e2d76e06351012a51` |
| Marcador | `VERSAO.txt` com `1.0` |
| `.env` no worktree | Ausente |
| Vendor copiado | Ausente |
| Banco ou runtime alterados | Não |

A separação entre a branch de implementação e o worktree da versão é intencional. A branch de implementação receberá mudanças compatíveis para revisão. A branch da versão preserva a base real do runtime até que o drift seja conciliado.

## Evidência do runtime

O checkout remoto está em `main` no commit `4b3ea8d0fff6d64f4e94170e2d76e06351012a51`, mas reporta 306 entradas no `git status`. A comparação dos arquivos rastreados pelo commit encontrou 725 arquivos analisados, dos quais 623 coincidem e 102 divergem; nenhum dos 725 arquivos estava ausente.

A classificação sanitizada do drift mostrou alterações rastreadas e arquivos extras principalmente em `app`, `database`, `tests`, `public`, `docs`, `routes`, `lang`, `storage`, `deploy`, `bin`, `migrations` e documentos de skill. Nenhum nome de paciente, UID DICOM, token, senha ou conteúdo de laudo foi incluído neste relatório.

O servidor tem aproximadamente 62 GiB livres. O checkout ativo ocupa aproximadamente 485 MiB, `shared` 76 MiB e `releases` 13 MiB. O espaço físico não é o bloqueio imediato; o bloqueio é a equivalência do código e a separação do banco/processos.

## Topologia publicada

O DNS `homolog.voxelpacs.com.br` aponta para `167.233.254.41`, com TLS válido e vhost separado. A aplicação é servida por `/var/www/voxelpacs/releases/1.0/public`; o checkout produtivo continua em `/var/www/voxelpacs/app`. A release possui `.env` fora do Git, apontando para `voxelpacs_homolog`, sem credenciais de produção e com delivery/Philips desabilitados.

A aplicação usa caminhos absolutos para assets, formulários e redirects. O bootstrap usa sessão com `cookie_path=/` e não define `cookie_samesite` no runtime atual. Essa evidência sustenta o uso de host próprio em vez de prefixo literal `/1.0`.

## Isolamento validado

A homologação é executada e testada somente no worktree `/home/ubuntu/voxelpacs_2026_versions/1.0`, branch `versao/1.0`. O wrapper `scripts/build-homolog.sh` bloqueia a compilação na raiz ou em outra branch; o teste estático `tests/homologation_isolation_static.php` confirmou que `.env`, storage runtime e dados sensíveis não estão rastreados. A compilação cria dependências ignoradas no worktree e o pacote fora da árvore, sem cópia automática ou escrita em `/home/ubuntu/voxelpacs_2026`.

O runtime remoto foi validado por metadados: release `1.0` presente, raiz de homologação distinta da raiz produtiva, banco homologado selecionado e flags de delivery desligadas. Nenhum worker global, e-mail real, migration, DICOM, Orthanc ou transmissão Philips faz parte deste ciclo funcional.

## Referências

[1]: ../SKILL-VOXEL-PACS/workflows/homologacao-versionamento.md "Workflow de homologação por versão"
[2]: ../SKILL-VOXEL-PACS/architecture/infraestrutura.md "Arquitetura de infraestrutura"
[3]: ../scripts/deploy.sh "Script de deploy versionado"
