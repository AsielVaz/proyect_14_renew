(() => {
    'use strict';

    const editor = document.querySelector('[data-user-editor]');
    if (!(editor instanceof HTMLFormElement)) return;

    const tabs = Array.from(editor.querySelectorAll('[data-user-tab]'));
    const panels = Array.from(editor.querySelectorAll('[data-user-panel]'));
    const activateTab = (tab) => {
        if (!(tab instanceof HTMLButtonElement)) return;
        const target = tab.dataset.userTab;
        tabs.forEach((item) => {
            const active = item === tab;
            item.classList.toggle('is-active', active);
            item.setAttribute('aria-selected', String(active));
            item.tabIndex = active ? 0 : -1;
        });
        panels.forEach((panel) => {
            const active = panel.dataset.userPanel === target;
            panel.hidden = !active;
            panel.classList.toggle('is-active', active);
        });
    };

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activateTab(tab));
        tab.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            let nextIndex = index;
            if (event.key === 'ArrowLeft') nextIndex = (index - 1 + tabs.length) % tabs.length;
            if (event.key === 'ArrowRight') nextIndex = (index + 1) % tabs.length;
            if (event.key === 'Home') nextIndex = 0;
            if (event.key === 'End') nextIndex = tabs.length - 1;
            activateTab(tabs[nextIndex]);
            tabs[nextIndex].focus();
        });
    });

    const options = Array.from(editor.querySelectorAll('[data-user-company-option]'));
    const search = editor.querySelector('[data-user-company-search]');
    const empty = editor.querySelector('[data-user-company-empty]');
    const counters = Array.from(editor.querySelectorAll('[data-user-company-count], [data-user-company-summary]'));
    const hideTimers = new WeakMap();
    const normalize = (value) => String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase('es-MX')
        .trim();

    const updateCount = () => {
        const count = options.filter((option) => option.querySelector('input[type="checkbox"]')?.checked).length;
        counters.forEach((counter) => { counter.textContent = String(count); });
    };

    const setVisible = (option, visible) => {
        const previousTimer = hideTimers.get(option);
        if (previousTimer) window.clearTimeout(previousTimer);
        if (visible) {
            option.hidden = false;
            window.requestAnimationFrame(() => option.classList.remove('is-filtered-out'));
            return;
        }
        option.classList.add('is-filtered-out');
        hideTimers.set(option, window.setTimeout(() => {
            option.hidden = true;
            hideTimers.delete(option);
        }, 160));
    };

    const applySearch = () => {
        const query = normalize(search?.value);
        let visible = 0;
        options.forEach((option) => {
            const matches = query === '' || normalize(option.dataset.companySearch).includes(query);
            setVisible(option, matches);
            if (matches) visible++;
        });
        if (empty) empty.hidden = visible !== 0;
    };

    options.forEach((option) => {
        const checkbox = option.querySelector('input[type="checkbox"]');
        checkbox?.addEventListener('change', () => {
            option.classList.toggle('is-selected', checkbox.checked);
            updateCount();
        });
    });
    search?.addEventListener('input', applySearch);

    editor.querySelector('[data-user-companies="all"]')?.addEventListener('click', () => {
        options.filter((option) => !option.hidden && !option.classList.contains('is-filtered-out')).forEach((option) => {
            const checkbox = option.querySelector('input[type="checkbox"]');
            if (checkbox) {
                checkbox.checked = true;
                option.classList.add('is-selected');
            }
        });
        updateCount();
    });
    editor.querySelector('[data-user-companies="none"]')?.addEventListener('click', () => {
        options.forEach((option) => {
            const checkbox = option.querySelector('input[type="checkbox"]');
            if (checkbox) checkbox.checked = false;
            option.classList.remove('is-selected');
        });
        updateCount();
    });

    updateCount();
    applySearch();
})();
