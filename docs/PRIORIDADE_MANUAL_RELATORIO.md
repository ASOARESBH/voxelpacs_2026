# Prioridade Manual no Laudário e no Relatório Médico

## Objetivo

A prioridade operacional efetiva é a prioridade manual confirmada quando ela existir; caso contrário, o sistema usa a tag DICOM válida e, na ausência dela, normaliza o valor legado. O valor efetivo deve ser o mesmo na Worklist, no contexto do médico, no detalhamento de produtividade, no CSV e no PDF.

## Confirmação dupla

A alteração manual exige duas confirmações na interface: uma caixa de ciência de que o valor será apresentado ao médico e uma confirmação final do navegador. O backend exige também o campo `confirmar_prioridade`; pedidos sem essa confirmação são recusados. A auditoria registra que houve confirmação explícita, sem copiar conteúdo de laudo.

## Exibição autorizada

O laudário autorizado mostra a prioridade efetiva. Quando existir override manual, apresenta o marcador de alteração manual e o valor DICOM original para conferência. O relatório de produtividade e suas exportações usam o mesmo valor efetivo e expõem o marcador e a origem apenas no contexto administrativo já autorizado.

## Proteções preservadas

As alterações não alteram estudo, tenant, unidade, modalidade, permissão de acesso ao laudo ou regras de assinatura. A mudança de prioridade continua protegida por sessão, CSRF, autorização de Gestão de Exames, escopo tenant/modalidade, lock transacional e auditoria.

## Validação

O teste `test/validar_prioridade_manual_relatorio.php` confere estaticamente a confirmação dupla no cliente e backend, o bloqueio sem confirmação, a evidência no log de auditoria, a prioridade efetiva no relatório e seus formatos de exportação, e as traduções pt-BR/en/es. O teste não acessa dados clínicos.
