# Correção do instalador — auditoria do gateway Philips

**Estado:** alteração somente no repositório local. Não houve commit, push, deploy, materialização ou execução no `gateway-dicom-01`.

## 1. Causa raiz

O instalador introduzido no commit `c38346645cd318b066e0b75b3a967cc008f3f919` exigia o executável `/usr/local/sbin/voxelpacs-deploy-force` antes de construir o auditor. A verificação real no gateway confirmou que esse executável não existe. Como o instalador usa `set -euo pipefail`, a pré-condição falha e interrompe a execução antes da materialização.

O problema não é uma falha do WireGuard, do SFTP, de serviços clínicos ou de rede. É uma premissa não confirmada de acesso técnico: o código foi derivado de um padrão existente no repositório, mas esse padrão não está comprovado no gateway em questão.

## 2. Resultado da inspeção do repositório

Uma busca versionada foi feita nos diretórios `deploy/`, `docs/`, `docs/technical/`, `scripts/` e no restante do repositório pelos termos solicitados. Foram encontradas referências ao padrão `voxelpacs-deploy-force` em instaladores técnicos voltados ao PACS/API, incluindo diagnósticos de Downloads, VPN do Delivery Hub, Voxel Desktop e colisão de sub-rede WireGuard.

| Referência | Resultado | Interpretação |
|---|---|---|
| `voxelpacs-deploy-force` | Encontrado em instaladores técnicos do repositório | É um padrão de script, não evidência de que exista no gateway. |
| `voxeldeploy` e `SSH_ORIGINAL_COMMAND` | Encontrados somente no padrão de dispatcher/sudoers desses instaladores | Não comprova usuário ou comando forçado no gateway. |
| `gateway-dicom-01` | Encontrado no instalador Philips | Referência documental/específica do novo diagnóstico. |
| Implementação versionada do dispatcher | Não encontrada | Não existe fonte de verdade no repositório para criar ou alterar esse mecanismo no gateway. |
| Mecanismo real de acesso técnico do gateway | Não encontrado | Não deve ser presumido, criado artificialmente ou alterado nesta correção. |

## 3. Arquitetura corrigida

| Alternativa | Alteração no gateway | Superfície de segurança | Reversibilidade | Decisão |
|---|---:|---:|---:|---|
| Criar dispatcher/usuário SSH semelhante ao PACS/API | Alta; muda SSH e sudoers sem base local comprovada | Maior | Média | Rejeitada |
| Presumir que o dispatcher existe | Nenhuma inicialmente, mas o instalador falha | Inadequada | Não aplicável | Rejeitada |
| **Executável local root-only** | Um executável `0700`, sem sudoers/SSH/serviço | Mínima | Alta; remover o executável | **Escolhida** |

A arquitetura selecionada não altera `wg0`, `wg-philips`, portas UDP, rotas, firewall, SSH/sshd, Orthanc, PostgreSQL, Evolution API, nginx, Docker, DICOM Gateway, bridge, SFTP, SMB, XML, PDF, worker, timer, cron ou systemd. O instalador materializa somente o executável root-only de auditoria. O executável, quando autorizado separadamente, apenas lê estado e emite classificações sanitizadas.

## 4. Arquivos alterados

| Arquivo | Alteração |
|---|---|
| `docs/technical/enable_gateway_philips_wireguard_audit_command.sh` | Remove dependência de dispatcher, `sudoers` e usuário técnico; mantém auditoria root-local fechada e adiciona classificações de handshake/binding UDP. |
| `docs/technical/GATEWAY_PHILIPS_WIREGUARD_AUDIT_LOCAL_ROOT.md` | Adiciona decisão arquitetural, limites, comandos independentes e revisão de ocorrências estáticas. |
| `docs/technical/GATEWAY_PHILIPS_WIREGUARD_AUDIT_CORRECTION_REVIEW.md` | Registra causa raiz, referências, alternativas, diff e estado sem publicação. |

## 5. Diff relevante

```diff
- readonly FORCE_COMMAND=/usr/local/sbin/voxelpacs-deploy-force
- readonly SUDOERS_FILE=/etc/sudoers.d/voxelpacs-gateway-philips-wireguard-audit
- readonly TECHNICAL_USER=voxeldeploy
- test -x "$FORCE_COMMAND"
  readonly RUNNER=/usr/local/sbin/voxelpacs-gateway-philips-wireguard-audit

- chmod 0750 "$RUNNER"
+ chmod 0700 "$RUNNER"

- [backup do dispatcher, alteração de SSH_ORIGINAL_COMMAND, sudoers e visudo]
+ [nenhum dispatcher, sudoers, usuário técnico ou alteração de SSH]
```

Também foi removido o uso de `rm -rf` na limpeza temporária. A limpeza agora é limitada a arquivos do diretório criado pelo próprio `mktemp -d`, seguida de `rmdir`.

## 6. Novo instalador

O instalador corrigido permanece em:

```text
docs/technical/enable_gateway_philips_wireguard_audit_command.sh
```

Ele deve ser executado localmente como root somente após revisão e aprovação separada. Não existe canal SSH técnico criado por ele.

## 7. SHA-256 do arquivo corrigido no workspace

```text
1c6d9d5a4813b66f31e66b29650b6d65b3d738b45d0260c304d821ace2980280
```

Esse hash refere-se ao arquivo ainda não versionado. Um hash de commit/download só poderá ser fornecido depois de autorização explícita para criar e enviar um commit.

## 8. Revisão estática de segurança

O instalador e o executável extraído passaram por `bash -n` e `git diff --check`.

| Grupo solicitado | Resultado |
|---|---|
| `reboot`, `shutdown`, `poweroff`, `mkfs`, `rm -rf` | Ausentes |
| `systemctl restart/stop/disable/enable/reload` | Ausentes |
| `wg-quick up/down/save`, `wg set/setconf/addconf` | Ausentes |
| Alterações `iptables`, `nft`, `ufw`, `ip route add/del` | Ausentes |
| `mount`, `umount`, `ssh`, `sshd`, `authorized_keys`, `sftp`, `scp`, `crontab` | Ausentes |
| `wg`, `ip`, `nft`, `iptables`, `ufw`, `systemctl`, Docker | Presentes somente para consulta/classificação, conforme detalhado no guia local root-only. |

## 9. Inventário completo de operações do instalador e runner

| Categoria | Chamadas ou operações | Finalidade e efeito |
|---|---|---|
| Filesystem do instalador | `cat > "$RUNNER"`, `chown root:root`, `chmod 0700`, `bash -n` | Cria ou sobrescreve somente o runner root-only e valida sua sintaxe. |
| Filesystem temporário do runner | `mktemp -d`, redirecionamentos para JSON/texto temporários, `find ... -delete`, `rmdir` | Armazena resultados transitórios de leitura e os remove ao encerrar; não usa `rm -rf`. |
| `systemctl` | `list-units`, `is-active`, `cat wg-quick@.service` | Consulta grupos de serviços e disponibilidade de template, sem iniciar, parar, reiniciar, habilitar ou desabilitar. |
| `wg` | `show <interface> latest-handshakes`, `show all allowed-ips` | Lê handshakes e prefixos; não altera peers ou interfaces. |
| `wg-quick` | `command -v wg-quick` | Apenas verifica se o binário existe; não usa `up`, `down` ou `save`. |
| `ip` | `-j -4 route show table all`, `-j -4 addr show`, `link show` | Lê rotas, endereços e interfaces; não adiciona, remove ou substitui rota/interface. |
| `iptables` | `iptables -S` | Consulta regras apenas quando este gerenciador existe. |
| `nft` | `nft list ruleset` | Consulta regras apenas quando este gerenciador existe. |
| `ufw` | `ufw status` | Consulta status apenas quando este gerenciador existe. |
| Docker | `docker info`, `docker network ls`, `docker network inspect` | Consulta redes apenas quando o daemon responde. |
| `ssh`, `sshd`, `sudo`, `mount`, `umount`, `sftp`, `scp`, `rm` | Ausentes | Não há acesso remoto, alteração de SSH, elevação delegada, montagem ou transferência. |

## 10. Busca de referências legadas

O instalador corrigido não contém `voxelpacs-deploy-force`, `voxeldeploy` ou `SSH_ORIGINAL_COMMAND`. A busca no repositório ainda encontra esses termos em onze instaladores históricos/técnicos destinados ao padrão do PACS/API: Downloads (seis), VPN do Delivery Hub, Voxel Desktop (três) e diagnóstico de colisão WireGuard. Essas referências permanecem fora do escopo e não comprovam infraestrutura correspondente no gateway.

## 11. Próxima autorização necessária

Nenhum dos quatro passos operacionais deve ser executado enquanto o arquivo corrigido estiver sem commit aprovado. A próxima decisão é exclusivamente de versionamento: aprovar ou rejeitar a criação de um commit sanitizado contendo estes três arquivos. Não haverá publicação no gateway como consequência desse commit.
