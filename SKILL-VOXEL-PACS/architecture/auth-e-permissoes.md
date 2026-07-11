# Arquitetura — Autenticação e Permissões

## Autenticação

- Mecanismo: `[A confirmar — JWT, OAuth2, sessão tradicional?]`
- Onde vive o código: `[A preencher caminho]`
- Fluxo de renovação de token/sessão: `[A confirmar]`
- Integração com SSO/HIS para login unificado, se houver: `[A confirmar]`

## Autorização / Permissões

- Modelo (RBAC simples, ACL por recurso, permissões por exame/instituição?): `[A confirmar]`
- Onde vive a definição de roles/permissões: `[A preencher caminho]`
- Como permissão de acesso a um estudo específico é verificada (por instituição, por médico responsável, por convênio?): `[A confirmar — este é um ponto crítico em PACS multi-tenant]`

## Perguntas que toda alteração nesta área deve responder

Antes de alterar qualquer coisa em auth/permissões, confirme e registre:

1. Essa mudança pode fazer um usuário ver um estudo de outra instituição/paciente que não deveria? 
2. Essa mudança afeta apenas a UI (esconder botão) ou também o backend (bloquear a requisição)? Mudança só na UI nunca é suficiente sozinha.
3. Existe teste automatizado cobrindo esse caminho de permissão? Se não, sinalizar como gap antes de prosseguir.
