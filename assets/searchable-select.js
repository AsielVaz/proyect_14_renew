(() => {
    'use strict';

    const instances = new WeakMap();
    const limit = 200;
    let active = null;
    let sequence = 0;

    const normalize = (value) => String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase('es-MX')
        .trim();

    const close = ({restoreFocus = false} = {}) => {
        if (!active) return;
        const {wrapper, button, menu} = active;
        wrapper.classList.remove('is-open');
        button.setAttribute('aria-expanded', 'false');
        button.removeAttribute('aria-controls');
        menu.remove();
        active = null;
        if (restoreFocus) button.focus();
    };

    const optionData = (select) => Array.from(select.options).map((option, index) => {
        const group = option.parentElement instanceof HTMLOptGroupElement ? option.parentElement.label.trim() : '';
        const label = option.textContent.trim();
        const extra = Object.values(option.dataset).join(' ');
        return {
            index,
            label,
            group,
            disabled: option.disabled || option.parentElement?.disabled === true,
            search: normalize([label, option.value, group, extra].join(' ')),
        };
    });

    const enhance = (select) => {
        if (instances.has(select) || select.multiple || select.size > 1 || select.dataset.nativeSelect !== undefined) return;

        const fieldLabel = select.getAttribute('aria-label')
            || select.labels?.[0]?.querySelector(':scope > span')?.textContent.trim()
            || Array.from(select.labels?.[0]?.childNodes || [])
                .filter((node) => node.nodeType === Node.TEXT_NODE)
                .map((node) => node.textContent.trim())
                .filter(Boolean)
                .join(' ')
            || select.name
            || 'opción';

        const wrapper = document.createElement('div');
        wrapper.className = 'search-select';
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'search-select-trigger';
        button.setAttribute('aria-haspopup', 'listbox');
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-label', `Buscar y seleccionar: ${fieldLabel}`);

        const buttonLabel = document.createElement('span');
        buttonLabel.className = 'search-select-label';
        const chevron = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        chevron.setAttribute('viewBox', '0 0 20 20');
        chevron.setAttribute('aria-hidden', 'true');
        chevron.classList.add('search-select-chevron');
        chevron.innerHTML = '<path d="m5 7.5 5 5 5-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>';
        button.append(buttonLabel, chevron);

        select.classList.add('search-select-native');
        select.insertAdjacentElement('beforebegin', wrapper);
        wrapper.append(select, button);

        const sync = () => {
            const selected = select.selectedOptions[0];
            const label = selected?.textContent.trim() || 'Seleccionar…';
            buttonLabel.textContent = label;
            button.title = label;
            button.disabled = select.disabled;
            button.setAttribute('aria-disabled', String(select.disabled));
            button.classList.toggle('has-value', Boolean(select.value));
            wrapper.classList.toggle('is-disabled', select.disabled);
            if (active?.select === select && select.disabled) close();
        };

        const open = () => {
            if (select.disabled) return;
            if (active?.select === select) {
                close();
                return;
            }
            close();

            const menuId = `searchSelectMenu${++sequence}`;
            const listId = `${menuId}List`;
            const menu = document.createElement('div');
            menu.id = menuId;
            menu.className = 'search-select-menu';
            menu.setAttribute('role', 'dialog');
            menu.setAttribute('aria-label', `Buscar y seleccionar: ${fieldLabel}`);

            const searchWrap = document.createElement('label');
            searchWrap.className = 'search-select-search';
            const searchIcon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            searchIcon.setAttribute('viewBox', '0 0 24 24');
            searchIcon.setAttribute('aria-hidden', 'true');
            searchIcon.innerHTML = '<circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="m16.5 16.5 4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>';
            const search = document.createElement('input');
            search.type = 'search';
            search.className = 'search-select-input';
            search.placeholder = 'Buscar por nombre, ID, clave…';
            search.autocomplete = 'off';
            search.setAttribute('aria-label', `Buscar en ${fieldLabel}`);
            search.setAttribute('aria-controls', listId);
            searchWrap.append(searchIcon, search);

            const list = document.createElement('div');
            list.id = listId;
            list.className = 'search-select-options';
            list.setAttribute('role', 'listbox');
            const status = document.createElement('p');
            status.className = 'search-select-status';
            status.setAttribute('aria-live', 'polite');

            const render = () => {
                const term = normalize(search.value);
                const options = optionData(select).filter((option) => !option.disabled && option.search.includes(term));
                list.replaceChildren();
                options.slice(0, limit).forEach((option) => {
                    const item = document.createElement('button');
                    item.type = 'button';
                    item.className = 'search-select-option';
                    item.setAttribute('role', 'option');
                    item.setAttribute('aria-selected', String(select.selectedIndex === option.index));
                    if (option.group) {
                        const group = document.createElement('small');
                        group.textContent = option.group;
                        const label = document.createElement('span');
                        label.textContent = option.label;
                        item.append(group, label);
                    } else {
                        item.textContent = option.label;
                    }
                    item.addEventListener('click', () => {
                        select.selectedIndex = option.index;
                        select.dispatchEvent(new Event('change', {bubbles: true}));
                        sync();
                        close({restoreFocus: true});
                    });
                    list.appendChild(item);
                });
                status.textContent = options.length === 0
                    ? 'No se encontraron opciones con ese criterio.'
                    : `${options.length} opción${options.length === 1 ? '' : 'es'} encontrada${options.length === 1 ? '' : 's'}${options.length > limit ? ` · mostrando las primeras ${limit}` : ''}`;
                if (term === '') list.querySelector('[aria-selected="true"]')?.scrollIntoView({block: 'nearest'});
            };

            search.addEventListener('input', render);
            search.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') close({restoreFocus: true});
                if (event.key === 'Enter') {
                    event.preventDefault();
                    list.querySelector('.search-select-option')?.click();
                }
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    list.querySelector('.search-select-option')?.focus();
                }
            });
            list.addEventListener('keydown', (event) => {
                const items = Array.from(list.querySelectorAll('.search-select-option'));
                const current = items.indexOf(document.activeElement);
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    items[Math.min(items.length - 1, current + 1)]?.focus();
                }
                if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    current <= 0 ? search.focus() : items[current - 1]?.focus();
                }
                if (event.key === 'Home') {
                    event.preventDefault();
                    items[0]?.focus();
                }
                if (event.key === 'End') {
                    event.preventDefault();
                    items.at(-1)?.focus();
                }
                if (event.key === 'Escape') close({restoreFocus: true});
            });

            menu.append(searchWrap, list, status);
            document.body.appendChild(menu);
            const rect = button.getBoundingClientRect();
            const menuWidth = Math.max(280, Math.min(440, rect.width));
            const spaceBelow = window.innerHeight - rect.bottom;
            menu.style.width = `${Math.min(menuWidth, window.innerWidth - 20)}px`;
            menu.style.left = `${Math.max(10, Math.min(window.innerWidth - menuWidth - 10, rect.left))}px`;
            if (spaceBelow >= 330 || rect.top < spaceBelow) {
                menu.style.top = `${rect.bottom + 6}px`;
            } else {
                menu.style.bottom = `${window.innerHeight - rect.top + 6}px`;
            }
            wrapper.classList.add('is-open');
            button.setAttribute('aria-expanded', 'true');
            button.setAttribute('aria-controls', menuId);
            active = {select, wrapper, button, menu};
            render();
            search.focus();
        };

        button.addEventListener('click', open);
        select.addEventListener('change', () => {
            button.classList.remove('is-invalid');
            sync();
        });
        select.addEventListener('invalid', (event) => {
            event.preventDefault();
            button.classList.add('is-invalid');
            button.focus();
        });
        select.form?.addEventListener('reset', () => window.setTimeout(sync));
        new MutationObserver(sync).observe(select, {attributes: true, childList: true, subtree: true});
        instances.set(select, {wrapper, button, sync});
        sync();
    };

    document.querySelectorAll('select').forEach(enhance);

    const pageObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
            if (!(node instanceof Element)) return;
            if (node.matches('select')) enhance(node);
            node.querySelectorAll?.('select').forEach(enhance);
        }));
    });
    pageObserver.observe(document.body, {childList: true, subtree: true});

    document.addEventListener('pointerdown', (event) => {
        if (active && !active.menu.contains(event.target) && !active.wrapper.contains(event.target)) close();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && active) close({restoreFocus: true});
    });
    window.addEventListener('resize', () => close());
    document.addEventListener('scroll', (event) => {
        if (active && !active.menu.contains(event.target)) close();
    }, true);
})();
