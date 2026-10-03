# Philips Non-DICOM — Diagnóstico oficial de produção

## Objetivo

`bin/philips_nondicom_production_diagnostic.php` é um diagnóstico **somente leitura** para avaliar se a preparação técnica do primeiro envio PDF+XML está completa para o tenant `2`, Destination `7`, report `348`, versão `4`.

Ele não cria Request, Outbox ou Job; não arma filas; não executa Worker; não chama Bridge; não executa SMB; não gera PDF/XML; não faz transmissão; não inicia/reinicia serviços; não executa migration e não escreve no banco.

## Execução

O comando é instalado em contexto isolado e deve ser executado somente pelo mecanismo administrativo allowlisted definido para o runtime, como o usuário de aplicação apropriado. O executor não aceita argumentos:

```text
php /usr/local/libexec/voxelpacs/philips_nondicom_production_diagnostic.php
```

O caminho acima é um contrato de instalação, não uma autorização para instalar ou executar em produção nesta PR. A instalação requer procedimento operacional separado, backup da configuração administrativa e validação da allowlist.

O diagnóstico não carrega `app/bootstrap.php`: evita sessão, criação de diretórios e os handlers web. Ele carrega apenas o autoloader da aplicação, lê o `.env` canônico sem emitir seus valores, usa PDO com `SELECT` parametrizado e consulta `systemctl` somente com argumentos fixos de leitura.

## Escopo fixo

| Item | Valor | Tratamento |
|---|---:|---|
| Tenant | `2` | Fixo no código |
| Destination | `7` | Fixo no código |
| Transport | `philips_non_dicom` | Deve corresponder |
| Ambiente | `producao` | Deve corresponder |
| Profile | `submission_document` | Deve corresponder |
| Report | `348` | Somente metadados |
| Versão | `4` | Somente existência estrutural |

Nenhum conteúdo clínico, PDF, XML, PatientName, segredo, senha, token, cookie, DSN ou credential materializa na saída.

## Gates

O resultado geral é `READY` somente quando todos os gates retornam `PASS`. Qualquer `UNKNOWN`, `BLOCKED` ou ausência de evidência resulta em `overall=BLOCKED`.

1. **Runtime** — flags efetivas do `ReportDeliveryRuntimeConfig`, incluindo Hub/Requests/Non-DICOM ativos, SMB test/read-only OFF e Worker kill switch OFF.
2. **Destination 7** — tenant, transport, produção, habilitado, auto-trigger OFF, profile, protocolo SMB, gateway bridge, credencial presente e vínculo PACS.
3. **Tenant/PACS** — servidor PACS ativo e autorizado para o tenant, com `task_site_id` canônico correspondente. O `task_site_id_alias` é um identificador técnico ASCII separado, obrigatório para o D7 controlado, e não substitui esse binding.
4. **Fila** — o gate usa somente Jobs tenant-scoped do Destination `7`; Jobs de outros destinos não bloqueiam D7. Outbox é exibido apenas como observação tenant-scoped, porque o schema não possui `destination_id` e não permite inferência de destino.
5. **Worker** — unit habilitada, inativa e sem processo.
6. **Bridge** — uma unidade oficial efetivamente proprietária do transporte. Se a propriedade remota não puder ser comprovada, o gate fica bloqueado; o diagnóstico não altera Host 2.
7. **Credential chain** — referência do Destination, referência do target Bridge e resolução do segredo. Presença nominal não é confundida com decifrabilidade; sem prova, `UNKNOWN` bloqueia.
8. **SMB read-only** — nesta primeira versão, permanece `NOT_EXECUTED` porque não existe um mecanismo seguro e dedicado de produção aprovado para autenticação/listagem sem ampliar o escopo. Isso bloqueia o envio até uma autorização e um mecanismo próprio.
9. **Report candidate** — report/version existem no tenant, estão liberados, possuem estudo vinculado e a versão exata está presente. Apenas metadados são lidos.

## Saída

A saída é JSON sanitizado. Os campos de configuração informam presença/estado, nunca valores. O campo `transmission_executed` é sempre `false` neste diagnóstico.

Exemplo de decisão (sem valores de ambiente):

```json
{
  "mode": "READ_ONLY",
  "overall": "BLOCKED",
  "environment": "production",
  "smb": {
    "status": "BLOCKED",
    "readonly_test": "NOT_EXECUTED"
  },
  "transmission_executed": false
}
```

## Segurança

- Tabelas consultadas são allowlisted no código: somente `pacs_report_delivery_outbox` e `pacs_report_delivery_jobs`, além das consultas estruturais fixas do Destination, PACS e report candidato.
- Não há SQL mutável.
- Não há chamada `smbclient`, `curl`, `scp`, `rsync` ou cliente de transporte.
- O comando de processo é restrito a `/usr/bin/systemctl` com `is-enabled`, `is-active` e `show --property=MainPID --value`.
- O processo não aceita argumentos arbitrários.
- O diagnóstico não publica alteração em produção. Qualquer provisionamento, allowlist, backup ou deploy é uma fase operacional independente.

## Testes locais

```text
php -l bin/philips_nondicom_production_diagnostic.php
php -l tests/philips_nondicom_production_diagnostic_static.php
php tests/philips_nondicom_production_diagnostic_static.php
```

Os testes são sintéticos: não acessam banco, Host 1, Host 2, Bridge, SMB ou dados clínicos.
