# WebAuthn / Passkeys

Sistema 14 admite autenticación y registro de Passkeys sin depender del sistema anterior. Las sesiones continúan usando `auth_user` y los mismos alias internos del login tradicional.

## Dependencia

Ejecutar en la carpeta de la aplicación:

```bash
composer install --no-dev --optimize-autoloader
```

Se utiliza `lbuchs/webauthn` 2.2 porque las credenciales existentes almacenan claves públicas PEM EC P-256, formato que esta biblioteca valida directamente. No se convierten, sobrescriben ni eliminan credenciales antiguas.

## Producción en dev1.sistema14.com

La configuración esperada está en `.env.example`. Para el despliegue deben coincidir:

```dotenv
APP_URL=https://dev1.sistema14.com
SESSION_NAME=S14DEV1SESSION
SESSION_COOKIE_DOMAIN=
SESSION_COOKIE_PATH=/
SESSION_SECURE_COOKIE=true
WEBAUTHN_ENABLED=true
WEBAUTHN_RP_ID=sistema14.com
WEBAUTHN_ORIGIN=https://dev1.sistema14.com
WEBAUTHN_HANDLE_SECRET=secreto_existente_de_64_caracteres
WEBAUTHN_USER_VERIFICATION=required
WEBAUTHN_CHALLENGE_TTL=120
WEBAUTHN_ENROLLMENT_TTL=300
WEBAUTHN_MAX_CREDENTIALS=20
```

`SESSION_COOKIE_DOMAIN` vacío genera una cookie exclusiva de `dev1.sistema14.com`. La aplicación rechaza al iniciar una combinación inválida de origen, RP ID, `APP_URL` o cookie insegura.

La implementación anterior utilizaba el origen `https://sistema14.com` y el RP ID `sistema14.com`. El nuevo sistema conserva el mismo RP ID, que es lo que permite presentar esas credenciales desde el subdominio `dev1`. El origen validado debe ser `https://dev1.sistema14.com`, porque es el origen que el navegador incluye en las nuevas ceremonias. No se debe configurar el origen anterior en el nuevo login.

Para usuarios que ya tienen Passkeys se reutiliza literalmente su `user_handle` almacenado. Para un usuario sin credenciales se genera un handle HMAC estable con `WEBAUTHN_HANDLE_SECRET`; el secreto real sólo debe estar en `.env`, nunca en el repositorio.

Para desarrollo local se permite únicamente la excepción de origen seguro definida por WebAuthn para `localhost`. Las credenciales registradas para `sistema14.com` no funcionan en `localhost`, porque el RP ID forma parte criptográfica de la credencial.

## Flujos

- `POST ?webauthn=login-options`: opciones detectables sin usuario o restringidas por correo/usuario.
- `POST ?webauthn=login-verify`: firma, origen exacto, RP ID, presencia, verificación de usuario, relación usuario-credencial y contador.
- `POST ?webauthn=register-options`: requiere sesión y CSRF.
- `POST ?webauthn=register-verify`: valida la atestación y guarda en `usuarios_passkeys`.
- `POST ?webauthn=rename`: requiere pertenencia y CSRF.
- `POST ?webauthn=delete`: requiere pertenencia, CSRF y autenticación reciente.

Los desafíos viven en la sesión, se vinculan al agente del navegador, caducan y se consumen antes de verificar la respuesta. Una respuesta fallida no puede reutilizar el mismo desafío.

## Compatibilidad comprobada

La tabla real contiene identificadores binarios de 16–32 bytes, hashes SHA-256 binarios de 32 bytes, `user_handle` estable de 32 bytes por usuario, transportes JSON y claves PEM EC `prime256v1`. El repositorio busca primero por hash y después compara el identificador binario completo.

Las credenciales sincronizadas (`backup_eligible`) admiten contador cero. En credenciales no sincronizadas se exige que el contador avance y la actualización es condicional para evitar dos autenticaciones concurrentes con el mismo estado.

## Prueba en navegador

La prueba criptográfica con una credencial antigua debe hacerse desde `https://dev1.sistema14.com`, con el dispositivo del propietario y sin modificar la base de datos manualmente:

1. Abrir la pantalla de acceso y dejar vacío el identificador para una Passkey detectable, o escribir la cuenta para una credencial no detectable.
2. Seleccionar **Iniciar sesión con Passkey** y completar la verificación del dispositivo.
3. Confirmar que se abre el panel y que `ultimo_uso`/`sign_count` sólo cambian después de una firma válida.
4. Desde **Seguridad**, registrar una segunda Passkey, cerrar sesión y autenticar con ella.
5. Probar cancelación, intento repetido y eliminación desde una sesión con más de diez minutos para confirmar los mensajes seguros.

El ejecutable `php tests/webauthn_compatibility.php` realiza comprobaciones no destructivas de configuración, esquema, hashes, claves públicas y opciones generadas.
