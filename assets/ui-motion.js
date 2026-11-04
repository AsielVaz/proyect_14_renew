(() => {
    'use strict';

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const html = document.documentElement;
    html.classList.add('motion-enabled');

    const navigationKey = 's14-internal-navigation';
    let internalNavigation = false;
    try {
        internalNavigation = window.sessionStorage.getItem(navigationKey) === '1';
        window.sessionStorage.removeItem(navigationKey);
    } catch (_) {
        internalNavigation = false;
    }

    if (internalNavigation && !reducedMotion.matches && typeof document.startViewTransition !== 'function') {
        const main = document.querySelector('main');
        if (main instanceof HTMLElement) {
            main.classList.add('motion-page-enter');
            main.addEventListener('animationend', () => main.classList.remove('motion-page-enter'), {once: true});
        }
    }

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!(link instanceof HTMLAnchorElement) || link.target || link.hasAttribute('download')) return;
        const target = new URL(link.href, window.location.href);
        if (target.origin !== window.location.origin || target.pathname !== window.location.pathname) return;
        if (target.href === window.location.href || (target.hash && target.pathname === location.pathname && target.search === location.search)) return;
        try {
            window.sessionStorage.setItem(navigationKey, '1');
        } catch (_) {
            // La transición visual es progresiva; la navegación no depende del almacenamiento.
        }
    }, {capture: true});

    const detailAnimations = new WeakMap();
    document.querySelectorAll('details.catalog-menu').forEach((details) => {
        const summary = details.querySelector(':scope > summary');
        const content = details.querySelector(':scope > .catalog-links');
        if (!(summary instanceof HTMLElement) || !(content instanceof HTMLElement)) return;

        const syncExpanded = () => summary.setAttribute('aria-expanded', String(details.open));
        syncExpanded();

        summary.addEventListener('click', (event) => {
            if (reducedMotion.matches || typeof content.animate !== 'function') return;
            event.preventDefault();

            const previousTarget = details.dataset.motionTarget;
            const currentHeight = content.getBoundingClientRect().height;
            const previous = detailAnimations.get(details);
            previous?.cancel();
            const opening = previousTarget === 'closed' ? true : (previousTarget === 'open' ? false : !details.open);
            details.dataset.motionTarget = opening ? 'open' : 'closed';

            if (opening) details.open = true;
            const startHeight = previousTarget ? currentHeight : (opening ? 0 : content.getBoundingClientRect().height);
            const endHeight = opening ? content.scrollHeight : 0;
            details.classList.add('is-motion-animating');
            summary.setAttribute('aria-expanded', String(opening));

            const animation = content.animate([
                {height: `${startHeight}px`, opacity: opening ? 0 : 1, transform: opening ? 'translateY(-6px)' : 'translateY(0)'},
                {height: `${endHeight}px`, opacity: opening ? 1 : 0, transform: opening ? 'translateY(0)' : 'translateY(-4px)'},
            ], {
                duration: opening ? 240 : 170,
                easing: opening ? 'cubic-bezier(0.16, 1, 0.3, 1)' : 'cubic-bezier(0.4, 0, 1, 1)',
            });
            detailAnimations.set(details, animation);

            animation.addEventListener('finish', () => {
                if (!opening) details.open = false;
                details.classList.remove('is-motion-animating');
                delete details.dataset.motionTarget;
                content.style.removeProperty('height');
                content.style.removeProperty('opacity');
                content.style.removeProperty('transform');
                syncExpanded();
                detailAnimations.delete(details);
            }, {once: true});
        });

        details.addEventListener('toggle', syncExpanded);
    });
})();
