# Informações úteis ao médico

## Finalidade e escopo

O campo **Informações** permite que um usuário autorizado da Gestão de Exames registre uma única observação textual útil para ciência do médico responsável pelo laudo. Não é um canal de conversa, não possui respostas, histórico de mensagens ou notificações automáticas.

O conteúdo é limitado a 1.000 caracteres, é tratado como texto simples e só aparece no Laudário para usuário vinculado a um perfil médico autorizado a acessar aquele laudo. Se estiver vazio, não há indicador nem popup no card do paciente.

## Segurança e auditoria

O valor é normalizado em caixa alta no navegador e novamente no servidor antes da validação e da persistência. A auditoria usa marcadores booleanos explícitos compatíveis com PostgreSQL e MySQL; o conteúdo do campo permanece fora dos eventos auditáveis.

| Controle | Regra |
|---|---|
| Alteração | Exige sessão, módulo Gestão de Exames, CSRF, tenant efetivo e escopo de modalidade. |
| Persistência | Mantida na linha do estudo e sempre limitada por `id` e `tenant_id`. |
| Renderização | Escapada no servidor; o texto só é exposto após clique de médico autorizado. |
| Auditoria | Registra criação, alteração ou limpeza pela presença do campo e pelo autor, sem reproduzir o texto informado. |
| Rollback | Não remove colunas ou histórico automaticamente; uma migration corretiva deve preservar rastreabilidade. |

## Fluxo

1. Em **Gerenciar estudo**, o cartão Informações abre um único editor de texto.
2. O backend valida e grava uma única versão vigente, criando evento sanitizado de auditoria.
3. No Report, o botão amarelo **Informações** aparece ao lado da idade somente se houver texto e o usuário for médico autorizado.
4. O clique abre um popup com o conteúdo em texto simples.
