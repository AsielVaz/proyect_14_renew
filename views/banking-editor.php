<?php

declare(strict_types=1);

?>
<section
    class="banking-editor"
    data-banking-editor
    data-list-url="?banking=editor&amp;data=list"
    data-save-url="?banking=editor&amp;data=save"
    data-export-xlsx-url="?banking=editor&amp;export=xlsx"
    data-export-pdf-url="?banking=editor&amp;export=pdf"
    data-csrf="<?= e(csrf_token()) ?>"
>
    <header class="banking-editor-hero">
        <div>
            <p class="eyebrow">Configuración de distribución</p>
            <h1>Editor del banking</h1>
            <p>Define cómo se reparte el 100% de cada razón social entre sus proveedores.</p>
        </div>
        <span class="banking-editor-client"><span aria-hidden="true"></span> Cliente 13</span>
    </header>

    <section class="panel banking-editor-toolbar" aria-label="Buscar y exportar configuraciones">
        <label class="banking-editor-search">
            <span>Buscar configuración</span>
            <input type="search" placeholder="Empresa, clave corta, RFC o proveedor…" autocomplete="off" data-banking-search>
            <small data-banking-search-status>Preparando configuraciones…</small>
        </label>
        <div class="banking-editor-export">
            <button class="button" type="button" data-banking-export="xlsx">Exportar Excel</button>
            <button class="button subtle" type="button" data-banking-export="pdf">Exportar PDF</button>
        </div>
    </section>

    <section class="panel banking-editor-add" aria-labelledby="bankingEditorAddTitle">
        <div>
            <p class="eyebrow">Nueva distribución</p>
            <h2 id="bankingEditorAddTitle">Agregar razón social</h2>
            <p>Solo se muestran razones del cliente 13 que todavía no tienen una configuración abierta.</p>
        </div>
        <label class="field">
            <span>Razón social</span>
            <select data-banking-company disabled><option value="">Cargando empresas…</option></select>
        </label>
        <button class="button primary" type="button" data-banking-add disabled>Agregar empresa</button>
    </section>

    <div class="banking-editor-list" data-banking-list aria-live="polite">
        <section class="panel banking-editor-state">
            <span class="module-loader-orbit" aria-hidden="true"></span>
            <div><strong>Cargando distribuciones</strong><p>Consultando razones sociales y proveedores configurados.</p></div>
        </section>
    </div>

    <div class="toast-stack banking-editor-toast" data-banking-toast hidden role="status" aria-live="polite"></div>
</section>
