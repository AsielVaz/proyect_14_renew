<?php
/** @var array|null $xmlInvoicePreview */
/** @var string $xmlInvoiceToken */
$preview = is_array($xmlInvoicePreview ?? null) ? $xmlInvoicePreview : null;
?>
<section class="xml-import-layout">
    <section class="panel xml-import-upload">
        <div class="panel-heading">
            <div>
                <p class="eyebrow">Importación fiscal</p>
                <h2>Cargar factura desde XML</h2>
                <p class="panel-copy">Lee el CFDI timbrado, identifica la empresa por el RFC emisor y localiza las carteras relacionadas con el RFC receptor.</p>
            </div>
            <span class="operation-badge">CFDI 3.3 / 4.0</span>
        </div>
        <form method="post" enctype="multipart/form-data" class="xml-import-dropzone">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="xml_invoice_preview">
            <label class="field">
                <span>Archivo XML de la factura *</span>
                <input type="file" name="invoice_xml" accept=".xml,text/xml,application/xml" required>
                <small>Máximo 5 MB. Por seguridad no se admiten entidades ni recursos externos.</small>
            </label>
            <button type="submit" class="button primary">Analizar XML</button>
        </form>
    </section>

    <?php if ($preview): ?>
        <section class="xml-import-preview" aria-labelledby="xml-preview-title">
            <header class="xml-import-hero">
                <div>
                    <p class="eyebrow">Documento validado</p>
                    <h2 id="xml-preview-title"><?= e(($preview['series'] ?? '') . ($preview['folio'] ?? '')) ?></h2>
                    <p><?= e($preview['stamp']['uuid'] ?? '') ?></p>
                </div>
                <div class="xml-import-total"><span>Total del CFDI</span><strong><?= e(report_value($preview['total'] ?? 0, 'money')) ?></strong><small><?= e($preview['currency'] ?? '') ?></small></div>
            </header>

            <?php if (!empty($preview['already_imported'])): ?>
                <div class="xml-import-alert error"><strong>Este CFDI ya está registrado.</strong><span>El UUID ya existe en la tabla de facturas y no se puede guardar nuevamente.</span></div>
            <?php endif; ?>

            <section class="panel xml-import-data">
                <div class="panel-heading"><div><p class="eyebrow">Información fiscal</p><h2>Datos del comprobante</h2></div><span class="status success">Timbrado</span></div>
                <dl class="xml-import-facts">
                    <div><dt>Fecha de emisión</dt><dd><?= e($preview['issued_at'] ?? '—') ?></dd></div>
                    <div><dt>Fecha de timbrado</dt><dd><?= e($preview['stamp']['stamped_at'] ?? '—') ?></dd></div>
                    <div><dt>Método / forma</dt><dd><?= e(($preview['payment_method'] ?? '—') . ' · ' . ($preview['payment_form'] ?? '—')) ?></dd></div>
                    <div><dt>Uso CFDI</dt><dd><?= e($preview['receiver']['cfdi_use'] ?? '—') ?></dd></div>
                    <div><dt>Subtotal</dt><dd><?= e(report_value($preview['subtotal'] ?? 0, 'money')) ?></dd></div>
                    <div><dt>Tipo de comprobante</dt><dd><?= e($preview['voucher_type'] ?? '—') ?></dd></div>
                </dl>
                <div class="xml-import-parties">
                    <article><span>Emisor</span><strong><?= e($preview['issuer']['name'] ?? '—') ?></strong><small><?= e($preview['issuer']['rfc'] ?? '') ?> · Régimen <?= e($preview['issuer']['tax_regime'] ?? '—') ?></small></article>
                    <article><span>Receptor</span><strong><?= e($preview['receiver']['name'] ?? '—') ?></strong><small><?= e($preview['receiver']['rfc'] ?? '') ?> · Régimen <?= e($preview['receiver']['tax_regime'] ?? '—') ?></small></article>
                </div>
            </section>

            <form method="post" class="xml-import-confirm" data-confirm="¿Guardar esta factura timbrada y todos sus conceptos?" data-confirm-title="Confirmar carga de XML">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="xml_invoice_save">
                <input type="hidden" name="import_token" value="<?= e($xmlInvoiceToken) ?>">

                <section class="panel xml-import-match">
                    <div class="panel-heading"><div><p class="eyebrow">RFC <?= e($preview['issuer']['rfc'] ?? '') ?></p><h2>Empresa emisora</h2><p class="panel-copy">La empresa se obtiene exclusivamente del RFC del emisor.</p></div><span class="match-count"><?= count($preview['companies'] ?? []) ?> coincidencia(s)</span></div>
                    <div class="xml-import-options">
                        <?php foreach (($preview['companies'] ?? []) as $index => $company): ?>
                            <label class="xml-import-option"><input type="radio" name="company_id" value="<?= e($company['id']) ?>" <?= $index === 0 ? 'checked' : '' ?> required><span><strong><?= e($company['razon']) ?></strong><small>#<?= e($company['id']) ?> · <?= e($company['rfc']) ?></small></span></label>
                        <?php endforeach; ?>
                        <?php if (($preview['companies'] ?? []) === []): ?><div class="empty">No existe una empresa con el RFC emisor del XML.</div><?php endif; ?>
                    </div>
                </section>

                <section class="panel xml-import-match">
                    <div class="panel-heading"><div><p class="eyebrow">RFC <?= e($preview['receiver']['rfc'] ?? '') ?></p><h2>Cartera receptora</h2><p class="panel-copy">Si varias claves cortas comparten RFC, selecciona la cartera a la que pertenece la factura.</p></div><span class="match-count"><?= count($preview['receivers'] ?? []) ?> posible(s)</span></div>
                    <div class="xml-import-options">
                        <?php foreach (($preview['receivers'] ?? []) as $index => $receiver): ?>
                            <label class="xml-import-option"><input type="radio" name="receiver_id" value="<?= e($receiver['id']) ?>" <?= count($preview['receivers']) === 1 ? 'checked' : '' ?> required><span><strong><?= e($receiver['cliente']) ?></strong><small><?= e($receiver['razon_social']) ?> · <?= e($receiver['clave_corta']) ?> · #<?= e($receiver['id']) ?></small></span></label>
                        <?php endforeach; ?>
                        <?php if (($preview['receivers'] ?? []) === []): ?><div class="empty">No existe una clave corta asociada al RFC receptor del XML.</div><?php endif; ?>
                    </div>
                </section>

                <section class="panel xml-import-concepts">
                    <div class="panel-heading"><div><p class="eyebrow">Partidas</p><h2>Conceptos del XML</h2><p class="panel-copy">Se conservarán las cantidades, descripciones, importes e IVA contenidos en el CFDI.</p></div><span class="match-count"><?= count($preview['concepts'] ?? []) ?> concepto(s)</span></div>
                    <div class="table-wrap"><table><thead><tr><th>Clave SAT</th><th>Descripción</th><th>Cantidad</th><th>Precio unitario</th><th>IVA</th><th>Total</th></tr></thead><tbody>
                    <?php foreach (($preview['concepts'] ?? []) as $concept): ?><tr><td><strong><?= e($concept['product_key']) ?></strong><small class="movement-cell-secondary"><?= e($concept['unit_key']) ?></small></td><td><?= e($concept['description']) ?></td><td><?= e(rtrim(rtrim(number_format((float) $concept['quantity'], 6, '.', ','), '0'), '.')) ?></td><td><?= e(report_value($concept['unit_value'], 'money')) ?></td><td><?= e(number_format((float) $concept['tax_rate'], 2)) ?>%</td><td><?= e(report_value($concept['gross'], 'money')) ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                </section>

                <?php
                $catalogsReady = min(array_map('intval', array_values($preview['catalogs'] ?? [0]))) > 0;
                $canSave = empty($preview['already_imported']) && ($preview['companies'] ?? []) !== []
                    && ($preview['receivers'] ?? []) !== [] && $catalogsReady;
                ?>
                <?php if (!$catalogsReady): ?><div class="xml-import-alert error"><strong>Faltan catálogos fiscales.</strong><span>Verifica que método, forma de pago, moneda y uso CFDI del XML existan en los catálogos del sistema.</span></div><?php endif; ?>
                <div class="xml-import-actions"><a class="button" href="?dev=xml-invoices">Cancelar</a><button type="submit" class="button primary" <?= $canSave ? '' : 'disabled' ?>>Guardar factura</button></div>
            </form>
        </section>
    <?php endif; ?>
</section>
