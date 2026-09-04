<?php
/** @var array|null $chat */
/** @var bool $readonly */
$chat = is_array($chat ?? null) ? $chat : [];
$chatMessages = is_array($chat['messages'] ?? null) ? $chat['messages'] : [];
$chatSubjects = is_array($chat['subjects'] ?? null) ? $chat['subjects'] : [];
$chatGroups = is_array($chat['groups'] ?? null) ? $chat['groups'] : [];
$chatUsers = is_array($chat['users'] ?? null) ? $chat['users'] : [];
$chatCanCommunicateCritical = (bool) array_filter(
    $chatSubjects,
    static fn(array $subject): bool => ($subject['codigo'] ?? '') === 'achado_critico'
);
$chatPending = ($chat['status'] ?? '') === 'pendente';
$chatCount = count($chatMessages);
$chatStatusText = $chatPending
    ? t('report_chat.pendente')
    : ($chatCount === 0
        ? t('report_chat.sem_mensagens')
        : str_replace(':count', (string) $chatCount, t($chatCount === 1
            ? 'report_chat.interacao_count_singular'
            : 'report_chat.interacao_count_plural')));
$recipientType = $chat['destinatario_tipo'] ?? 'grupo';
$recipientGroupId = (int) ($chat['destinatario_grupo_id'] ?? $chat['destinatario_grupo'] ?? 0);
$recipientUser = (int) ($chat['destinatario_user_id'] ?? 0);
$selectedRecipient = $recipientType === 'usuario' && $recipientUser > 0
    ? 'usuario:' . $recipientUser
    : 'grupo:' . $recipientGroupId;
?>
<div class="pacs-card reports-card reports-chat-card" id="card-chat"
     data-chat-report-id="<?= (int) ($chat['report_id'] ?? ($report->id ?? 0)) ?>"
     data-chat-pending="<?= $chatPending ? '1' : '0' ?>">
    <button type="button" class="pacs-card-header reports-chat-header reports-chat-toggle"
            data-bs-toggle="collapse" data-bs-target="#chat-laudo-body"
            aria-expanded="false" aria-controls="chat-laudo-body">
        <span><i class="fa fa-comments"></i> <?= htmlspecialchars(t('report_chat.titulo')) ?></span>
        <span class="reports-chat-header-meta">
            <span id="chat-status-badge" class="chat-status-badge <?= $chatPending ? 'is-pending' : 'is-clear' ?>">
                <?= htmlspecialchars($chatStatusText) ?>
            </span>
            <i class="fa fa-chevron-down chat-toggle-icon" aria-hidden="true"></i>
        </span>
    </button>
    <div class="collapse reports-chat-collapse" id="chat-laudo-body">
        <div class="pacs-card-body reports-card-body reports-chat-body">
        <p class="reports-chat-subtitle"><?= htmlspecialchars(t('report_chat.subtitulo')) ?></p>

        <div id="chat-pending-alert" class="reports-chat-alert <?= $chatPending ? '' : 'd-none' ?>">
            <i class="fa fa-circle-exclamation"></i>
            <span><?= htmlspecialchars(t('report_chat.aviso_pendente')) ?></span>
        </div>

        <div id="chat-messages" class="reports-chat-messages" aria-live="polite">
            <?php if (!$chatMessages): ?>
                <div class="reports-chat-empty" id="chat-empty-state">
                    <i class="fa fa-message"></i>
                    <span><?= htmlspecialchars(t('report_chat.sem_mensagens')) ?></span>
                </div>
            <?php else: ?>
                <?php foreach ($chatMessages as $message): ?>
                    <article class="reports-chat-message">
                        <div class="reports-chat-message-meta">
                            <strong><?= htmlspecialchars((string) ($message['autor_nome'] ?? 'Usuário')) ?></strong>
                            <time datetime="<?= htmlspecialchars((string) ($message['criado_em'] ?? '')) ?>">
                                <?= htmlspecialchars((string) ($message['criado_em'] ?? '')) ?>
                            </time>
                        </div>
                        <div class="reports-chat-message-body"><?= nl2br(htmlspecialchars((string) ($message['corpo'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if (!$readonly): ?>
        <form id="reportChatForm" class="reports-chat-form" novalidate>
            <div class="reports-chat-field">
                <label for="chatDestinatario"><?= htmlspecialchars(t('report_chat.selecione_destinatario')) ?></label>
                <select id="chatDestinatario" class="form-select form-select-sm">
                    <?php if ($chatGroups): ?>
                    <optgroup label="<?= htmlspecialchars(t('report_chat.destinatarios_grupos')) ?>">
                    <?php foreach ($chatGroups as $group): ?>
                        <?php $groupId = (int) ($group['id'] ?? 0); ?>
                        <option value="grupo:<?= $groupId ?>" <?= ('grupo:' . $groupId) === $selectedRecipient ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($group['label'] ?? 'Grupo')) ?>
                            <?php if (isset($group['total_membros'])): ?> (<?= (int) $group['total_membros'] ?>)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                    <?php if ($chatUsers): ?>
                    <optgroup label="<?= htmlspecialchars(t('report_chat.destinatarios_usuarios')) ?>">
                    <?php foreach ($chatUsers as $user): ?>
                        <option value="usuario:<?= (int) ($user['id'] ?? 0) ?>" <?= ('usuario:' . (int) ($user['id'] ?? 0)) === $selectedRecipient ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($user['name'] ?? 'Usuário')) ?> — <?= htmlspecialchars((string) ($user['perfil'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                    <?php if (!$chatGroups && !$chatUsers): ?>
                        <option value="" selected disabled><?= htmlspecialchars(t('report_chat.nenhum_destinatario')) ?></option>
                    <?php endif; ?>
                </select>
            </div>

            <?php if ($chatCanCommunicateCritical): ?>
            <div class="reports-chat-critical-controls">
                <button type="button" class="btn-pacs-outline reports-chat-critical-btn" id="btn-chat-critical" aria-pressed="false">
                    <i class="fa fa-triangle-exclamation" aria-hidden="true"></i>
                    <?= htmlspecialchars(t('report_chat.acao_achado_critico')) ?>
                </button>
                <div id="chat-critical-alert" class="reports-chat-critical-alert d-none" role="alert">
                    <i class="fa fa-triangle-exclamation" aria-hidden="true"></i>
                    <span><?= htmlspecialchars(t('report_chat.aviso_achado_critico')) ?></span>
                </div>
            </div>
            <?php endif; ?>

            <div class="reports-chat-field">
                <label for="chatMensagem"><?= htmlspecialchars(t('report_chat.mensagem')) ?></label>
                <textarea id="chatMensagem" class="form-control form-control-sm" rows="4" maxlength="5000"
                          placeholder="<?= htmlspecialchars(t('report_chat.mensagem_placeholder')) ?>"></textarea>
            </div>

            <div class="reports-chat-actions">
                <button type="submit" class="btn-pacs-primary reports-chat-send-btn" id="btn-chat-send">
                    <i class="fa fa-paper-plane"></i> <?= htmlspecialchars(t('report_chat.enviar')) ?>
                </button>
                <button type="button" class="btn-pacs-outline reports-chat-complete-btn" id="btn-chat-complete" <?= $chatPending && !empty($chat['can_complete']) ? '' : 'style="display:none"' ?>>
                    <i class="fa fa-check"></i> <?= htmlspecialchars(t('report_chat.concluir_liberar_evolucao')) ?>
                </button>
            </div>
        </form>
        <?php endif; ?>
        </div>
    </div>
</div>
