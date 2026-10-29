# Sistema 14 — renovación fase 1

Aplicación autocontenida para PHP 8 que administra los catálogos existentes de la base MariaDB `iohanes_14`. No requiere el proyecto anterior ni el archivo externo `funciones.php`.

## Ejecutar

Con Laragon, abrir `http://localhost/proyec_14/proyect_14/`. Esta ruta está centralizada en `APP_URL` dentro de `.env`; debe actualizarse ahí si cambia la carpeta o el dominio.

La configuración local y las credenciales están en `.env`. `config/database.php` no contiene secretos y exige las variables `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD`. El archivo `.env` está bloqueado por Apache y excluido de Git; `.env.example` sirve como plantilla sin credenciales reales.

La aplicación debe publicarse con Apache (incluyendo `.htaccess`) o con reglas equivalentes en Nginx/IIS que permitan acceso público únicamente a `index.php` y `assets/`. No se debe exponer esta carpeta con el servidor integrado de PHP sin un router que bloquee `.env`, `src/`, `config/` y la documentación.

`DASHBOARD_CACHE_SECONDS` controla por cuántos segundos se reutilizan los indicadores globales dentro de la sesión. El valor recomendado es `30` cuando PHP y MariaDB están en servicios distintos; las listas y búsquedas siguen consultando datos actuales.

Para producción, usar HTTPS, cambiar `APP_URL`, generar un `APP_KEY` aleatorio propio y establecer `SESSION_SECURE_COOKIE=true`. Sólo debe activarse `TRUST_PROXY=true` cuando el proxy inverso sea conocido y reemplace de forma segura `X-Forwarded-For`.

## Alcance

- Autenticación con los mismos usuarios y contraseñas legadas, sin modificar datos durante el acceso.
- Segundo factor TOTP únicamente para cuentas que ya tienen configurado el campo `usuarios.2fa`.
- Sesiones endurecidas, CSRF, rotación del identificador, vencimiento por inactividad y límite de intentos fuera de MariaDB.
- Catálogos con listado, búsqueda, paginación, alta, edición y eliminación.
- Reportes operativos con filtros, paginación y exportación CSV.
- Movimientos sin asignar, asignados, reasignación, traspasos y consultas STP.
- Facturación con consulta, búsqueda, alta transaccional, catálogos fiscales y conceptos dinámicos.
- Complementos de pago con consulta, detalle, captura de múltiples facturas PPD, cálculo de saldos y guardado transaccional.
- Panel de empresas con directorio, cuentas activas, saldos, capturas, contenido bancario y movimientos por empresa o cuenta.
- Panel principal de carteras con saldos, movimientos, cheques y facturas pendientes, usando la misma información calculada del sistema anterior.
- Listados con paginación rápida sin ejecutar conteos globales en cada solicitud, pensados para PHP y MariaDB alojados en servicios separados.
- PDO con parámetros preparados, escape HTML, protección CSRF y encabezados de seguridad del navegador.
- No modifica el esquema de la base ni copia información.
- El guardado de facturas y sus conceptos está integrado. El timbrado fiscal, reenvío y cancelación permanecen separados para evitar emitir o alterar CFDI reales durante pruebas.

Antes de usarlo en producción debe crearse un usuario de base de datos con permisos mínimos y retirarse la cuenta `root` de la configuración de la aplicación.

## Modificar la apariencia

Los estilos están separados en dos niveles:

- `assets/theme.css`: colores, tipografía, sombras, radios y medidas globales. Para cambiar la identidad visual, empezar aquí.
- `assets/app.css`: estructura y componentes de la interfaz, organizado por secciones.

El HTML carga primero `theme.css` y después `app.css`. Los componentes usan variables semánticas como `--color-primary`, `--color-surface` y `--radius-panel`; por ello un rediseño de paleta no requiere modificar PHP. Se recomienda conservar los nombres de las variables para no romper componentes existentes.

La correspondencia entre cada reporte nuevo, su pantalla anterior y sus métodos de backend está documentada en `REPORTS.md`.

Las reglas transaccionales de asignación, comisiones, desasignación y traspasos están documentadas en `MOVEMENTS.md`. El operador de movimientos se obtiene del usuario autenticado mediante `current_operator_id()`.

El flujo, los catálogos y las reglas de persistencia del módulo de facturación están documentados en `INVOICES.md`.

La equivalencia del flujo legado de complementos de recepción de pagos y sus validaciones está documentada en `PAYMENT_COMPLEMENTS.md`.

La equivalencia del panel legado de empresas, cuentas, capturas y movimientos está documentada en `COMPANIES.md`.
