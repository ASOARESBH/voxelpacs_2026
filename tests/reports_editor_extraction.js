#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('public/assets/js/reports/reports-editor.js', 'utf8');
const makeNode = (tagName, textContent, outerHTML, dataset = {}) => ({
  nodeType: 1,
  tagName,
  textContent,
  outerHTML,
  dataset,
});

const root = {
  children: [
    makeNode('H4', 'Exame', '<h4>Exame</h4>'),
    makeNode('P', 'Radiografia de tórax', '<p>Radiografia de tórax</p>'),
    makeNode('H4', 'Técnica', '<h4>Técnica</h4>'),
    makeNode('P', 'Incidências PA e perfil', '<p>Incidências PA e perfil</p>'),
    makeNode('H4', 'Achados', '<h4>Achados</h4>'),
    makeNode('P', 'Sem alterações', '<p>Sem alterações</p>'),
    makeNode('H4', 'Conclusão', '<h4>Conclusão</h4>'),
    makeNode('P', 'Exame normal', '<p>Exame normal</p>'),
    makeNode('H4', 'Recomendação', '<h4>Recomendação</h4>'),
    makeNode('P', '', '<p><br></p>'),
  ],
  textContent: 'Exame Radiografia de tórax Técnica Incidências PA e perfil Achados Sem alterações Conclusão Exame normal Recomendação',
};

class FakeQuill {
  constructor() {
    this.root = root;
  }
}

const sandbox = {
  window: { VoxelReports: {} },
  Quill: FakeQuill,
  console: { warn() {} },
};
vm.runInNewContext(source, sandbox, { filename: 'reports-editor.js' });
const editor = sandbox.window.VoxelReports.editor;
editor.init({ readonly: false });
const secoes = editor.extractSecoes();

assert.match(secoes.exame, /Radiografia de tórax/);
assert.match(secoes.tecnica, /Incidências PA e perfil/);
assert.match(secoes.achados, /Sem alterações/);
assert.match(secoes.conclusao, /Exame normal/);
assert.equal(secoes.recomendacao, '<p><br></p>');
console.log('OK: editor extrai seções por título mesmo sem data-secao.');
