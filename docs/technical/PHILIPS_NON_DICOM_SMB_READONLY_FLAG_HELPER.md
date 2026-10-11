# Helper root-controlled da flag SMB read-only Non-DICOM

## Objetivo

`ops/deploy/voxelpacs-philips-nondicom-smb-readonly-flag` é um helper administrativo com escopo fixo para desligar somente:

```text
PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED
```

Ele não altera `PHILIPS_NON_DICOM_DELIVERY_ENABLED`, `PHILIPS_NON_DICOM_AUTO_TRIGGER_ENABLED`, kill switch, Destination, Bridge, banco, Worker, SMB ou DICOM/C-STORE.

## Fonte e segurança

- fonte fixa: `.env` canônico do runtime (`/var/www/voxelpacs/app/.env`);
- backup fixo: `/var/backups/voxelpacs/config/<64-hex>`;
- o arquivo de ambiente deve ser regular, não ser symlink e não possuir acesso mundial;
- owner, group e modo originais são preservados na substituição e no rollback;
- o helper calcula internamente a assinatura do conteúdo não relacionado à flag e falha fechado se qualquer outra configuração mudar;
- nenhum valor do `.env` é impresso.

## Interface fechada

```text
/usr/local/sbin/voxelpacs-philips-nondicom-smb-readonly-flag --status
/usr/local/sbin/voxelpacs-philips-nondicom-smb-readonly-flag --dry-run
/usr/local/sbin/voxelpacs-philips-nondicom-smb-readonly-flag --apply
/usr/local/sbin/voxelpacs-philips-nondicom-smb-readonly-flag --rollback --backup-id <64-hex>
```

Não são aceitos path, chave, valor, comando, shell ou diretório informados pelo caller. O `--apply` só converte a flag fixa para `false`; se ela já estiver desligada ou ausente, retorna `NOOP` sem escrever.

O `--apply` cria e valida backup root-only antes da alteração, faz substituição atômica e verifica novamente:

```text
TARGET_STATE=OFF
OTHER_FLAGS_PRESERVED=YES
RELOAD=NOT_EXECUTED
```

O helper não reinicia serviços. Como a flag é lida no bootstrap do processo, o one-shot deve ser iniciado em uma nova leitura do ambiente; não há necessidade de reiniciar Nginx/PHP-FPM para esta operação. Qualquer serviço persistente deve seguir preflight próprio e autorização separada.

## Provisionamento

O provisioner `scripts/provision-philips-nondicom-smb-readonly-flag.sh` exige checkout limpo, SHA exata da `main`, validação de `bash -n`, `visudo`, ownership/mode e ausência dos destinos. O `--dry-run` não grava.

Arquivos instalados:

```text
/usr/local/sbin/voxelpacs-philips-nondicom-smb-readonly-flag       root:root 0555
/etc/sudoers.d/voxelpacs-philips-nondicom-smb-readonly-flag        root:root 0440
```

A allowlist concede ao `manus-admin` apenas as quatro formas fechadas do helper. Não concede shell, `cp`, `mv`, `rm`, `install`, `systemctl`, PHP, Composer ou acesso genérico.

## Sequência operacional

1. Confirmar SHA da `origin/main` e worktree limpo.
2. Executar o provisioner com `--dry-run`.
3. Fazer backup verificável da release/configuração conforme o workflow operacional.
4. Instalar o helper pela fonte da SHA aprovada.
5. Confirmar owner, mode, `visudo` e `--status`.
6. Executar `--dry-run`.
7. Com autorização operacional já concedida para esta flag, executar `--apply`.
8. Registrar somente `BACKUP_ID`, estados sanitizados e horário.
9. Reexecutar o diagnóstico automático e o preflight read-only do Job 574.
10. Parar antes de `runOne`; a execução do Job exige autorização separada.

Rollback:

```text
--rollback --backup-id <BACKUP_ID>
```

O rollback aceita somente um identificador hexadecimal de 64 posições sob a raiz fixa e valida o checksum/metadata do backup antes de restaurar.

## Testes

```text
bash tests/deploy/philips_nondicom_smb_readonly_flag_static.sh
bash tests/deploy/philips_nondicom_smb_readonly_flag_isolated.sh
```

Os testes cobrem sintaxe, `visudo`, escopo fixo, rejeição de argumentos arbitrários, dry-run sem escrita, preservação de outras configurações, backup root-only, rollback e não exposição de valores sintéticos.
