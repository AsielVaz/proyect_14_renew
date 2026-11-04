(() => {
    'use strict';

    const form = document.querySelector('[data-transfer-form]');
    if (!form) return;

    const origin = form.querySelector('[data-transfer-origin]');
    const destination = form.querySelector('[data-transfer-destination]');
    const amount = form.querySelector('[data-transfer-amount]');
    const summary = form.querySelector('[data-transfer-summary]');
    const summaryBox = form.querySelector('[data-transfer-route-summary]');
    const validation = form.querySelector('[data-transfer-validation]');
    const currency = new Intl.NumberFormat('es-MX', {style: 'currency', currency: 'MXN'});

    const selectedName = (select, fallback) => {
        const option = select.selectedOptions[0];
        return option && option.value ? option.textContent.trim() : fallback;
    };

    const updateRoute = () => {
        const sameWallet = origin.value !== '' && origin.value === destination.value;
        destination.setCustomValidity(sameWallet ? 'La cartera destino debe ser diferente de la cartera origen.' : '');
        validation.hidden = !sameWallet;
        validation.textContent = sameWallet ? 'El origen y el destino no pueden ser la misma cartera. Selecciona una cartera diferente.' : '';

        const originName = selectedName(origin, 'Origen pendiente');
        const destinationName = selectedName(destination, 'Destino pendiente');
        const numericAmount = Number(amount.value);
        const amountText = Number.isFinite(numericAmount) && numericAmount > 0 ? currency.format(numericAmount) : 'el monto indicado';

        if (origin.value && destination.value && !sameWallet) {
            summary.textContent = `${originName} envía ${amountText} a ${destinationName}.`;
            summaryBox.classList.add('is-ready');
            summaryBox.classList.remove('has-error');
            form.dataset.confirm = `${originName} enviará ${amountText} a ${destinationName}. ¿Confirmar este traspaso?`;
        } else if (sameWallet) {
            summary.textContent = 'La cartera que envía debe ser diferente de la cartera que recibe.';
            summaryBox.classList.remove('is-ready');
            summaryBox.classList.add('has-error');
            form.dataset.confirm = '¿Registrar este traspaso entre carteras?';
        } else {
            summary.textContent = `${originName} → ${destinationName}`;
            summaryBox.classList.remove('is-ready', 'has-error');
            form.dataset.confirm = '¿Registrar este traspaso entre carteras?';
        }
    };

    [origin, destination, amount].forEach((control) => {
        control.addEventListener('change', updateRoute);
        control.addEventListener('input', updateRoute);
    });

    updateRoute();
})();
