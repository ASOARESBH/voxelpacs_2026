#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const factorySource = fs.readFileSync('public/assets/js/shared/voxel-quill-factory.js', 'utf8');
const source = fs.readFileSync('public/assets/js/reports/reports-editor.js', 'utf8');

/** Cada cenário roda em sandbox próprio para isolar o estado do módulo IIFE. */
function extractFor(html, textContent) {
  const root = { innerHTML: html, textContent };
  class FakeQuill {
    constructor() { this.root = root; }
  }
  const sandbox = {
    window: {
      VoxelReports: {},
      VoxelQuill: { factory: {} },
      createVoxelQuillEditor: () => new FakeQuill(),
    },
  };
  vm.runInNewContext(factorySource, sandbox, { filename: 'voxel-quill-factory.js' });
  sandbox.window.createVoxelQuillEditor = () => new FakeQuill();
  vm.runInNewContext(source, sandbox, { filename: 'reports-editor.js' });
  const editor = sandbox.window.VoxelReports.editor;
  editor.init({ readonly: false });
  return editor.extractSecoes();
}

// A máscara só importa um ponto de partida. Após qualquer edição, o HTML atual
// do Quill deve seguir completo para o autosave e para o PDF, sem reinterpretar
// headings ou descartar medidas, palavras e formatação; só o espaçamento
// estrutural redundante é compactado.
{
  const html = [
    '<p><strong>ANGIOTOMOGRAFIA COMPUTADORIZADA DA AORTA</strong></p>',
    '<p>Exame realizado conforme protocolo.</p>',
    '<p>AQUI FOI INSERIDA UMA OBSERVAÇÃO PELO MÉDICO.</p>',
    '<p><strong>Medida máxima:</strong> 14 mm</p>',
    '<p style="margin-bottom: 28px;">Parágrafo com espaçamento clínico preservado.</p>',
    '<p><br></p><p><br></p>',
  ].join('');

  const secoes = extractFor(html, 'ANGIOTOMOGRAFIA Exame AQUI FOI INSERIDA UMA OBSERVAÇÃO Medida máxima 14 mm Parágrafo com espaçamento clínico preservado.');
  const expected = html
    .replace(' style="margin-bottom: 28px;"', '')
    .replace('<p><br></p><p><br></p>', '<p><br></p>');

  assert.deepEqual(Object.keys(secoes), ['corpo'], 'O editor deve enviar um único corpo clínico ao backend.');
  assert.equal(secoes.corpo, expected, 'O HTML atual deve ser compactado sem dividir ou reordenar conteúdo.');
  assert.match(secoes.corpo, /AQUI FOI INSERIDA UMA OBSERVAÇÃO PELO MÉDICO/);
  assert.match(secoes.corpo, /14 mm/);
  assert.doesNotMatch(secoes.corpo, /margin-bottom/);
  assert.doesNotMatch(secoes.corpo, /<p><br><\/p><p><br><\/p>/);
  console.log('OK 1: editor envia o HTML completo atual com espaçamento estrutural normalizado.');
}

// Conteúdo livre sem headings canônicos continua íntegro e não é realocado para
// seções históricas antes do salvamento.
{
  const html = '<h2>Método personalizado</h2><p>Texto livre revisado.</p><p>Nova medida: 2,4 cm.</p>';
  const secoes = extractFor(html, 'Método personalizado Texto livre revisado. Nova medida: 2,4 cm.');

  assert.deepEqual(Object.keys(secoes), ['corpo']);
  assert.equal(secoes.corpo, html);
  console.log('OK 2: conteúdo livre com headings personalizados permanece íntegro.');
}

console.log('Todos os cenários de fonte única do editor passaram.');
