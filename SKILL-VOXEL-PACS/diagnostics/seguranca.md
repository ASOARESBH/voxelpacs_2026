# Diagnóstico — Segurança

> Checklist a rodar antes de considerar uma alteração pronta. Ver `patterns/padrao-seguranca.md` para o padrão esperado por trás de cada item.

## Checklist

- [ ] **SQL Injection**: toda entrada de usuário em query passa por prepared statement/bind, nunca concatenação de string.
- [ ] **XSS**: toda saída de dado vindo de usuário ou integração externa (incluindo texto livre de mensagens HL7) é escapada/sanitizada na renderização.
- [ ] **CSRF**: rotas que alteram estado (POST/PUT/DELETE) protegidas conforme mecanismo do projeto.
- [ ] **Autenticação**: token/sessão validado em toda rota que não é explicitamente pública.
- [ ] **Autorização**: permissão verificada para o recurso específico (não só "usuário está logado") — especialmente em rotas que retornam dado de paciente/estudo.
- [ ] **Uploads**: tipo e tamanho de arquivo validados; se aplicável, verificado que o arquivo não pode ser executado/interpretado pelo servidor.
- [ ] **Segredos**: nenhuma chave/senha/token hardcoded no código alterado.
- [ ] **Auditoria**: ação sensível (acesso a exame, alteração de laudo/permissão) gera registro de auditoria, se essa capacidade existir no projeto.

## Cuidado adicional para PACS

Dado de paciente é o ativo mais sensível do sistema. Em caso de dúvida sobre se um dado é sensível o suficiente para exigir os cuidados acima, tratar como sensível por padrão.
