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
- [ ] **Middleware fantasma**: para toda classe em `app/Middlewares/`, confirmar que ela é de fato instanciada em algum ponto do fluxo real (`public/index.php`, `Router::dispatch()`) — não basta a classe existir e "prometer" a proteção certa. Achado real neste projeto (`PlatformAdminMiddleware`, verificado 2026-07-15): a classe existia, nunca era chamada, e `docs/MANUAL_TECNICO.md` documentou isso como P0 aberto por meses — só depois se descobriu que `Router::dispatch()` já tinha um guard inline equivalente adicionado em commit posterior, e o documento nunca foi atualizado. Sempre testar o comportamento real (`php` isolado chamando `Router::dispatch()` com sessão simulada, sem precisar de banco) em vez de confiar só na leitura do código ou de documentação antiga.

## Cuidado adicional para PACS

Dado de paciente é o ativo mais sensível do sistema. Em caso de dúvida sobre se um dado é sensível o suficiente para exigir os cuidados acima, tratar como sensível por padrão.
