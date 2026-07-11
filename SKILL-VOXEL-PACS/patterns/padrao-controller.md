# Padrão — Controller

> Preencher com um exemplo real extraído do próprio código assim que analisado. O objetivo é ter aqui um template mínimo e um exemplo real lado a lado, para que criar um controller novo signifique "copiar o padrão", não "inventar um novo".

## Responsabilidade

Receber requisição → validar entrada → chamar Service → formatar resposta. **Aspiracional**: nenhuma lógica de negócio, query direta ao banco, ou chamada direta a integração externa deveria ficar aqui.

**Realidade observada no código (confirmar antes de "corrigir" para o ideal acima):** os controllers de `app/Controllers/Platform/` (`ServidorPacsController`, `NegociosController`) fazem PDO direto — sem Service nem Repository — e é assim na maior parte do projeto até 2026-07-10. `EstudosController` é a exceção, com `EstudosService`/`EstudosRepository`. Ao alterar um controller existente, siga o padrão *daquele arquivo específico* (não force uma camada Service nova só por consistência com o ideal — isso vira um refactor não pedido). Só introduza Service/Repository quando a tarefa pedir explicitamente ou quando a lógica for complexa o bastante para justificar (ex: `EstudosService`).

## Template mínimo (PDO direto — padrão real da maioria dos Platform controllers)

```php
public function algumaAcao(int $id): void {
    $pdo = Database::getInstance();
    try {
        // 1. validação simples de entrada ($_POST/$_GET)
        // 2. query(ies) via prepared statement
        // 3. resposta: $this->view(...) para tela, $this->json([...]) para AJAX,
        //    $this->redirect(...) para POST que não é AJAX
    } catch (\Throwable $e) {
        error_log("[NomeController::algumaAcao] " . $e->getMessage());
        $this->json(['success' => false, 'message' => 'Erro ao processar.'], 500);
    }
}
```

## Exemplo real do projeto

`app/Controllers/Platform/NegociosController.php::listarUnidades()` — endpoint AJAX simples, tenant-scoped, usando o helper `Controller::json()`:

```php
public function listarUnidades(int $id): void {
    $pdo = Database::getInstance();
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM bi_tenant_unidades_dicom WHERE tenant_id = ? ORDER BY nome ASC
        ");
        $stmt->execute([$id]);
        $this->json(['success' => true, 'unidades' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    } catch (\Throwable $e) {
        error_log("[NegociosController::listarUnidades] " . $e->getMessage());
        $this->json(['success' => false, 'message' => 'Erro ao listar unidades.'], 500);
    }
}
```

Note o `WHERE tenant_id = ?` — todo endpoint que recebe um `{id}` de recurso pertencente a um tenant deve escopar a query pelo tenant, nunca confiar só no `id` do recurso (evita IDOR entre Negócios).

## Checklist ao criar/alterar um Controller

- [ ] Validação de entrada está presente (mínimo: campos obrigatórios checados antes de tocar no banco)
- [ ] Se o controller já usa Service/Repository, a lógica nova entra lá — não misture os dois estilos no mesmo arquivo
- [ ] Se o controller é PDO direto (padrão atual da maioria de `Platform/`), siga o mesmo estilo do resto do arquivo
- [ ] Toda query com `{id}` de rota usa prepared statement E escopa por `tenant_id`/dono do recurso quando aplicável
- [ ] Resposta AJAX usa `$this->json([...])`, não `header()`/`echo json_encode()` manual (ver `indexes/rotas-api.md`, seção de convenções)
- [ ] Permissão/autenticação: para rotas `/platform/*`, já é garantida por `Router::dispatch()` — não precisa checar de novo no Controller
- [ ] Antes de considerar pronto: confirmar que o método referenciado em `routes/*.php` realmente existe na classe (ver `memory/convencoes.md` — rota para método ausente só quebra em runtime)
