<?php
/** @var array $tenant */
/** @var array $emissao */
/** @var array $linhas */
/** @var array $filtros */
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><style>
@page { margin: 18mm 11mm 16mm; }
body { font-family: DejaVu Sans, sans-serif; color:#17253d; font-size:9px; }
.brand { display:table; width:100%; border-bottom:2px solid #1a56db; padding-bottom:8px; margin-bottom:12px; }
.brand-logo { display:table-cell; width:72px; vertical-align:middle; }
.brand-logo img { max-width:60px; max-height:45px; }
.brand-title { display:table-cell; vertical-align:middle; }
h1 { margin:0; font-size:17px; color:#173f88; } .sub { margin-top:3px; color:#54667d; }
.verify { display:table-cell; width:190px; text-align:right; vertical-align:middle; }
.verify img { width:58px; height:58px; } .verify small { display:block; color:#54667d; }
.meta { width:100%; border-collapse:collapse; margin:0 0 10px; } .meta td { padding:4px 6px; border:1px solid #dce4ef; } .label { color:#53657c; font-weight:bold; width:16%; } .code { font-family:monospace; color:#173f88; }
table.data { width:100%; border-collapse:collapse; } table.data th { background:#173f88; color:#fff; padding:6px 4px; font-size:8px; text-align:left; } table.data td { border-bottom:1px solid #dce4ef; padding:5px 4px; vertical-align:top; } table.data tr:nth-child(even) td { background:#f5f8fc; }
.muted { color:#65758a; } .footer { position:fixed; bottom:-8mm; left:0; right:0; border-top:1px solid #dce4ef; padding-top:4px; color:#65758a; font-size:8px; }
</style></head><body>
<div class="brand"><div class="brand-logo"><?php if (!empty($tenant['logo_data_uri'])): ?><img src="<?= htmlspecialchars($tenant['logo_data_uri']) ?>" alt="<?= htmlspecialchars(t('auditoria.pdf.logo')) ?>"><?php endif; ?></div><div class="brand-title"><h1><?= htmlspecialchars(t('auditoria.pdf.titulo')) ?></h1><div class="sub"><?= htmlspecialchars($tipo_label) ?> · <?= htmlspecialchars(t('auditoria.pdf.verificavel')) ?></div></div><div class="verify"><img src="<?= htmlspecialchars($emissao['qr_data_uri']) ?>" alt="<?= htmlspecialchars(t('auditoria.pdf.qr_alt')) ?>"><small><?= htmlspecialchars(t('auditoria.pdf.qr_instrucao')) ?></small></div></div>
<table class="meta"><tr><td class="label"><?= htmlspecialchars(t('auditoria.pdf.tenant')) ?></td><td><?= htmlspecialchars($tenant['razao_social'] ?: $tenant['nome']) ?></td><td class="label"><?= htmlspecialchars(t('auditoria.pdf.emitido_em')) ?></td><td><?= htmlspecialchars($emissao['emitido_em']->format('d/m/Y H:i:s')) ?></td></tr><tr><td class="label"><?= htmlspecialchars(t('auditoria.pdf.cnpj')) ?></td><td><?= htmlspecialchars($tenant['cnpj'] ?: '—') ?></td><td class="label"><?= htmlspecialchars(t('auditoria.pdf.emitido_por')) ?></td><td><?= htmlspecialchars($usuario_nome) ?></td></tr><tr><td class="label"><?= htmlspecialchars(t('auditoria.pdf.periodo')) ?></td><td><?= htmlspecialchars($filtros['data_de'] . ' a ' . $filtros['data_ate']) ?></td><td class="label"><?= htmlspecialchars(t('auditoria.pdf.codigo')) ?></td><td class="code"><?= htmlspecialchars($emissao['codigo_publico']) ?></td></tr><tr><td class="label"><?= htmlspecialchars(t('auditoria.pdf.integridade')) ?></td><td class="code"><?= htmlspecialchars($emissao['manifesto_hash_curto']) ?></td><td class="label"><?= htmlspecialchars(t('auditoria.pdf.validacao')) ?></td><td class="code"><?= htmlspecialchars($emissao['url_validacao']) ?></td></tr></table>
<table class="data"><thead><tr><th><?= htmlspecialchars(t('auditoria.coluna.data')) ?></th><th><?= htmlspecialchars(t('auditoria.coluna.autor')) ?></th><?php if ($tipo === 'clinica'): ?><th><?= htmlspecialchars(t('auditoria.coluna.assumido_em')) ?></th><th><?= htmlspecialchars(t('auditoria.coluna.tempo')) ?></th><th><?= htmlspecialchars(t('auditoria.coluna.peer_review')) ?></th><?php endif; ?><th><?= htmlspecialchars(t('auditoria.coluna.evento')) ?></th><th><?= htmlspecialchars(t('auditoria.coluna.entidade')) ?></th><th><?= htmlspecialchars(t('auditoria.coluna.contexto')) ?></th><th>IP</th><th><?= htmlspecialchars(t('auditoria.coluna.regiao')) ?></th></tr></thead><tbody><?php foreach ($linhas as $linha): ?><tr><td><?= htmlspecialchars($linha['data']) ?></td><td><?= htmlspecialchars($linha['autor']) ?></td><?php if ($tipo === 'clinica'): ?><td><?= htmlspecialchars($linha['assumido_em']) ?></td><td><?= htmlspecialchars($linha['duracao']) ?></td><td><?= htmlspecialchars($linha['peer_review']) ?></td><?php endif; ?><td><?= htmlspecialchars($linha['evento']) ?></td><td><?= htmlspecialchars($linha['entidade']) ?></td><td><?= htmlspecialchars($linha['contexto']) ?></td><td><?= htmlspecialchars($linha['ip']) ?></td><td><?= htmlspecialchars($linha['regiao']) ?></td></tr><?php endforeach; ?></tbody></table>
<div class="footer">VOXEL PACS · <?= htmlspecialchars(t('auditoria.pdf.rodape')) ?> · <?= htmlspecialchars(t('auditoria.pdf.codigo')) ?> <?= htmlspecialchars($emissao['codigo_publico']) ?> · <?= htmlspecialchars(t('auditoria.pdf.qr_aviso')) ?></div>
</body></html>
