/**
 * VOXEL PACS — Reports / CHAT contextual
 * Uma conversa por report/tenant, com histórico e transição pendente/concluído.
 */
window.VoxelReports = window.VoxelReports || {};

window.VoxelReports.chat = (function () {
    let config = null;
    let state = { pending: false, status: 'sem_chat', messages: [], can_complete: false };
    let busy = false;
    let criticalMode = false;

    const fallbackText = {
        pending: 'Pendente',
        clear: 'Sem mensagens',
        required: 'Digite a interação antes de enviar.',
        error: 'Não foi possível processar a interação.',
        confirm: 'Concluir esta pendência? O estudo voltará à situação anterior.',
        criticalConfirm: 'Confirmar o registro de ACHADO CRÍTICO? A sinalização será gravada no estudo e os administradores do tenant serão notificados por e-mail.',
        recipientRequired: 'Selecione um destinatário antes de enviar.',
        sent: 'Interação registrada e destinatários notificados.',
        completed: 'CHAT concluído. O estudo voltou para a evolução do fluxo.',
    };

    function text(key) {
        return (window.VoxelReports.chatI18n && window.VoxelReports.chatI18n[key]) || fallbackText[key] || '';
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
        }[char]));
    }

    function lineBreaks(value) {
        return escapeHtml(value).replace(/\n/g, '<br>');
    }

    function setFeedback(message, error = false) {
        let el = document.getElementById('chat-feedback');
        if (!el) {
            el = document.createElement('div');
            el.id = 'chat-feedback';
            el.className = 'reports-chat-toast';
            document.getElementById('reportChatForm')?.appendChild(el);
        }
        el.textContent = message || '';
        el.style.color = error ? '#fca5a5' : '#86efac';
        el.style.display = message ? 'block' : 'none';
    }

    function renderMessages(messages) {
        const root = document.getElementById('chat-messages');
        if (!root) return;
        if (!Array.isArray(messages) || messages.length === 0) {
            root.innerHTML = '<div class="reports-chat-empty" id="chat-empty-state"><i class="fa fa-message"></i><span>'
                + escapeHtml(text('clear')) + '</span></div>';
            return;
        }
        root.innerHTML = messages.map((item) => `
            <article class="reports-chat-message">
                <div class="reports-chat-message-meta">
                    <strong>${escapeHtml(item.autor_nome || 'Usuário')}</strong>
                    <time>${escapeHtml(item.criado_em || '')}</time>
                </div>
                <div class="reports-chat-message-body">${lineBreaks(item.corpo || '')}</div>
            </article>
        `).join('');
        root.scrollTop = root.scrollHeight;
    }

    function setSelectValue(id, value) {
        const el = document.getElementById(id);
        if (el && value !== null && value !== undefined && value !== '') el.value = String(value);
    }

    function interactionCountText(messages) {
        const count = Array.isArray(messages) ? messages.length : 0;
        if (count === 0) return text('clear');
        const pattern = count === 1 ? text('countSingular') : text('countPlural');
        return pattern.replace(':count', String(count));
    }

    function renderStatus(chat) {
        state = { ...state, ...chat, pending: chat.status === 'pendente' };
        const card = document.getElementById('card-chat');
        const badge = document.getElementById('chat-status-badge');
        const alertEl = document.getElementById('chat-pending-alert');
        const complete = document.getElementById('btn-chat-complete');
        if (card) card.dataset.chatPending = state.pending ? '1' : '0';
        if (badge) {
            badge.textContent = state.pending ? text('pending') : interactionCountText(state.messages);
            badge.classList.toggle('is-pending', state.pending);
            badge.classList.toggle('is-clear', !state.pending);
        }
        if (alertEl) alertEl.classList.toggle('d-none', !state.pending);
        if (complete) complete.style.display = state.pending && state.can_complete === true ? '' : 'none';
        renderMessages(state.messages || []);
        document.dispatchEvent(new CustomEvent('reports:chat-status', { detail: { pending: state.pending } }));
    }

    function setCriticalMode(enabled) {
        const button = document.getElementById('btn-chat-critical');
        criticalMode = Boolean(enabled && button);
        if (button) {
            button.classList.toggle('is-active', criticalMode);
            button.setAttribute('aria-pressed', criticalMode ? 'true' : 'false');
        }
        document.getElementById('chat-critical-alert')?.classList.toggle('d-none', !criticalMode);
    }

    function selectedRecipient(chat) {
        const type = chat?.destinatario_tipo === 'usuario' ? 'usuario' : 'grupo';
        const id = type === 'usuario' ? chat?.destinatario_user_id : chat?.destinatario_grupo_id;
        return id ? `${type}:${id}` : '';
    }

    function parseRecipient() {
        const value = document.getElementById('chatDestinatario')?.value || '';
        const match = /^(grupo|usuario):([1-9][0-9]*)$/.exec(value);
        if (!match) return null;
        return {
            type: match[1],
            groupId: match[1] === 'grupo' ? match[2] : '',
            userId: match[1] === 'usuario' ? match[2] : null,
        };
    }

    async function load() {
        if (!config || !config.reportId) return null;
        try {
            const response = await fetch(`/api/reports/chat?report_id=${encodeURIComponent(config.reportId)}`, {
                headers: { 'Accept': 'application/json' },
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.msg || text('error'));
            renderStatus(data.chat || {});
            setSelectValue('chatDestinatario', selectedRecipient(data.chat));
            setCriticalMode(false);
            return data.chat;
        } catch (error) {
            setFeedback(error.message || text('error'), true);
            return null;
        }
    }

    async function send(event) {
        event.preventDefault();
        if (busy) return;
        const message = document.getElementById('chatMensagem');
        const body = (message?.value || '').trim();
        const recipient = parseRecipient();
        const isCritical = criticalMode;
        if (!body) {
            setFeedback(text('required'), true);
            message?.focus();
            return;
        }
        if (!recipient) {
            setFeedback(text('recipientRequired'), true);
            document.getElementById('chatDestinatario')?.focus();
            return;
        }
        if (isCritical && !window.confirm(text('criticalConfirm'))) return;

        busy = true;
        setFeedback('');
        const button = document.getElementById('btn-chat-send');
        if (button) button.disabled = true;
        const payload = {
            report_id: config.reportId,
            csrf: config.csrf,
            destinatario_tipo: recipient.type,
            destinatario_grupo: recipient.groupId,
            destinatario_user_id: recipient.userId,
            assunto_codigo: isCritical ? 'achado_critico' : 'outro',
            assunto: '',
            mensagem: body,
        };
        try {
            const response = await fetch('/api/reports/chat/send', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf },
                body: JSON.stringify(payload),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.msg || text('error'));
            if (message) message.value = '';
            setFeedback(data.email_warning || text('sent'), Boolean(data.email_warning));
            await load();
        } catch (error) {
            setFeedback(error.message || text('error'), true);
        } finally {
            busy = false;
            if (button) button.disabled = false;
        }
    }

    async function complete() {
        if (busy || !state.pending) return;
        if (!window.confirm(text('confirm'))) return;
        busy = true;
        const button = document.getElementById('btn-chat-complete');
        if (button) button.disabled = true;
        try {
            const response = await fetch('/api/reports/chat/complete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf },
                body: JSON.stringify({ report_id: config.reportId, csrf: config.csrf }),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.msg || text('error'));
            setFeedback(text('completed'));
            await load();
            // O médico está no laudário autorizado neste instante. A página
            // principal faz a transição de a_laudar para em_laudo no backend.
            document.dispatchEvent(new CustomEvent('reports:chat-completed', {
                detail: { situacao: data.situacao || 'a_laudar' }
            }));
        } catch (error) {
            setFeedback(error.message || text('error'), true);
        } finally {
            busy = false;
            if (button) button.disabled = false;
        }
    }

    function hasPending() { return !!state.pending; }

    function init(cfg) {
        config = cfg;
        const form = document.getElementById('reportChatForm');
        document.getElementById('btn-chat-critical')?.addEventListener('click', () => setCriticalMode(!criticalMode));
        document.getElementById('btn-chat-complete')?.addEventListener('click', complete);
        if (form) form.addEventListener('submit', send);
        setCriticalMode(false);
        load();
    }

    return { init, load, hasPending };
})();
