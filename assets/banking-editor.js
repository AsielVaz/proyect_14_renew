(() => {
    const root = document.querySelector('[data-banking-editor]');
    if (!root) return;

    const list = root.querySelector('[data-banking-list]');
    const search = root.querySelector('[data-banking-search]');
    const searchStatus = root.querySelector('[data-banking-search-status]');
    const companySelect = root.querySelector('[data-banking-company]');
    const addButton = root.querySelector('[data-banking-add]');
    const toast = root.querySelector('[data-banking-toast]');
    const state = { companies: [], providers: [], configurations: new Map(), order: [] };
    let toastTimer = 0;

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;',
    })[character]);
    const normalize = (value) => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const percentage = (value) => Number(value || 0).toLocaleString('es-MX', { maximumFractionDigits: 4 });

    function showToast(message, isError = false) {
        window.clearTimeout(toastTimer);
        toast.hidden = false;
        toast.className = `toast-stack banking-editor-toast ${isError ? 'is-error' : 'is-success'}`;
        toast.textContent = message;
        toastTimer = window.setTimeout(() => { toast.hidden = true; }, 5000);
    }

    function companyById(id) {
        return state.companies.find((company) => Number(company.id) === Number(id));
    }

    function providerById(id) {
        return state.providers.find((provider) => Number(provider.id) === Number(id));
    }

    function totalFor(configuration) {
        return configuration.proveedores.reduce((total, provider) => total + Number(provider.porcentaje_sugerido || 0), 0);
    }

    function validConfiguration(configuration) {
        if (!configuration.proveedores.length) return false;
        const selected = new Set();
        for (const provider of configuration.proveedores) {
            const id = Number(provider.id_proveedor || 0);
            const value = Number(provider.porcentaje_sugerido);
            if (!id || selected.has(id) || !Number.isFinite(value) || value <= 0 || value > 100) return false;
            selected.add(id);
        }
        return Math.abs(totalFor(configuration) - 100) <= 0.0001;
    }

    function selectedProviders(configuration, ignoredIndex) {
        return new Set(configuration.proveedores
            .filter((provider, index) => index !== ignoredIndex && Number(provider.id_proveedor) > 0)
            .map((provider) => Number(provider.id_proveedor)));
    }

    function providerOptions(configuration, rowIndex) {
        const selected = selectedProviders(configuration, rowIndex);
        const current = Number(configuration.proveedores[rowIndex]?.id_proveedor || 0);
        return '<option value="">Selecciona un proveedor…</option>' + state.providers.map((provider) => {
            const id = Number(provider.id);
            const label = provider.rfc ? `${provider.razon} · ${provider.rfc}` : provider.razon;
            return `<option value="${id}"${id === current ? ' selected' : ''}${selected.has(id) ? ' disabled' : ''}>${escapeHtml(label)}</option>`;
        }).join('');
    }

    function providerRow(configuration, index) {
        const provider = configuration.proveedores[index];
        return `<tr>
            <td><label><span class="sr-only">Proveedor ${index + 1}</span><select data-action="provider" data-company="${configuration.id_empresa}" data-index="${index}">${providerOptions(configuration, index)}</select></label></td>
            <td><label class="banking-editor-percentage"><span class="sr-only">Porcentaje del proveedor ${index + 1}</span><input type="number" min="0.0001" max="100" step="1" inputmode="decimal" value="${escapeHtml(provider.porcentaje_sugerido)}" data-action="percentage" data-company="${configuration.id_empresa}" data-index="${index}"><b>%</b></label></td>
            <td><button class="button subtle banking-editor-remove" type="button" data-action="remove" data-company="${configuration.id_empresa}" data-index="${index}" aria-label="Quitar proveedor ${index + 1}">×</button></td>
        </tr>`;
    }

    function configurationSearchText(configuration) {
        const company = companyById(configuration.id_empresa) || configuration;
        const providers = configuration.proveedores.map((item) => {
            const provider = providerById(item.id_proveedor) || item;
            return `${provider.id || item.id_proveedor} ${provider.razon || ''} ${provider.rfc || ''}`;
        }).join(' ');
        return normalize(`${configuration.id_empresa} ${company.clave_corta || ''} ${company.razon_social || ''} ${company.rfc || ''} ${providers}`);
    }

    function visibleConfigurations() {
        const needle = normalize(search.value.trim());
        return state.order.map((id) => state.configurations.get(String(id)))
            .filter(Boolean)
            .filter((configuration) => !needle || configurationSearchText(configuration).includes(needle));
    }

    function configurationCard(configuration) {
        const company = companyById(configuration.id_empresa) || configuration;
        const total = totalFor(configuration);
        const isValid = validConfiguration(configuration);
        return `<article class="panel banking-editor-card" id="banking-company-${configuration.id_empresa}" data-banking-card="${configuration.id_empresa}">
            <header>
                <div><p>${escapeHtml(company.clave_corta || `Empresa #${configuration.id_empresa}`)}${configuration.isNew ? '<span class="banking-editor-new">Nueva</span>' : ''}</p><h2>${escapeHtml(company.razon_social || `Empresa #${configuration.id_empresa}`)}</h2><span>RFC ${escapeHtml(company.rfc || 'sin registrar')} · ID ${configuration.id_empresa}</span></div>
                ${configuration.isNew ? `<button class="button subtle" type="button" data-action="cancel" data-company="${configuration.id_empresa}">Cancelar</button>` : ''}
            </header>
            <div class="banking-editor-table-wrap"><table><thead><tr><th>Proveedor</th><th>Porcentaje sugerido</th><th>Quitar</th></tr></thead><tbody>${configuration.proveedores.map((_, index) => providerRow(configuration, index)).join('')}</tbody></table></div>
            <footer>
                <button class="button" type="button" data-action="add-provider" data-company="${configuration.id_empresa}"${configuration.proveedores.length >= state.providers.length ? ' disabled' : ''}>Agregar proveedor</button>
                <div><span class="banking-editor-total ${isValid ? 'is-valid' : 'is-invalid'}" data-total="${configuration.id_empresa}">Total: ${percentage(total)}%</span><button class="button primary" type="button" data-action="save" data-company="${configuration.id_empresa}"${isValid ? '' : ' disabled'}>Guardar distribución</button></div>
            </footer>
        </article>`;
    }

    function renderCompanySelect() {
        const available = state.companies.filter((company) => !state.configurations.has(String(company.id)));
        companySelect.innerHTML = '<option value="">Selecciona una razón social…</option>' + available.map((company) =>
            `<option value="${company.id}">${escapeHtml(company.razon_social)} · ${escapeHtml(company.clave_corta)}</option>`
        ).join('');
        companySelect.disabled = available.length === 0;
        addButton.disabled = true;
    }

    function render() {
        const visible = visibleConfigurations();
        searchStatus.textContent = `${visible.length} de ${state.order.length} configuraciones visibles`;
        if (!visible.length) {
            list.innerHTML = `<section class="panel banking-editor-state"><div><strong>Sin coincidencias</strong><p>No hay empresas o proveedores que coincidan con el criterio.</p></div></section>`;
            return;
        }
        list.innerHTML = visible.map(configurationCard).join('');
    }

    function syncCardValidation(companyId) {
        const configuration = state.configurations.get(String(companyId));
        const card = list.querySelector(`[data-banking-card="${companyId}"]`);
        if (!configuration || !card) return;
        const total = totalFor(configuration);
        const isValid = validConfiguration(configuration);
        const totalNode = card.querySelector(`[data-total="${companyId}"]`);
        const save = card.querySelector('[data-action="save"]');
        totalNode.textContent = `Total: ${percentage(total)}%`;
        totalNode.className = `banking-editor-total ${isValid ? 'is-valid' : 'is-invalid'}`;
        save.disabled = !isValid;
    }

    async function loadData() {
        try {
            const response = await fetch(root.dataset.listUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || 'No fue posible consultar las configuraciones.');
            state.companies = payload.data.empresas_catalogo || [];
            state.providers = payload.data.proveedores_catalogo || [];
            state.configurations.clear();
            state.order = [];
            for (const configuration of payload.data.configuraciones || []) {
                configuration.isNew = false;
                state.configurations.set(String(configuration.id_empresa), configuration);
                state.order.push(Number(configuration.id_empresa));
            }
            renderCompanySelect();
            render();
        } catch (error) {
            list.innerHTML = `<section class="panel banking-editor-state is-error"><div><strong>No se pudieron cargar las distribuciones</strong><p>${escapeHtml(error.message)}</p></div><button class="button" type="button" data-action="retry">Reintentar</button></section>`;
            searchStatus.textContent = 'Consulta no disponible';
        }
    }

    function addCompany() {
        const id = Number(companySelect.value || 0);
        const company = companyById(id);
        if (!company || state.configurations.has(String(id))) return;
        state.configurations.set(String(id), {
            id_empresa: id, clave_corta: company.clave_corta, razon_social: company.razon_social,
            rfc: company.rfc, proveedores: [{ id_proveedor: '', porcentaje_sugerido: '' }], isNew: true,
        });
        state.order.unshift(id);
        search.value = '';
        renderCompanySelect();
        render();
        document.getElementById(`banking-company-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    async function saveConfiguration(companyId, button) {
        const configuration = state.configurations.get(String(companyId));
        if (!configuration || !validConfiguration(configuration)) {
            showToast('La suma debe ser exactamente 100% y no puede haber proveedores repetidos.', true);
            return;
        }
        const form = new FormData();
        form.set('csrf_token', root.dataset.csrf);
        form.set('action', 'banking_editor_save');
        form.set('company_id', String(companyId));
        form.set('providers', JSON.stringify(configuration.proveedores.map((provider) => ({
            id_proveedor: Number(provider.id_proveedor), porcentaje_sugerido: Number(provider.porcentaje_sugerido),
        }))));
        button.disabled = true;
        button.textContent = 'Guardando…';
        try {
            const response = await fetch(root.dataset.saveUrl, { method: 'POST', body: form, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || 'No fue posible guardar la distribución.');
            showToast(payload.message || 'Distribución guardada.');
            await loadData();
        } catch (error) {
            showToast(error.message, true);
            button.disabled = false;
            button.textContent = 'Guardar distribución';
        }
    }

    search.addEventListener('input', render);
    companySelect.addEventListener('change', () => { addButton.disabled = !companySelect.value; });
    addButton.addEventListener('click', addCompany);
    root.querySelectorAll('[data-banking-export]').forEach((button) => button.addEventListener('click', () => {
        const base = button.dataset.bankingExport === 'pdf' ? root.dataset.exportPdfUrl : root.dataset.exportXlsxUrl;
        const query = search.value.trim();
        window.location.assign(query ? `${base}&q=${encodeURIComponent(query)}` : base);
    }));

    list.addEventListener('input', (event) => {
        const target = event.target;
        if (target.dataset.action !== 'percentage') return;
        const configuration = state.configurations.get(String(target.dataset.company));
        if (!configuration?.proveedores[target.dataset.index]) return;
        configuration.proveedores[target.dataset.index].porcentaje_sugerido = target.value;
        syncCardValidation(target.dataset.company);
    });

    list.addEventListener('change', (event) => {
        const target = event.target;
        if (target.dataset.action !== 'provider') return;
        const configuration = state.configurations.get(String(target.dataset.company));
        if (!configuration?.proveedores[target.dataset.index]) return;
        configuration.proveedores[target.dataset.index].id_proveedor = Number(target.value || 0);
        render();
    });

    list.addEventListener('click', (event) => {
        const action = event.target.closest('[data-action]');
        if (!action) return;
        const companyId = Number(action.dataset.company || 0);
        const configuration = state.configurations.get(String(companyId));
        if (action.dataset.action === 'retry') return loadData();
        if (!configuration) return;
        if (action.dataset.action === 'add-provider') {
            const selected = selectedProviders(configuration, -1);
            const available = state.providers.find((provider) => !selected.has(Number(provider.id)));
            configuration.proveedores.push({ id_proveedor: available?.id || '', porcentaje_sugerido: '' });
            render();
        } else if (action.dataset.action === 'remove') {
            configuration.proveedores.splice(Number(action.dataset.index), 1);
            render();
        } else if (action.dataset.action === 'cancel' && configuration.isNew) {
            state.configurations.delete(String(companyId));
            state.order = state.order.filter((id) => id !== companyId);
            renderCompanySelect();
            render();
        } else if (action.dataset.action === 'save') {
            saveConfiguration(companyId, action);
        }
    });

    loadData();
})();
