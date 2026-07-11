# Workflow — Refatoração

1. **Justificar antes de começar** — qual `diagnostics/` (código morto, duplicação, arquivo/função grande, dependência circular) motivou isso? Refatoração sem gatilho concreto tende a virar escopo infinito.
2. **Delimitar fronteira** — listar exatamente quais arquivos entram no escopo, usando `architecture/dependencias.md` para garantir que nada fora da fronteira será afetado sem querer.
3. **Preservar comportamento externo** — a refatoração não deve mudar contrato de API, formato de evento, ou schema de banco, a menos que isso seja explicitamente parte do objetivo (e nesse caso, tratar como `nova-funcionalidade.md` também).
4. **Refatorar incrementalmente** — preferir vários passos pequenos e validáveis a uma reescrita grande de uma vez.
5. **Validar a cada passo** com os `diagnostics/` relevantes, não só no final.
6. **Atualizar documentação** — `patterns/` (se o padrão mudou), `modules/`, `architecture/dependencias.md`.
