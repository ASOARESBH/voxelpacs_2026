# Helper privilegiado de publicação do runtime

## Problema resolvido

O executor anterior transferia o ZIP ao Host 1 e executava `unzip -o` diretamente em `APP_ROOT` como `manus-admin`. O runtime é `voxel:voxel` e a substituição/remoção dos arquivos existentes falha por permissão. O N2.1 não corrige isso com `chmod`, cópia manual, `sudo` amplo ou edição direta em produção.

## Contrato

- O código versionado constrói o artefato a partir de um checkout limpo e de um SHA exato.
- O executor sem privilégio envia somente quatro arquivos com nomes derivados do SHA para a entrada fixa `/var/lib/voxelpacs/deploy/incoming/`:
  - `voxelpacs-runtime-<SHA>.zip`;
  - `voxelpacs-runtime-<SHA>.manifest.tsv`;
  - `voxelpacs-runtime-<SHA>.sha256`;
  - `voxelpacs-runtime-<SHA>.source-sha`.
- O executor solicita somente `/usr/local/sbin/voxelpacs-deploy-runtime --sha <SHA>` por sudoers.
- O fluxo aceita somente os callers SSH aprovados `manus-admin` e `manus-deploy`; `DEPLOY_USER` permanece `manus-admin` por padrão e pode selecionar explicitamente `manus-deploy` para uma operação separada.
- O helper não aceita caminhos, comandos, shell, serviço ou ambiente fornecidos pelo usuário.
- O helper valida SHA, checksum, manifesto completo, paths allowlisted, entrypoints obrigatórios e ausência de `.env`, storage, uploads, logs, backups, testes, docs, scripts, migrations e dados clínicos.
- O helper cria uma transação root-only, preserva os arquivos versionados existentes, publica com arquivo temporário no diretório do destino e `mv` atômico por arquivo, e restaura a transação em caso de falha.
- O helper comprova que `.env`, `storage`, symlink de storage e a cópia plana legada permaneceram inalterados.
- O helper não executa Composer, migration, reload, restart, Worker, Bridge, SMB ou transmissão.

## Provisionamento único no Host 1

A instalação abaixo é uma operação administrativa separada e **não é executada pelo PR**:

1. Copiar o arquivo versionado para `/usr/local/sbin/voxelpacs-deploy-runtime` como `root:root`, modo `0755`.
2. Criar `/var/lib/voxelpacs/deploy/incoming`, `/var/lib/voxelpacs/deploy/releases` e `/var/lib/voxelpacs/deploy/releases/transactions` como `root:root`, modo `0700`, com ACL/grupo de entrada definido pelo administrador sem conceder escrita em `APP_ROOT`.
3. Instalar `ops/sudoers/voxelpacs-deploy-runtime` em `/etc/sudoers.d/voxelpacs-deploy-runtime`, modo `0440`, usando `visudo -cf` antes de ativar; o arquivo contém duas regras para cada identidade aprovada (`manus-admin` e `manus-deploy`), somente para publicação/rollback pelo helper.
4. Validar, read-only, que `manus-admin` e `manus-deploy` conseguem listar a permissão do helper via `sudo -n -l`, sem executar publicação.
5. Remover qualquer arquivo de entrada de SHA anterior somente por procedimento administrativo separado e autorizado; o helper não faz limpeza ampla.

## Rollback

Depois de uma publicação aprovada, o rollback usa exclusivamente a transação correspondente:

```text
sudo -n /usr/local/sbin/voxelpacs-deploy-runtime --rollback --sha <SHA>
```

O helper só aceita rollback de uma transação publicada e restaura apenas os arquivos que pertenciam ao manifesto da própria transação. Não remove dados persistentes nem altera banco.

### Separação operacional dos callers

`manus-admin` e `manus-deploy` são identidades SSH distintas. Ambos podem executar somente o mecanismo versionado e allowlisted, mas o executor deve selecionar explicitamente `DEPLOY_USER` conforme a ação operacional. O helper rejeita qualquer `SUDO_USER` diferente desses dois nomes; não usar variável forjada, wrapper paralelo ou sudo amplo como atalho.

## Limites

Esta branch não instala o helper, não altera sudoers em produção, não cria backup, não executa deploy, não inicia Worker e não transmite.
