# Reportes

Los reportes se definen en `src/Reports.php`, mientras que todas las consultas y transformaciones están en `src/ReportRepository.php`. La interfaz es compartida y se renderiza desde `index.php` mediante `?report=clave`.

| Reporte | Comportamiento |
|---|---|
| `accesos` | Sesiones en orden descendente |
| `bitacora` | Usuario, empresa, acción y monto |
| `disparador` | Traducción de IDs de banca |
| `conta` | Pagos acreditables del rango y factura asociada |
| `conta-interno` | Emparejamiento por importe exacto |
| `pagos-saldo` | Restante y número de folios asociados |
| `timbres` | Agrupación por factura y conteo de timbres |
| `facturas-eliminadas` | Últimos 1,000 registros y usuarios relacionados |
| `facturas-captura-eliminada` | Facturas con estado `CAPDEL` |
| `facturas-canceladas` | Histórico con UUID |
| `banking` | Porcentajes reales y rango de ±30 % |
| `correos-factura` | Sustitución de marcadores de fecha y limpieza de respuesta |
| `correos-contexto` | Separación del texto por la marca `RESPUESTA` |

## Optimizaciones aplicadas

- Consultas PDO parametrizadas para búsqueda y fechas.
- Selección explícita de columnas en lugar de `SELECT *`.
- Paginación en el servidor para tablas de cientos de miles de filas.
- Banking se obtiene con una sola consulta y funciones de ventana.
- Conta Interno selecciona de forma explícita todos los datos usados.
- Los grupos de timbrado son deterministas mediante agregados explícitos.
- Las exportaciones CSV reutilizan exactamente los filtros activos y tienen un límite preventivo de 10,000 filas.

## Seguridad

Las operaciones fiscales requieren identidad de usuario, permisos, confirmación y auditoría. Los reportes son consultas y no ejecutan timbrados, reenvíos ni eliminaciones de CFDI.
