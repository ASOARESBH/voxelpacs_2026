#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('public/assets/js/reports/reports-autosave.js', 'utf8');

(async () => {
  let fetchCalls = [];
  let diagnostics = { htmlLength: 11, textLength: 0, hasClinicalContent: false };
  const status = {
    textContent: 'salvo agora',
    classList: { add() {}, remove() {} },
  };
  const listeners = [];
  const editor = {
    extractSecoes: () => ({ corpo: diagnostics.hasClinicalContent ? '<p>Texto sintético</p>' : '<p><br></p>' }),
    getContentDiagnostics: () => ({ ...diagnostics }),
  };
  const sandbox = {
    console: {
      debug: (...args) => listeners.push(args),
    },
    document: {
      getElementById: (id) => id === 'autosave-status' ? status : null,
      addEventListener: (_event, callback) => listeners.push(callback),
    },
    window: {
      VoxelReports: { editor },
      setInterval: () => 1,
    },
    setInterval: () => 1,
    fetch: async (_url, options) => {
      fetchCalls.push(options);
      return { json: async () => ({ ok: true, saved_at: '12:00:00' }) };
    },
  };

  vm.runInNewContext(source, sandbox, { filename: 'reports-autosave.js' });
  const autosave = sandbox.window.VoxelReports.autosave;
  autosave.init({ reportId: 123, csrf: 'synthetic-csrf', readonly: false, templateId: 0 });

  const skipped = await autosave.save('auto');
  assert.equal(skipped.ok, true);
  assert.equal(skipped.skipped, true);
  assert.equal(skipped.reason, 'empty_payload');
  assert.equal(fetchCalls.length, 0, 'Autosave vazio não pode chamar fetch.');
  assert.equal(status.textContent, 'salvo agora', 'O status visual conhecido deve permanecer intacto.');
  assert.equal(JSON.stringify(listeners).includes('<p><br></p>'), false, 'Telemetria não pode conter HTML clínico.');

  diagnostics = { htmlLength: 24, textLength: 14, hasClinicalContent: true };
  const saved = await autosave.save('auto');
  assert.equal(saved.ok, true);
  assert.equal(fetchCalls.length, 1, 'Autosave com conteúdo deve chamar fetch uma vez.');
  assert.equal(JSON.parse(fetchCalls[0].body).modo, 'auto');

  diagnostics = { htmlLength: 11, textLength: 0, hasClinicalContent: false };
  const manual = await autosave.save('rascunho');
  assert.equal(manual.ok, true);
  assert.equal(fetchCalls.length, 2, 'Salvamento manual não deve ser bloqueado pelo guard cliente.');
  assert.equal(JSON.parse(fetchCalls[1].body).modo, 'rascunho');

  console.log('OK: autosave automático vazio é ignorado, conteúdo é enviado e rascunho manual permanece permitido.');
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
