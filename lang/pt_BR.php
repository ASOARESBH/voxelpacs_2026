<?php
// Idioma padrão do sistema — fallback de qualquer chave ausente nos outros idiomas.
// Convenção de chave: modulo.tela.elemento (ver patterns/padrao-i18n.md).
return [
    // Vocabulário comum, reutilizável entre telas
    'comum.idioma.pt_br' => 'Português (Brasil)',
    'comum.idioma.en'    => 'Inglês',
    'comum.idioma.es'    => 'Espanhol',

    // /platform/negocios (listagem) — tela piloto de i18n (2026-07-15)
    'negocios.index.titulo'            => 'Negócios',
    'negocios.index.subtitulo'         => 'Gerencie os negócios (tenants) cadastrados na plataforma',
    'negocios.index.botao_novo'        => 'Novo Negócio',
    'negocios.index.coluna_id'         => '#',
    'negocios.index.coluna_nome'       => 'Nome / Razão Social',
    'negocios.index.coluna_cnpj'       => 'CNPJ',
    'negocios.index.coluna_plano'      => 'Plano',
    'negocios.index.coluna_status'     => 'Status',
    'negocios.index.coluna_cadastro'   => 'Cadastro',
    'negocios.index.coluna_acoes'      => 'Ações',
    'negocios.index.vazio_titulo'      => 'Nenhum negócio cadastrado.',
    'negocios.index.vazio_link'        => 'Cadastrar primeiro',
    'negocios.index.acao_editar'       => 'Editar',
    'negocios.index.acao_acessar'      => 'Acessar como este negócio',
    'negocios.index.acao_suspender'    => 'Suspender',
    'negocios.index.confirma_suspender'=> 'Suspender este negócio?',
    'negocios.status.ativo'            => 'Ativo',
    'negocios.status.suspenso'         => 'Suspenso',
    'negocios.status.cancelado'        => 'Cancelado',
    'negocios.status.trial'            => 'Trial',

    // /platform/negocios (form) — só o campo novo de idioma (2026-07-15)
    'negocios.form.campo_idioma' => 'Idioma padrão deste Negócio',
];
