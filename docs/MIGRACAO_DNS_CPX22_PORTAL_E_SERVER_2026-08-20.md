# Migração DNS para o servidor CPX22 — Portal e VOXEL PACS

**Destino:** `167.233.254.41`

**Servidor:** CPX22 — API VOXEL PACS / Ubuntu 24.04 / Nginx / PHP 8.3

**Domínios envolvidos:** `portal.resultados.com.br` e `server.voxelpacs.com.br`
**Data-base:** 20 de agosto de 2026

## 1. Decisão de arquitetura

A alteração proposta direciona os dois nomes públicos para o mesmo servidor da API VOXEL PACS:

| Nome público | Função após a migração | IP de destino |
|---|---|---:|
| `server.voxelpacs.com.br` | Aplicação interna do VOXEL PACS: login, Worklist, Laudário, relatórios e API web | `167.233.254.41` |
| `portal.resultados.com.br` | Portal público de Resultados para pacientes | `167.233.254.41` |

A aplicação identifica o Portal pelo host HTTP. Por isso, no servidor será definido `PORTAL_HOST=portal.resultados.com.br`. Quando o acesso vier por esse domínio, o sistema carregará apenas as rotas públicas do Portal; quando vier por `server.voxelpacs.com.br`, carregará o PACS interno autenticado.

> **Importante:** mover `server.voxelpacs.com.br` altera o endereço oficial de toda a aplicação, não apenas do Portal. A mudança só deve ser feita depois de confirmar que o banco migrado, arquivos enviados, dependências PHP, SMTP e usuários estão prontos no CPX22.

## 2. Estado verificado antes da mudança

| Item | Situação atual observada |
|---|---|
| `portal.resultados.com.br` | Não possui registro A ou AAAA público no momento; sua zona DNS é autoritativa no Cloudflare (`darwin.ns.cloudflare.com`) |
| `server.voxelpacs.com.br` | Registro A atual aponta para `162.241.63.72` e a zona é atendida pelos nameservers do HostGator |
| CPX22 | Nginx responde atualmente apenas em HTTP/80; porta 443 ainda não está liberada; não há certificado Let's Encrypt instalado |
| Portal no CPX22 | Está configurado para o host temporário `portal-homolog.167.233.254.41.nip.io`; será trocado para o domínio definitivo após a propagação DNS |

## 3. Alterações DNS sob responsabilidade do proprietário

Não é necessário alterar hospedagem, arquivos ou banco no HostGator para apontar os DNS. Devem ser alterados **somente os registros DNS autoritativos** abaixo.

### 3.1 `portal.resultados.com.br`: alterar no Cloudflare

O domínio `resultados.com.br` utiliza os nameservers do Cloudflare. Portanto, mesmo que o domínio tenha sido registrado no HostGator, a alteração deve ser feita no painel **Cloudflare → resultados.com.br → DNS → Records**.

Crie o registro abaixo. Se já existir algum registro `A`, `AAAA` ou `CNAME` chamado `portal`, remova ou substitua-o para não haver conflito.

| Campo Cloudflare | Valor |
|---|---|
| Type | `A` |
| Name | `portal` |
| IPv4 address | `167.233.254.41` |
| Proxy status | **DNS only** — nuvem cinza |
| TTL | `Auto` ou `300` segundos, se o painel permitir |

Não crie registro `AAAA`: o CPX22 não está configurado com IPv6 público para esta aplicação. O registro deve permanecer em **DNS only** até a emissão e validação do certificado HTTPS no servidor. O modo DNS only permite que a validação HTTP do Let's Encrypt alcance diretamente o CPX22.[1]

### 3.2 `server.voxelpacs.com.br`: alterar no HostGator

A zona `voxelpacs.com.br` está sendo respondida pelos nameservers do HostGator. A alteração deve ser feita em **HostGator → cPanel → Domains → Zone Editor**; a edição de registros A é feita no Zone Editor.[2]

Localize o registro `A` de `server.voxelpacs.com.br` — ele aponta hoje para `162.241.63.72` — e altere apenas o destino:

| Campo HostGator/cPanel | Valor |
|---|---|
| Type | `A` |
| Name | `server` ou `server.voxelpacs.com.br.` |
| Address / Record | `167.233.254.41` |
| TTL | `300` segundos para a janela de migração; depois elevar para `3600` segundos |

Não altere os registros do domínio raiz, `www`, e-mail/MX, SPF, DKIM, DMARC, Autodiscover, FTP ou outros subdomínios. Eles não fazem parte desta migração.

> Se existir qualquer registro `AAAA` para `server`, remova-o antes da troca ou aponte-o para uma infraestrutura IPv6 válida. Um AAAA desatualizado faz usuários IPv6 acessarem um destino diferente do IPv4.

## 4. Sequência recomendada para o usuário

1. Registre uma janela de mudança e confirme que há backup do banco e dos arquivos do HostGator.
2. No Cloudflare, crie o A `portal → 167.233.254.41` como **DNS only**.
3. No HostGator cPanel, reduza o TTL do A `server` para 300, aguarde o TTL anterior de 14.400 segundos expirar se a mudança precisar de reversão rápida, e então altere o IP para `167.233.254.41`.
4. Não exclua ainda o conteúdo do HostGator e não cancele o plano: ele será o caminho de rollback durante a estabilização.
5. Assim que os registros forem salvos, informe que a mudança foi feita. A partir desse ponto, a configuração no CPX22 será aplicada.

A propagação observada pelos resolvedores pode variar conforme o TTL e cache de cada provedor. A mudança não deve ser considerada concluída apenas porque o painel mostrou “salvo”; ela deve ser validada por consulta DNS pública e por acesso HTTPS.[2]

## 5. Alterações que serão feitas no CPX22 após o DNS apontar

Após o usuário confirmar os registros e eles resolverem para `167.233.254.41`, serão executadas no servidor as seguintes alterações, em ordem segura:

| Ordem | Alteração no CPX22 | Finalidade |
|---:|---|---|
| 1 | Atualizar o release da aplicação para a `main` publicada | Garantir que Portal, compartilhamento e migration estejam na versão aprovada |
| 2 | Validar banco, dados migrados, `vendor/`, uploads e variáveis de produção | Evitar virar o DNS para uma aplicação incompleta |
| 3 | Alterar `PORTAL_HOST=portal.resultados.com.br` no `.env` | Fazer o dispatch seguro do Portal pelo host definitivo |
| 4 | Ajustar o virtual host Nginx para ambos os domínios | Atender `server.voxelpacs.com.br` e `portal.resultados.com.br` no mesmo codebase |
| 5 | Liberar UFW `443/tcp` | Permitir HTTPS público; HTTP/80 já está liberado |
| 6 | Emitir certificado Let's Encrypt com ambos os nomes | Criar HTTPS válido para os dois subdomínios |
| 7 | Forçar redirecionamento HTTP → HTTPS | Evitar sessões e dados médicos em HTTP |
| 8 | Recarregar Nginx e validar rotas | Confirmar servidor interno e Portal com comportamentos isolados |

A emissão do certificado será solicitada para ambos os nomes em um único certificado SAN:

```text
server.voxelpacs.com.br
portal.resultados.com.br
```

O Nginx será configurado para negar acesso a arquivos ocultos, usar o front controller PHP atual e redirecionar requisições HTTP para HTTPS. A porta 443 será liberada sem alterar as regras privadas do Orthanc ou a sua autenticação.

## 6. Pré-condições técnicas que precisam estar verdadeiras no CPX22

A virada de `server.voxelpacs.com.br` só será segura se a lista abaixo for aprovada durante a configuração do servidor.

| Componente | Critério de aceite |
|---|---|
| Código | `main` atualizada com o commit do Portal e da compatibilidade MySQL/PostgreSQL conforme o banco configurado |
| Banco | PostgreSQL migrado contém dados esperados e a aplicação lista usuários, estudos, Worklist e laudos corretamente |
| Arquivos | Uploads e logos necessários estão presentes e legíveis pelo usuário do PHP-FPM |
| Dependências | `vendor/autoload.php` e bibliotecas usadas por PDF/QR code estão instaladas |
| E-mail | SMTP de produção configurado antes de habilitar teste de compartilhamento por e-mail |
| Portal | `PORTAL_IMAGES_ENABLED=false` e `PORTAL_IMAGES_ANONYMIZED=false` continuam obrigatoriamente desabilitados |
| Infraestrutura | Nginx e PHP-FPM saudáveis; firewall permite somente 80 e 443 públicos, além das regras SSH administrativas existentes |
| Plano de reversão | HostGator permanece intacto até o aceite operacional dos dois domínios |

## 7. Validação depois da configuração do servidor

| URL / teste | Resultado esperado |
|---|---|
| `https://server.voxelpacs.com.br/health` | HTTP 200 com `ok` |
| `https://server.voxelpacs.com.br/login` | Tela de login do PACS via HTTPS válido |
| `https://portal.resultados.com.br/` | Tela pública do Portal, sem sidebar e sem login interno |
| Portal com laudo liberado | **Ver laudo** abre apenas o PDF público autorizado |
| **Ver imagens** | Exibe tela de proteção; não expõe imagens clínicas, OHIF ou DICOMweb |
| HTTP nos dois domínios | Redireciona para a mesma URL HTTPS |
| Certificado | Emitido por autoridade confiável e com os dois nomes no SAN |

## 8. Reversão de DNS

Se houver falha crítica durante a estabilização, restaure somente o A de `server.voxelpacs.com.br` para `162.241.63.72` no Zone Editor do HostGator. O Portal `portal.resultados.com.br` pode ser removido ou mantido inativo, conforme decisão operacional.

Não apague o CPX22, não remova certificado, não apague banco e não exclua o conteúdo do HostGator como parte de um rollback. Primeiro restabeleça o tráfego pelo DNS e preserve evidências técnicas sem expor dados clínicos ou segredos.

## 9. Confirmação necessária para a próxima etapa

Quando os registros estiverem alterados, envie esta confirmação:

> **DNS atualizado:** `portal.resultados.com.br → 167.233.254.41` no Cloudflare, DNS only; `server.voxelpacs.com.br → 167.233.254.41` no HostGator. Pode configurar Nginx, HTTPS e o host do Portal no CPX22.

Apenas após essa confirmação serão alterados Nginx, firewall, `.env` e certificado no servidor.

## Referências

[1] [Cloudflare — Proxy status](https://developers.cloudflare.com/dns/proxy-status/)

[2] [HostGator — Changing DNS zones: A, CNAME and MX records](https://www.hostgator.com/help/article/how-to-change-dns-zones-mx-cname-and-a-records)

[3] [cPanel — Zone Editor](https://docs.cpanel.net/cpanel/domains/zone-editor/)
