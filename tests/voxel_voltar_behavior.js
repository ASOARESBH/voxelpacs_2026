#!/usr/bin/env node
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/shared/voxel-voltar.js'), 'utf8');

function criarAmbiente(referrer, historyLength) {
    let retornos = 0;
    const navegacoes = [];
    const listeners = {};
    const location = {
        href: 'https://server.voxelpacs.com.br/medicos/2/mascaras/6/visualizar',
        origin: 'https://server.voxelpacs.com.br',
        assign(destino) {
            navegacoes.push(destino);
        },
    };
    const window = {
        location,
        history: {
            length: historyLength,
            back() {
                retornos += 1;
            },
        },
    };
    const document = {
        referrer,
        addEventListener(evento, callback) {
            listeners[evento] = callback;
        },
    };
    const context = vm.createContext({
        window,
        document,
        URL,
        console,
        Element: class Element {},
        HTMLAnchorElement: class HTMLAnchorElement {},
    });
    vm.runInContext(source, context, { filename: 'voxel-voltar.js' });
    return { window, navegacoes, listeners, getRetornos: () => retornos };
}

{
    const ambiente = criarAmbiente('https://server.voxelpacs.com.br/medicos/2/edit?aba=mascaras', 2);
    ambiente.window.voxelVoltar('/medicos/2/edit?aba=mascaras');
    assert.strictEqual(ambiente.getRetornos(), 1, 'Histórico interno deveria ser restaurado.');
    assert.deepStrictEqual(ambiente.navegacoes, [], 'Fallback não deveria ser usado com histórico interno.');
}

{
    const ambiente = criarAmbiente('', 1);
    ambiente.window.voxelVoltar('/medicos/2/edit?aba=mascaras');
    assert.strictEqual(ambiente.getRetornos(), 0, 'Acesso direto não deveria voltar no histórico.');
    assert.deepStrictEqual(ambiente.navegacoes, ['/medicos/2/edit?aba=mascaras'], 'Acesso direto deveria usar o fallback.');
}

{
    const ambiente = criarAmbiente('https://externo.exemplo/rota', 4);
    ambiente.window.voxelVoltar('/estudos');
    assert.strictEqual(ambiente.getRetornos(), 0, 'Referrer externo não deveria receber history.back().');
    assert.deepStrictEqual(ambiente.navegacoes, ['/estudos'], 'Referrer externo deveria usar o fallback interno.');
}

console.log('OK: voxelVoltar decide corretamente entre histórico interno e fallback seguro.');
