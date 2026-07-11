# Arquitetura — Backend

> Preencher conforme análise real. Este arquivo descreve a organização em camadas do backend — os padrões de código propriamente ditos (como escrever um Controller/Service) ficam em `patterns/`, não aqui.

## Stack

- Linguagem/Framework: `[A confirmar]`
- Camadas usadas: `[A confirmar — Controller → Service → Repository → Model? Alguma camada extra tipo UseCase/Handler?]`

## Organização de pastas (preencher com a árvore real, resumida)

```
[A preencher]
```

## Regra de responsabilidade por camada (confirmar contra o código real)

- **Controller**: recebe requisição, valida entrada, chama Service, formata resposta. Não deve conter lógica de negócio.
- **Service**: contém a lógica de negócio. Orquestra Repositories e integrações externas.
- **Repository**: acesso a dados. Não deve conter lógica de negócio.
- **Model/Entity**: representação dos dados, sem lógica de orquestração.

Se o código real não seguir esse padrão em algum ponto, **documente a exceção aqui** em vez de assumir silenciosamente que segue — isso evita que uma alteração futura "corrija" algo que na verdade era intencional.

## Pontos de entrada principais

| Ponto de entrada | Caminho | Propósito |
|---|---|---|
| `[A preencher]` | | |

## Ver também

- `indexes/rotas-api.md` para o mapa completo de endpoints.
- `patterns/` para os padrões de código de cada camada.
- `architecture/dependencias.md` para o grafo de quem depende de quem.
