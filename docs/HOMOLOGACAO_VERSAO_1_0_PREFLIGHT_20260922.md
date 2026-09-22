# Homologação por versão — preflight da Opção B

## Conclusão

A Opção B foi concluída no escopo autorizado. O worktree `versao/1.0` foi criado a partir do commit efetivamente identificado no checkout produtivo, e não a partir de `origin/main`; a release foi publicada em diretório separado, com banco homologado e flags de delivery desabilitadas.

O preflight encontrou drift relevante entre o checkout produtivo e seu commit Git de referência. A evidência foi preservada no relatório; a homologação foi publicada somente a partir da branch `versao/1.0`, sem limpar ou substituir o checkout produtivo.

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

## Topologia criada e validada

O registro A `homolog.voxelpacs.com.br → 167.233.254.41` foi criado na zona HostGator com TTL 300. O Nginx possui server block separado, a release está em `/var/www/voxelpacs/releases/1.0` e o certificado TLS foi emitido para o hostname. O endpoint produtivo `server.voxelpacs.com.br` permaneceu inalterado.

A aplicação usa caminhos absolutos para assets, formulários e redirects. O bootstrap usa sessão com `cookie_path=/` e não define `cookie_samesite` no runtime atual. Essa evidência sustenta o uso de host próprio em vez de prefixo literal `/1.0`.

## Próxima ação controlada

## Resultado operacional

| Verificação | Resultado |
|---|---|
| URL de homologação | `https://homolog.voxelpacs.com.br/` |
| DNS externo | `PASS` — resolve para `167.233.254.41` |
| HTTPS | `PASS` — certificado válido para o hostname |
| `/health` | `200 homolog-ok` |
| HTTP → HTTPS | `301` |
| Aplicação | `200` no smoke test da raiz |
| Release | `1.0`, commit `e33df76e0a95c1b20669eb18d9732fa6ec70196b` |
| Banco | `voxelpacs_homolog`, separado do banco produtivo |
| Delivery/Philips | Desabilitados no `.env` da homologação |
| Worker global | Não iniciado |
| PHP-FPM produtivo | Não recarregado; somente Nginx recebeu reload para o novo vhost |
| Dados clínicos produtivos | Não alterados |

O rollback é reversível: desabilitar o vhost `homolog-voxelpacs.conf`, remover o registro A após o TTL e retirar a release `1.0` sem tocar no checkout produtivo, no banco produtivo ou no storage clínico.

## Referências

[1]: ../SKILL-VOXEL-PACS/workflows/homologacao-versionamento.md "Workflow de homologação por versão"
[2]: ../SKILL-VOXEL-PACS/architecture/infraestrutura.md "Arquitetura de infraestrutura"
[3]: ../scripts/deploy.sh "Script de deploy versionado"
