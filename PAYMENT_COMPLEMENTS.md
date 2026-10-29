# Complementos de pago

El módulo `?complement=list` replica la captura y consulta de complementos de recepción de pagos del sistema anterior sobre las mismas tablas:

- `facturas_complementos`: encabezado del CFDI tipo P.
- `facturar_rel_complementos`: documentos relacionados, parcialidad, saldo anterior, importe pagado y saldo insoluto.
- `facturas`: facturas PPD que pueden recibir el pago.
- `complementos_folio`: folio interno generado por el proceso fiscal legado, cuando existe.

## Flujo

1. Se seleccionan empresa emisora, cliente, razón social, forma y fecha del pago.
2. Cada factura se consulta por UUID. La pantalla muestra su saldo actual y propone la siguiente parcialidad.
3. Al guardar se vuelven a consultar y bloquear las facturas dentro de una transacción.
4. Se valida que sean PPD, pertenezcan al mismo emisor y receptor, no estén repetidas y que ningún importe exceda el saldo.
5. El encabezado y todas sus relaciones se insertan atómicamente usando `lastInsertId()`.

El total del complemento se deriva de la suma de sus documentos relacionados; no se acepta un total independiente enviado por el navegador.

## Estado fiscal

Los registros nuevos quedan con estado `Pendiente` y sin UUID. La emisión fiscal real, el reenvío por correo, PDF/XML y la cancelación ante el PAC no se ejecutan desde este módulo para evitar efectos fiscales durante la transición. Un registro pendiente puede eliminarse junto con sus relaciones; uno timbrado no puede borrarse y debe seguir el proceso de cancelación fiscal correspondiente.

El tratamiento legado se conserva en `facturas_complementos.cadena`:

- `T`: típico, IVA 16%.
- `T0`: tasa 0%.
- `SV`: sin IVA.
- `4`: retención 4%.
