# Auditoria WireGuard Philips — execução root-local

## Causa e decisão

O gateway não comprovou possuir o dispatcher `/usr/local/sbin/voxelpacs-deploy-force`, o usuário técnico `voxeldeploy` ou uma política SSH de comando forçado. O instalador anterior pressupunha esses componentes e, por isso, abortava antes de criar o executável de auditoria.

O instalador corrigido não cria nem simula esse mecanismo. Ele usa apenas a capacidade confirmada de execução **local como root** e materializa um executável sem argumentos em `/usr/local/sbin/voxelpacs-gateway-philips-wireguard-audit`, com proprietário `root:root` e modo `0700`.

> A materialização muda somente esse executável root-only. A auditoria que ele disponibiliza é somente leitura; não altera WireGuard, rota, firewall, SSH, serviços, bridge, PDF, XML ou automação.

## Escopo sanitizado da auditoria

O comando classifica a capacidade do host, sobreposição da faixa `10.201.10.0/24`, estado/handshake das interfaces `wg0` e `wg-philips`, bindings UDP solicitados, firewall, disponibilidade de `wg-quick`, grupos de serviços e redes Docker/WireGuard. A saída não inclui IPs adicionais, peers, rotas, nomes de units, logs, chaves, credenciais, caminhos remotos ou conteúdo clínico.

O término bem-sucedido é marcado por `GATEWAY_PHILIPS_WIREGUARD_AUDIT_OK`.

## Passo 1 — download e verificação

Preencha `<commit-completo>` e `<sha256-confirmado>` apenas após revisar o commit aprovado.

```bash
set -euo pipefail
install_dir=/root/voxelpacs-audit
source_file="$install_dir/enable_gateway_philips_wireguard_audit_command.sh"
commit=<commit-completo>
expected_sha256=<sha256-confirmado>

install -d -o root -g root -m 0700 "$install_dir"
curl --fail --silent --show-error --location \
  "https://raw.githubusercontent.com/ASOARESBH/voxelpacs_2026/${commit}/docs/technical/enable_gateway_philips_wireguard_audit_command.sh" \
  -o "$source_file"
chown root:root "$source_file"
chmod 0700 "$source_file"
test "$(sha256sum "$source_file" | awk '{print $1}')" = "$expected_sha256"
bash -n "$source_file"
printf 'GATEWAY_AUDIT_INSTALLER_VERIFIED\n'
```

## Passo 2 — inspeção estática

```bash
set -euo pipefail
source_file=/root/voxelpacs-audit/enable_gateway_philips_wireguard_audit_command.sh
grep -nE 'voxelpacs-deploy-force|SSH_ORIGINAL_COMMAND|sudoers|systemctl (start|stop|restart|reload|enable|disable)|wg-quick (up|down)|wg (set|setconf|addconf)|ip route (add|del)|iptables (-A|-I|-D|-F)|nft (add|delete|flush)|ufw (allow|deny|enable|disable)|mount|umount|sshd|authorized_keys|sftp|scp|curl|wget|cron|timer|reboot|shutdown|poweroff|mkfs' "$source_file" || true
printf 'GATEWAY_AUDIT_INSTALLER_INSPECTION_COMPLETE\n'
```

Ocorrências permitidas são exclusivamente consultas: `wg-quick` na checagem de disponibilidade, `wg`/`ip`/`nft`/`iptables`/`ufw` para classificação de estado, `docker network inspect`, e `systemctl` para consulta. Qualquer comando de ação interrompe o processo antes do passo 3.

## Passo 3 — materialização root-local

```bash
set -euo pipefail
source_file=/root/voxelpacs-audit/enable_gateway_philips_wireguard_audit_command.sh
expected_sha256=<sha256-confirmado>
test "$(sha256sum "$source_file" | awk '{print $1}')" = "$expected_sha256"
bash "$source_file"
```

Este passo cria ou sobrescreve somente o executável root-only da auditoria. Requer aprovação distinta.

## Passo 4 — auditoria somente leitura

```bash
set -euo pipefail
/usr/local/sbin/voxelpacs-gateway-philips-wireguard-audit
```

Este passo não deve ser combinado com a materialização sem autorização específica.

## Ocorrências relevantes na revisão estática

| Termo | Uso permitido |
|---|---|
| `wg-quick` | Checagem da unidade de template; sem `up`, `down` ou `save`. |
| `wg` | Leitura de handshakes e `allowed-ips`. |
| `ip` | Listagem de rotas, endereços e interfaces. |
| `nft`, `iptables`, `ufw` | Consulta do gerenciador/regra presente. |
| `systemctl` | `list-units`, `is-active` e `cat`, sem controle de unidades. |
| `docker` | Consulta de redes quando disponível. |
| `find ... -delete` | Limpeza limitada a arquivos criados pelo próprio `mktemp -d`; não usa `rm -rf`. |
| `curl` | Existe somente no passo externo de download, não no executável de auditoria. |
