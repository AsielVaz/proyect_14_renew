(() => {
    'use strict';

    const currency = new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    const resetFeedback = (button) => {
        window.setTimeout(() => button.classList.remove('is-success', 'is-error'), 1200);
    };

    document.querySelectorAll('[data-wallet-balance-refresh]').forEach((button) => {
        button.addEventListener('click', async () => {
            const card = button.closest('[data-wallet-card]');
            if (!card || button.disabled) return;

            const value = card.querySelector('[data-wallet-balance-value]');
            const state = card.querySelector('[data-wallet-state-label]');
            const updated = card.querySelector('[data-wallet-updated]');
            const status = card.querySelector('[data-wallet-balance-status]');
            const initialTitle = button.getAttribute('title') || 'Actualizar saldo';

            button.disabled = true;
            button.classList.remove('is-success', 'is-error');
            button.classList.add('is-loading');
            button.setAttribute('aria-busy', 'true');
            if (status) status.textContent = 'Actualizando saldo acreditable.';

            try {
                const url = new URL(window.location.href);
                url.search = '';
                url.searchParams.set('wallet_balance', '1');
                url.searchParams.set('client_id', card.dataset.walletId || '');

                const response = await fetch(url, {
                    method: 'GET',
                    headers: {'Accept': 'application/json'},
                    cache: 'no-store',
                    credentials: 'same-origin',
                });
                const payload = await response.json().catch(() => null);
                if (!response.ok || !payload?.ok) {
                    throw new Error(payload?.message || 'No fue posible actualizar el saldo.');
                }

                const balance = Number(payload.data.balance);
                if (!Number.isFinite(balance)) throw new Error('El saldo recibido no es válido.');

                const negative = balance < -0.005;
                const hasBalance = Math.abs(balance) > 0.005;
                if (value) value.textContent = currency.format(balance);
                if (state) state.textContent = negative ? 'Saldo negativo' : 'Disponible';
                if (updated) updated.textContent = 'Actualizado ahora';
                card.classList.toggle('is-negative', negative);
                card.dataset.walletAvailable = String(!negative);
                card.dataset.walletBalance = String(hasBalance);

                button.classList.add('is-success');
                button.setAttribute('title', 'Saldo actualizado');
                if (status) status.textContent = `Saldo acreditable actualizado: ${currency.format(balance)}.`;
            } catch (error) {
                const message = error instanceof Error ? error.message : 'No fue posible actualizar el saldo.';
                button.classList.add('is-error');
                button.setAttribute('title', message);
                if (status) status.textContent = message;
            } finally {
                button.disabled = false;
                button.classList.remove('is-loading');
                button.removeAttribute('aria-busy');
                resetFeedback(button);
                window.setTimeout(() => button.setAttribute('title', initialTitle), 1200);
            }
        });
    });
})();
