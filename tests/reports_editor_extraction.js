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

/** Cada cenário roda em um sandbox/VM próprio para isolar o estado do módulo IIFE. */
function extractFor(children, textContent) {
  const root = { children, textContent };
  class FakeQuill {
    constructor() { this.root = root; }
  }
  const sandbox = { window: { VoxelReports: {} }, Quill: FakeQuill, console: { warn() {} } };
  vm.runInNewContext(source, sandbox, { filename: 'reports-editor.js' });
  const editor = sandbox.window.VoxelReports.editor;
  editor.init({ readonly: false });
  return editor.extractSecoes();
}

// ── Cenário 1: extração normal, título bate exatamente (regressão do fix de 2026-08-08) ──
{
  const secoes = extractFor([
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
  ], 'Exame Radiografia de tórax Técnica Incidências PA e perfil Achados Sem alterações Conclusão Exame normal Recomendação');

  assert.match(secoes.exame, /Radiografia de tórax/);
  assert.match(secoes.tecnica, /Incidências PA e perfil/);
  assert.match(secoes.achados, /Sem alterações/);
  assert.match(secoes.conclusao, /Exame normal/);
  assert.equal(secoes.recomendacao, '<p><br></p>');
  console.log('OK 1: editor extrai seções por título mesmo sem data-secao.');
}

// ── Cenário 2: bug de report_id=18 (2026-08-10) — médico renomeia os headings
// para "Método"/"Análise" (fora do vocabulário canônico). Antes do fix, isso
// zerava as 5 seções e o autosave apagava o conteúdo já salvo no banco.
{
  const secoes = extractFor([
    makeNode('H4', 'Método:', '<h4>Método:</h4>'),
    makeNode('P', 'Tomografia computadorizada de pescoço', '<p>Tomografia computadorizada de pescoço</p>'),
    makeNode('H4', 'Análise:', '<h4>Análise:</h4>'),
    makeNode('P', 'Linha 1 de achados', '<p>Linha 1 de achados</p>'),
    makeNode('P', 'Linha 2 de achados', '<p>Linha 2 de achados</p>'),
  ], 'Método: Tomografia computadorizada de pescoço Análise: Linha 1 de achados Linha 2 de achados');

  const total = Object.values(secoes).join('');
  assert.ok(total.includes('Tomografia computadorizada de pescoço'), 'conteúdo do heading não reconhecido não pode ser perdido');
  assert.ok(total.includes('Linha 1 de achados') && total.includes('Linha 2 de achados'));
  console.log('OK 2: heading fora do vocabulário canônico não apaga o laudo — conteúdo preservado.');
}

// ── Cenário 3: heading com pontuação de fechamento ("Técnica:") agora reconhece ──
{
  const secoes = extractFor([
    makeNode('H4', 'Exame', '<h4>Exame</h4>'),
    makeNode('P', 'Texto exame', '<p>Texto exame</p>'),
    makeNode('H4', 'Técnica:', '<h4>Técnica:</h4>'),
    makeNode('P', 'Texto técnica', '<p>Texto técnica</p>'),
  ], 'Exame Texto exame Técnica: Texto técnica');

  assert.match(secoes.tecnica, /Texto técnica/, 'heading com dois-pontos deveria bater com "tecnica"');
  console.log('OK 3: heading com pontuação de fechamento (dois-pontos) ainda é reconhecido.');
}

// ── Cenário 4: heading criado via toolbar como H2 (não H4) com título canônico ──
{
  const secoes = extractFor([
    makeNode('H2', 'Achados', '<h2>Achados</h2>'),
    makeNode('P', 'Texto achados via H2', '<p>Texto achados via H2</p>'),
  ], 'Achados Texto achados via H2');

  assert.match(secoes.achados, /Texto achados via H2/, 'H2 com título canônico deveria ser reconhecido como marcador');
  console.log('OK 4: heading H2 (não só H4) com título canônico é reconhecido via toolbar de formatação.');
}

console.log('Todos os cenários de extractSecoes() passaram.');
