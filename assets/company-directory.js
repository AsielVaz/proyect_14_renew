(() => {
    'use strict';

    document.querySelectorAll('[data-company-logo]').forEach((image) => {
        const showFallback = () => {
            image.hidden = true;
            const fallback = image.parentElement?.querySelector('[data-company-logo-fallback]');
            if (fallback) fallback.hidden = false;
        };
        image.addEventListener('error', showFallback, {once: true});
        if (image.complete && image.naturalWidth === 0) showFallback();
    });

    const root = document.querySelector('.company-directory-shell');
    if (!root) return;

    root.querySelectorAll('[data-company-auto-submit]').forEach((control) => {
        control.addEventListener('change', () => control.form?.requestSubmit());
    });

    const buttons = Array.from(root.querySelectorAll('[data-company-view]'));
    const panels = Array.from(root.querySelectorAll('[data-company-view-panel]'));
    if (!buttons.length || !panels.length) return;

    const setView = (view) => {
        const resolvedView = view === 'list' ? 'list' : 'grid';
        buttons.forEach((button) => {
            button.setAttribute('aria-pressed', String(button.dataset.companyView === resolvedView));
        });
        panels.forEach((panel) => {
            panel.hidden = panel.dataset.companyViewPanel !== resolvedView;
        });
        try {
            window.localStorage.setItem('s14-company-directory-view', resolvedView);
        } catch (_) {
            // La preferencia es opcional; la vista sigue funcionando sin almacenamiento.
        }
    };

    let savedView = 'grid';
    try {
        savedView = window.localStorage.getItem('s14-company-directory-view') || 'grid';
    } catch (_) {
        savedView = 'grid';
    }
    setView(savedView);
    buttons.forEach((button) => button.addEventListener('click', () => setView(button.dataset.companyView)));
})();
