# Padrão — Segurança

> Este arquivo define o padrão esperado. Para o checklist de verificação a rodar antes/depois de uma alteração, ver `diagnostics/seguranca.md`.

## Áreas cobertas

- SQL Injection — sempre prepared statements / bind de parâmetros (ver `patterns/padrao-sql.md`)
- XSS — sanitização/escaping de saída em toda renderização de dado vindo de usuário ou de integração externa (inclusive dados HL7/DICOM, que podem conter texto livre)
- CSRF — confirmar mecanismo usado: `[A confirmar — token CSRF, SameSite cookie, ambos?]`
- JWT/OAuth — confirmar expiração, rotação de refresh token, e onde a chave/secret é armazenada (nunca hardcoded): `[A confirmar]`
- Uploads — validação de tipo/tamanho de arquivo, scanning se aplicável: `[A confirmar]`
- Permissões — ver `architecture/auth-e-permissoes.md`
- Sessões — invalidação em logout, expiração por inatividade: `[A confirmar]`
- Auditoria — toda ação sensível (acesso a exame, alteração de laudo, alteração de permissão) deve gerar log de auditoria: `[A confirmar se isso já existe; se não, é um gap a sinalizar, não a assumir como resolvido]`

## Regra geral

Em um sistema PACS, dado de paciente é o ativo mais sensível do sistema. Na dúvida entre "mais simples" e "mais seguro", documentar explicitamente a escolha e por quê — nunca silenciosamente escolher o caminho mais simples em código que toca autenticação, permissão, ou dado clínico.
