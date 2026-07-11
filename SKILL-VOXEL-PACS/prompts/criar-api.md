# Prompt interno — Criar API/Endpoint

```
1. Verificar em indexes/rotas-api.md se algo equivalente já existe.
2. Seguir patterns/padrao-api.md e patterns/padrao-controller.md.
3. Definir: método, rota, autenticação, permissão, formato de resposta (sucesso e erro).
4. Se o endpoint expõe dado de paciente/estudo, validar permissão de acesso ao recurso específico, não só autenticação genérica.
5. Implementar Controller → Service → Repository, cada um no seu papel (ver patterns/).
6. Registrar em indexes/rotas-api.md.
7. Rodar diagnostics/seguranca.md.
```
