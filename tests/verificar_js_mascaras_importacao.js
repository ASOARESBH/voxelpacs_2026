const fs = require('fs');

const arquivo = '/home/ubuntu/voxelpacs_2026/app/Views/medicos/form.php';
const conteudo = fs.readFileSync(arquivo, 'utf8');
const inicio = conteudo.indexOf('// ─── MÓDULO DE MÁSCARAS');
const fim = conteudo.indexOf('// ─── MÓDULO DE ASSINATURA');

if (inicio < 0 || fim < 0 || fim <= inicio) {
  throw new Error('Não foi possível localizar o módulo JavaScript de máscaras.');
}

const modulo = conteudo
  .slice(inicio, fim)
  .replace(/<\?=\s*\(int\)\s*\$medicoId\s*\?>/g, '1');

new Function(modulo);

const obrigatorios = [
  '/templates/importar/analisar',
  '/templates/importar/confirmar',
  'modalRevisarImportacao',
  'origem === \'importado\'',
  't.revisar == 1',
];

for (const trecho of obrigatorios) {
  if (!modulo.includes(trecho) && !conteudo.includes(trecho)) {
    throw new Error(`Trecho obrigatório ausente: ${trecho}`);
  }
}

console.log('JavaScript de importação DOCX e badges verificado com sucesso.');
