<?php
$whatsapp = $whatsapp ?? [];
$telegram = $telegram ?? [];
$contagens = $contagens ?? ['whatsapp' => 0, 'telegram' => 0];
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

$badge = static fn (array $config): array => !empty($config['ativo'])
    ? ['Ativo', 'bg-success-subtle text-success-emphasis border-success-subtle']
    : ['Inativo', 'bg-secondary-subtle text-secondary-emphasis border-secondary-subtle'];
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="fa fa-plug text-primary me-2"></i>Conectores</h1>
            <p class="text-muted mb-0">Notificações globais da plataforma após assinatura ou liberação de laudos.</p>
        </div>
        <a class="btn btn-outline-primary" href="/platform/conectores/logs"><i class="fa fa-list me-1"></i>Ver logs</a>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><i class="fa fa-circle-check me-2"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><i class="fa fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="alert alert-warning small"><i class="fa fa-shield-heart me-2"></i>As configurações são globais e exclusivas de superadmin. Ative um conector somente após validar o destino administrativo e a política de privacidade aplicável.</div>

    <div class="row g-4">
        <?php foreach ([
            ['tipo' => 'whatsapp', 'titulo' => 'WhatsApp', 'icone' => 'fa-brands fa-whatsapp', 'config' => $whatsapp, 'url' => '/platform/conectores/whatsapp', 'descricao' => 'Evolution API para alertas administrativos.'],
            ['tipo' => 'telegram', 'titulo' => 'Telegram', 'icone' => 'fa-brands fa-telegram', 'config' => $telegram, 'url' => '/platform/conectores/telegram', 'descricao' => 'Bot API para alertas administrativos.'],
        ] as $card):
            [$status, $class] = $badge($card['config']);
        ?>
        <div class="col-lg-6">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-3 bg-light p-3 text-primary"><i class="<?= $card['icone'] ?> fa-xl"></i></div>
                            <div><h2 class="h5 mb-1"><?= $card['titulo'] ?></h2><p class="small text-muted mb-0"><?= $card['descricao'] ?></p></div>
                        </div>
                        <span class="badge border <?= $class ?>"><?= $status ?></span>
                    </div>
                    <dl class="row small mb-4">
                        <dt class="col-5 text-muted">Último teste</dt>
                        <dd class="col-7 mb-2"><?= !empty($card['config']['ultimo_teste_em']) ? htmlspecialchars(date('d/m/Y H:i', strtotime((string) $card['config']['ultimo_teste_em']))) : 'Ainda não testado' ?></dd>
                        <dt class="col-5 text-muted">Resultado</dt>
                        <dd class="col-7 mb-0"><?= htmlspecialchars((string) ($card['config']['ultimo_teste_mensagem'] ?? 'Sem histórico')) ?></dd>
                    </dl>
                    <div class="d-flex gap-2">
                        <a class="btn btn-primary" href="<?= $card['url'] ?>"><i class="fa fa-gear me-1"></i>Configurar</a>
                        <a class="btn btn-outline-secondary" href="/platform/conectores/logs?tipo=<?= $card['tipo'] ?>"><i class="fa fa-clock-rotate-left me-1"></i><?= (int) ($contagens[$card['tipo']] ?? 0) ?> logs</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
