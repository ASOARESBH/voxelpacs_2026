# Reformulação responsiva do Report

## Problema identificado

A tela do laudo usa `height: 100vh` com `overflow: hidden` no `body`, uma grade rígida de `30% / 70%`, duas colunas com rolagem independente e um CHAT com histórico limitado a `245px`. Em telas estreitas, a coluna lateral fica comprimida, o editor permanece parcialmente fora do viewport e o histórico do CHAT é suprimido por uma segunda rolagem. O card de exame também encurta o Study UID por PHP.

## Decisão visual

A tela passa a usar uma composição fluida com três regras. No desktop largo, a grade mantém duas áreas, mas com coluna lateral baseada em `clamp(360px, 34vw, 520px)` e editor ocupando o restante. No tablet, a coluna lateral fica acima do editor em uma única rolagem contínua. No mobile, todos os cards passam a largura total, campos quebram linhas e o editor mantém altura mínima confortável sem criar overflow horizontal.

Nenhum conteúdo será escondido por `overflow: hidden`, `text-overflow: ellipsis`, altura fixa ou `max-height` no card de dados. Rolagens internas ficam limitadas ao editor Quill e, somente quando necessário para desempenho, ao histórico do CHAT com expansão natural da página.

## Impacto

Os partials de paciente, exame, CHAT, histórico e editor continuam os mesmos; apenas a composição CSS é alterada, com uma correção pontual para exibir o Study UID completo. Os endpoints, payloads, permissões, assinatura e dados do banco não mudam.

## Rollback

O rollback consiste em reverter o commit de CSS, view e documentação. Nenhuma migration ou alteração de dados é necessária.
