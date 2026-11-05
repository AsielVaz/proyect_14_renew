(() => {
    'use strict';

    const movementPattern = /\b(Retiros?|Dep[oó]sitos?)\b/giu;
    const movementTest = /\b(Retiros?|Dep[oó]sitos?)\b/iu;
    const movementExact = /^(Retiros?|Dep[oó]sitos?)$/iu;
    const withdrawalTest = /^Retiros?$/iu;
    const ignoredSelector = [
        'script',
        'style',
        'textarea',
        'input',
        'select',
        'option',
        'template',
        '[contenteditable="true"]',
        '.movement-kind',
        '.movement-detail-text',
        '.movement-description-text',
        '.global-search-result-description',
        '.invoice-line-description',
    ].join(',');

    const movementClass = (value) => withdrawalTest.test(value)
        ? 'is-withdrawal'
        : 'is-deposit';

    const syncSelect = (select) => {
        select.classList.remove('movement-kind', 'is-withdrawal', 'is-deposit');
        const selectedText = (select.selectedOptions[0]?.textContent || '').trim();
        const match = selectedText.match(movementExact);
        if (match) select.classList.add('movement-kind', movementClass(match[0]));
    };

    const highlightTextNode = (node) => {
        const text = node.nodeValue || '';
        const parent = node.parentElement;
        if (!parent || parent.closest(ignoredSelector) || !movementTest.test(text)) return;

        const fragment = document.createDocumentFragment();
        let cursor = 0;
        movementPattern.lastIndex = 0;
        for (const match of text.matchAll(movementPattern)) {
            const index = match.index ?? 0;
            if (index > cursor) fragment.append(document.createTextNode(text.slice(cursor, index)));

            const marker = document.createElement('span');
            marker.className = `movement-kind ${movementClass(match[0])}`;
            marker.textContent = match[0];
            fragment.append(marker);
            cursor = index + match[0].length;
        }
        if (cursor < text.length) fragment.append(document.createTextNode(text.slice(cursor)));
        node.replaceWith(fragment);
    };

    const highlightOptions = (root) => {
        const options = [];
        if (root instanceof HTMLOptionElement) options.push(root);
        if (root instanceof Element || root instanceof Document || root instanceof DocumentFragment) {
            options.push(...root.querySelectorAll('option'));
        }
        options.forEach((option) => {
            option.classList.remove('movement-kind', 'is-withdrawal', 'is-deposit');
            const match = option.textContent?.trim().match(movementExact);
            if (match) option.classList.add('movement-kind', movementClass(match[0]));
        });
        const selects = [];
        if (root instanceof HTMLSelectElement) selects.push(root);
        if (root instanceof Element || root instanceof Document || root instanceof DocumentFragment) {
            selects.push(...root.querySelectorAll('select'));
        }
        selects.forEach(syncSelect);
    };

    const highlight = (root) => {
        if (root instanceof Text) {
            highlightTextNode(root);
            return;
        }
        if (!(root instanceof Element || root instanceof Document || root instanceof DocumentFragment)) return;

        highlightOptions(root);
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode: (node) => {
                const parent = node.parentElement;
                return parent && !parent.closest(ignoredSelector) && movementTest.test(node.nodeValue || '')
                    ? NodeFilter.FILTER_ACCEPT
                    : NodeFilter.FILTER_REJECT;
            },
        });
        const nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        nodes.forEach(highlightTextNode);
    };

    highlight(document.body);
    document.addEventListener('change', (event) => {
        if (event.target instanceof HTMLSelectElement) syncSelect(event.target);
    });

    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.type === 'characterData') {
                highlight(mutation.target);
                return;
            }
            mutation.addedNodes.forEach(highlight);
        });
    });
    observer.observe(document.body, {childList: true, characterData: true, subtree: true});
})();
