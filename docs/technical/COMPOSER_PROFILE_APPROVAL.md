# Aprovação root-controlled do perfil Composer

## Objetivo

`scripts/provision-composer-profile-approval.sh` é um mecanismo administrativo versionado para preparar a aprovação da SHA de `main` usada pelo perfil Composer da captura oficial de baseline.

Ele não captura baseline, não compara drift, não publica código e não acessa banco, Worker, Bridge, SMB, Philips ou DICOM.

## Caminhos fixos

```text
Diretório: /etc/voxelpacs-composer-profile
Âncora:    /etc/voxelpacs-composer-profile/voxelpacs-composer-profile-approved-main-sha
Lock:      /run/lock/voxelpacs-composer-profile-approval.lock
```

O diretório deve terminar em `root:root` com modo `0700`. A âncora deve ser arquivo regular `root:root` com modo `0600`, contendo exatamente uma SHA de 40 caracteres hexadecimais.

O parent dedicado pode estar ausente na configuração inicial. Quando existe, somente a âncora com o nome fixo é aceita; entradas adicionais, symlinks, tipos inesperados, owner diferente de UID 0 ou bits de escrita para grupo/outros bloqueiam. O parent compartilhado `/etc/voxelpacs`, usado por outras configurações administrativas, não é lido, alterado ou reconciliado por este mecanismo.

O contrato exige `root:root:0700` no parent dedicado e rejeita GID não-root por padrão operacional fail-closed. O modo `--dry-run` classifica uma instalação ausente como `DIRECTORY_ACTION=CREATE`; não altera o parent compartilhado nem qualquer configuração existente.

## Origem aprovada

Antes de qualquer modo operacional, o helper exige:

- checkout limpo;
- HEAD exatamente na SHA esperada;
- `origin/main` alinhada à mesma SHA;
- histórico Git completo;
- remoto `origin` canônico;
- arquivo do próprio helper presente na SHA esperada;
- `git ls-remote` do remoto canônico confirmando a SHA atual de `refs/heads/main`.

A SHA não é aceita somente porque foi digitada pelo operador. A prova combina a árvore local limpa, a referência remota e a consulta independente ao remoto canônico.

## Interface

```bash
sudo bash scripts/provision-composer-profile-approval.sh \
  --expected-sha <40-hex-sha> --inspect

sudo bash scripts/provision-composer-profile-approval.sh \
  --expected-sha <40-hex-sha> --dry-run

sudo bash scripts/provision-composer-profile-approval.sh \
  --expected-sha <40-hex-sha> --apply
```

Os modos são exclusivos. Não há argumentos de path, arquivo, grupo, owner, comando, shell ou destino.

### `--inspect`

Somente leitura. Informa estado sanitizado do diretório e da âncora. Nunca expõe a SHA completa armazenada.

### `--dry-run`

Somente leitura. Informa se a ação seria `CREATE`, `RECONCILE` ou `UNCHANGED`. Não cria arquivo, não altera modo e não altera owner/group.

### `--apply`

Executa apenas a transição administrativa prevista:

1. cria o diretório fixo ausente, ou reconcilia somente owner/group/mode do diretório conforme o contrato;
2. cria a âncora por arquivo temporário root-owned e link físico no destino fixo;
3. não substitui âncora existente;
4. bloqueia âncora divergente, symlink ou formato inválido;
5. valida owner/mode e o valor após a escrita;
6. remove a alteração parcial se a validação final falhar.

Uma âncora existente e correspondente é preservada. A operação é idempotente e não retorna `CONFIG_CHANGED=YES` quando não há mudança.

## Estados de falha

O helper bloqueia, entre outros, em:

- SHA/argumentos inválidos;
- checkout sujo ou remoto incorreto;
- `origin/main` divergente;
- histórico raso;
- falha na consulta do remoto canônico;
- parent `/etc` inseguro;
- `/etc/voxelpacs-composer-profile` symlink, owner inesperado, modo gravável ou com entradas inesperadas;
- âncora existente divergente, symlink, tipo ou modo inválido;
- corrida de criação;
- falha de validação final;
- lock ocupado.

## Rollback

A criação inicial não sobrescreve âncoras. Em falha posterior à criação de um diretório/âncora novos, o helper tenta remover somente os objetos que ele próprio criou. Em reconciliação de diretório preexistente, guarda owner/group/mode anteriores e tenta restaurá-los se a validação final falhar.

`ROLLBACK=FAIL` ou `ORIGINAL_PRESERVED=UNKNOWN` bloqueia qualquer nova tentativa até reconciliação administrativa separada.

## Limites

- Não altera a aplicação, `.env`, banco, migrations ou baseline.
- Não cria ou atualiza o `composer-profile.tsv`.
- Não aceita exceção para `vendor/composer/installed.php`.
- Não executa o capturador nem o drift gate.
- Não inicia serviços ou processos clínicos.

A captura oficial e o drift gate são fases posteriores, separadas e dependentes de autorização operacional própria.

## Validação

O CI e os testes isolados cobrem:

- diretório ausente, correto, owner/group inesperado e modo permissivo;
- symlink e entradas inesperadas;
- âncora ausente, correspondente e divergente;
- no-replace e repetição segura;
- checkout com SHA/remoto incorretos e checkout sujo;
- argumentos e caminhos não permitidos;
- corrida de criação e validação final;
- rollback lógico sem substituir configuração existente;
- preservação de uma configuração preexistente no parent compartilhado `/etc/voxelpacs`;
- não alteração no modo dry-run.

A implementação desta PR não provisiona nem altera o Host1.
