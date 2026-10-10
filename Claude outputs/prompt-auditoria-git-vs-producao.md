# PROMPT — AUDITORIA SOMENTE-LEITURA: GIT x SERVIDOR DE PRODUÇÃO (VOXEL PACS)

> Para colar no agente com acesso ao servidor real (VS Code chat / gateway / SSH).
> Objetivo: provar se o que roda em produção é **exatamente** o que está no GitHub (`ASOARESBH/voxelpacs_2026`), ou se algum agente/pessoa alterou o servidor sem commitar (ou commitou só no servidor, sem push, ou está rodando outra branch).

---

## 0. REGRAS INVIOLÁVEIS (leia antes de qualquer comando)

Esta tarefa é **100% SOMENTE LEITURA**. É proibido:

- `git pull`, `git merge`, `git rebase`, `git checkout`, `git switch`, `git reset`, `git clean`, `git stash`, `git add`, `git commit`, `git push`, `git apply`, `git restore`, `git cherry-pick`;
- editar, apagar, mover, renomear ou copiar por cima de qualquer arquivo em `/var/www/voxelpacs`, `/etc`, `/opt`, `/usr/local/sbin`;
- executar `voxelpacs-deploy`, `voxelpacs-deploy-force` ou qualquer script de deploy/migração (apenas **ler** com `cat`/`sha256sum`);
- reiniciar/recarregar serviços (`systemctl restart|reload|stop`, `nginx -s reload`, `php-fpm reload`);
- executar `INSERT/UPDATE/DELETE/ALTER/DROP/CREATE` no banco (apenas `SELECT` em `information_schema`/catálogos).

Permitido escrever **somente** em `/tmp/voxel-audit-<AAAAMMDD>/` (saídas, patches de evidência).
Use sempre `git --no-optional-locks` (e `export GIT_OPTIONAL_LOCKS=0`) para não escrever no `.git`.
**Nunca imprima segredos**: senhas, tokens, `APP_SECRET`, `DB_PASSWORD`, `ORTHANC_PASS`, `VOXEL_REPORT_DELIVERY_WORKER_TOKEN`, chaves privadas, URLs de remote com token embutido. Ao mostrar diffs/arquivos, mascare valores (`***`). Nunca cole o conteúdo de `.env`.
Se algo não for localizado, escreva literalmente: **"Não localizado no código analisado."** — não invente.
Se algum comando exigir permissão que você não tem, registre e siga em frente; não tente contornar.

---

## 1. CONTEXTO JÁ LEVANTADO (linha de base do lado do repositório / máquina local do André)

Use isto como referência para comparar — **confirme com `git ls-remote` porque pode ter mudado**.

- Repositório: `https://github.com/ASOARESBH/voxelpacs_2026.git` — 611 commits, sem tags.
- `origin/main` = `a730377471f5f269b6dbc754a915661e5524254c` (2026-09-19 17:26 UTC, autor `choppon24h-png`, "feat(report-delivery): constrain patient name override scope").
- **`origin/phase74-8b-deploy-20260920`** = `06a87a5…` (2026-09-20 15:47 UTC) — está **3 commits À FRENTE de `main`** (0 atrás): `b84da69` (freeze structured patient name per version), `badcc58` (make patient name migrations additive), `06a87a5` (gate temporary V11 patient-name omission). Mexe em 35 arquivos, inclui migrations e `tests/report_version_patient_name_static.php`. **O nome sugere que é a branch de deploy atual — produção pode estar nela, e não em `main`.**
- `origin/reconcile-runtime` = `0e4a940` (2026-08-31): 36 commits à frente e 172 atrás de `main`; existe porque em **2026-08-27** o servidor tinha alterações não commitadas que foram "reconciliadas" por commits de `deploy@voxelpacs.local` (`5036678`, `6dad239`). **Ou seja: já houve drift real antes.**
- Commits recentes (set/2026) vêm quase todos do autor **`choppon24h-png`** (outro agente/conta — 40 commits), em paralelo com `andreprogramadorbh-ai` (446) e `ASOARESBH` (97). Também existem commits históricos de `VOXEL PACS Deploy <deploy@voxelpacs.com.br>` (feitos no servidor).
- Layout: a **raiz do repositório = `/var/www/voxelpacs/app`** no servidor (`WorkingDirectory=/var/www/voxelpacs/app`; entrypoint `public/index.php`; worker `bin/report_delivery_worker.php`).
- Componentes fora do repositório-app que também precisam ser conferidos contra o Git:
  - `deploy/report-delivery-gateway-bridge/*` (repo) ↔ `/opt/voxelpacs/report-delivery-gateway/` (servidor)
  - `ops/systemd/voxelpacs-report-delivery-worker.service` e `deploy/.../voxelpacs-report-delivery-bridge.service` (repo) ↔ `/etc/systemd/system/` (servidor)
  - Nginx `/etc/nginx/sites-enabled/voxel-api.conf`, scripts `/usr/local/sbin/voxelpacs-*` (incl. `voxelpacs-deploy` e `voxelpacs-deploy-force`), `/etc/voxelpacs/*` (env — **não imprimir**)
- Migrations: `database/migrations/*.sql` (139 arquivos; últimas: `2026-09-18_report_delivery_requests_*`, `2026-09-19_report_delivery_request_patient_name_overrides_postgresql.sql`). Não há framework de migrations — não existe tabela de controle confiável; a aplicação real só se comprova olhando o banco.
- Banco: PostgreSQL 16, schema `voxelpacs_mysql_source`.
- Observação: na máquina do André (Windows) 792 arquivos aparecem como modificados **apenas por diferença CRLF/LF** (`git diff --ignore-cr-at-eol` = vazio). No servidor Linux isso **não** deve ocorrer; se ocorrer, é sinal relevante.

---

## 2. PASSO A PASSO (execute na ordem; cole a saída de cada bloco no relatório)

### 2.1 Identidade do checkout de produção

```bash
export GIT_OPTIONAL_LOCKS=0
OUT=/tmp/voxel-audit-$(date +%Y%m%d); mkdir -p "$OUT"
APP=/var/www/voxelpacs/app
cd "$APP" || exit 1
G="git --no-optional-locks"

date -Is; hostname
$G rev-parse --show-toplevel
$G rev-parse --abbrev-ref HEAD           # branch (ou "HEAD" = detached)
$G rev-parse HEAD 'HEAD^{tree}'
$G log -1 --format='%H%n%ci%n%an <%ae>%n%s'
$G config --get remote.origin.url | sed -E 's#//[^@/]*@#//***@#'   # mascara token
$G branch -vv
$G worktree list
$G stash list
$G reflog -40 --date=iso                 # QUEM/QUANDO moveu o HEAD (pull, reset, checkout...)
stat -c '%y  .git/logs/HEAD (última movimentação do HEAD)' .git/logs/HEAD
find /var/www/voxelpacs -maxdepth 4 -name .git -not -path '*/node_modules/*' 2>/dev/null   # outros repositórios?
```

Registre: branch em produção, SHA do HEAD, se é `detached`, e **quando** foi o último deploy (reflog).

### 2.2 Estado do remoto (sem escrever nada)

```bash
$G ls-remote --heads origin 2>&1 | sed -E 's#//[^@/]*@#//***@#' | head -40
```

(Se falhar por credencial, registre e use os refs `origin/*` já existentes — informando a data do último fetch: `stat -c %y .git/FETCH_HEAD`.)
Somente se `ls-remote` funcionar e os refs locais estiverem defasados, é permitido **um** `git fetch origin` (ele só atualiza `refs/remotes/*`, não o working tree). Registre que foi feito.

### 2.3 HEAD de produção x branches do remoto

```bash
for ref in origin/main origin/phase74-8b-deploy-20260920 origin/reconcile-runtime origin/development; do
  $G rev-parse --verify -q "$ref" >/dev/null || { echo "$ref: NÃO EXISTE localmente"; continue; }
  echo "$ref  sha=$($G rev-parse --short $ref)  prod-tem-a-mais=$($G rev-list --count $ref..HEAD)  prod-tem-a-menos=$($G rev-list --count HEAD..$ref)  tree-igual=$([ "$($G rev-parse HEAD^{tree})" = "$($G rev-parse $ref^{tree})" ] && echo SIM || echo NAO)"
done

echo "--- commits que existem na produção e NÃO estão em origin/main:"
$G log --format='%h %ci %an <%ae> %s' origin/main..HEAD | head -50
echo "--- commits que estão em origin/main e a produção NÃO tem:"
$G log --format='%h %ci %an %s' HEAD..origin/main | head -50
echo "--- em qual(is) branch(es) remota(s) o HEAD de produção está contido:"
$G branch -r --contains HEAD
```

Interpretação: `prod-tem-a-mais > 0` em **todas** as branches = há commit feito no servidor e **nunca enviado** ao GitHub (ex.: autores `deploy@voxelpacs.*`, `admin@voxelpacs.com.br`). `branch -r --contains HEAD` vazio = HEAD órfão do remoto.

### 2.4 Working tree x HEAD (alterações NÃO commitadas no servidor)

```bash
$G status --porcelain=v1 --untracked-files=all | tee "$OUT/status.txt" | head -200
echo "total linhas: $(wc -l < $OUT/status.txt)"

echo "--- resumo por tipo:"; cut -c1-2 "$OUT/status.txt" | sort | uniq -c

echo "--- diff real (bytes) vs HEAD:"
$G diff --stat HEAD | tail -40
echo "--- diff ignorando fim de linha:"
$G diff --ignore-cr-at-eol --stat HEAD | tail -40
echo "--- só mudança de permissão (modo):"
$G diff --summary HEAD | grep -i 'mode change' | head -40
echo "--- lista nome+status:"
$G diff --name-status HEAD | head -200
$G config core.fileMode; $G config core.autocrlf

# guarda o patch completo como evidência (NÃO imprimir no chat sem mascarar segredos)
$G diff HEAD > "$OUT/working-tree-vs-HEAD.patch" 2>/dev/null; wc -c "$OUT/working-tree-vs-HEAD.patch"
```

### 2.5 Arquivos fora do Git dentro do diretório da aplicação

```bash
echo "--- não rastreados (não ignorados):"
$G ls-files -o --exclude-standard | head -200

echo "--- ignorados pelo .gitignore, exceto vendor/storage/node_modules (candidatos a código 'escondido'):"
$G ls-files -o -i --exclude-standard | grep -vE '^(vendor/|storage/|node_modules/)' | head -100

echo "--- backups/resíduos:"
find . -type f \( -name '*.bak*' -o -name '*.orig' -o -name '*.old' -o -name '*.rej' -o -name '*.swp' -o -name '*~' -o -name '*.tmp' \) \
  -not -path './vendor/*' -not -path './storage/*' -not -path './.git/*' -printf '%TY-%Tm-%Td %TH:%TM  %u  %p\n' | sort | tail -100

echo "--- arquivos .php/.sql/.sh/.py/.js/.service modificados DEPOIS do último movimento do HEAD (= editados à mão após o deploy):"
REF=$(mktemp); touch -r .git/logs/HEAD "$REF"
find . -type f -newer "$REF" -not -path './.git/*' -not -path './vendor/*' -not -path './storage/*' \
  -printf '%TY-%Tm-%Td %TH:%TM  %u  %p\n' | sort | tail -150
rm -f "$REF"
```

> Atenção: arquivos alterados **antes** do último movimento do HEAD podem ter sido sobrescritos pelo deploy; por isso o critério forte é o `git diff HEAD` (2.4) e o `sha256` (2.6), não só o mtime.

### 2.6 Verificação byte-a-byte dos arquivos rastreados (independe de mtime e de `git status` cache)

```bash
# recalcula o hash do conteúdo de cada arquivo rastreado e compara com o índice/HEAD
$G ls-files -s | while read mode sha stage path; do
  [ -f "$path" ] || { echo "AUSENTE  $path"; continue; }
  cur=$(git hash-object -- "$path")
  [ "$cur" = "$sha" ] || echo "DIFERE   $path"
done | tee "$OUT/hash-mismatch.txt" | head -100
echo "arquivos divergentes: $(wc -l < $OUT/hash-mismatch.txt)"
```

Compare também o índice com o HEAD: `git diff --cached --stat HEAD` (algo "staged" e não commitado é sinal de trabalho interrompido).

### 2.7 Quem foi o autor das divergências

Para cada arquivo divergente/não rastreado relevante (top 30 mais críticos: `app/Controllers`, `app/Services`, `app/Core`, `routes/`, `public/`, `bin/`, `database/migrations/`):

```bash
stat -c '%y %U %n' <arquivo>               # dono/data no servidor
$G log -3 --format='%h %ci %an %s' -- <arquivo>   # último commit no Git que tocou o arquivo
```

Tente correlacionar o horário do mtime com (a) `reflog`, (b) `last`/`who` (`last -n 30`), (c) `journalctl --since '7 days ago' -t voxelpacs-deploy` (se existir), (d) `ls -la --time-style=long-iso /var/log | grep -i voxel`. Se não houver rastro do autor, diga "autoria não determinável".

### 2.8 O processo real de deploy (leitura)

```bash
ls -la --time-style=long-iso /usr/local/sbin/voxelpacs-* 2>&1
sha256sum /usr/local/sbin/voxelpacs-deploy /usr/local/sbin/voxelpacs-deploy-force 2>&1
cat /usr/local/sbin/voxelpacs-deploy 2>&1 | sed -E 's/(PASS|TOKEN|SECRET|KEY)[A-Z_]*=.*/\1=***/I'
cat /usr/local/sbin/voxelpacs-deploy-force 2>&1 | sed -E 's/(PASS|TOKEN|SECRET|KEY)[A-Z_]*=.*/\1=***/I'
```

Responda objetivamente: o deploy faz `git fetch + reset --hard`? `git pull`? `rsync`/cópia de arquivos? Qual **branch/ref** ele usa? Roda migrations? Reinicia worker/php-fpm? Isso decide se "produção == Git" é garantido pelo processo ou apenas por disciplina.

### 2.9 Componentes fora do diretório `app` (também podem divergir do Git)

```bash
cd "$APP"; G="git --no-optional-locks"
echo "== bridge do gateway (repo x /opt) =="
for f in bridge_server.py dicom_scu.py philips_folder_bridge.py generate_envelope_keypair.py; do
  p=$(sha256sum /opt/voxelpacs/report-delivery-gateway/$f 2>/dev/null | cut -d' ' -f1)
  g=$($G show HEAD:deploy/report-delivery-gateway-bridge/$f 2>/dev/null | sha256sum | cut -d' ' -f1)
  echo "$f  prod=${p:-AUSENTE}  git=$g  $([ "$p" = "$g" ] && echo IGUAL || echo DIFERENTE)"
done
ls -la --time-style=long-iso /opt/voxelpacs/report-delivery-gateway/ 2>&1

echo "== units systemd (repo x /etc/systemd/system) =="
for u in voxelpacs-report-delivery-worker.service voxelpacs-report-delivery-bridge.service voxelpacs-tenant-agent.service; do
  p=$(sha256sum /etc/systemd/system/$u 2>/dev/null | cut -d' ' -f1)
  g=$( ($G show HEAD:ops/systemd/$u 2>/dev/null || $G show HEAD:deploy/report-delivery-gateway-bridge/$u 2>/dev/null) | sha256sum | cut -d' ' -f1)
  echo "$u  prod=${p:-AUSENTE}  git=$g"
done

echo "== nginx =="
sha256sum /etc/nginx/sites-enabled/voxel-api.conf /etc/nginx/sites-enabled/* 2>&1 | head
$G ls-files | grep -iE 'nginx|\.conf$' | head    # existe cópia versionada?
ls -la --time-style=long-iso /var/www/voxelpacs/ 2>&1     # portal-viewer etc.
```

Para cada item sem cópia no Git, registre: **"sem versionamento no Git (drift não verificável)"**.
(Comparação com `git show` pode divergir apenas por CRLF; se `DIFERENTE`, refaça com `tr -d '\r'` nos dois lados antes de concluir.)

### 2.10 Banco de dados x migrations do repositório (somente `SELECT`)

Leia as credenciais `DB_*` do `.env` **sem imprimi-las** e use `psql` em modo leitura. Extraia o que as migrations recentes criam e verifique se existe no banco:

```bash
cd "$APP"
grep -hoiE 'create table( if not exists)? +[a-z0-9_."]+|add column( if not exists)? +[a-z0-9_"]+|create (unique )?index( if not exists)? +[a-z0-9_"]+' \
  database/migrations/2026-09-1[4-9]*_postgresql.sql database/migrations/2026-09-2*_postgresql.sql 2>/dev/null | sort -u
# e o que a branch de deploy adicionaria a mais:
$G diff --name-only origin/main origin/phase74-8b-deploy-20260920 -- database/migrations
```

Com o resultado, confirme no banco (ex.: `SELECT table_name FROM information_schema.tables WHERE table_schema='voxelpacs_mysql_source' AND table_name LIKE 'report_delivery%';` e `information_schema.columns` para colunas novas). Reporte: migration → objeto criado → **existe / não existe** no banco. Migration não aplicada com código dependente dela em produção (ou o inverso) é achado **crítico**.

### 2.11 O que está de fato executando (código carregado ≠ código em disco)

```bash
systemctl status voxelpacs-report-delivery-worker voxelpacs-tenant-agent voxelpacs-report-delivery-bridge --no-pager 2>&1 | grep -E 'Loaded|Active|Main PID|since'
systemctl show voxelpacs-report-delivery-worker -p ActiveEnterTimestamp
ps -eo pid,lstart,cmd | grep -E 'report_delivery_worker|bridge_server|philips_folder_bridge' | grep -v grep
systemctl show php8.3-fpm -p ActiveEnterTimestamp 2>&1
php -i 2>/dev/null | grep -E 'opcache.validate_timestamps|opcache.revalidate_freq'
```

Compare o início de cada processo com a data do último movimento do HEAD (2.1). **Worker iniciado antes do último deploy = ainda executa código antigo** — reporte como divergência de runtime (sem reiniciar).

---

## 3. FORMATO OBRIGATÓRIO DO RELATÓRIO FINAL

**3.1 Veredito (uma linha):**
`GIT == PRODUÇÃO` ou `GIT != PRODUÇÃO`, com a causa principal.

**3.2 Tabela-resumo**

| Verificação | Resultado | Evidência |
|---|---|---|
| Branch/SHA em produção | ... | saída 2.1 |
| Produção == `origin/main`? | SIM/NÃO (a mais / a menos) | 2.3 |
| Produção == `origin/phase74-8b-deploy-20260920`? | SIM/NÃO | 2.3 |
| Commits só no servidor (não enviados ao GitHub) | n (lista) | 2.3 |
| Alterações não commitadas (tracked) | n arquivos (n só EOL / n só modo / n conteúdo) | 2.4 / 2.6 |
| Arquivos não rastreados / ignorados suspeitos | n (lista) | 2.5 |
| Editados após o último deploy (mtime) | n (lista) | 2.5 |
| Bridge / systemd / nginx / sbin x Git | igual / diferente / sem versionamento | 2.8 / 2.9 |
| Migrations x banco | aplicadas / pendentes / adiantadas | 2.10 |
| Processos rodando código antigo | sim/não | 2.11 |

**3.3 Classificação de cada divergência** (uma linha por item, com arquivo e SHA/hash):

- **A** — Alteração no servidor **não commitada** (contém código real; risco de ser perdida no próximo deploy).
- **B** — Commit feito **só no servidor**, ausente no GitHub.
- **C** — Produção em **branch/commit diferente** do esperado (ex.: rodando `phase74-…` enquanto `main` está 3 commits atrás, ou vice-versa).
- **D** — Produção **atrás** do remoto (existe commit novo no GitHub — inclusive de outro agente — ainda não implantado).
- **E** — Arquivo **não rastreado** ou **ignorado** contendo código/config relevante.
- **F** — Drift **fora do repo** (nginx, systemd, sbin, bridge, banco/migrations).
- **G** — Ruído inofensivo (somente EOL ou permissão) — listar mas não tratar como divergência de conteúdo.

**3.4 Para os itens A, B, E:** trecho de diff **mascarado** (máx. ~40 linhas por arquivo) + o mais provável autor/horário e o caminho do patch de evidência em `/tmp/voxel-audit-*/`.

**3.5 Resposta direta à pergunta do André:**
"Algum outro agente alterou o servidor/repositório e não commitou tudo?" — responda **SIM / NÃO / INCONCLUSIVO**, com as 3 evidências mais fortes. Liste também os commits recentes do autor `choppon24h-png` que **ainda não estão** em produção (2.3) e os que estão.

**3.6 Recomendação (não executar):** o menor conjunto de passos para tornar Git e produção idênticos — por exemplo "commitar/absorver o arquivo X em branch dedicada", "fazer merge de `phase74-…` em `main` depois de validar", "aplicar migration Y", "reiniciar worker Z" — sempre pelo fluxo **Git primeiro → deploy pelo processo existente**, nunca editando arquivos direto no servidor.

Ao final, confirme explicitamente: "Nenhum arquivo, serviço ou banco foi alterado durante esta auditoria" (e liste o único write permitido: pasta `/tmp/voxel-audit-*` e, se ocorreu, o `git fetch`).
