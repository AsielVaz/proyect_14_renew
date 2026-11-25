<dialog class="s14-chat" id="s14-chat" aria-labelledby="s14-chat-title" data-chat
    data-csrf="<?= e(csrf_token()) ?>">
    <header class="s14-chat-header">
        <div>
            <h2 id="s14-chat-title">Asistente de consultas</h2>
            <p>Facturas, pagos, STP y reportes · Solo lectura</p>
        </div>
        <button type="button" class="button s14-chat-dismiss" data-chat-dismiss aria-label="Cerrar asistente">×</button>
    </header>
    <div class="s14-chat-layout">
        <details class="s14-chat-history" open data-chat-history>
            <summary>Conversaciones</summary>
            <div class="s14-chat-history-inner">
                <button type="button" class="button" data-chat-new>Nueva conversación</button>
                <ul class="s14-chat-threads" data-chat-threads></ul>
                <button type="button" class="button" data-chat-more-threads hidden>Ver más conversaciones</button>
            </div>
        </details>
        <section class="s14-chat-thread" aria-label="Conversación activa">
            <div class="s14-chat-toolbar">
                <h3 data-chat-thread-title>Nueva conversación</h3>
                <button type="button" class="button compact" data-chat-end hidden>Terminar conversación</button>
            </div>
            <div class="s14-chat-scroll" data-chat-scroll tabindex="0" aria-label="Historial de mensajes">
                <button type="button" class="button" data-chat-older hidden>Ver mensajes anteriores</button>
                <div class="s14-chat-empty" data-chat-empty>
                    <h3>¿Qué quieres consultar?</h3>
                    <div class="s14-chat-suggestions">
                        <button type="button" class="button" data-chat-suggestion="Buscar una factura por folio">Buscar factura</button>
                        <button type="button" class="button" data-chat-suggestion="Muéstrame los pagos de esta semana">Pagos de la semana</button>
                        <button type="button" class="button" data-chat-suggestion="Consultar una orden STP por folio">Estado de una orden STP</button>
                        <button type="button" class="button" data-chat-suggestion="Resume el reporte Conta 14 de este mes" data-chat-report hidden>Reporte Conta 14</button>
                    </div>
                </div>
                <ol class="s14-chat-messages" data-chat-messages></ol>
            </div>
            <div class="s14-chat-compose">
                <p class="s14-chat-status" role="status" aria-live="polite" data-chat-status></p>
                <form data-chat-form>
                    <label for="s14-chat-message">Pregunta</label>
                    <div class="s14-chat-input">
                        <textarea id="s14-chat-message" name="mensaje" rows="2" maxlength="2000" required
                            placeholder="Escribe tu consulta" data-chat-input></textarea>
                        <button type="submit" class="button primary" data-chat-send>Enviar</button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</dialog>

