# Incidente — acionamento automático do VOXEL Desktop na Worklist

## Sintoma

Ao carregar a Worklist, o navegador tentava abrir o aplicativo Desktop sem que o usuário selecionasse um estudo ou acionasse o menu **Abrir**.

## Causa confirmada

A própria view da Worklist continha um botão global de Desktop e um detector automático executado no carregamento da página. Esse detector inseria um frame oculto e tentava acionar um protocolo de aplicativo para inferir se o Desktop estava instalado. O comportamento era independente das rotas autorizadas por estudo.

## Correção

O botão global e o detector foram removidos integralmente. A única ação que permanece para o Desktop é a opção específica do menu **Abrir** de uma linha de estudo, que passa pelo controlador de autorização, pelo escopo de tenant e pelo fluxo de launch temporário.

## Limites preservados

Esta alteração não consulta, cria, abre ou transmite dados clínicos. Também não modifica o mecanismo de autorização do estudo, as rotas de manifesto, a integração Orthanc, os outros visualizadores ou as regras de acesso existentes.
