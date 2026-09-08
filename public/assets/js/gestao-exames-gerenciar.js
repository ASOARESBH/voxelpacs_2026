/* Gestão de Exames — submenu Gerenciar
 * O backend continua sendo a autoridade para tenant, pendência e prioridade.
 * O CHAT usa o mesmo destinatário único e a mesma ação crítica explícita.
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
        reopenGerenciarAfterDescription: false,
        reopenGerenciarAfterInformation: false,
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

    function showDescriptionStatus(message, type = 'danger') {
        const element = $('#gerenciarDescricaoStatus');
        if (!element) return;
        element.className = `alert alert-${type} py-2 small`;
        element.textContent = message || '';
        element.style.display = message ? 'block' : 'none';
    }

    function showRequestingPhysicianStatus(message, type = 'danger') {
        const element = $('#gerenciarSolicitanteStatus');
        if (!element) return;
        element.className = `alert alert-${type} py-2 small`;
        element.textContent = message || '';
        element.style.display = message ? 'block' : 'none';
    }

    function showStudyInformationStatus(message, type = 'danger') {
        const element = $('#gerenciarInformacoesStatus');
        if (!element) return;
        element.className = `alert alert-${type} py-2 small`;
        element.textContent = message || '';
        element.style.display = message ? 'block' : 'none';
    }

    function csrfToken() {
        return state.csrf
            || document.querySelector('#gerenciarDescricaoForm input[name="csrf"]')?.value
            || document.querySelector('input[name="csrf"]')?.value
            || '';
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
        const critical = $('#gerenciarChatCritical');
        const complete = $('#gerenciarChatConcluir');
        const message = $('#gerenciarChatMensagem');
        const recipient = $('#gerenciarChatDestinatario');
        const hint = $('#gerenciarChatHint');
        if (send) send.disabled = !canInteract;
        if (critical) critical.disabled = !canInteract;
        if (message) message.disabled = !canInteract;
        if (recipient) recipient.disabled = !canInteract;
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
        const patientName = $('#gerenciarPacienteNome');
        if (patientName) patientName.textContent = context?.patient_name || '—';
        const reportStatus = reportStatusLabel(context?.report_situacao);
        const effectivePriorityLabel = priorityLabel(context?.priority || {});
        const meta = [
            context?.study_instance_uid ? `${text('studyUid')}: ${context.study_instance_uid}` : '',
            reportStatus ? `${text('laudo')}: ${reportStatus}` : text('semLaudo'),
            context?.priority?.effective ? `${text('prioridade')}: ${effectivePriorityLabel}` : ''
        ].filter(Boolean).join(' · ');
        const studyMeta = $('#gerenciarEstudoMeta');
        if (studyMeta) studyMeta.textContent = meta;

        const viewReport = $('#gerenciarVerLaudo');
        if (viewReport) {
            const canView = Boolean(context?.can_view_report && context?.report_url);
            viewReport.style.display = canView ? 'flex' : 'none';
            viewReport.href = canView ? context.report_url : '#';
        }

        const chatButton = $('#gerenciarChat');
        const descriptionButton = $('#gerenciarDescricao');
        const priorityButton = $('#gerenciarPrioridade');
        const requestingPhysicianButton = $('#gerenciarSolicitante');
        const informationButton = $('#gerenciarInformacoes');
        const badge = $('#gerenciarChatBadge');
        const lockNotice = $('#gerenciarLockNotice');
        const pending = Boolean(context?.chat_pending);
        if (badge) badge.style.display = pending ? 'inline-flex' : 'none';
        if (priorityButton) priorityButton.disabled = pending;
        if (requestingPhysicianButton) requestingPhysicianButton.disabled = pending;
        if (informationButton) informationButton.disabled = pending;
        if (lockNotice) lockNotice.style.display = pending ? 'block' : 'none';
        if (chatButton) chatButton.disabled = !state.reportId;
        if (descriptionButton) descriptionButton.disabled = !context?.modalidade;
        const descriptionDetail = $('#gerenciarDescricaoDesc');
        if (descriptionDetail) descriptionDetail.textContent = context?.modalidade || text('erroOperacao');
        const priorityDetail = $('#gerenciarPrioridadeDesc');
        if (priorityDetail) {
            priorityDetail.textContent = context?.priority?.effective
                ? priorityLabel(context.priority)
                : text('prioridade');
        }
        const requesterDetail = $('#gerenciarSolicitanteDesc');
        if (requesterDetail) requesterDetail.textContent = context?.requesting_physician || text('solicitanteSemInformacao');
        const informationDetail = $('#gerenciarInformacoesDesc');
        if (informationDetail) informationDetail.textContent = context?.study_information_present
            ? text('informacoesRegistradas')
            : text('informacoesSemInformacao');

        const chat = context?.chat || null;
        renderChatHistory(chat);
        fillChatRecipients(chat || {});
        const canCommunicateCritical = (chat?.subjects || []).some((subject) => subject?.codigo === 'achado_critico');
        const critical = $('#gerenciarChatCritical');
        if (critical) critical.style.display = canCommunicateCritical ? '' : 'none';
        const reportIdInput = $('#gerenciarChatReportId');
        if (reportIdInput) reportIdInput.value = String(state.reportId);
        updateChatControls(chat || {}, context);
    }

    function selectedChatRecipient(chat) {
        const preferredUserId = Number(chat?.destinatario_preferencial_user_id || 0);
        if (preferredUserId > 0) return `usuario:${preferredUserId}`;
        const type = chat?.destinatario_tipo === 'usuario' ? 'usuario' : 'grupo';
        const id = type === 'usuario' ? chat?.destinatario_user_id : chat?.destinatario_grupo_id;
        return id ? `${type}:${id}` : '';
    }

    function fillChatRecipients(chat) {
        const select = $('#gerenciarChatDestinatario');
        if (!select) return;
        const selected = selectedChatRecipient(chat);
        const groups = Array.isArray(chat?.groups) ? chat.groups : [];
        const users = Array.isArray(chat?.users) ? chat.users : [];
        const groupOptions = groups.map((group) => {
            const id = Number(group?.id || 0);
            const count = Number(group?.total_membros || 0);
            const label = `${group?.label || text('chatDestinatariosGrupos')}${count > 0 ? ` (${count})` : ''}`;
            return `<option value="grupo:${id}"${selected === `grupo:${id}` ? ' selected' : ''}>${escapeHtml(label)}</option>`;
        }).join('');
        const userOptions = users.map((user) => {
            const id = Number(user?.id || 0);
            const label = `${user?.name || text('chatUsuario')}${user?.perfil ? ` — ${user.perfil}` : ''}`;
            return `<option value="usuario:${id}"${selected === `usuario:${id}` ? ' selected' : ''}>${escapeHtml(label)}</option>`;
        }).join('');
        select.innerHTML = [
            groupOptions ? `<optgroup label="${escapeHtml(text('chatDestinatariosGrupos'))}">${groupOptions}</optgroup>` : '',
            userOptions ? `<optgroup label="${escapeHtml(text('chatDestinatariosUsuarios'))}">${userOptions}</optgroup>` : '',
        ].join('') || `<option value="" selected disabled>${escapeHtml(text('chatNenhumDestinatario'))}</option>`;
    }

    function parseChatRecipient() {
        const value = $('#gerenciarChatDestinatario')?.value || '';
        const match = /^(grupo|usuario):([1-9][0-9]*)$/.exec(value);
        if (!match) return null;
        return {
            type: match[1],
            groupId: match[1] === 'grupo' ? match[2] : '',
            userId: match[1] === 'usuario' ? match[2] : null,
        };
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

    async function sendChat(event, action = 'enviar_interacao') {
        event?.preventDefault();
        if (!state.reportId || state.context?.can_interact === false) return;
        const message = $('#gerenciarChatMensagem');
        const body = String(message?.value || '').trim();
        const recipient = parseChatRecipient();
        const isCritical = action === 'comunicar_achado_critico';
        if (!body) {
            showChatStatus(text('chatMensagemObrigatoria'), 'danger');
            message?.focus();
            return;
        }
        if (!recipient) {
            showChatStatus(text('chatDestinatarioObrigatorio'), 'danger');
            $('#gerenciarChatDestinatario')?.focus();
            return;
        }
        if (isCritical && !window.confirm(text('chatConfirmarAchadoCritico'))) return;
        const data = {
            report_id: state.reportId,
            csrf: state.csrf,
            origem: 'gestao_exames',
            destinatario_tipo: recipient.type,
            destinatario_grupo: recipient.groupId,
            destinatario_user_id: recipient.userId,
            assunto_codigo: isCritical ? 'achado_critico' : 'outro',
            assunto: '',
            mensagem: body,
            acao: action,
        };
        showChatStatus(text('enviando'), 'info');
        const send = $('#gerenciarChatEnviar');
        const critical = $('#gerenciarChatCritical');
        if (send) send.disabled = true;
        if (critical) critical.disabled = true;
        try {
            const response = await fetch('/api/reports/chat/send', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify(data)
            });
            let payload = {};
            try { payload = await response.json(); } catch (error) { /* resposta não JSON */ }
            if (!response.ok || !payload.ok) throw new Error(payload.msg || text('erroOperacao'));
            message.value = '';
            await loadContext(state.studyId);
            showChatStatus(payload.email_warning || text('enviado'), payload.email_warning ? 'warning' : 'success');
        } finally {
            if (send) send.disabled = false;
            if (critical) critical.disabled = false;
        }
    }

    async function completeChat() {
        const button = $('#gerenciarChatConcluir');
        if (!state.reportId || !button || button.disabled) return;
        if (!window.confirm(text('confirmarConclusao'))) return;
        if (button) button.disabled = true;
        showChatStatus(text('concluindo'), 'info');
        try {
            const response = await fetch('/api/reports/chat/complete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ report_id: state.reportId, csrf: state.csrf, origem: 'gestao_exames' })
            });
            let payload = {};
            try { payload = await response.json(); } catch (error) { /* resposta não JSON */ }
            if (!response.ok || !payload.ok) throw new Error(payload.msg || text('erroOperacao'));
            showChatStatus(text('concluido'), 'success');
            // A Worklist é a fonte visual do estado. Recarrega os dados já
            // restaurados pelo backend, sem simular status apenas no navegador.
            window.setTimeout(() => window.location.reload(), 250);
        } catch (error) {
            showChatStatus(error.message || text('erroOperacao'), 'danger');
            if (button) button.disabled = false;
        }
    }

    function renderDescriptionSuggestions(suggestions) {
        const datalist = $('#gerenciarDescricaoSugestoes');
        const list = $('#gerenciarDescricaoSugestoesLista');
        const values = Array.isArray(suggestions) ? suggestions : [];
        if (datalist) datalist.innerHTML = values.map((item) => `<option value="${escapeHtml(item.descricao)}"></option>`).join('');
        if (!list) return;
        if (!values.length) {
            list.innerHTML = `<small class="text-muted">${escapeHtml(text('descricaoSemSugestoes'))}</small>`;
            return;
        }
        list.innerHTML = values.map((item) => `<button type="button" class="btn btn-outline-secondary btn-sm gerenciar-descricao-sugestao" data-descricao="${escapeHtml(item.descricao)}">${escapeHtml(item.descricao)}</button>`).join('');
        list.querySelectorAll('.gerenciar-descricao-sugestao').forEach((button) => {
            button.addEventListener('click', () => {
                $('#gerenciarDescricaoInput').value = normalizeDescriptionInput(button.dataset.descricao || '');
            });
        });
    }

    function normalizeDescriptionInput(value) {
        return String(value || '').toLocaleUpperCase();
    }

    function normalizeRequestingPhysicianInput(value) {
        return String(value || '').toLocaleUpperCase();
    }

    function normalizeStudyInformationInput(value) {
        return String(value || '').toLocaleUpperCase('pt-BR');
    }

    async function openDescriptionModal() {
        const modalidade = String(state.context?.modalidade || '').trim();
        const descriptionModal = modal('gerenciarDescricaoModal');
        if (!state.studyId || !modalidade || !descriptionModal) {
            showFeedback(text('erroOperacao'), 'danger');
            return;
        }
        $('#gerenciarDescricaoInput').value = normalizeDescriptionInput(state.context?.study_description || '');
        $('#gerenciarDescricaoModalidade').textContent = format(text('descricaoModalidade'), modalidade);
        $('#gerenciarDescricaoLote').style.display = state.context?.can_apply_description_batch ? 'inline-flex' : 'none';
        renderDescriptionSuggestions([]);
        showDescriptionStatus('', 'info');

        // Bootstrap não deve manter dois modais abertos no mesmo backdrop. Fecha
        // o menu Gerenciar e só abre o formulário após o evento hidden, evitando
        // o clique aparentemente sem efeito observado na Gestão de Exames.
        const mainElement = document.getElementById('gerenciarModal');
        const mainModal = modal('gerenciarModal');
        if (mainElement?.classList.contains('show') && mainModal) {
            state.reopenGerenciarAfterDescription = true;
            mainElement.addEventListener('hidden.bs.modal', () => descriptionModal.show(), { once: true });
            mainModal.hide();
        } else {
            descriptionModal.show();
        }
        try {
            const response = await fetch(`/api/gestao-exames/descricoes-por-modalidade?estudo_id=${encodeURIComponent(state.studyId)}&modalidade=${encodeURIComponent(modalidade)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin'
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.msg || text('erroOperacao'));
            renderDescriptionSuggestions(payload.sugestoes || []);
        } catch (error) {
            showDescriptionStatus(error.message || text('erroOperacao'), 'danger');
        }
    }

    async function applyDescription(batch) {
        const input = $('#gerenciarDescricaoInput');
        const descricao = normalizeDescriptionInput(input.value).trim();
        input.value = descricao;
        if (!state.studyId || descricao.length < 3) {
            showDescriptionStatus(text('erroOperacao'), 'danger');
            return;
        }
        const baseUrl = `/api/gestao-exames/estudos/${encodeURIComponent(state.studyId)}/descricao`;
        if (!batch) {
            const response = await fetch(baseUrl, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
                body: JSON.stringify({ descricao, csrf: csrfToken() })
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.msg || text('erroOperacao'));
            modal('gerenciarDescricaoModal')?.hide();
            window.location.reload();
            return;
        }

        const previewResponse = await fetch(`${baseUrl}/previa-lote`, {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
            body: JSON.stringify({ descricao, csrf: csrfToken() })
        });
        const previewPayload = await previewResponse.json();
        if (!previewResponse.ok || !previewPayload.ok) throw new Error(previewPayload.msg || text('erroOperacao'));
        const preview = previewPayload.previa || {};
        if (!window.confirm(format(text('confirmarDescricaoLote'), preview.total || 0, preview.modalidade || ''))) return;

        const response = await fetch(`${baseUrl}/lote`, {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
            body: JSON.stringify({ descricao, confirmar: true, csrf: csrfToken() })
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) throw new Error(payload.msg || text('erroOperacao'));
        modal('gerenciarDescricaoModal')?.hide();
        window.location.reload();
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
        $('#gerenciarPrioridadeConfirmacao').checked = false;
        $('#gerenciarPrioridadeAviso').style.display = 'none';
        $('#gerenciarPrioridadeErro').style.display = 'none';
        modal('gerenciarPrioridadeModal')?.show();
        loadPriorityRecipients();
    }

    function openRequestingPhysicianModal() {
        if (!state.studyId || state.context?.chat_pending) return;
        $('#gerenciarSolicitanteInput').value = normalizeRequestingPhysicianInput(state.context?.requesting_physician_manual || '');
        showRequestingPhysicianStatus('', 'info');
        modal('gerenciarSolicitanteModal')?.show();
    }

    function openStudyInformationModal() {
        if (!state.studyId || state.context?.chat_pending) return;
        const informationModal = modal('gerenciarInformacoesModal');
        if (!informationModal) return;
        $('#gerenciarInformacoesInput').value = normalizeStudyInformationInput(state.context?.study_information || '');
        showStudyInformationStatus('', 'info');
        const mainElement = document.getElementById('gerenciarModal');
        const mainModal = modal('gerenciarModal');
        if (mainElement?.classList.contains('show') && mainModal) {
            state.reopenGerenciarAfterInformation = true;
            mainElement.addEventListener('hidden.bs.modal', () => informationModal.show(), { once: true });
            mainModal.hide();
        } else {
            informationModal.show();
        }
    }

    async function saveRequestingPhysician(event) {
        event.preventDefault();
        if (!state.studyId || state.context?.chat_pending) return;
        const input = $('#gerenciarSolicitanteInput');
        const value = normalizeRequestingPhysicianInput(input.value).trim();
        input.value = value;
        const response = await fetch(`/api/gestao-exames/estudos/${encodeURIComponent(state.studyId)}/medico-solicitante`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify({ medico_solicitante: value, csrf: csrfToken() })
        });
        let payload = {};
        try { payload = await response.json(); } catch (error) { /* resposta não JSON */ }
        if (!response.ok || !payload.ok) throw new Error(payload.msg || text('erroOperacao'));
        modal('gerenciarSolicitanteModal')?.hide();
        window.location.reload();
    }

    async function saveStudyInformation(event) {
        event.preventDefault();
        if (!state.studyId || state.context?.chat_pending) return;
        const input = $('#gerenciarInformacoesInput');
        const value = normalizeStudyInformationInput(input?.value || '').trim();
        input.value = value;
        const response = await fetch(`/api/gestao-exames/estudos/${encodeURIComponent(state.studyId)}/informacoes`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify({ informacoes: value, csrf: csrfToken() })
        });
        let payload = {};
        try { payload = await response.json(); } catch (error) { /* resposta não JSON */ }
        if (!response.ok || !payload.ok) throw new Error(payload.msg || text('erroOperacao'));
        modal('gerenciarInformacoesModal')?.hide();
        window.location.reload();
    }

    async function loadPriorityRecipients() {
        const box = $('#gerenciarPrioridadeDestinatarios');
        const list = $('#gerenciarPrioridadeDestinatariosLista');
        const prioritySelect = $('#gerenciarPrioridadeSelect');
        if (!box || !list || !prioritySelect || !state.studyId) return;
        box.style.display = 'block';
        list.textContent = text('destinatariosCarregando');
        try {
            const response = await fetch(`/api/gestao-exames/estudos/${encodeURIComponent(state.studyId)}/destinatarios-prioridade?prioridade=${encodeURIComponent(prioritySelect.value)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin'
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.msg || text('erroOperacao'));
            const groups = payload.preview?.groups || [];
            if (!groups.length) {
                list.textContent = text('destinatariosNenhum');
                return;
            }
            list.innerHTML = groups.map((group) => {
                const channels = (group.channels || []).map(escapeHtml).join(', ');
                return `<div class="border-top pt-1 mt-1"><strong>${escapeHtml(group.name)}</strong><br><span class="text-muted">${escapeHtml(text('destinatariosGrupo'))}: ${escapeHtml(String(group.member_count || 0))} ${escapeHtml(text('destinatariosMembros'))} · ${escapeHtml(text('destinatariosCanais'))}: ${channels}</span></div>`;
            }).join('');
        } catch (error) {
            list.textContent = error.message || text('erroOperacao');
        }
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
        if (!$('#gerenciarPrioridadeConfirmacao')?.checked) {
            $('#gerenciarPrioridadeErro').textContent = text('confirmacaoPrioridadeObrigatoria');
            $('#gerenciarPrioridadeErro').style.display = 'block';
            return;
        }
        if (!window.confirm(text('confirmarPrioridade'))) return;
        const priority = $('#gerenciarPrioridadeSelect').value;
        const response = await fetch(`/api/gestao-exames/estudos/${encodeURIComponent(state.studyId)}/prioridade`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify({ prioridade: priority, motivo: reason, confirmar_prioridade: true, csrf: state.csrf })
        });
        let payload = {};
        try { payload = await response.json(); } catch (error) { /* resposta não JSON */ }
        if (!response.ok || !payload.ok) throw new Error(payload.msg || text('erroOperacao'));
        modal('gerenciarPrioridadeModal')?.hide();
        window.location.reload();
    }

    function bind() {
        const descriptionInput = $('#gerenciarDescricaoInput');
        if (descriptionInput) descriptionInput.style.textTransform = 'uppercase';
        descriptionInput?.addEventListener('input', (event) => {
            const input = event.currentTarget;
            const normalized = normalizeDescriptionInput(input.value);
            if (input.value !== normalized) input.value = normalized;
        });
        descriptionInput?.addEventListener('change', (event) => {
            const input = event.currentTarget;
            input.value = normalizeDescriptionInput(input.value);
        });
        const requestingPhysicianInput = $('#gerenciarSolicitanteInput');
        if (requestingPhysicianInput) requestingPhysicianInput.style.textTransform = 'uppercase';
        requestingPhysicianInput?.addEventListener('input', (event) => {
            const input = event.currentTarget;
            const normalized = normalizeRequestingPhysicianInput(input.value);
            if (input.value !== normalized) input.value = normalized;
        });
        requestingPhysicianInput?.addEventListener('change', (event) => {
            const input = event.currentTarget;
            input.value = normalizeRequestingPhysicianInput(input.value);
        });
        const studyInformationInput = $('#gerenciarInformacoesInput');
        if (studyInformationInput) studyInformationInput.style.textTransform = 'uppercase';
        studyInformationInput?.addEventListener('input', (event) => {
            const input = event.currentTarget;
            const normalized = normalizeStudyInformationInput(input.value);
            if (input.value !== normalized) input.value = normalized;
        });
        studyInformationInput?.addEventListener('change', (event) => {
            const input = event.currentTarget;
            input.value = normalizeStudyInformationInput(input.value);
        });
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
        $('#gerenciarDescricao')?.addEventListener('click', () => { openDescriptionModal(); });
        document.getElementById('gerenciarDescricaoModal')?.addEventListener('hidden.bs.modal', () => {
            if (!state.reopenGerenciarAfterDescription || !state.context) return;
            state.reopenGerenciarAfterDescription = false;
            modal('gerenciarModal')?.show();
        });
        $('#gerenciarPrioridade')?.addEventListener('click', openPriorityModal);
        $('#gerenciarSolicitante')?.addEventListener('click', openRequestingPhysicianModal);
        $('#gerenciarInformacoes')?.addEventListener('click', openStudyInformationModal);
        document.getElementById('gerenciarInformacoesModal')?.addEventListener('hidden.bs.modal', () => {
            if (!state.reopenGerenciarAfterInformation || !state.context) return;
            state.reopenGerenciarAfterInformation = false;
            modal('gerenciarModal')?.show();
        });
        $('#gerenciarPrioridadeSelect')?.addEventListener('change', loadPriorityRecipients);
        $('#gerenciarChatForm')?.addEventListener('submit', (event) => {
            sendChat(event).catch((error) => showChatStatus(error.message || text('erroOperacao'), 'danger'));
        });
        $('#gerenciarChatCritical')?.addEventListener('click', () => {
            sendChat(null, 'comunicar_achado_critico').catch((error) => showChatStatus(error.message || text('erroOperacao'), 'danger'));
        });
        $('#gerenciarChatConcluir')?.addEventListener('click', () => {
            completeChat().catch((error) => showChatStatus(error.message || text('erroOperacao'), 'danger'));
        });
        $('#gerenciarDescricaoForm')?.addEventListener('submit', (event) => {
            event.preventDefault();
            applyDescription(false).catch((error) => showDescriptionStatus(error.message || text('erroOperacao'), 'danger'));
        });
        $('#gerenciarDescricaoLote')?.addEventListener('click', () => {
            applyDescription(true).catch((error) => showDescriptionStatus(error.message || text('erroOperacao'), 'danger'));
        });
        $('#gerenciarPrioridadeForm')?.addEventListener('submit', (event) => {
            savePriority(event).catch((error) => {
                $('#gerenciarPrioridadeErro').textContent = error.message || text('erroOperacao');
                $('#gerenciarPrioridadeErro').style.display = 'block';
            });
        });
        $('#gerenciarSolicitanteForm')?.addEventListener('submit', (event) => {
            saveRequestingPhysician(event).catch((error) => showRequestingPhysicianStatus(error.message || text('erroOperacao'), 'danger'));
        });
        $('#gerenciarInformacoesForm')?.addEventListener('submit', (event) => {
            saveStudyInformation(event).catch((error) => showStudyInformationStatus(error.message || text('erroOperacao'), 'danger'));
        });
        $('#gerenciarPrioridadeMotivo')?.addEventListener('input', (event) => {
            $('#gerenciarPrioridadeCount').textContent = `${event.target.value.length}/20`;
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
    else bind();
}());
