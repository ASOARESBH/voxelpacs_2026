# Conectores de Comunicação — Operação Segura

## Escopo

O módulo **Plataforma → Infraestrutura → Conectores** é global e exclusivo de superadmin. Ele envia alertas administrativos quando um laudo é assinado ou liberado. Não cria contas, não recebe mensagens e não altera o resultado clínico do laudo.

> A assinatura ou liberação é concluída primeiro. As chamadas ao WhatsApp e Telegram ocorrem apenas depois do commit e cada conector é isolado por tratamento de erro. Uma indisponibilidade externa nunca deve bloquear o laudo.

## Banco e estado inicial

A migration `2026-08-21_conectores_comunicacao_postgresql.sql` cria as tabelas globais `bi_conectores_config` e `bi_conectores_log`. Os dois conectores iniciam **inativos**. Credenciais são cifradas com `App\Core\Crypto` antes de serem armazenadas.

| Conector | Dados necessários | Teste disponível |
|---|---|---|
| WhatsApp | URL da Evolution API, API Key, instância e número administrativo no formato DDI+DDD+número | Consulta o estado da instância; o resultado esperado é `open`. |
| Telegram | Bot Token e Chat ID administrativo | Chama `getMe` e retorna o username do bot. |

## Homologação recomendada

Primeiro, salve os dados mantendo a chave **Ativar notificações** desligada. Em seguida, use **Testar conexão** para validar a credencial. Apenas depois de obter resultado positivo, informe o destino administrativo e ative o conector.

O botão de teste usa a configuração já persistida. Portanto, após alterar URL, instância, API Key ou token, clique primeiro em **Salvar configuração** e depois em **Testar conexão**.

## Privacidade e segurança

As mensagens de evento incluem unidade, paciente, exame, modalidade, data e médico conforme a configuração funcional aprovada. Antes de ativar cada conector, o superadmin deve confirmar que o canal administrativo, os participantes autorizados e a política de retenção são compatíveis com a LGPD e com o contrato aplicável.

Os logs registram resultado técnico, HTTP status e resposta sanitizada. Eles não armazenam API Key, Bot Token nem cabeçalhos de autorização. O campo de credencial nos formulários permanece em branco após salvar; deixá-lo vazio preserva o segredo existente.

## Diagnóstico e reversão

Use **Conectores → Ver logs** para verificar os últimos 100 envios e testes. Falhas são registradas com status `ERRO`; a assinatura do laudo continua válida mesmo nessas situações.

Para interromper novos envios, desmarque **Ativar notificações** no conector correspondente e salve. Essa reversão não altera laudos já assinados ou liberados e não exige migration adicional.
