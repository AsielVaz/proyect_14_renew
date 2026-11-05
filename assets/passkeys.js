(() => {
    'use strict';

    const encode = (buffer) => {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    };

    const decode = (value) => {
        const padded = value.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - value.length % 4) % 4);
        const binary = atob(padded);
        return Uint8Array.from(binary, (character) => character.charCodeAt(0)).buffer;
    };

    const request = async (action, payload = {}) => {
        const response = await fetch(`?webauthn=${encodeURIComponent(action)}`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            body: JSON.stringify(payload),
        });
        let data;
        try {
            data = await response.json();
        } catch {
            throw new Error('El servidor devolvió una respuesta inesperada.');
        }
        if (!response.ok || !data.ok) {
            throw new Error(data.message || 'No fue posible completar la operación.');
        }
        return data;
    };

    const publicKeyRequestOptions = (options) => {
        const publicKey = {...options.publicKey, challenge: decode(options.publicKey.challenge)};
        if (Array.isArray(publicKey.allowCredentials)) {
            publicKey.allowCredentials = publicKey.allowCredentials.map((item) => ({...item, id: decode(item.id)}));
        }
        return publicKey;
    };

    const publicKeyCreationOptions = (options) => {
        const publicKey = {
            ...options.publicKey,
            challenge: decode(options.publicKey.challenge),
            user: {...options.publicKey.user, id: decode(options.publicKey.user.id)},
        };
        if (Array.isArray(publicKey.excludeCredentials)) {
            publicKey.excludeCredentials = publicKey.excludeCredentials.map((item) => ({...item, id: decode(item.id)}));
        }
        return publicKey;
    };

    const assertionPayload = (credential) => ({
        id: credential.id,
        rawId: encode(credential.rawId),
        type: credential.type,
        authenticatorAttachment: credential.authenticatorAttachment || null,
        response: {
            clientDataJSON: encode(credential.response.clientDataJSON),
            authenticatorData: encode(credential.response.authenticatorData),
            signature: encode(credential.response.signature),
            userHandle: credential.response.userHandle ? encode(credential.response.userHandle) : '',
        },
        clientExtensionResults: credential.getClientExtensionResults(),
    });

    const registrationPayload = (credential, name, csrfToken) => ({
        id: credential.id,
        rawId: encode(credential.rawId),
        type: credential.type,
        name,
        csrf_token: csrfToken,
        response: {
            clientDataJSON: encode(credential.response.clientDataJSON),
            attestationObject: encode(credential.response.attestationObject),
            transports: typeof credential.response.getTransports === 'function'
                ? credential.response.getTransports() : [],
        },
        clientExtensionResults: credential.getClientExtensionResults(),
    });

    const readableError = (error) => {
        if (error?.name === 'NotAllowedError') return 'La operación se canceló o no se encontró una llave compatible.';
        if (error?.name === 'InvalidStateError') return 'Esta llave ya está registrada en la cuenta.';
        if (error?.name === 'SecurityError') return 'El dominio no coincide con la configuración segura de Passkeys.';
        if (error?.name === 'AbortError') return 'La operación fue cancelada.';
        return error?.message || 'No fue posible usar la llave de acceso.';
    };

    const setStatus = (element, message, error = false) => {
        if (!element) return;
        element.textContent = message;
        element.hidden = false;
        element.classList.toggle('is-error', error);
    };

    const supported = 'PublicKeyCredential' in window && !!navigator.credentials;
    const loginButton = document.querySelector('[data-passkey-login]');
    if (loginButton) {
        const status = document.querySelector('[data-passkey-status]');
        if (!supported) {
            loginButton.disabled = true;
            setStatus(status, 'Este navegador no admite llaves de acceso.', true);
        } else {
            loginButton.addEventListener('click', async () => {
                loginButton.disabled = true;
                loginButton.setAttribute('aria-busy', 'true');
                setStatus(status, 'Esperando la confirmación de tu dispositivo…');
                try {
                    const fieldName = loginButton.dataset.identityField;
                    const identity = fieldName ? (document.querySelector(`[name="${fieldName}"]`)?.value || '').trim() : '';
                    const options = await request('login-options', {identity});
                    const credential = await navigator.credentials.get({publicKey: publicKeyRequestOptions(options.options)});
                    if (!credential) throw new Error('El dispositivo no devolvió una credencial.');
                    const result = await request('login-verify', assertionPayload(credential));
                    setStatus(status, 'Acceso verificado. Abriendo el sistema…');
                    window.location.assign(result.redirect);
                } catch (error) {
                    setStatus(status, readableError(error), true);
                    loginButton.disabled = false;
                    loginButton.removeAttribute('aria-busy');
                }
            });
        }
    }

    const manager = document.querySelector('[data-passkey-manager]');
    if (!manager) return;
    const csrfToken = manager.dataset.csrfToken;
    const managerStatus = manager.querySelector('[data-passkey-status]');
    const registerButton = manager.querySelector('[data-passkey-register]');

    if (registerButton && !supported) {
        registerButton.disabled = true;
        setStatus(managerStatus, 'Este navegador no admite WebAuthn / Passkeys.', true);
    }

    registerButton?.addEventListener('click', async () => {
        const requestedName = window.prompt('Nombre para identificar este dispositivo:', 'Mi dispositivo');
        if (requestedName === null) return;
        const name = requestedName.trim();
        if (!name) {
            setStatus(managerStatus, 'Escribe un nombre para la llave de acceso.', true);
            return;
        }
        registerButton.disabled = true;
        registerButton.setAttribute('aria-busy', 'true');
        setStatus(managerStatus, 'Preparando el registro seguro…');
        try {
            const options = await request('register-options', {csrf_token: csrfToken});
            const credential = await navigator.credentials.create({publicKey: publicKeyCreationOptions(options.options)});
            if (!credential) throw new Error('El dispositivo no devolvió una credencial.');
            const result = await request('register-verify', registrationPayload(credential, name, csrfToken));
            setStatus(managerStatus, result.data?.message || 'Llave registrada.');
            window.location.reload();
        } catch (error) {
            setStatus(managerStatus, readableError(error), true);
            registerButton.disabled = false;
            registerButton.removeAttribute('aria-busy');
        }
    });

    manager.querySelectorAll('[data-passkey-rename]').forEach((button) => {
        button.addEventListener('click', async () => {
            const name = window.prompt('Nuevo nombre para esta llave:', button.dataset.currentName || '');
            if (name === null || !name.trim()) return;
            button.disabled = true;
            try {
                const result = await request('rename', {
                    csrf_token: csrfToken,
                    credential_id: button.dataset.passkeyRename,
                    name: name.trim(),
                });
                setStatus(managerStatus, result.message || 'Nombre actualizado.');
                window.location.reload();
            } catch (error) {
                setStatus(managerStatus, readableError(error), true);
                button.disabled = false;
            }
        });
    });

    manager.querySelectorAll('[data-passkey-delete]').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!window.confirm('¿Eliminar esta llave de acceso? El dispositivo dejará de poder iniciar sesión.')) return;
            button.disabled = true;
            try {
                const result = await request('delete', {
                    csrf_token: csrfToken,
                    credential_id: button.dataset.passkeyDelete,
                });
                setStatus(managerStatus, result.message || 'Llave eliminada.');
                button.closest('[data-passkey-card]')?.remove();
            } catch (error) {
                setStatus(managerStatus, readableError(error), true);
                button.disabled = false;
            }
        });
    });
})();
