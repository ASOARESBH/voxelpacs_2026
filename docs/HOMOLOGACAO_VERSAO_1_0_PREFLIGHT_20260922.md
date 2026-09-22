# Homologação por versão — preflight da Opção B

## Conclusão

A Opção B foi iniciada somente no clone. O worktree `versao/1.0` foi criado a partir do commit efetivamente identificado no checkout produtivo, e não a partir de `origin/main`. Nenhuma configuração remota foi alterada.

O preflight encontrou drift relevante entre o checkout produtivo e seu commit Git de referência. Por isso, a versão está preparada para investigação, mas ainda não está pronta para publicação em um host de homologação.

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

## Topologia ainda não criada

Não existem no servidor os diretórios `versoes/` e `versoes/current`. Também não há vhost ou DNS para `homolog.voxelpacs.com.br` ou `staging.voxelpacs.com.br`. O host `server.voxelpacs.com.br` é o endpoint resolvido do PACS atual.

A aplicação usa caminhos absolutos para assets, formulários e redirects. O bootstrap usa sessão com `cookie_path=/` e não define `cookie_samesite` no runtime atual. Essa evidência sustenta o uso de host próprio em vez de prefixo literal `/1.0`.

## Próxima ação controlada

A próxima ação deve ser somente no clone: produzir o inventário de reconciliação por categoria, selecionar o conjunto mínimo de arquivos de código que representa o runtime, atualizar a documentação de release e testar o marcador de versão. Não se deve limpar o checkout remoto, executar `git add` remoto, copiar `.env`, criar DNS, editar Nginx ou iniciar qualquer serviço nesta etapa.

## Referências

[1]: ../SKILL-VOXEL-PACS/workflows/homologacao-versionamento.md "Workflow de homologação por versão"
[2]: ../SKILL-VOXEL-PACS/architecture/infraestrutura.md "Arquitetura de infraestrutura"
[3]: ../scripts/deploy.sh "Script de deploy versionado"
