# Padrão — Service

## Responsabilidade

Contém a lógica de negócio. Orquestra um ou mais Repositories e, quando necessário, integrações externas (Orthanc, HL7, RIS/HIS). É a camada onde regras clínicas/operacionais são aplicadas — não no Controller, não no Model.

## Template mínimo

```
[A preencher com a sintaxe real do projeto:
 1. Recebe dados já validados do Controller
 2. Aplica regra de negócio
 3. Chama Repository(s) para persistência
 4. Dispara evento(s), se aplicável (registrar em indexes/eventos-filas.md)
 5. Retorna resultado ao Controller]
```

## Exemplo real do projeto

`[A preencher com um Service real e pequeno]`

## Checklist ao criar/alterar um Service

- [ ] A regra de negócio está isolada aqui, não vazou para Controller ou Repository
- [ ] Se este Service dispara evento/publica em fila, isso está registrado em `indexes/eventos-filas.md`
- [ ] Se este Service chama integração externa (Orthanc/HL7), o comportamento de falha está tratado explicitamente (não apenas deixado propagar)
- [ ] Dependências deste Service em outros Services/Repositories estão refletidas em `architecture/dependencias.md`
