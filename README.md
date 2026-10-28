# Sistema 14 — renovación fase 1

Aplicación autocontenida para PHP 8 que administra los catálogos existentes de la base MariaDB `iohanes_14`. No requiere el proyecto anterior ni el archivo externo `funciones.php`.

## Ejecutar

Con Laragon, abrir `http://localhost/proyec_14/proyect_14/`. Esta ruta está centralizada en `APP_URL` dentro de `.env`; debe actualizarse ahí si cambia la carpeta o el dominio.

También puede ejecutarse desde esta carpeta:

```powershell
php -S 127.0.0.1:8080
```

Y abrir `http://127.0.0.1:8080/`.

La configuración local y las credenciales están en `.env`. `config/database.php` no contiene secretos y exige las variables `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD`. El archivo `.env` está bloqueado por Apache y excluido de Git; `.env.example` sirve como plantilla sin credenciales reales.

Si se usa el servidor integrado de PHP, cambia temporalmente `APP_URL` a `http://127.0.0.1:8080` antes de abrir la aplicación.

## Alcance

- Sin autenticación, por petición para esta fase.
- Catálogos con listado, búsqueda, paginación, alta, edición y eliminación.
- Reportes operativos con filtros, paginación y exportación CSV.
- Movimientos sin asignar, asignados, reasignación, traspasos y consultas STP.
- Facturación con consulta, búsqueda, alta transaccional, catálogos fiscales y conceptos dinámicos.
- Panel principal de carteras con saldos, movimientos, cheques y facturas pendientes, usando la misma información calculada del sistema anterior.
- PDO con parámetros preparados, escape HTML y protección CSRF.
- No modifica el esquema de la base ni copia información.
- El guardado de facturas y sus conceptos está integrado. El timbrado fiscal, reenvío y cancelación permanecen separados para evitar emitir o alterar CFDI reales durante pruebas sin autenticación.

Antes de usarlo en producción debe reactivarse la autenticación, crear un usuario de base de datos con permisos mínimos y retirar las credenciales predeterminadas.

## Modificar la apariencia

Los estilos están separados en dos niveles:

- `assets/theme.css`: colores, tipografía, sombras, radios y medidas globales. Para cambiar la identidad visual, empezar aquí.
- `assets/app.css`: estructura y componentes de la interfaz, organizado por secciones.

El HTML carga primero `theme.css` y después `app.css`. Los componentes usan variables semánticas como `--color-primary`, `--color-surface` y `--radius-panel`; por ello un rediseño de paleta no requiere modificar PHP. Se recomienda conservar los nombres de las variables para no romper componentes existentes.

La correspondencia entre cada reporte nuevo, su pantalla anterior y sus métodos de backend está documentada en `REPORTS.md`.

Las reglas transaccionales de asignación, comisiones, desasignación y traspasos están documentadas en `MOVEMENTS.md`. El operador temporal de movimientos está centralizado en `current_operator_id()` y actualmente devuelve `1` hasta integrar la cookie del login.

El flujo, los catálogos y las reglas de persistencia del módulo de facturación están documentados en `INVOICES.md`.
