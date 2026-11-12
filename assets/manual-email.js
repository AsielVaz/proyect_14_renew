(() => {
    'use strict';

    const root = document.querySelector('[data-manual-email]');
    if (!root) return;

    const form = document.getElementById('manualEmailForm');
    const status = root.querySelector('[data-mail-status]');
    const editor = root.querySelector('[data-mail-editor]');
    const htmlField = root.querySelector('[data-mail-html]');
    const preview = root.querySelector('[data-mail-preview]');
    const reviewPreview = document.querySelector('[data-review-preview]');
    const modal = document.querySelector('[data-mail-modal]');
    const tokenField = root.querySelector('[data-preview-token]');
    const conceptsBody = root.querySelector('[data-concepts-body]');
    const conceptsJson = root.querySelector('[data-concepts-json]');
    const conceptsEmpty = root.querySelector('[data-concepts-empty]');
    const conceptsTotal = root.querySelector('[data-concepts-total]');
    const result = root.querySelector('[data-mail-result]');
    const generateButton = root.querySelector('[data-generate-mail]');
    const reviewButton = root.querySelector('[data-review-mail]');
    const sendButton = document.querySelector('[data-send-mail]');
    const summary = document.querySelector('[data-review-summary]');
    let lastFocus = null;
    let sending = false;
    let modalCloseTimer = 0;

    const endpoint = (action) => `?movement=correos-manuales&mail_action=${encodeURIComponent(action)}`;
    const money = new Intl.NumberFormat('es-MX', {style: 'currency', currency: 'MXN'});
    const safeDocument = (html) => `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data: https:; style-src 'unsafe-inline' https:; font-src https:; base-uri 'none'; form-action 'none'"><style>a{pointer-events:none}</style></head><body>${html}</body></html>`;

    function setStatus(message, type = 'info') {
        status.hidden = !message;
        status.textContent = message;
        status.dataset.type = type;
    }

    function setBusy(button, busy, label) {
        if (!button) return;
        if (busy) {
            button.dataset.originalLabel = button.textContent;
            button.textContent = label;
        } else if (button.dataset.originalLabel) {
            button.textContent = button.dataset.originalLabel;
        }
        button.disabled = busy;
        button.setAttribute('aria-busy', String(busy));
    }

    function step(number) {
        root.querySelectorAll('[data-mail-step]').forEach((item) => {
            const current = Number(item.dataset.mailStep);
            item.classList.toggle('is-active', current === number);
            item.classList.toggle('is-done', current < number);
        });
    }

    function invalidateReview() {
        tokenField.value = '';
    }

    function updatePreview(target = preview) {
        target.srcdoc = safeDocument(htmlField.value);
    }

    function conceptRows() {
        return Array.from(conceptsBody.querySelectorAll('[data-concept-row]'));
    }

    function syncConcepts() {
        const rows = conceptRows().map((row) => ({
            description: row.querySelector('[data-concept-description]').value.trim(),
            quantity: row.querySelector('[data-concept-quantity]').value,
            price: row.querySelector('[data-concept-price]').value,
            tax: row.querySelector('[data-concept-tax]').checked,
        }));
        conceptsJson.value = JSON.stringify(rows);
        conceptsEmpty.hidden = rows.length > 0;
        let total = 0;
        rows.forEach((row, index) => {
            const quantity = Number(row.quantity) || 0;
            const price = Number(row.price) || 0;
            const line = quantity * price * (row.tax ? 1.16 : 1);
            total += line;
            const output = conceptRows()[index].querySelector('[data-concept-amount]');
            output.textContent = money.format(line);
        });
        conceptsTotal.textContent = money.format(total);
        invalidateReview();
    }

    function addConcept() {
        const row = document.createElement('tr');
        row.dataset.conceptRow = '';
        row.innerHTML = `
            <td><textarea rows="2" maxlength="2000" required data-concept-description aria-label="Descripción del concepto"></textarea></td>
            <td><input type="number" min="0.0001" max="1000000" step="0.0001" value="1" required data-concept-quantity aria-label="Cantidad"></td>
            <td><input type="number" min="0" max="1000000" step="0.01" value="0.00" required data-concept-price aria-label="Precio unitario"></td>
            <td><label class="manual-tax-toggle"><input type="checkbox" checked data-concept-tax><span>Sí</span></label></td>
            <td><strong data-concept-amount>$0.00</strong></td>
            <td><button type="button" class="manual-remove-concept" data-remove-concept aria-label="Eliminar concepto">×</button></td>`;
        conceptsBody.appendChild(row);
        row.querySelector('[data-concept-description]').focus();
        syncConcepts();
    }

    async function post(action, data) {
        const response = await fetch(endpoint(action), {method: 'POST', body: data, headers: {'X-Requested-With': 'XMLHttpRequest'}});
        let payload;
        try { payload = await response.json(); } catch (_) { throw new Error('El servidor devolvió una respuesta no válida.'); }
        if (!response.ok || !payload.ok) throw new Error(payload.message || 'No fue posible completar la operación.');
        return payload.data;
    }

    function validateForm(includeHtml = false) {
        syncConcepts();
        if (!form.reportValidity()) return false;
        if (conceptRows().some((row) => !row.querySelector('[data-concept-description]').value.trim())) {
            setStatus('Completa la descripción de todos los conceptos.', 'error');
            return false;
        }
        if (root.querySelector('[data-include-quote-pdf]')?.checked && conceptRows().length === 0) {
            setStatus('Agrega al menos un concepto para incluir el PDF de cotización.', 'error');
            root.querySelector('[data-add-concept]')?.focus();
            return false;
        }
        if (includeHtml && !htmlField.value.trim()) {
            setStatus('Genera o escribe el HTML del correo.', 'error');
            htmlField.focus();
            return false;
        }
        return true;
    }

    function openModal() {
        window.clearTimeout(modalCloseTimer);
        lastFocus = document.activeElement;
        modal.hidden = false;
        document.body.classList.add('has-manual-modal');
        step(3);
        window.requestAnimationFrame(() => {
            modal.classList.add('is-visible');
            sendButton.focus();
        });
    }

    function hideModal(afterClose) {
        modal.classList.remove('is-visible');
        document.body.classList.remove('has-manual-modal');
        window.clearTimeout(modalCloseTimer);
        modalCloseTimer = window.setTimeout(() => {
            if (!modal.classList.contains('is-visible')) modal.hidden = true;
            afterClose?.();
        }, 220);
    }

    function closeModal() {
        if (sending) return;
        hideModal(() => {
            step(2);
            lastFocus?.focus();
        });
    }

    root.querySelector('[data-company-select]').addEventListener('change', (event) => {
        const option = event.target.selectedOptions[0];
        root.querySelector('[data-sender-email]').value = option?.dataset.sender || '';
        invalidateReview();
    });

    root.querySelector('[data-color-mode]').addEventListener('change', (event) => {
        const color = root.querySelector('[data-content-color]');
        color.disabled = event.target.value !== 'custom';
        invalidateReview();
    });

    root.querySelector('[data-add-concept]').addEventListener('click', addConcept);
    conceptsBody.addEventListener('input', syncConcepts);
    conceptsBody.addEventListener('change', syncConcepts);
    conceptsBody.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-concept]');
        if (!button) return;
        button.closest('[data-concept-row]').remove();
        syncConcepts();
    });

    root.querySelector('[data-mail-files]').addEventListener('change', (event) => {
        const list = root.querySelector('[data-file-list]');
        list.replaceChildren();
        Array.from(event.target.files).forEach((file) => {
            const item = document.createElement('li');
            item.textContent = `${file.name} · ${(file.size / 1048576).toFixed(2)} MB`;
            list.appendChild(item);
        });
        invalidateReview();
    });

    form.addEventListener('input', (event) => {
        if (event.target !== tokenField) invalidateReview();
    });

    generateButton.addEventListener('click', async () => {
        if (!validateForm(false)) return;
        setBusy(generateButton, true, 'Generando…');
        setStatus('Preparando la plantilla y validando los conceptos…');
        try {
            const data = await post('generate', new FormData(form));
            htmlField.value = data.html;
            if (data.generatedByAi) form.elements.message.value = data.message;
            if (data.generatedAdditional) form.elements.additional.value = data.additional;
            editor.hidden = false;
            updatePreview();
            step(2);
            setStatus(data.generatedByAi ? 'Vista previa generada con redacción asistida. Revísala cuidadosamente.' : 'Vista previa generada. Revisa el contenido antes de continuar.', 'success');
            editor.scrollIntoView({
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                block: 'start'
            });
        } catch (error) {
            setStatus(error.message, 'error');
        } finally {
            setBusy(generateButton, false);
        }
    });

    root.querySelector('[data-refresh-preview]').addEventListener('click', () => {
        updatePreview();
        invalidateReview();
        setStatus('Vista previa actualizada.', 'success');
    });
    htmlField.addEventListener('input', () => invalidateReview());

    reviewButton.addEventListener('click', async () => {
        if (!validateForm(true)) return;
        setBusy(reviewButton, true, 'Validando…');
        setStatus('Validando destinatarios, contenido y adjuntos…');
        try {
            const data = await post('review', new FormData(form));
            tokenField.value = data.token;
            summary.replaceChildren();
            const recipients = data.recipients.map((item) => `${item.email}${item.type === 'bcc' ? ' (CCO)' : ''}`).join(', ');
            [['Remitente', data.from], ['Destinatarios', recipients], ['Asunto', data.subject], ['Adjuntos', data.attachments.length ? data.attachments.join(', ') : 'Sin adjuntos']].forEach(([label, value]) => {
                const wrapper = document.createElement('div');
                const dt = document.createElement('dt');
                const dd = document.createElement('dd');
                dt.textContent = label;
                dd.textContent = value;
                wrapper.append(dt, dd);
                summary.appendChild(wrapper);
            });
            updatePreview(reviewPreview);
            setStatus('Validación completa. Confirma el envío en la ventana de revisión.', 'success');
            openModal();
        } catch (error) {
            setStatus(error.message, 'error');
        } finally {
            setBusy(reviewButton, false);
        }
    });

    document.querySelectorAll('[data-close-mail-modal]').forEach((button) => button.addEventListener('click', closeModal));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) closeModal();
    });

    sendButton.addEventListener('click', async () => {
        if (!tokenField.value) {
            closeModal();
            setStatus('La revisión venció o el contenido cambió. Revísalo nuevamente.', 'error');
            return;
        }
        sending = true;
        setBusy(sendButton, true, 'Enviando…');
        setStatus('Enviando el correo. No cierres esta página…');
        try {
            const data = await post('send', new FormData(form));
            hideModal();
            result.hidden = false;
            result.dataset.type = data.status;
            const heading = document.createElement('h3');
            heading.textContent = data.status === 'success' ? 'Correo aceptado para envío' : 'Resultado del envío';
            const message = document.createElement('p');
            message.textContent = data.message;
            result.replaceChildren(heading, message);
            if (data.imap?.message) {
                const note = document.createElement('p');
                note.textContent = data.imap.message;
                result.appendChild(note);
            }
            tokenField.value = '';
            step(3);
            setStatus('El proveedor respondió. Consulta el resultado que aparece abajo.', data.status === 'success' ? 'success' : 'error');
            result.scrollIntoView({
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                block: 'center'
            });
        } catch (error) {
            tokenField.value = '';
            hideModal();
            step(2);
            setStatus(error.message, 'error');
        } finally {
            sending = false;
            setBusy(sendButton, false);
        }
    });
})();
