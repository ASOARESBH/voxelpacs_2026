/**
 * VOXEL PACS — Reports / CHAT contextual
 * Uma conversa por report/tenant, com histórico e transição pendente/concluído.
 */
window.VoxelReports = window.VoxelReports || {};

window.VoxelReports.chat = (function () {
    let config = null;
    let state = { pending: false, status: 'sem_chat', messages: [] };
    let busy = false;

    const fallbackText = {
        pending: 'Pendente',
        clear: 'Sem mensagens',
        required: 'Digite a interação antes de enviar.',
        error: 'Não foi possível processar a interação.',
        confirm: 'Concluir esta pendência? O estudo voltará à situação anterior.',
        criticalConfirm: 'Confirmar o registro de ACHADO CRÍTICO? A sinalização será gravada no estudo e os administradores do tenant serão notificados por e-mail.',
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
        if (complete) complete.style.display = state.pending ? '' : 'none';
        renderMessages(state.messages || []);
        document.dispatchEvent(new CustomEvent('reports:chat-status', { detail: { pending: state.pending } }));
    }

    function updateCriticalAlert() {
        const isCritical = document.getElementById('chatAssuntoCodigo')?.value === 'achado_critico';
        document.getElementById('chat-critical-alert')?.classList.toggle('d-none', !isCritical);
    }

    function updateRecipientFields() {
        const type = document.getElementById('chatDestinatarioTipo')?.value || 'grupo';
        const group = document.getElementById('chatGrupoField');
        const user = document.getElementById('chatUsuarioField');
        if (group) group.style.display = type === 'grupo' ? '' : 'none';
        if (user) user.style.display = type === 'usuario' ? '' : 'none';
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
            setSelectValue('chatDestinatarioTipo', data.chat?.destinatario_tipo || 'grupo');
            setSelectValue('chatDestinatarioGrupo', data.chat?.destinatario_grupo_id || data.chat?.destinatario_grupo || '');
            setSelectValue('chatDestinatarioUsuario', data.chat?.destinatario_user_id || '');
            setSelectValue('chatAssuntoCodigo', data.chat?.assunto_codigo || 'outro');
            updateRecipientFields();
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
        const isCritical = document.getElementById('chatAssuntoCodigo')?.value === 'achado_critico';
        if (!body) {
            setFeedback(text('required'), true);
            message?.focus();
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
            destinatario_tipo: document.getElementById('chatDestinatarioTipo')?.value || 'grupo',
            destinatario_grupo: document.getElementById('chatDestinatarioGrupo')?.value || '',
            destinatario_user_id: document.getElementById('chatDestinatarioUsuario')?.value || null,
            assunto_codigo: document.getElementById('chatAssuntoCodigo')?.value || 'outro',
            assunto: document.getElementById('chatAssunto')?.value || '',
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
            setFeedback(data.email_warning || 'Interação registrada e destinatários notificados.', Boolean(data.email_warning));
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
            setFeedback('CHAT concluído. O estudo voltou para a evolução do fluxo.');
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
        document.getElementById('chatDestinatarioTipo')?.addEventListener('change', updateRecipientFields);
        document.getElementById('chatAssuntoCodigo')?.addEventListener('change', updateCriticalAlert);
        document.getElementById('btn-chat-complete')?.addEventListener('click', complete);
        if (form) form.addEventListener('submit', send);
        updateCriticalAlert();
        load();
    }

    return { init, load, hasPending };
})();
