(() => {
    'use strict';

    const backdrop = document.querySelector('[data-global-search-backdrop]');
    const dialog = document.getElementById('global-search-dialog');
    const input = document.getElementById('global-search-input');
    const form = document.querySelector('[data-global-search-form]');
    const results = document.querySelector('[data-global-search-results]');
    const status = document.querySelector('[data-global-search-status]');
    const openButtons = Array.from(document.querySelectorAll('[data-global-search-open]'));
    const closeButton = document.querySelector('[data-global-search-close]');
    if (!backdrop || !dialog || !input || !form || !results || !status || !closeButton) return;

    const groupDefinitions = [
        ['payments', 'Pagos', 'P'],
        ['invoices', 'Facturas', 'F'],
        ['captures', 'Capturas de saldos', 'C'],
    ];
    let previousFocus = null;
    let debounceTimer = 0;
    let closeTimer = 0;
    let controller = null;

    const setStatus = (message) => {
        status.textContent = message;
    };

    const openSearch = () => {
        window.clearTimeout(closeTimer);
        previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        backdrop.hidden = false;
        document.body.classList.add('has-global-search');
        window.requestAnimationFrame(() => {
            backdrop.classList.add('is-visible');
            input.focus();
            input.select();
        });
    };

    const closeSearch = () => {
        if (backdrop.hidden) return;
        if (controller) controller.abort();
        window.clearTimeout(debounceTimer);
        backdrop.classList.remove('is-visible');
        document.body.classList.remove('has-global-search');
        closeTimer = window.setTimeout(() => {
            if (!backdrop.classList.contains('is-visible')) backdrop.hidden = true;
            if (previousFocus && document.contains(previousFocus)) previousFocus.focus();
        }, 220);
    };

    const appendText = (parent, tag, text, className = '') => {
        const element = document.createElement(tag);
        if (className) element.className = className;
        element.textContent = text;
        parent.appendChild(element);
        return element;
    };

    const createResult = (item, type, letter) => {
        const link = document.createElement('a');
        link.className = `global-search-result is-${type}`;
        link.href = item.url;

        const badge = appendText(link, 'span', letter, 'global-search-result-badge');
        badge.setAttribute('aria-hidden', 'true');

        const copy = document.createElement('span');
        copy.className = 'global-search-result-copy';
        appendText(copy, 'strong', item.title || 'Resultado');
        appendText(copy, 'span', item.description || 'Sin descripción', 'global-search-result-description');
        const meta = [item.meta, item.date].filter(Boolean).join(' · ');
        if (meta) appendText(copy, 'small', meta);
        link.appendChild(copy);

        const values = document.createElement('span');
        values.className = 'global-search-result-values';
        appendText(values, 'strong', item.value || '');
        if (item.secondaryValue) appendText(values, 'small', item.secondaryValue);
        values.setAttribute('aria-label', [item.value, item.secondaryValue].filter(Boolean).join(', '));
        link.appendChild(values);
        return link;
    };

    const render = (payload) => {
        results.replaceChildren();
        let total = 0;
        groupDefinitions.forEach(([key, label, letter]) => {
            const items = Array.isArray(payload.results?.[key]) ? payload.results[key] : [];
            if (!items.length) return;
            total += items.length;
            const section = document.createElement('section');
            section.className = 'global-search-group';
            const heading = document.createElement('div');
            heading.className = 'global-search-group-heading';
            appendText(heading, 'h3', label);
            appendText(heading, 'span', String(items.length));
            section.appendChild(heading);
            const list = document.createElement('div');
            list.className = 'global-search-result-list';
            items.forEach((item) => list.appendChild(createResult(item, key, letter)));
            section.appendChild(list);
            results.appendChild(section);
        });

        if (total === 0) {
            const empty = document.createElement('div');
            empty.className = 'global-search-empty';
            appendText(empty, 'strong', 'Sin coincidencias');
            appendText(empty, 'p', 'Prueba con otro ID, monto, descripción, folio, UUID o referencia.');
            results.appendChild(empty);
            setStatus(`No se encontraron resultados para “${payload.query}”.`);
        } else {
            setStatus(`${total} ${total === 1 ? 'resultado encontrado' : 'resultados encontrados'} para “${payload.query}”.`);
        }
    };

    const search = async () => {
        const query = input.value.trim();
        if (query.length < 2) {
            if (controller) controller.abort();
            results.replaceChildren();
            setStatus('Escribe al menos 2 caracteres para buscar.');
            return;
        }

        if (controller) controller.abort();
        controller = new AbortController();
        form.setAttribute('aria-busy', 'true');
        setStatus('Buscando en pagos, facturas y capturas…');
        try {
            const url = new URL(window.location.href);
            url.search = '';
            url.searchParams.set('global_search', '1');
            url.searchParams.set('q', query);
            const response = await fetch(url, {
                headers: {'Accept': 'application/json'},
                signal: controller.signal,
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || 'No fue posible buscar.');
            if (input.value.trim() === query) render(payload);
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') return;
            results.replaceChildren();
            const failure = document.createElement('div');
            failure.className = 'global-search-empty is-error';
            appendText(failure, 'strong', 'No se pudo completar la búsqueda');
            appendText(failure, 'p', error instanceof Error ? error.message : 'Inténtalo nuevamente.');
            results.appendChild(failure);
            setStatus('La búsqueda no pudo completarse.');
        } finally {
            form.removeAttribute('aria-busy');
        }
    };

    openButtons.forEach((button) => button.addEventListener('click', openSearch));
    closeButton.addEventListener('click', closeSearch);
    backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) closeSearch();
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        window.clearTimeout(debounceTimer);
        search();
    });
    input.addEventListener('input', () => {
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(search, 260);
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            const firstResult = results.querySelector('a');
            if (firstResult instanceof HTMLElement) {
                event.preventDefault();
                firstResult.focus();
            }
        }
    });

    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            openSearch();
            return;
        }
        if (event.key === 'Escape' && !backdrop.hidden) {
            event.preventDefault();
            closeSearch();
            return;
        }
        if (event.key !== 'Tab' || backdrop.hidden) return;
        const focusable = Array.from(dialog.querySelectorAll('button, input, a[href]'))
            .filter((element) => element instanceof HTMLElement && !element.hidden);
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
})();
