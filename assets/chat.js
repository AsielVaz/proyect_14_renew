(() => {
    const dialog = document.querySelector('[data-chat]');
    if (!dialog || typeof dialog.showModal !== 'function') return;

    const find = (key) => dialog.querySelector('[data-chat-' + key + ']');
    const open = document.querySelector('[data-chat-open]');
    const history = find('history');
    const threads = find('threads');
    const moreThreads = find('more-threads');
    const title = find('thread-title');
    const end = find('end');
    const review = find('review');
    const confirmation = find('review-confirm');
    const transcript = find('scroll');
    const messages = find('messages');
    const empty = find('empty');
    const older = find('older');
    const form = find('form');
    const input = find('input');
    const send = find('send');
    const status = find('status');
    const mobile = window.matchMedia('(max-width: 650px)');

    let active = null;
    let closed = false;
    let reviewed = false;
    let busy = false;
    let offset = 0;
    let retry = null;
    let hydrated = false;

    function notice(text = '', error = false, loading = false) {
        status.textContent = text;
        status.dataset.error = String(error);
        status.dataset.loading = String(loading);
    }

    async function api(action, fields = {}, post = false) {
        const url = new URL(window.location.pathname, window.location.origin);
        url.searchParams.set('chat_api', action);
        const options = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
        if (post) {
            options.method = 'POST';
            options.body = new URLSearchParams({ ...fields, csrf_token: dialog.dataset.csrf });
        } else {
            Object.entries(fields).forEach(([name, value]) => url.searchParams.set(name, value));
        }
        const response = await fetch(url, options);
        const data = await response.json().catch(() => null);
        if (!response.ok || !data?.ok) {
            const error = new Error(data?.mensaje || 'No se pudo conectar con el chat. Intenta de nuevo.');
            error.status = response.status;
            throw error;
        }
        return data;
    }

    function syncControls() {
        for (const control of dialog.querySelectorAll('button:not([data-chat-dismiss])')) {
            control.disabled = busy;
        }
        send.disabled = busy || closed || !hydrated;
        input.readOnly = busy || closed || !hydrated;
        end.hidden = !active || closed;
        review.hidden = !active;
        review.disabled = busy || reviewed;
        review.textContent = reviewed ? 'Enviada a revisión' : 'Enviar a revisión';
        form.hidden = closed;
    }

    async function work(task, pending = '') {
        if (busy) return;
        busy = true;
        notice(pending, false, Boolean(pending));
        syncControls();
        try {
            await task();
        } catch (error) {
            if (error.status === 401 || error.status === 403) {
                resetThread();
                threads.replaceChildren();
                moreThreads.hidden = true;
                find('report').hidden = true;
                find('scope').textContent = 'Facturas y movimientos · Solo lectura';
                hydrated = false;
                retry = null;
            }
            notice(error.message, true);
        } finally {
            if (status.dataset.loading === 'true') notice();
            busy = false;
            syncControls();
        }
    }

    function select(id) {
        active = id === null ? null : String(id);
        for (const button of threads.querySelectorAll('button[data-thread]')) {
            button.setAttribute('aria-current', String(button.dataset.thread === active));
        }
    }

    function renderAnswer(paragraph, content) {
        const allowed = /\?(?:chat_registro=(?:factura|complemento|movimiento|banco|stp|traspaso)|document=(?:invoice|complement)_(?:pdf|xml))&id=[1-9][0-9]{0,9}(?![\w&])/g;
        let cursor = 0;
        for (const match of content.matchAll(allowed)) {
            paragraph.append(document.createTextNode(content.slice(cursor, match.index)));
            const link = document.createElement('a');
            link.href = match[0];
            link.textContent = match[0].includes('_pdf') ? 'Abrir PDF' :
                match[0].includes('_xml') ? 'Abrir XML' : 'Ver registro';
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            paragraph.append(link);
            cursor = match.index + match[0].length;
        }
        paragraph.append(document.createTextNode(content.slice(cursor)));
    }

    function renderMessages(rows) {
        const fragment = document.createDocumentFragment();
        for (const row of rows) {
            const item = document.createElement('li');
            item.className = 's14-chat-message';
            item.dataset.role = row.rol;
            item.dataset.id = row.id;
            const label = document.createElement('strong');
            label.textContent = row.rol === 'asistente' ? 'Asistente' : 'Tú';
            const text = document.createElement('p');
            if (row.rol === 'asistente') {
                renderAnswer(text, row.contenido);
            } else {
                text.textContent = row.contenido;
            }
            item.append(label, text);
            fragment.append(item);
        }
        return fragment;
    }

    function renderThread(data) {
        select(data.conversacion.id);
        title.textContent = data.conversacion.titulo || 'Nueva conversación';
        closed = Boolean(data.conversacion.cerrado_en);
        reviewed = Number(data.conversacion.revision) === 1;
        empty.hidden = data.mensajes.length > 0;
        older.hidden = !data.hay_mas;
        messages.replaceChildren(renderMessages(data.mensajes));
        transcript.scrollTop = transcript.scrollHeight;
    }

    function resetThread() {
        select(null);
        closed = false;
        reviewed = false;
        title.textContent = 'Nueva conversación';
        empty.hidden = false;
        older.hidden = true;
        messages.replaceChildren();
    }

    async function loadList(append = false) {
        const data = await api('list', { offset: append ? offset : 0 });
        if (!append) {
            offset = 0;
            threads.replaceChildren();
        }
        for (const row of data.conversaciones) {
            if ([...threads.querySelectorAll('[data-thread]')].some((item) => item.dataset.thread === String(row.id))) {
                continue;
            }
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.thread = row.id;
            button.textContent = (row.titulo || 'Nueva conversación') + (row.cerrado_en ? ' · Cerrada' : '');
            button.setAttribute('aria-current', String(String(row.id) === active));
            li.append(button);
            threads.append(li);
        }
        offset += data.conversaciones.length;
        moreThreads.hidden = !data.hay_mas;
        find('report').hidden = !data.reportes;
        find('scope').textContent = data.reportes
            ? 'Facturas, movimientos y reportes · Solo lectura'
            : 'Facturas y movimientos · Solo lectura';
    }

    function focusInput() {
        if (!closed && dialog.open) input.focus();
    }

    open.addEventListener('click', () => {
        dialog.showModal();
        if (mobile.matches) history.open = false;
        if (busy) return;
        const previous = active;
        hydrated = false;
        resetThread();
        work(async () => {
            await loadList();
            const id = previous || threads.querySelector('[data-thread]')?.dataset.thread;
            if (id) {
                try {
                    renderThread(await api('thread', { id }));
                } catch (error) {
                    if (error.status !== 404) throw error;
                    retry = null;
                    input.value = '';
                }
            }
            hydrated = true;
            notice(closed ? 'La conversación está cerrada.' : '');
        }, 'Cargando conversaciones…');
    });

    find('dismiss').addEventListener('click', () => dialog.close());
    find('new').addEventListener('click', () => {
        if (busy) return;
        retry = null;
        input.value = '';
        resetThread();
        if (mobile.matches) history.open = false;
        notice();
        syncControls();
        focusInput();
    });

    threads.addEventListener('click', (event) => {
        const button = event.target.closest('[data-thread]');
        if (!button) return;
        work(async () => {
            const data = await api('thread', { id: button.dataset.thread });
            retry = null;
            input.value = '';
            renderThread(data);
            if (mobile.matches) history.open = false;
            notice(closed ? 'La conversación está cerrada.' : '');
        }, 'Cargando conversación…').then(focusInput);
    });

    moreThreads.addEventListener('click', () => work(() => loadList(true), 'Cargando conversaciones…'));
    older.addEventListener('click', () => work(async () => {
        const first = messages.firstElementChild;
        if (!first) return;
        const data = await api('thread', { id: active, antes: first.dataset.id });
        const height = transcript.scrollHeight;
        messages.prepend(renderMessages(data.mensajes));
        older.hidden = !data.hay_mas;
        transcript.scrollTop += transcript.scrollHeight - height;
    }, 'Cargando mensajes anteriores…'));

    review.addEventListener('click', () => {
        if (!active || busy || reviewed) return;
        confirmation.showModal();
    });

    confirmation.querySelector('form').addEventListener('submit', (event) => {
        event.preventDefault();
        confirmation.close();
        if (event.submitter?.value !== 'yes' || !active || reviewed || !dialog.open) return;
        work(async () => {
            const data = await api('review', { id: active }, true);
            reviewed = Number(data.revision) === 1;
            notice('Conversación enviada a revisión.');
        }, 'Enviando a revisión…');
    });

    end.addEventListener('click', () => work(async () => {
        renderThread(await api('close', { id: active }, true));
        retry = null;
        input.value = '';
        notice('Conversación cerrada.');
        await loadList();
    }, 'Cerrando conversación…'));

    dialog.querySelectorAll('[data-chat-suggestion]').forEach((button) => {
        button.addEventListener('click', () => {
            input.value = button.dataset.chatSuggestion;
            focusInput();
        });
    });

    input.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.shiftKey || event.isComposing || event.keyCode === 229) {
            return;
        }
        event.preventDefault();
        if (!send.disabled && input.value.trim() !== '') {
            form.requestSubmit();
        }
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const draft = input.value;
        const text = draft.trim();
        if (!text || closed || !hydrated || busy) return;
        input.value = '';
        work(async () => {
            try {
                if (retry?.text !== text || retry?.id !== active) {
                    const bytes = crypto.getRandomValues(new Uint8Array(16));
                    retry = { id: active, text, token: Array.from(bytes, (part) => part.toString(16).padStart(2, '0')).join('') };
                }
                if (!active) {
                    const data = await api('new', { solicitud: retry.token }, true);
                    select(data.id);
                    retry.id = active;
                }
                const data = await api('send', { id: active, solicitud: retry.token, mensaje: text }, true);
                renderThread(data);
                retry = null;
                notice('Respuesta recibida.');
            } catch (error) {
                input.value = draft;
                if (active) {
                    try {
                        renderThread(await api('thread', { id: active }));
                    } catch {
                        // Conserva el error del envío y el borrador para reintentar.
                    }
                }
                throw error;
            }
            await loadList();
        }, 'Consultando con sistema 14').then(focusInput);
    });
})();

