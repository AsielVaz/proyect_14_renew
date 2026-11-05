<?php
declare(strict_types=1);

/** @var array<string, array{name:string,email:string,template:string,available:bool}> $manualEmailCompanies */
?>
<section class="report-intro movement-intro manual-email-intro">
    <div>
        <p class="eyebrow">Movimientos · cotizaciones</p>
        <h2>Cotizador por correo</h2>
        <p>Prepara cotizaciones y mensajes con la identidad de cada empresa. El envío sólo se habilita después de revisar la versión final.</p>
    </div>
    <span class="operation-badge">Revisión obligatoria antes de enviar</span>
</section>

<section class="panel manual-email-panel" data-manual-email>
    <ol class="manual-email-steps" aria-label="Proceso de envío">
        <li class="is-active" data-mail-step="1"><span>1</span><strong>Redactar</strong></li>
        <li data-mail-step="2"><span>2</span><strong>Editar</strong></li>
        <li data-mail-step="3"><span>3</span><strong>Revisar y enviar</strong></li>
    </ol>

    <div class="manual-email-status" data-mail-status role="status" aria-live="polite" hidden></div>

    <form id="manualEmailForm" class="manual-email-form" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="concepts" value="[]" data-concepts-json>
        <input type="hidden" name="preview_token" value="" data-preview-token>

        <fieldset class="manual-email-section">
            <legend>Remitente y destinatarios</legend>
            <div class="form-grid">
                <label class="field">
                    <span>Empresa remitente *</span>
                    <select name="company" required data-company-select>
                        <option value="">Seleccionar empresa…</option>
                        <?php foreach ($manualEmailCompanies as $companyKey => $company): ?>
                            <option value="<?= e($companyKey) ?>" data-sender="<?= e($company['email']) ?>" <?= $company['available'] ? '' : 'disabled' ?>>
                                <?= e($company['name']) ?><?= $company['available'] ? '' : ' · plantilla no disponible' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span>Correo remitente</span>
                    <input type="email" data-sender-email value="" placeholder="Se completa al elegir la empresa" readonly>
                </label>
                <label class="field wide">
                    <span>Destinatarios *</span>
                    <input type="text" name="to" required maxlength="2000" placeholder="cliente@empresa.com, compras@empresa.com">
                    <small>Separa varias direcciones con coma o punto y coma.</small>
                </label>
                <label class="field wide">
                    <span>Copia oculta (CCO)</span>
                    <input type="text" name="bcc" maxlength="2000" placeholder="auditoria@empresa.com">
                </label>
                <label class="field wide">
                    <span>Asunto *</span>
                    <input type="text" name="subject" required maxlength="250" placeholder="Cotización solicitada">
                </label>
            </div>
        </fieldset>

        <fieldset class="manual-email-section">
            <legend>Contenido</legend>
            <div class="form-grid">
                <label class="field wide">
                    <span>Mensaje principal</span>
                    <textarea name="message" rows="6" maxlength="50000" placeholder="Escribe el mensaje. Si lo dejas vacío y agregas conceptos, la redacción asistida puede prepararlo."></textarea>
                </label>
                <label class="field wide">
                    <span>Mensaje adicional</span>
                    <textarea name="additional" rows="3" maxlength="50000" placeholder="Condiciones, notas o información complementaria"></textarea>
                </label>
                <label class="field">
                    <span>Color del contenido</span>
                    <select data-color-mode>
                        <option value="default">Usar color de la plantilla</option>
                        <option value="custom">Elegir color</option>
                    </select>
                </label>
                <label class="field manual-email-color">
                    <span>Color personalizado</span>
                    <input type="color" name="content_color" value="#172033" disabled data-content-color>
                </label>
            </div>
        </fieldset>

        <fieldset class="manual-email-section">
            <div class="manual-email-legend-row">
                <legend>Conceptos de cotización</legend>
                <button type="button" class="button compact" data-add-concept>+ Agregar concepto</button>
            </div>
            <p class="panel-copy">Opcional. Los importes se calculan y validan nuevamente en el servidor.</p>
            <div class="table-wrap manual-concepts-wrap">
                <table class="movement-table manual-concepts-table">
                    <thead><tr><th>Descripción</th><th>Cantidad</th><th>Precio unitario</th><th>IVA 16%</th><th>Importe</th><th></th></tr></thead>
                    <tbody data-concepts-body></tbody>
                    <tfoot><tr><td colspan="4">Total estimado</td><td data-concepts-total>$0.00</td><td></td></tr></tfoot>
                </table>
            </div>
            <div class="empty manual-concepts-empty" data-concepts-empty>Sin conceptos. Puedes enviar únicamente un mensaje.</div>
        </fieldset>

        <fieldset class="manual-email-section">
            <legend>Archivos adjuntos</legend>
            <label class="manual-file-drop">
                <span>Adjuntar PDF</span>
                <input type="file" name="attachments[]" accept="application/pdf,.pdf" multiple data-mail-files>
                <small>Hasta 10 PDF, máximo 10 MB por archivo y 20 MB en total.</small>
            </label>
            <ul class="manual-file-list" data-file-list aria-live="polite"></ul>
        </fieldset>

        <div class="form-actions manual-email-actions">
            <button type="button" class="button primary" data-generate-mail>Generar vista previa</button>
            <span class="panel-copy">No se enviará nada en este paso.</span>
        </div>

        <section class="manual-email-editor" data-mail-editor hidden>
            <div class="panel-heading">
                <div><p class="eyebrow">Paso 2</p><h3>Revisa y edita el HTML</h3><p class="panel-copy">Los cambios se reflejan en la vista previa; los enlaces permanecen desactivados.</p></div>
                <button type="button" class="button" data-refresh-preview>Actualizar vista previa</button>
            </div>
            <div class="manual-editor-grid">
                <label class="field manual-html-field"><span>HTML del correo *</span><textarea name="html" rows="22" maxlength="512000" spellcheck="false" data-mail-html></textarea></label>
                <div class="manual-preview-frame"><span>Vista previa</span><iframe title="Vista previa del correo" sandbox data-mail-preview></iframe></div>
            </div>
            <div class="form-actions">
                <button type="button" class="button primary" data-review-mail>Revisar destinatarios y envío</button>
            </div>
        </section>
    </form>

    <section class="manual-email-result" data-mail-result hidden aria-live="polite"></section>
</section>

<div class="manual-mail-modal" data-mail-modal hidden>
    <div class="manual-mail-backdrop" data-close-mail-modal></div>
    <section class="manual-mail-dialog" role="dialog" aria-modal="true" aria-labelledby="mailReviewTitle">
        <div class="panel-heading">
            <div><p class="eyebrow">Paso 3</p><h2 id="mailReviewTitle">Confirmar envío</h2><p class="panel-copy">Ésta es la última revisión antes de contactar a los destinatarios.</p></div>
            <button type="button" class="manual-modal-close" data-close-mail-modal aria-label="Cerrar">×</button>
        </div>
        <dl class="manual-review-summary" data-review-summary></dl>
        <div class="manual-preview-frame review"><span>Contenido final</span><iframe title="Contenido final del correo" sandbox data-review-preview></iframe></div>
        <div class="form-actions manual-modal-actions">
            <button type="button" class="button" data-close-mail-modal>Volver a editar</button>
            <button type="button" class="button primary" data-send-mail>Confirmar y enviar</button>
        </div>
    </section>
</div>
