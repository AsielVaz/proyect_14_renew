# Migración de reportes

Los reportes se definen en `src/Reports.php`, mientras que todas las consultas y transformaciones están en `src/ReportRepository.php`. La interfaz es compartida y se renderiza desde `index.php` mediante `?report=clave`.

| Reporte nuevo | Origen anterior | Backend anterior | Comportamiento conservado |
|---|---|---|---|
| `accesos` | `rep-acceso.php` | `AdministradorConecciones::dameAccesosPaginacion` | Sesiones en orden descendente |
| `bitacora` | `rep-bitacora.php` | `AdminBitacora::dameBitacorasPaginacion` | Usuario, empresa, acción y monto |
| `disparador` | `rep-disparador.php` | `AdministradorDisparador::dameEjecucionesDisparador` | Traducción de IDs de banca |
| `conta` | `rep-conta.php` | `AdministradorPagos::dameReporteConta14` | Pagos acreditables del rango y factura asociada |
| `conta-interno` | `rep-conta-interno.php` | `damePagosInternos` + `dameFacturasInternas` | Emparejamiento por importe exacto |
| `pagos-saldo` | `rep-pagos-saldo.php` | `AdministradorPagos::damePagosConCuenta` | Restante y número de folios asociados |
| `timbres` | `rep-timbres.php` | `AdministradorUtil::dameReporteTimbres` | Agrupación por factura y conteo de timbres |
| `facturas-eliminadas` | `fac-facturas-eliminadas.php` | `dameFacturasEliminadasRep` | Últimos 1,000 registros y usuarios relacionados |
| `facturas-captura-eliminada` | `rep-fac-eliminadas.php` | `dameFacturasEliminadasPorCaptura` | Facturas con estado `CAPDEL` |
| `facturas-canceladas` | `fac-facturas-canceladas.php` | `dameFacturasCanceladas` | Histórico con UUID |
| `banking` | `rep-ban.php` | `dameReporte`, `dameSumaReporte`, `dameProveedorE` | Porcentajes reales y rango de ±30 % |
| `correos-factura` | `rep-correos-factura.php` | `dameFacturasCorreos` | Sustitución de marcadores de fecha y limpieza de respuesta |
| `correos-contexto` | `rep-correos-contexto.php` | `dameContextoCorreo` | Separación del texto por la marca `RESPUESTA` |

## Optimizaciones aplicadas

- Consultas PDO parametrizadas para búsqueda y fechas.
- Selección explícita de columnas en lugar de `SELECT *`.
- Paginación en el servidor para tablas de cientos de miles de filas.
- Banking se obtiene con una sola consulta y funciones de ventana; el código anterior ejecutaba varias consultas por cada empresa y proveedor.
- Conta Interno selecciona de forma explícita todos los datos usados. La consulta anterior leía propiedades que no estaban en su `SELECT`.
- Los grupos de timbrado son deterministas mediante agregados explícitos.
- Las exportaciones CSV reutilizan exactamente los filtros activos y tienen un límite preventivo de 10,000 filas.

## Límite de seguridad de esta etapa

Las pantallas antiguas de facturas eliminadas y canceladas incluían botones para timbrar, reenviar o eliminar CFDI. Esas operaciones no son funciones de consulta y requieren identidad de usuario, permisos y auditoría. No se exponen mientras el proyecto opere sin login. Los datos y cálculos de los reportes sí están integrados.
