# Movimientos

La navegación y columnas de las vistas están en `src/Movements.php`. Las consultas, cálculos y operaciones transaccionales están en `src/MovementRepository.php`.

## Flujos integrados

- Movimientos sin asignar: consulta, selector de cartera buscable por cualquier fragmento del nombre o ID, previsualización de comisiones y asignación conjunta únicamente de los movimientos seleccionados.
- Movimientos asignados: consulta, desasignación y asociación manual de documentos existentes.
- Reasignación: reversión de comisiones anteriores y cálculo completo para la cartera nueva.
- Log de movimientos: consulta general de pagos y movimientos registrados.
- Traspasos: creación y eliminación conjunta de la entrada y la salida.
- Movimientos STP: consulta paginada.
- Correos manuales: composición con plantilla por empresa, conceptos calculados, edición HTML, adjuntos PDF y revisión final obligatoria.

Cada operación de escritura valida CSRF y se ejecuta dentro de una transacción de base de datos. El operador se obtiene de la sesión autenticada mediante `current_operator_id()`.

Los buscadores de **Movimientos sin asignar** y **Movimientos asignados** incluyen los campos originales `pagos.descripcion`, `pagos.detalle` y `pagos.descripcion_detallada`. Al escribir filtran inmediatamente los registros de la página visible; al presionar Enter ejecutan la consulta sobre todo el histórico y conservan los filtros activos.

En **Movimientos asignados**, el filtro de empresas inicia con todas marcadas. La interfaz envía únicamente `company_exclude[]` para las empresas desmarcadas, de modo que la selección se conserva al buscar y paginar sin generar una URL con todas las empresas del catálogo.

## Capturas manuales

El módulo **Movimientos → Capturas manuales** replica el flujo operativo anterior con validaciones y transacciones del sistema actual. Solicita empresa, cuenta bancaria activa, tipo de movimiento, monto, fecha bancaria, saldo mostrado por el banco y detalle. La operación crea de forma atómica la cabecera en `captura`, el movimiento en `captura_edo_cta`, el registro en `pagos` y la entrada de `bitacora`.

El detalle se analiza con el mismo catálogo de claves cortas que las capturas bancarias pegadas. Si se identifica una cartera, el movimiento se asigna, se calculan las comisiones y, cuando la clave tiene facturación automática habilitada, se genera la factura pendiente correspondiente. Si no se identifica una clave, queda en Movimientos sin asignar.

La eliminación desde el historial retira únicamente capturas manuales y sus registros asociados dentro de una transacción. Se bloquea cuando existe una factura timbrada, un complemento o una relación fiscal que deba conservarse.

## Asociación manual de documentos

Los depósitos asignados que todavía no tengan `id_factura` ni una referencia entera válida en `id_factura_res` ofrecen la acción **Asignar documento**. La vista busca facturas y complementos que:

- pertenezcan a la misma empresa emisora del movimiento;
- tengan un total dentro de una tolerancia de ±$10.00 respecto al monto absoluto del pago;
- pertenezcan a cualquier fecha, sin límite de antigüedad ni rango temporal;
- no estén cancelados o desactivados;
- no estén relacionados con otro movimiento.

Al confirmar, el servidor vuelve a bloquear y validar el pago y el documento dentro de una transacción. Para una factura actualiza `pagos.id_factura`; para un complemento actualiza `pagos.id_factura_res`. También conserva la relación inversa en `id_pago`, registra operador y fecha de asociación, y agrega la acción `document_assign` a la auditoría de movimientos.

## Correos manuales

El módulo aparece dentro de **Movimientos** únicamente para los operadores autorizados (IDs 1, 32 y 82). El flujo separa la generación de la plantilla, la revisión y el envío. La revisión crea un token temporal ligado a destinatarios, asunto, HTML y adjuntos; si algún dato cambia, el servidor rechaza el envío hasta que se vuelva a revisar. El token vence a los 30 minutos y se consume antes de contactar al proveedor, para reducir envíos duplicados.

Las 16 plantillas proporcionadas están integradas en `src/manual-email-templates.php`. La redacción asistida sólo se utiliza cuando el mensaje principal está vacío y existen conceptos. La configuración externa se toma exclusivamente de variables de entorno:

- `DEEPSEEK_API_KEY` y `DEEPSEEK_MODEL` para redacción asistida.
- `MANDRILL_API_KEY` para envío.
- `S14_IMAP_HOST`, `S14_IMAP_PASSWORD` y `S14_IMAP_SENT_FOLDER` para guardar una copia opcional en Enviados.
- `S14_AUDIT_EMAIL` para la copia oculta de auditoría cuando aplique.

Los adjuntos aceptan exclusivamente PDF: máximo 10 archivos, 10 MB por archivo y 20 MB en conjunto. Si PHP no tiene la extensión IMAP, el correo todavía puede ser aceptado por Mandrill, pero no se guardará la copia local en Enviados.

La asignación se guarda por lote mediante un único botón. Las filas sin cartera se omiten y permanecen sin asignar. Antes de escribir, el servidor calcula y devuelve al modal el saldo acreditable, el esquema utilizado, cada comisión, su porcentaje, la cartera receptora y los totales repartidos. Al aceptar, el lote seleccionado se vuelve a validar y se ejecuta en una sola transacción: si un movimiento fue modificado o falla, se revierte todo el lote.

## Cálculo de comisiones

Para un depósito `D`, comisión principal `C` y subcomisiones `S1...S4`:

### Total

- Comisión principal: `D × C / 100`.
- Cada subcomisión: `D × Sn / 100`.
- Saldo acreditable: `D - comisión principal - suma(subcomisiones)`.

### Subtotal

- Subtotal: `D / (1 + IVA / 100)`.
- Comisión principal: `subtotal × C / 100`.
- Cada subcomisión: `subtotal × Sn / 100`.
- Saldo acreditable: `D - comisión principal - suma(subcomisiones)`.

### Total Invertido

- Porcentaje total: `C + suma(Sn)`.
- Saldo acreditable: `D / (1 + porcentaje total / 100)`.
- La diferencia se distribuye proporcionalmente entre la comisión principal y las subcomisiones.

La suma del saldo acreditable y todos los abonos de comisión se valida para conservar el monto original.

Los retiros no generan comisión y se acreditan con signo negativo.

## Desasignación y reasignación

Las nuevas asignaciones guardan una relación exacta entre pago principal y pagos de comisión en `s14_movimiento_comision_links`. Al desasignar:

1. Se bloquea el pago principal.
2. Se localizan y bloquean sus comisiones.
3. Se eliminan esas comisiones.
4. Se limpia la cartera, saldo acreditable y estado del pago principal.
5. Se registra la operación en auditoría.
6. Se confirma todo junto; ante cualquier error se revierte todo.

Para asignaciones sin relación explícita, las comisiones se localizan mediante monto, fecha de asignación, captura y cartera de origen.

La reasignación ejecuta la desasignación y la nueva asignación dentro de una única transacción.

## Traspasos

Cada traspaso crea dos pagos:

- depósito positivo para la cartera destino;
- retiro negativo para la cartera origen.

La pareja se registra en `s14_traspaso_links`. La eliminación exige encontrar ambas filas; si la pareja no puede identificarse, no elimina ninguna. Los traspasos históricos se emparejan por origen, destino, fecha, detalle y monto compensado, evitando depender de que sus IDs sean consecutivos.

## Auditoría

`s14_movimiento_auditoria` registra asignaciones, desasignaciones, reasignaciones indirectas y creación/eliminación de traspasos, incluyendo operador, pago y datos relevantes en JSON.

Las tres tablas auxiliares se crean automáticamente si no existen:

- `s14_movimiento_comision_links`
- `s14_traspaso_links`
- `s14_movimiento_auditoria`

## Reglas de implementación

- Se utilizan PDO y parámetros preparados.
- No se realizan escrituras parciales: todas las operaciones son atómicas.
- La desasignación tardía sí elimina las comisiones históricas relacionadas.
- La eliminación de traspasos no presupone que la salida tenga `ID entrada + 1`.
- Se registra auditoría local para cada operación relevante.
