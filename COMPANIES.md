# Panel de empresas

El módulo `?companies=list` concentra la operación por empresa. Utiliza las tablas financieras de Sistema 14 y restringe el acceso a la empresa 132 a los operadores 1, 31 y 82.

## Rutas

| Función | Ruta |
| --- | --- |
| Directorio y selección de empresa | `?companies=list` |
| Resumen y cuentas bancarias | `?companies=detail&id={empresa}` |
| Capturas importadas | `?companies=captures&id={empresa}` |
| Contenido y desglose de una captura | `?companies=capture&id={empresa}&capture_id={captura}` |
| Movimientos de la empresa | `?companies=movements&id={empresa}` |
| Estado de una cuenta bancaria | `?companies=account&id={empresa}&account_id={cuenta}` |
| Análisis y carga de una captura | `?companies=analyze&id={empresa}&account_id={cuenta}` |

Las consultas están centralizadas en `src/CompanyPanelRepository.php`. Todas validan que la empresa sea visible y que la cuenta solicitada pertenezca a ella. Las listas aceptan búsqueda, rango de fechas, cuenta y paginación sin ejecutar conteos globales.

## Cálculos

- El saldo de cada cuenta es la suma de depósitos menos la suma de retiros de `captura_edo_cta`.
- Los movimientos y los importes sin facturar se calculan desde `pagos`.
- Cada captura se relaciona con su cuenta mediante `captura.nombre_corto` y con sus renglones mediante `captura_edo_cta.id_captura`.
- Los logotipos se obtienen desde la URL de recursos configurada para las empresas.
- El análisis previo se ejecuta sin escrituras mediante `CaptureAnalysisService`: reconoce tablas con encabezados de fecha, importe/cargo/abono, descripción, referencia y saldo. Después vuelve a comprobar cada descripción y referencia directamente contra `claves_cortas`. El resultado muestra explícitamente “Con clave corta” o “Sin clave corta”, junto con el cliente asociado.
- El análisis muestra el saldo registrado de la cuenta antes de la carga y proyecta el saldo posterior sumando los depósitos y restando los retiros nuevos pegados. Los duplicados no modifican la proyección porque tampoco se guardan durante la carga.

## Carga de capturas

La consulta, el análisis y la carga de capturas están integrados. El proceso valida duplicados, importes y claves cortas antes de guardar. Captura, movimientos, pagos, comisiones y facturas automáticas se insertan dentro de una sola transacción; cualquier error revierte la operación completa.
