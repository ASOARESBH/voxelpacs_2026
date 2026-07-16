# Padrão — i18n (Tradução / Multi-idioma)

## Regra inegociável

**Toda string nova visível ao usuário nasce traduzida nos 3 idiomas suportados (pt_BR, en, es), nunca hardcoded numa view.** Isso vale para qualquer tela nova ou alterada, não só para uma migração planejada — é etapa obrigatória do Modo Enterprise (ver `SKILL.md`) desde 2026-07-15. Se você está escrevendo `<h1>Algum Texto</h1>` numa view, pare e adicione a chave nos 3 arquivos de `lang/` antes de continuar.

## Mecanismo (decisão estrutural, não reabrir sem motivo forte)

- Arquivos de tradução em PHP puro: `lang/pt_BR.php`, `lang/en.php`, `lang/es.php` — cada um retorna um array associativo `'chave' => 'texto'`. `pt_BR` é o idioma padrão e o fallback de qualquer chave ausente nos outros dois.
- **Por que não gettext**: exige compilação de `.mo` e configuração de locale do SO — frágil em hospedagem compartilhada (mesmo ambiente que já limita este projeto a PDO puro, sem Composer garantido em produção — ver `docs/MANUAL_TECNICO.md` §3.1). Arrays PHP são só `require`, sem dependência externa.
- Helper de leitura: `App\Core\Translator` (`app/Core/Translator.php`) — classe estática simples, sem Service/Repository, seguindo o mesmo padrão direto já usado no resto do projeto (`App\Core\Auth`, `App\Core\TenantContext`).
- Função global curta para uso nas views: `t('chave')` (`app/helpers.php`, carregado no `bootstrap.php`). Não usar `Translator::t()` diretamente na view — sempre `t()`.

## Convenção de nomenclatura de chave

`modulo.tela.elemento` — três segmentos, minúsculo, `snake_case` dentro de cada segmento se precisar de mais de uma palavra.

Exemplos reais (ver `lang/pt_BR.php`):
- `negocios.index.titulo`, `negocios.index.botao_novo`, `negocios.index.coluna_cnpj`
- `negocios.status.ativo` (para valores de enum exibidos como badge/label)
- `comum.idioma.pt_br` — vocabulário genuinamente reutilizável entre telas (não específico de um módulo) vai no namespace `comum.*`, não duplicado em cada tela que precisar dele.

## Como adicionar uma chave nova

1. Escolher a chave seguindo a convenção acima — checar primeiro se já existe algo parecido em `comum.*` antes de criar uma nova específica do módulo.
2. Adicionar a chave **nos 3 arquivos ao mesmo tempo** (`lang/pt_BR.php`, `lang/en.php`, `lang/es.php`) — nunca só num. Isso não é opcional nem "depois eu traduzo": se a chave não existir em `en`/`es`, o fallback silencioso para pt_BR mascara o esquecimento (ver `diagnostics/i18n.md` para o script que pega isso).
3. Na view, usar `<?= htmlspecialchars(t('modulo.tela.elemento')) ?>` — sempre com `htmlspecialchars()` em volta, `t()` não escapa por conta própria (retorna string crua, igual qualquer outro dado).
4. Para texto usado dentro de atributo JS (`onsubmit="return confirm('...')"`), usar `addslashes(t('...'))` em vez de `htmlspecialchars()` — contexto diferente, escaping diferente. Ver exemplo em `app/Views/platform/negocios/index.php` (`negocios.index.confirma_suspender`).
5. Para um valor de enum do banco (ex: `status` de `bi_tenants`) virar label traduzido, não usar `ucfirst($valor)` cru — mapear cada valor conhecido do enum para uma chave (`modulo.status.<valor>`) com fallback pro `ucfirst()` só para um valor realmente inesperado. Ver `negocios/index.php` (`$statusLabels`).

## Idioma efetivo da requisição

- Hoje (2026-07-15): só por Negócio (tenant), sem override por usuário individual — decisão consciente para manter o escopo desta fase menor; reavaliar só se um caso de uso real pedir.
- Ordem de resolução: `bi_tenants.idioma_padrao` do tenant ativo (via `TenantContext`) → `pt_BR` como fallback global se não houver tenant ativo (rotas `/platform/*`, públicas, ou tenant sem idioma setado).
- Resolvido **uma vez por request**, dentro de `TenantMiddleware::handle()` (`Translator::setLocale($tenant->idioma_padrao)`), não recalculado a cada chamada de `t()`.

## Onde NÃO presumir que já existe

Não existe hoje troca de idioma dentro da mesma sessão sem trocar de tenant/deslogar, nem preferência de idioma por usuário — ver decisão acima. Não existe tradução de conteúdo vindo de fora (nome de paciente, texto de laudo, mensagem HL7) — isso é dado clínico, não string de UI, e está fora do escopo deste mecanismo.

## Validar antes de considerar pronto

Ver `diagnostics/i18n.md`.
