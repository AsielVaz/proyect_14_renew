# Migración de movimientos

La navegación y columnas de las vistas están en `src/Movements.php`. Las consultas, cálculos y operaciones transaccionales están en `src/MovementRepository.php`.

## Flujos integrados

- Movimientos sin asignar: consulta, selector de cartera buscable por cualquier fragmento del nombre o ID, previsualización de comisiones y asignación conjunta únicamente de los movimientos seleccionados.
- Movimientos asignados: consulta y desasignación.
- Reasignación: reversión de comisiones anteriores y cálculo completo para la cartera nueva.
- Ingreso de pagos: consulta general equivalente a la pantalla anterior.
- Traspasos: creación y eliminación conjunta de la entrada y la salida.
- Movimientos STP: consulta paginada.

Cada operación de escritura valida CSRF y se ejecuta dentro de una transacción de base de datos. El operador se obtiene de la sesión autenticada mediante `current_operator_id()`.

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

Los retiros no generan comisión y se acreditan con signo negativo, igual que en el flujo anterior.

## Desasignación y reasignación

Las nuevas asignaciones guardan una relación exacta entre pago principal y pagos de comisión en `s14_movimiento_comision_links`. Al desasignar:

1. Se bloquea el pago principal.
2. Se localizan y bloquean sus comisiones.
3. Se eliminan esas comisiones.
4. Se limpia la cartera, saldo acreditable y estado del pago principal.
5. Se registra la operación en auditoría.
6. Se confirma todo junto; ante cualquier error se revierte todo.

Para asignaciones históricas sin relación explícita, se conserva compatibilidad buscando las comisiones mediante monto, fecha de asignación original, captura y cartera de origen. Esto corrige el problema anterior que sólo intentaba eliminarlas usando la fecha actual.

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

## Diferencias intencionales respecto al código anterior

- Se utilizan PDO y parámetros preparados.
- No se realizan escrituras parciales: todas las operaciones son atómicas.
- La desasignación tardía sí elimina las comisiones históricas relacionadas.
- La eliminación de traspasos no presupone que la salida tenga `ID entrada + 1`.
- Se registra auditoría local en lugar de depender de funciones externas de correo y notificaciones.
