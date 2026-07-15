# Memória — Convenções do Projeto

> Fatos permanentes sobre como o VOXEL PACS é escrito e organizado. Diferente de `patterns/` (que explica o padrão de cada tipo de arquivo com checklist), este arquivo é uma lista plana de convenções gerais — o tipo de coisa que se explica a alguém no primeiro dia.

## Nomenclatura

- Idioma usado em nomes de variáveis/funções/classes: `[A confirmar — português, inglês, misto?]`
- Idioma usado em nomes de tabelas/colunas: `[A confirmar]`
- Convenção de case (camelCase, snake_case, PascalCase) por contexto: `[A confirmar]`

## Estrutura de commits e branches

- Convenção de nome de branch: `[A confirmar]`
- Convenção de mensagem de commit: ver `prompts/criar-commit.md` (ajustar se o projeto já tiver uma própria diferente)

## Regras de negócio transversais (aplicam-se a múltiplos módulos)

- **Matching de InstitutionName é case-insensitive e trim, nunca exato.** Sempre que código precisar casar um `institution_name` vindo do Orthanc (`bi_pacs_estudos`) contra um valor cadastrado manualmente (`bi_pacs_roteamento`, `bi_negocio_institution_names`, `bi_tenant_unidades_dicom`), normalize com `strtolower(trim($valor))` antes de comparar. Convenção observada primeiro em `ServidorPacsController::sincronizar()`, reaplicada em `ServidorPacsController::getInstitutionStats()` (2026-07-10). Nomes cadastrados manualmente podem divergir da tag DICOM real do equipamento em caixa/acentuação — não presumir igualdade exata.
- **Rotas com método de controller ausente não geram erro em tempo de build.** O `Router` faz `call_user_func_array([$controller, $action], ...)` sem checar `method_exists` antes — uma rota apontando para um método inexistente só quebra em runtime, no primeiro clique. Encontrados 3 casos assim em `NegociosController` no mesmo commit (`unidades*` — corrigido em 2026-07-10 —, `uploadLogo`, `enviarTokenAcesso` — ainda quebrados). Ao adicionar rotas novas, confirmar que o método existe antes de considerar a tarefa pronta; ao investigar "por que essa tela dá erro 500", checar isso antes de qualquer outra hipótese.
- **`app/Core/Database.php` configura o PDO globalmente com `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ`.** Qualquer `$pdo->query(...)->fetchAll()` ou `->fetch()` sem passar `\PDO::FETCH_ASSOC` explicitamente retorna `stdClass`, nunca array. O padrão `$x->campo ?? $x['campo'] ?? default` é uma bomba-relógio: em PHP 8, acessar `[]` num `stdClass` é `Error` fatal ("Cannot use object of type stdClass as array"), não um warning suprimível por `??`. Em views alimentadas por query PDO direta (sem `FETCH_ASSOC` explícito), usar **só** sintaxe de objeto (`$x->campo ?? default`); nunca misturar `->` e `[]` como fallback um do outro. Esse exato bug já regrediu 3x em `app/Views/platform/negocios/index.php` (ver `modules/negocios.md`, hotfix 2026-07-15) porque commits de feature grandes reescreveram o arquivo inteiro por cima de um fix já aplicado. O mesmo padrão quebrado também existe (não corrigido, fora do escopo do hotfix) em `app/Views/platform/plans/index.php` e `form.php`.

## Glossário de domínio

| Termo | Significado no contexto deste projeto |
|---|---|
| Study / Estudo | Um exame DICOM completo, pode conter múltiplas séries |
| Series / Série | Conjunto de instâncias (imagens) dentro de um estudo |
| Instance / Instância | Uma imagem/objeto DICOM individual |
| Laudo | `[A confirmar — terminologia exata usada no projeto]` |
| `[A preencher]` | |

## Regra de manutenção

Este arquivo deve conter só o que é **verdadeiro em qualquer parte do sistema**. Convenção específica de uma camada (ex: como um Controller deve ser escrito) pertence a `patterns/`, não aqui.
