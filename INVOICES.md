# Facturación

## Alcance integrado

El menú **Facturación** contiene la consulta paginada y la creación de facturas. Ambas pantallas trabajan directamente con las tablas fiscales de `iohanes_14`.

La consulta combina `facturas` con `clientes`, `empresas`, `claves_cortas`, `formas_pago`, `metodos_pago` y `usuarios`. Permite buscar por ID, folio, serie, UUID, cliente, emisor, receptor o RFC.

## Catálogos del formulario

- Emisor: `empresas`.
- Cliente: `clientes` activos.
- Razón social receptora: `claves_cortas`, limitada al cliente elegido.
- Conceptos: `conceptos`, limitados a la empresa emisora.
- Método y forma de pago: `metodos_pago` y `formas_pago`.
- Uso CFDI y moneda: `catalogo_usos_cfdi` y `catalogo_monedas`.

Las razones sociales y los conceptos se solicitan bajo demanda para evitar enviar miles de registros en el HTML inicial.

## Guardado

El backend vuelve a validar todas las relaciones, cantidades e IVA. En facturación manual, el importe capturado representa el total con IVA de la partida y el precio unitario se calcula según la cantidad. Para cada partida se calcula el unitario sin IVA, neto y total con IVA.

`facturas`, `detalle_factura` y la relación opcional con `pagos` se guardan dentro de una sola transacción. Se utiliza `PDO::lastInsertId()` en lugar de `MAX(id)`, eliminando el riesgo de asociar conceptos a la factura de otro proceso concurrente. Cualquier error revierte todo el conjunto.

Antes de enviar, un modal muestra el número de conceptos, subtotal, IVA, total y desglose de cada partida.

## Timbrado fiscal

El alta conserva el estado `Pendiente` y no emite automáticamente. Desde el listado, el botón **Timbrar** solicita confirmación explícita y envía la factura al servicio fiscal. El servidor vuelve a comprobar que la factura exista y no tenga UUID antes de llamar al servicio.

Cada fila ofrece un menú contextual con clic derecho y un botón visible de acciones. Para pendientes contiene timbrado, PDF previo, PRE XML y eliminación; para timbradas contiene PDF, XML y reenvío por correo. Todas las escrituras usan sesión, CSRF y confirmación.

Las facturas PPD incluyen la acción **Hacer complemento de pago**. La acción abre el alta de complementos con empresa, cliente y clave corta precargados y bloqueados, y relaciona la factura con su saldo pendiente vigente.

La acción **Ver complementos asociados** muestra únicamente los complementos aplicados a la factura, su cantidad, los importes pagados y el saldo restante vigente.

Las pruebas automáticas nunca invocan el timbrado o el reenvío, ya que producirían efectos fiscales o comunicaciones reales.
