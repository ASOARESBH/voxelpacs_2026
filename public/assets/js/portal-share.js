(() => {
  'use strict';

  const dialog = document.getElementById('portal-share-dialog');
  const form = document.getElementById('portal-share-form');
  if (!dialog || !form) return;

  const token = document.getElementById('portal-share-token');
  const csrf = document.getElementById('portal-share-csrf');
  const study = document.getElementById('portal-share-study');
  const phoneField = document.getElementById('portal-share-phone-field');
  const emailField = document.getElementById('portal-share-email-field');
  const phone = document.getElementById('portal-share-phone');
  const email = document.getElementById('portal-share-email');
  const feedback = document.getElementById('portal-share-feedback');
  const submit = document.getElementById('portal-share-submit');

  const selectedChannel = () => document.querySelector('input[name="portal_share_channel"]:checked')?.value || 'whatsapp';
  const setChannel = () => {
    const isWhatsapp = selectedChannel() === 'whatsapp';
    phoneField.hidden = !isWhatsapp;
    emailField.hidden = isWhatsapp;
    phone.required = isWhatsapp;
    email.required = !isWhatsapp;
    submit.textContent = isWhatsapp ? 'Abrir WhatsApp para enviar' : 'Enviar e-mail com PDF';
    feedback.textContent = '';
  };

  document.querySelectorAll('.portal-share-trigger').forEach((button) => {
    button.addEventListener('click', () => {
      token.value = button.dataset.reportToken || '';
      study.textContent = button.dataset.studyLabel || 'Laudo médico';
      phone.value = '';
      email.value = '';
      setChannel();
      dialog.showModal();
      window.setTimeout(() => (selectedChannel() === 'whatsapp' ? phone : email).focus(), 0);
    });
  });
  document.querySelectorAll('[data-portal-share-close]').forEach((button) => button.addEventListener('click', () => dialog.close()));
  document.querySelectorAll('input[name="portal_share_channel"]').forEach((input) => input.addEventListener('change', setChannel));
  dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const channel = selectedChannel();
    const recipient = channel === 'whatsapp' ? phone.value.trim() : email.value.trim();
    if (!recipient) {
      feedback.textContent = channel === 'whatsapp' ? 'Informe o número do WhatsApp.' : 'Informe o e-mail do destinatário.';
      return;
    }
    submit.disabled = true;
    feedback.textContent = 'Preparando compartilhamento seguro…';
    try {
      const body = new URLSearchParams({ csrf: csrf.value, channel });
      body.set(channel === 'whatsapp' ? 'phone' : 'email', recipient);
      const response = await fetch(`/compartilhar/${encodeURIComponent(token.value)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        credentials: 'same-origin',
        body: body.toString(),
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.msg || 'Não foi possível concluir o compartilhamento.');
      if (data.action === 'whatsapp') {
        feedback.textContent = 'Link temporário criado. O WhatsApp será aberto para sua confirmação final de envio.';
        window.open(data.url, '_blank', 'noopener');
        return;
      }
      feedback.textContent = 'E-mail com o PDF e link temporário enviado com sucesso.';
      window.setTimeout(() => dialog.close(), 1700);
    } catch (error) {
      feedback.textContent = error instanceof Error ? error.message : 'Não foi possível concluir o compartilhamento.';
    } finally {
      submit.disabled = false;
    }
  });
})();
