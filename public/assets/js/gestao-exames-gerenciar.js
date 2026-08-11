/* Gestão de Exames — submenu Gerenciar
 * O backend continua sendo a autoridade para tenant, pendência e prioridade.
 */
(function () {
    'use strict';

    const i18nNode = document.getElementById('gerenciarI18n');
    const text = (key, fallback = '') => i18nNode?.dataset?.[key] || fallback || key;
    const format = (template, ...values) => String(template).replace(/%s/g, () => String(values.shift() ?? ''));

    const state = {
        studyId: 0,
        reportId: 0,
        context: null,
        csrf: document.querySelector('#pedidoForm input[name="csrf"]')?.value || '',
    };

    const $ = (selector) => document.querySelector(selector);
    const modal = (id) => {
        const element = document.getElementById(id);
        if (!element || !window.bootstrap?.Modal) return null;
        return bootstrap.Modal.getOrCreateInstance(element);
    };

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        }[char]));
    }

    function showFeedback(message, type = 'danger') {
        const element = $('#gerenciarFeedback');
        if (!element) return;
        element.className = `alert alert-${type} py-2 small`;
        element.textContent = message || '';
        element.style.display = message ? 'block' : 'none';
    }

    function showChatStatus(message, type = 'danger') {
        const element = $('#gerenciarChatStatus');
        if (!element) return;
        element.className = `alert alert-${type} py-2 small`;
        element.textContent = message || '';
        element.style.display = message ? 'block' : 'none';
    }

    function priorityLabel(option) {
        const value = String(option?.value || '').toUpperCase();
        const labels = {
            STAT: text('prioridadeStat'),
            HIGH: text('prioridadeHigh'),
            ROUTINE: text('prioridadeRoutine'),
            MEDIUM: text('prioridadeMedium'),
            LOW: text('prioridadeLow'),
        };
        return labels[value] || option?.label || value;
    }

    function subjectLabel(option) {
        const labels = {
            erro_pedido: text('temaErroPedido'),
            contraste: text('temaContraste'),
            exames_complementares: text('temaExamesComplementares'),
            duvida_administrativa: text('temaDuvidaAdministrativa'),
            outro: text('temaOutro'),
        };
        return labels[String(option?.codigo || '')] || option?.label || option?.codigo || '';
    }

    function reportStatusLabel(status) {
        const normalized = String(status || '').toLowerCase();
        const labels = {
            novo: text('statusNovo'),
            aberto: text('statusAberto'),
            a_laudar: text('statusALaudar'),
            em_laudo: text('statusEmLaudo'),
            rascunho: text('statusRascunho'),
            revisao: text('statusRevisao'),
            peer_review: text('statusPeerReview'),
            assinado: text('statusAssinado'),
            liberado: text('statusLiberado'),
        };
        return labels[normalized] || status || '';
    }

    function renderChatHistory(chat) {
        const container = $('#gerenciarChatHistory');
        if (!container) return;
        const messages = Array.isArray(chat?.messages) ? chat.messages : [];
        if (!messages.length) {
            container.innerHTML = `<div class="chat-empty"><i class="fa fa-comments"></i> ${escapeHtml(text('semMensagens'))}</div>`;
            return;
        }
        container.innerHTML = messages.map((message) => {
            const author = escapeHtml(message.autor_nome || text('usuario'));
            const date = escapeHtml(message.criado_em || '');
            const body = escapeHtml(message.corpo || '');
            return `<article class="gerenciar-chat-message">
                <header><strong>${author}</strong><time>${date}</time></header>
                <p>${body}</p>
            </article>`;
        }).join('');
        container.scrollTop = container.scrollHeight;
    }

    function fillSelect(select, options, valueKey, labelKey, selected, labelResolver = null) {
        if (!select) return;
        select.innerHTML = (options || []).map((option) => {
            const value = option[valueKey] ?? '';
            const label = labelResolver ? labelResolver(option) : (option[labelKey] ?? value);
            const isSelected = String(value) === String(selected ?? '') ? ' selected' : '';
            return `<option value="${escapeHtml(value)}"${isSelected}>${escapeHtml(label)}</option>`;
        }).join('');
    }

    function updateChatControls(chat, context) {
        const pending = Boolean(context?.chat_pending || chat?.pendente);
        const canInteract = context?.can_interact !== false;
        const canComplete = context?.can_complete !== false;
        const send = $('#gerenciarChatEnviar');
        const complete = $('#gerenciarChatConcluir');
        const message = $('#gerenciarChatMensagem');
        const hint = $('#gerenciarChatHint');
        if (send) send.disabled = !canInteract;
        if (message) message.disabled = !canInteract;
        if (complete) complete.disabled = !pending || !canComplete;
        if (hint) {
            hint.textContent = pending
                ? (canInteract ? text('aguardandoSaneamento') : text('aguardandoContraparte'))
                : text('primeiroEnvio');
        }
        showChatStatus(!canInteract ? text('aguardandoContraparte') : '', 'warning');
    }

    function renderContext(context) {
        state.context = context || {};
        state.reportId = Number(context?.report_id || 0);
        $('#gerenciarPacienteNome').textContent = context?.patient_name || '—';
        const reportStatus = reportStatusLabel(context?.report_situacao);
        const effectivePriorityLabel = priorityLabel(context?.priority || {});
        const meta = [
            context?.study_instance_uid ? `${text('studyUid')}: ${context.study_instance_uid}` : '',
            reportStatus ? `${text('laudo')}: ${reportStatus}` : text('semLaudo'),
            context?.priority?.effective ? `${text('prioridade')}: ${effectivePriorityLabel}` : ''
        ].filter(Boolean).join(' · ');
        $('#gerenciarEstudoMeta').textContent = meta;

        const viewReport = $('#gerenciarVerLaudo');
        if (viewReport) {
            const canView = Boolean(context?.can_view_report && context?.report_url);
            viewReport.style.display = canView ? 'flex' : 'none';
            viewReport.href = canView ? context.report_url : '#';
        }

        const chatButton = $('#gerenciarChat');
        const priorityButton = $('#gerenciarPrioridade');
        const badge = $('#gerenciarChatBadge');
        const lockNotice = $('#gerenciarLockNotice');
        const pending = Boolean(context?.chat_pending);
        if (badge) badge.style.display = pending ? 'inline-flex' : 'none';
        if (priorityButton) priorityButton.disabled = pending;
        if (lockNotice) lockNotice.style.display = pending ? 'block' : 'none';
        if (chatButton) chatButton.disabled = !state.reportId;
        $('#gerenciarPrioridadeDesc').textContent = context?.priority?.effective
            ? priorityLabel(context.priority)
            : text('prioridade');

        const chat = context?.chat || null;
        renderChatHistory(chat);
        fillSelect($('#gerenciarChatGrupo'), chat?.groups || [], 'id', 'label', chat?.destinatario_grupo_id);
        fillSelect($('#gerenciarChatUsuario'), chat?.users || [], 'id', 'name', chat?.destinatario_user_id);
        fillSelect($('#gerenciarChatAssuntoCodigo'), chat?.subjects || [], 'codigo', 'label', chat?.assunto_codigo, subjectLabel);
        $('#gerenciarChatTipo').value = chat?.destinatario_tipo || 'grupo';
        $('#gerenciarChatAssunto').value = chat?.assunto || '';
        $('#gerenciarChatReportId').value = String(state.reportId);
        updateChatRecipientVisibility();
        updateChatControls(chat || {}, context);
    }

    function updateChatRecipientVisibility() {
        const isUser = $('#gerenciarChatTipo')?.value === 'usuario';
        const groupWrap = $('#gerenciarChatGrupoWrap');
        const userWrap = $('#gerenciarChatUsuarioWrap');
        if (groupWrap) groupWrap.style.display = isUser ? 'none' : 'flex';
        if (userWrap) userWrap.style.display = isUser ? 'flex' : 'none';
    }

    async function loadContext(studyId) {
        state.studyId = Number(studyId || 0);
        showFeedback(text('carregandoAcoes'), 'info');
        const response = await fetch(`/api/gestao-exames/estudos/${encodeURIComponent(state.studyId)}/gerenciar`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        });
        let payload = {};
        try { payload = await response.json(); } catch (error) { /* resposta não JSON */ }
        if (!response.ok || !payload.ok) throw new Error(payload.msg || text('erroContexto'));
        renderContext(payload.context);
        showFeedback('', 'info');
    }

    async function sendChat(event) {
        event.preventDefault();
        if (!state.reportId || state.context?.can_interact === false) return;
        const form = $('#gerenciarChatForm');
        const data = Object.fromEntries(new FormData(form).entries());
        data.report_id = state.reportId;
        data.csrf = state.csrf;
        showChatStatus(text('enviando'), 'info');
        const response = await fetch('/api/reports/chat/send', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify(data)
        });
        let payload = {};
        try { payload = await response.json(); } catch (error) { /* resposta não JSON */ }
        if (!response.ok || !payload.ok) throw new Error(payload.msg || text('erroOperacao'));
        $('#gerenciarChatMensagem').value = '';
        await loadContext(state.studyId);
        showChatStatus(text('enviado'), 'success');
    }

    async function completeChat() {
        if (!state.reportId || $('#gerenciarChatConcluir').disabled) return;
        if (!window.confirm(text('confirmarConclusao'))) return;
        showChatStatus(text('concluindo'), 'info');
        const response = await fetch('/api/reports/chat/complete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify({ report_id: state.reportId, csrf: state.csrf, origem: 'gestao_exames' })
        });
        let payload = {};
        try { payload = await response.json(); } catch (error) { /* resposta não JSON */ }
        if (!response.ok || !payload.ok) throw new Error(payload.msg || text('erroOperacao'));
        await loadContext(state.studyId);
        showChatStatus(text('concluido'), 'success');
    }

    function openPriorityModal() {
        if (!state.context || state.context.chat_pending) return;
        const priority = state.context.priority || {};
        $('#gerenciarPrioridadeAtual').textContent = priority.effective
            ? priorityLabel(priority)
            : '—';
        const rawDicom = priority.raw_dicom || '—';
        $('#gerenciarPrioridadeDicom').textContent = priority.override
            ? format(text('dicomOriginal'), rawDicom, priority.override)
            : format(text('semOverride'), rawDicom);
        fillSelect($('#gerenciarPrioridadeSelect'), priority.options || [], 'value', 'label', priority.effective, priorityLabel);
        $('#gerenciarPrioridadeMotivo').value = '';
        $('#gerenciarPrioridadeCount').textContent = '0/20';
        $('#gerenciarPrioridadeAviso').style.display = 'none';
        $('#gerenciarPrioridadeErro').style.display = 'none';
        modal('gerenciarPrioridadeModal')?.show();
    }

    async function savePriority(event) {
        event.preventDefault();
        if (!state.studyId || state.context?.chat_pending) return;
        const reason = String($('#gerenciarPrioridadeMotivo').value || '').trim();
        if (reason.length < 20) {
            $('#gerenciarPrioridadeErro').textContent = text('motivoCurto');
            $('#gerenciarPrioridadeErro').style.display = 'block';
            return;
        }
        if (!window.confirm(text('confirmarPrioridade'))) return;
        const priority = $('#gerenciarPrioridadeSelect').value;
        const response = await fetch(`/api/gestao-exames/estudos/${encodeURIComponent(state.studyId)}/prioridade`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify({ prioridade: priority, motivo: reason, csrf: state.csrf })
        });
        let payload = {};
        try { payload = await response.json(); } catch (error) { /* resposta não JSON */ }
        if (!response.ok || !payload.ok) throw new Error(payload.msg || text('erroOperacao'));
        modal('gerenciarPrioridadeModal')?.hide();
        window.location.reload();
    }

    function bind() {
        document.querySelectorAll('.gerenciar-trigger').forEach((button) => {
            button.addEventListener('click', async () => {
                modal('gerenciarModal')?.show();
                try { await loadContext(button.dataset.id); }
                catch (error) { showFeedback(error.message || text('erroContexto'), 'danger'); }
            });
        });
        $('#gerenciarChat')?.addEventListener('click', () => {
            if (!state.reportId) return;
            modal('gerenciarChatModal')?.show();
            renderChatHistory(state.context?.chat || null);
        });
        $('#gerenciarPrioridade')?.addEventListener('click', openPriorityModal);
        $('#gerenciarChatTipo')?.addEventListener('change', updateChatRecipientVisibility);
        $('#gerenciarChatForm')?.addEventListener('submit', (event) => {
            sendChat(event).catch((error) => showChatStatus(error.message || text('erroOperacao'), 'danger'));
        });
        $('#gerenciarChatConcluir')?.addEventListener('click', () => {
            completeChat().catch((error) => showChatStatus(error.message || text('erroOperacao'), 'danger'));
        });
        $('#gerenciarPrioridadeForm')?.addEventListener('submit', (event) => {
            savePriority(event).catch((error) => {
                $('#gerenciarPrioridadeErro').textContent = error.message || text('erroOperacao');
                $('#gerenciarPrioridadeErro').style.display = 'block';
            });
        });
        $('#gerenciarPrioridadeMotivo')?.addEventListener('input', (event) => {
            $('#gerenciarPrioridadeCount').textContent = `${event.target.value.length}/20`;
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
    else bind();
}());
