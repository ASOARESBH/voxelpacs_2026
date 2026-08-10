<?php
/** @var array|null $chat */
/** @var bool $readonly */
$chat = is_array($chat ?? null) ? $chat : [];
$chatMessages = is_array($chat['messages'] ?? null) ? $chat['messages'] : [];
$chatSubjects = is_array($chat['subjects'] ?? null) ? $chat['subjects'] : [];
$chatGroups = is_array($chat['groups'] ?? null) ? $chat['groups'] : [];
$chatUsers = is_array($chat['users'] ?? null) ? $chat['users'] : [];
$chatPending = ($chat['status'] ?? '') === 'pendente';
$recipientType = $chat['destinatario_tipo'] ?? 'grupo';
$recipientGroup = $chat['destinatario_grupo'] ?? 'administrativo';
$recipientUser = (int) ($chat['destinatario_user_id'] ?? 0);
?>
<div class="pacs-card reports-card reports-chat-card" id="card-chat"
     data-chat-report-id="<?= (int) ($chat['report_id'] ?? ($report->id ?? 0)) ?>"
     data-chat-pending="<?= $chatPending ? '1' : '0' ?>">
    <div class="pacs-card-header reports-chat-header">
        <span><i class="fa fa-comments"></i> <?= htmlspecialchars(t('report_chat.titulo')) ?></span>
        <span id="chat-status-badge" class="chat-status-badge <?= $chatPending ? 'is-pending' : 'is-clear' ?>">
            <?= htmlspecialchars($chatPending ? t('report_chat.pendente') : t('report_chat.sem_mensagens')) ?>
        </span>
    </div>
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
                <label for="chatDestinatarioTipo"><?= htmlspecialchars(t('report_chat.destinatario')) ?></label>
                <select id="chatDestinatarioTipo" class="form-select form-select-sm">
                    <option value="grupo" <?= $recipientType === 'grupo' ? 'selected' : '' ?>><?= htmlspecialchars(t('report_chat.grupo_administrativo')) ?></option>
                    <option value="usuario" <?= $recipientType === 'usuario' ? 'selected' : '' ?>><?= htmlspecialchars(t('report_chat.usuario_especifico')) ?></option>
                </select>
            </div>

            <div class="reports-chat-field" id="chatGrupoField" <?= $recipientType === 'usuario' ? 'style="display:none"' : '' ?>>
                <label for="chatDestinatarioGrupo"><?= htmlspecialchars(t('report_chat.destinatario')) ?></label>
                <select id="chatDestinatarioGrupo" class="form-select form-select-sm">
                    <?php foreach ($chatGroups as $group): ?>
                        <option value="<?= htmlspecialchars((string) ($group['codigo'] ?? '')) ?>" <?= ($group['codigo'] ?? '') === $recipientGroup ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($group['label'] ?? 'Administrativo')) ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if (!$chatGroups): ?>
                        <option value="administrativo" selected><?= htmlspecialchars(t('report_chat.grupo_administrativo')) ?></option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="reports-chat-field" id="chatUsuarioField" <?= $recipientType !== 'usuario' ? 'style="display:none"' : '' ?>>
                <label for="chatDestinatarioUsuario"><?= htmlspecialchars(t('report_chat.selecione_usuario')) ?></label>
                <select id="chatDestinatarioUsuario" class="form-select form-select-sm">
                    <option value=""><?= htmlspecialchars(t('report_chat.selecione_usuario')) ?></option>
                    <?php foreach ($chatUsers as $user): ?>
                        <option value="<?= (int) ($user['id'] ?? 0) ?>" <?= (int) ($user['id'] ?? 0) === $recipientUser ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($user['name'] ?? 'Usuário')) ?> — <?= htmlspecialchars((string) ($user['perfil'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="reports-chat-field">
                <label for="chatAssuntoCodigo"><?= htmlspecialchars(t('report_chat.tema')) ?></label>
                <select id="chatAssuntoCodigo" class="form-select form-select-sm">
                    <?php foreach ($chatSubjects as $subject): ?>
                        <option value="<?= htmlspecialchars((string) ($subject['codigo'] ?? 'outro')) ?>" <?= ($subject['codigo'] ?? '') === ($chat['assunto_codigo'] ?? 'outro') ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($subject['label'] ?? 'Outro')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="reports-chat-field">
                <label for="chatAssunto"><?= htmlspecialchars(t('report_chat.assunto')) ?></label>
                <input id="chatAssunto" class="form-control form-control-sm" maxlength="180"
                       placeholder="<?= htmlspecialchars(t('report_chat.assunto_placeholder')) ?>"
                       value="<?= htmlspecialchars((string) ($chat['assunto'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="reports-chat-field">
                <label for="chatMensagem"><?= htmlspecialchars(t('report_chat.mensagem')) ?></label>
                <textarea id="chatMensagem" class="form-control form-control-sm" rows="4" maxlength="5000"
                          placeholder="<?= htmlspecialchars(t('report_chat.mensagem_placeholder')) ?>"></textarea>
            </div>

            <div class="reports-chat-actions">
                <button type="submit" class="pacs-btn pacs-btn-primary" id="btn-chat-send">
                    <i class="fa fa-paper-plane"></i> <?= htmlspecialchars(t('report_chat.enviar')) ?>
                </button>
                <button type="button" class="pacs-btn pacs-btn-success" id="btn-chat-complete" <?= $chatPending ? '' : 'style="display:none"' ?>>
                    <i class="fa fa-check"></i> <?= htmlspecialchars(t('report_chat.concluido')) ?>
                </button>
            </div>
            <small class="reports-chat-email-hint"><i class="fa fa-envelope"></i> <?= htmlspecialchars(t('report_chat.aviso_email')) ?></small>
        </form>
        <?php endif; ?>
    </div>
</div>
