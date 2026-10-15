#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const factorySource = fs.readFileSync('public/assets/js/shared/voxel-quill-factory.js', 'utf8');
const source = fs.readFileSync('public/assets/js/reports/reports-editor.js', 'utf8');

function textFromHtml(html) {
  return String(html || '')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<[^>]*>/g, ' ')
    .replace(/&nbsp;|&#160;|&#xA0;/gi, ' ')
    .replace(/\s+/g, ' ');
}

/** Cada cenário roda em sandbox próprio para isolar o estado do módulo IIFE. */
function createEditor(html) {
  const root = {
    innerHTML: html,
    addEventListener() {},
  };
  class FakeQuill {
    constructor() {
      this.root = root;
      this.pasteCalls = [];
      // Simula o construtor consumindo/reprocessando o DOM inicial.
      this.root.innerHTML = '';
      this.clipboard = {
        dangerouslyPasteHTML: (_index, value, sourceName) => {
          this.pasteCalls.push({ value, source: sourceName });
          this.root.innerHTML = value;
        },
      };
    }

    setText(value) {
      this.root.innerHTML = value;
    }

    getText() {
      return textFromHtml(this.root.innerHTML) + '\n';
    }

    getSelection() { return null; }
  }

  const sandbox = {
    document: {
      getElementById: (id) => id === 'editor-container' ? root : null,
    },
    window: {
      VoxelReports: {},
      VoxelQuill: { factory: {} },
      setTimeout,
      createVoxelQuillEditor: () => new FakeQuill(),
    },
  };
  vm.runInNewContext(factorySource, sandbox, { filename: 'voxel-quill-factory.js' });
  sandbox.window.createVoxelQuillEditor = () => new FakeQuill();
  vm.runInNewContext(source, sandbox, { filename: 'reports-editor.js' });
  const editor = sandbox.window.VoxelReports.editor;
  editor.init({ readonly: false });
  return { editor, quill: editor.getQuill(), root };
}

// O HTML entregue pelo servidor é capturado antes do construtor Quill e
// reidratado pela API oficial, sem depender do parsing implícito do DOM.
{
  const html = '<p><strong>CONTEÚDO INICIAL DO LAUDO</strong></p><p>Texto clínico preservado.</p>';
  const { editor, quill, root } = createEditor(html);
  const secoes = editor.extractSecoes();

  assert.equal(root.innerHTML, html, 'A hidratação explícita deve restaurar o HTML inicial.');
  assert.equal(quill.pasteCalls[0].source, 'silent');
  assert.equal(secoes.corpo, html);
  const diagnostics = editor.getContentDiagnostics();
  assert.equal(diagnostics.htmlLength, html.length);
  assert.equal(diagnostics.textLength, 'CONTEÚDO INICIAL DO LAUDO Texto clínico preservado.'.length);
  assert.equal(diagnostics.hasClinicalContent, true);
  console.log('OK 1: HTML inicial server-side hidratado explicitamente no Quill.');
}

// A fonte de verdade é o HTML atual do Quill; espaçamento inline redundante é
// normalizado sem dividir, reordenar ou descartar conteúdo clínico.
{
  const html = [
    '<p><strong>ANGIOTOMOGRAFIA COMPUTADORIZADA DA AORTA</strong></p>',
    '<p>Exame realizado conforme protocolo.</p>',
    '<p>AQUI FOI INSERIDA UMA OBSERVAÇÃO PELO MÉDICO.</p>',
    '<p><strong>Medida máxima:</strong> 14 mm</p>',
    '<p style="margin-bottom: 28px;">Parágrafo com espaçamento clínico preservado.</p>',
    '<p><br></p><p><br></p>',
  ].join('');

  const { editor } = createEditor(html);
  const secoes = editor.extractSecoes();
  const expected = html
    .replace(' style="margin-bottom: 28px;"', '')
    .replace('<p><br></p><p><br></p>', '<p><br></p>');

  assert.deepEqual(Object.keys(secoes), ['corpo'], 'O editor deve enviar um único corpo clínico ao backend.');
  assert.equal(secoes.corpo, expected, 'O HTML atual deve ser compactado sem dividir ou reordenar conteúdo.');
  assert.match(secoes.corpo, /AQUI FOI INSERIDA UMA OBSERVAÇÃO PELO MÉDICO/);
  assert.match(secoes.corpo, /14 mm/);
  assert.doesNotMatch(secoes.corpo, /margin-bottom/);
  assert.doesNotMatch(secoes.corpo, /<p><br><\/p><p><br><\/p>/);
  console.log('OK 2: editor envia o HTML completo atual com espaçamento estrutural normalizado.');
}

// Conteúdo livre com heading personalizado permanece íntegro.
{
  const html = '<h2>Método personalizado</h2><p>Texto livre revisado.</p><p>Nova medida: 2,4 cm.</p>';
  const { editor } = createEditor(html);
  const secoes = editor.extractSecoes();

  assert.deepEqual(Object.keys(secoes), ['corpo']);
  assert.equal(secoes.corpo, html);
  console.log('OK 3: conteúdo livre com headings personalizados permanece íntegro.');
}

// Conteúdo colado é carregado pela API do Quill e tem estilos inline
// redundantes removidos sem perder marcação clínica permitida, como tabela.
{
  const { editor, quill } = createEditor('<p><br></p>');
  editor.loadConteudoLivre('<p style="color:red">Texto colado permitido.</p><table><tbody><tr><td>Medida</td></tr></tbody></table>');
  const corpo = editor.extractSecoes().corpo;

  assert.match(corpo, /Texto colado permitido/);
  assert.match(corpo, /<table>/);
  assert.doesNotMatch(corpo, /style=/);
  assert.equal(quill.pasteCalls.at(-1).source, 'silent');
  console.log('OK 4: colagem de HTML permitido é hidratada e normalizada sem conteúdo clínico descartado.');
}

// Placeholder Quill não é conteúdo clínico e deve ser representado de forma
// canônica para que o autosave possa bloqueá-lo no modo automático.
{
  const { editor } = createEditor('<p><br></p>');
  assert.equal(editor.extractSecoes().corpo, '<p><br></p>');
  const diagnostics = editor.getContentDiagnostics();
  assert.equal(diagnostics.htmlLength, 11);
  assert.equal(diagnostics.textLength, 0);
  assert.equal(diagnostics.hasClinicalContent, false);
  console.log('OK 5: editor vazio é diagnosticado sem texto clínico.');
}

// O botão ql-table usa exclusivamente o handler próprio; nenhum módulo table
// inexistente é declarado na configuração da factory.
assert.match(factorySource, /table: \(\) => insertBasicTable\(quill\)/);
assert.doesNotMatch(factorySource, /table:\s*false/);
console.log('OK 6: botão de tabela não tenta importar o módulo Quill inexistente.');

console.log('Todos os cenários de hidratação, extração e diagnóstico do editor passaram.');
