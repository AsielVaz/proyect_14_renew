# Panel de empresas

El módulo `?companies=list` replica en el sistema renovado la navegación operativa que comenzaba en `sistema/empresas.php`. Utiliza las mismas tablas y conserva la restricción histórica de acceso a la empresa 132 para los operadores 1, 31 y 82.

## Correspondencia con el sistema anterior

| Función | Sistema anterior | Sistema renovado |
| --- | --- | --- |
| Directorio y selección de empresa | `empresas.php` | `?companies=list` |
| Resumen y cuentas bancarias | Paneles dentro de `empresas.php` | `?companies=detail&id={empresa}` |
| Capturas importadas | `cap-capturas.php` | `?companies=captures&id={empresa}` |
| Contenido y desglose de una captura | `cap-ver.php` | `?companies=capture&id={empresa}&capture_id={captura}` |
| Movimientos de la empresa | Consultas de pagos del panel | `?companies=movements&id={empresa}` |
| Estado de una cuenta bancaria | `reporte-banco-detalle.php` | `?companies=account&id={empresa}&account_id={cuenta}` |
| Análisis previo de una captura | `captura.php` + `api/apiCap.php` | `?companies=analyze&id={empresa}&account_id={cuenta}` |

Las consultas están centralizadas en `src/CompanyPanelRepository.php`. Todas validan que la empresa sea visible y que la cuenta solicitada pertenezca a ella. Las listas aceptan búsqueda, rango de fechas, cuenta y paginación sin ejecutar conteos globales.

## Cálculos conservados

- El saldo de cada cuenta es la suma de depósitos menos la suma de retiros de `captura_edo_cta`.
- Los movimientos y los importes sin facturar se calculan desde `pagos` con las mismas condiciones del panel anterior.
- Cada captura se relaciona con su cuenta mediante `captura.nombre_corto` y con sus renglones mediante `captura_edo_cta.id_captura`.
- Los logotipos continúan leyéndose desde los recursos del sistema anterior mientras se migra el repositorio de imágenes.
- El análisis previo se ejecuta sin escrituras mediante `CaptureAnalysisService`: reconoce tablas con encabezados de fecha, importe/cargo/abono, descripción, referencia y saldo. Después vuelve a comprobar cada descripción y referencia directamente contra `claves_cortas`. El resultado muestra explícitamente “Con clave corta” o “Sin clave corta”, junto con el cliente asociado.

## Límite deliberado de esta réplica

La consulta y el análisis previo de capturas están integrados, incluidos el contenido original, sus movimientos, duplicados y la asociación de claves cortas. La confirmación que importa una captura bancaria nueva no se copió desde `apiCapturas.php`: ese archivo contiene analizadores diferentes por banco y, en una misma ejecución, inserta capturas, movimientos, pagos, comisiones y relaciones de facturación sin una frontera transaccional uniforme.

Antes de habilitar escritura debe separarse cada formato bancario en un adaptador probado, validar duplicados e importes y envolver todas las inserciones relacionadas en una sola transacción. Hasta entonces el módulo nuevo es de consulta y no altera información bancaria real.
