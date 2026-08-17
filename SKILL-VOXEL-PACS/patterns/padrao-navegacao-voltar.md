# Padrão de Navegação: Voltar com Histórico Seguro

## Objetivo

Usar o histórico do navegador quando a página anterior pertence ao próprio VOXEL PACS e preservar uma rota-pai conhecida como fallback para acessos diretos, favoritos e novas abas. O padrão evita perda de contexto em telas com estado carregado de forma assíncrona sem redirecionar o usuário para fora do sistema.

## Implementação central

O helper compartilhado está em `public/assets/js/shared/voxel-voltar.js`. Ele expõe `window.voxelVoltar(fallbackUrl)` e trata automaticamente links internos cujo texto, `title` ou `aria-label` indica **Voltar** ou **Cancelar**. Os layouts PACS, Platform, Laudário, Autenticação e Portal carregam o arquivo com a versão central de assets.

| Cenário | Comportamento |
|---|---|
| Referrer interno e histórico disponível | Executa `history.back()` e restaura a página anterior. |
| Nova aba, acesso direto ou referrer externo | Navega para o `fallbackUrl` declarado. |
| Link comum de Voltar/Cancelar com `href` interno | O próprio `href` é usado como fallback. |
| Fluxo que precisa preservar navegação fixa | Adicionar `data-voxel-voltar-skip`. |

## Uso recomendado

Declare sempre uma rota-pai explícita para botões importantes, principalmente em páginas independentes ou que possam abrir em nova aba.

```html
<a href="/medicos/42/edit?aba=mascaras"
   data-voxel-voltar="/medicos/42/edit?aba=mascaras"
   class="btn-pacs-outline">
    Voltar para Máscaras
</a>
```

O atributo não exige `onclick`; o listener compartilhado previne a navegação fixa, decide entre histórico interno e fallback, e mantém o `href` como alternativa sem JavaScript.

## Exceções

Não usar o padrão para ações que possuem semântica de negócio além de navegar. A seleção de empresa é o exemplo atual: **Voltar ao login** chama `/logout` e precisa encerrar o contexto de empresa. Esse link recebe `data-voxel-voltar-skip`.

Botões de cancelar que fecham modais ou reinicializam controles locais não são navegação de página e não devem receber o atributo.

## Estado assíncrono e parâmetros de URL

Páginas com abas ou dados carregados via `fetch` precisam reconstruir o conteúdo ao serem abertas diretamente. A edição de Médico usa `ativarAbaMedico()` e `carregarConteudoAbaMedico()` para que tanto um clique quanto `?aba=mascaras` chamem a mesma rotina de carregamento. Nunca depender exclusivamente de um evento de clique para montar o estado solicitado pela URL.

## Validação mínima

1. Abrir a tela destino diretamente pela URL de fallback.
2. Navegar internamente e usar Voltar.
3. Abrir o destino em nova aba e usar Voltar.
4. Confirmar que exceções de logout, submissão e modal mantêm o comportamento próprio.
5. Executar `php tests/voltar_navegacao_static.php` e `node --check public/assets/js/shared/voxel-voltar.js`.
