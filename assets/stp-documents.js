(() => {
    'use strict';

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[data-stp-cep-pdf]');
        if (!link || event.defaultPrevented || event.button !== 0) return;
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;

        const pdfUrl = link.dataset.stpCepPdf;
        if (!pdfUrl) return;

        // Se ejecuta dentro del gesto del usuario para que el navegador permita
        // abrir el PDF, mientras el comportamiento normal del enlace abre Banxico.
        const pdfWindow = window.open('about:blank', '_blank');
        if (!pdfWindow) {
            link.closest('.stp-document-links')?.classList.add('is-popup-blocked');
            return;
        }

        pdfWindow.opener = null;
        pdfWindow.location.replace(pdfUrl);
    });
})();
