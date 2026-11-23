(() => {
    const module = document.querySelector('[data-payment-notifier]');
    if (!module) return;

    const recipients = Array.from(module.querySelectorAll('[data-notifier-recipient]'));
    const checkboxes = Array.from(module.querySelectorAll('[data-notifier-checkbox]'));
    const search = module.querySelector('[data-notifier-search]');
    const selectedPhones = module.querySelector('[data-notifier-selected-phones]');
    const selectedClients = module.querySelector('[data-notifier-selected-clients]');
    const visibleCount = module.querySelector('[data-notifier-visible-count]');
    const empty = module.querySelector('[data-notifier-empty]');
    const submit = module.querySelector('[data-notifier-submit]');
    const message = module.querySelector('[data-notifier-message]');
    const messageCount = module.querySelector('[data-notifier-message-count]');

    const normalize = (value) => String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase('es-MX')
        .trim();

    const updateSelection = () => {
        const checked = checkboxes.filter((checkbox) => checkbox.checked);
        const clients = checked.reduce((total, checkbox) => total + Number(checkbox.dataset.clientCount || 0), 0);
        recipients.forEach((recipient) => {
            recipient.classList.toggle('is-selected', recipient.querySelector('[data-notifier-checkbox]')?.checked === true);
        });
        if (selectedPhones) selectedPhones.textContent = String(checked.length);
        if (selectedClients) selectedClients.textContent = String(clients);
        if (submit) submit.disabled = checked.length === 0;
    };

    const applySearch = () => {
        const query = normalize(search?.value);
        let visible = 0;
        recipients.forEach((recipient) => {
            const matches = query === '' || normalize(recipient.dataset.notifierSearchText).includes(query);
            recipient.hidden = !matches;
            if (matches) visible += 1;
        });
        if (visibleCount) visibleCount.textContent = `${visible.toLocaleString('es-MX')} teléfonos visibles`;
        if (empty) empty.hidden = visible !== 0 || recipients.length === 0;
    };

    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', updateSelection));
    module.querySelector('[data-notifier-select-all]')?.addEventListener('click', () => {
        checkboxes.forEach((checkbox) => { checkbox.checked = true; });
        updateSelection();
    });
    module.querySelector('[data-notifier-clear-all]')?.addEventListener('click', () => {
        checkboxes.forEach((checkbox) => { checkbox.checked = false; });
        updateSelection();
    });
    search?.addEventListener('input', applySearch);
    message?.addEventListener('input', () => {
        if (messageCount) messageCount.textContent = message.value.length.toLocaleString('es-MX');
    });

    updateSelection();
    applySearch();
})();
