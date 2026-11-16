# Panel de administración · Sublilaservinyl

Módulo PHP del repositorio para administrar **usuarios con acceso por token** y los
**archivos de `proyectos/`** (crear carpetas, subir imágenes, renombrar y borrar).

Comparte la identidad visual de la app (`app/index.html`): fondo oscuro, acento oro
champán, tipografía editorial.

**Requisitos:** PHP 8.1+ con `pdo_sqlite` y `fileinfo` (incluidos en Laragon).

---

## 1. Primer acceso

La base de datos y el primer token se generan **la primera vez que se abre el panel**:

1. Abre en el navegador:
   ```
   http://localhost/sublilaservinyl/app/administracion/
   ```
   Sin token, redirige a la app (`app/index.html`) y, de paso, crea la base SQLite.

2. Abre el archivo generado y copia el enlace:
   ```
   app/administracion/data/primer-acceso.txt
   ```

3. Entra con la dirección:
   ```
   http://localhost/sublilaservinyl/app/administracion/index.php?token=rat_...
   ```

> El token queda guardado en una cookie de este navegador durante **24 horas**, igual
> que la sesión del panel. Pasado ese tiempo hay que volver a entrar con el enlace.

También puedes consultar o generar tokens por consola:

```bash
php app/administracion/cli.php listar
php app/administracion/cli.php nuevo-token 1 "Acceso laptop"
php app/administracion/cli.php nuevo-usuario "Byron" admin
```

---

## 2. Funcionalidades

### Acceso por token

- Solo se entra con un token activo de la tabla `tokens` (SQLite).
- El token puede viajar en la URL (`?token=...`), por cookie o por sesión PHP.
- **Sin token válido → redirección a `app/index.html`.**
- La dirección de acceso se puede **restaurar** desde *Usuarios y accesos*:
  copiar el enlace de un token activo, **regenerarlo** (crea otro y anula el anterior)
  o **revocarlo** (bloquea el acceso y cierra sus sesiones de inmediato).
- No es posible revocar el token con el que estás conectado (evita bloquearte).
- Intentos correctos y fallidos quedan en la tabla `accesos`.

### Usuarios

| Rol | Permisos |
|---|---|
| `admin` | Todo: usuarios, tokens, archivos y bitácora |
| `editor` | Ver el panel y gestionar archivos (subir, crear, renombrar, borrar) |
| `lectura` | Solo consultar el panel y los archivos |

- Crear / editar / desactivar / eliminar usuarios (con token automático al crear).
- Desactivar un usuario revoca todos sus tokens.
- Siempre debe quedar al menos un administrador activo.
- No puedes eliminar tu propio usuario ni quitarte el rol de administrador.

### Archivos de `proyectos/`

- Navegación por carpetas y subcarpetas con portada, migas de pan e historial del navegador.
- En la raíz de `/proyectos` las carpetas se listan en **orden inverso** (la última primero).
- **Solo se listan y administran imágenes y videos.** Los `.md`, scripts, textos y
  demás archivos no se muestran, no se pueden renombrar ni borrar.
- **No se permite subir nada en la raíz de `/proyectos`**: hay que entrar primero en
  una carpeta de proyecto o subcarpeta.
- **Subir archivos** por selector o arrastrando y soltando (varios a la vez).
  Formatos permitidos: `jpg, jpeg, png, svg, heic, heif` (fotos de iPhone) y `mp4`
  (máx. 25 MB por archivo).
- **Fotos de la cámara del iPhone**: el formato se deduce del **contenido real**,
  no del nombre. Se aceptan nombres sin extensión o genéricos (`image`, `blob`) y
  archivos HEIC renombrados como `.jpg` (se guardan como `.heic`). El lector de
  cajas ISO-BMFF localiza `ftyp` aunque haya cajas `free`/`wide` delante o use
  marcas HEIF (`mif1`, `msf1`, `heic`, `heix`, `hevc`, `heim`, `heis`, `hevm`, `hevs`).
- Validación en servidor en cadena:
  1. Extensión aparente (si la hay) y **detección real del formato por firma**.
  2. MIME con `finfo` como dato **orientativo**: se aceptan variantes de iOS
     (`image/heic`, `image/heif`, sus secuencias y `application/octet-stream`);
     solo se bloquean tipos claramente ejecutables.
  3. Magic bytes / caja `ftyp` (JPEG, PNG, SVG, HEIC/HEIF y MP4).
  4. Estructura real: `getimagesize()` en JPG/PNG, caja `ftyp` + `meta`/`mdat`
     en HEIC/HEIF y MP4.
  5. Escaneo anti-código en JPG/PNG/SVG. Los contenedores HEIC/HEIF y MP4 no se
     escanean como texto porque son binarios y pueden dar falsos positivos.
- **SVG saneado**: se eliminan `<script>`, `foreignObject`, `iframe`, `object`,
  `embed`, atributos `on*` y enlaces `javascript:` antes de guardarlo.
- Visor para **ampliar imágenes y reproducir videos** (anterior/siguiente, teclado
  y `Esc`) con controles visibles para **renombrar**, **borrar** y copiar la URL.
- Botón para **copiar imágenes al portapapeles** (una o varias a la vez), con barra
  inferior de selección; en videos se copia la URL.
- Crear carpetas, renombrar archivos/carpetas y borrar archivos.
- Las carpetas solo se borran si están vacías (protección contra pérdidas).
- No se puede cambiar la extensión de un archivo al renombrar.
- Toda ruta se valida con `realpath` para impedir *path traversal*.
- Los archivos subidos aparecen de inmediato en la app de referencias.

### Mini Google Analytics

Analítica propia, sin servicios externos ni cookies de terceros:

- La web pública (`app/index.html`) envía una visita anónima a
  `app/administracion/track.php` (con `sendBeacon`) al cargar y al cambiar de
  carpeta (`hashchange`).
- Se guardan ruta, referente, idioma, tamaño de pantalla, dispositivo,
  navegador, sistema y un identificador anónimo de visitante (`localStorage`).
  La IP solo se almacena como **hash** y las visitas se purgan a los 365 días.
- **Panel** muestra un resumen compacto (web: hoy, 7 y 30 días, total, rutas
  principales; panel: páginas vistas hoy y usuarios activos).
- **Estadísticas** (nueva opción del menú) muestra visitas y únicos por periodo,
  gráfico de 14 días, páginas/carpetas más vistas, orígenes del tráfico,
  dispositivos, navegadores, sistemas y últimas visitas.
- **Seguimiento del panel**: cada página de administración registra una visita
  en el ámbito `admin` al renderizarse, asociada al **usuario** (`admin_<id>`) y
  al **token** utilizado. Estadísticas muestra:
  - páginas del panel hoy / 7 / 30 días y total, con gráfico de 14 días;
  - secciones más usadas y usuarios con más actividad;
  - tabla de actividad por usuario (páginas, tokens activos, accesos de login y
    última actividad);
  - tabla de uso de tokens (accesos de login, páginas vistas, creado, último
    acceso y estado);
  - últimas páginas del panel con usuario, dispositivo y navegador.
- La analítica del panel se escribe en servidor (sin peticiones adicionales) y
  nunca interrumpe la carga: cualquier fallo se ignora y se anota en el
  `error_log`.

### Log de actividad

Cada acción se anota en `app/administracion/logs/actividad-AAAA-MM.log`:

```
2026-10-31 11:26:31   sistema         INSTALACION         Base de datos creada y token inicial generado   127.0.0.1
2026-10-31 11:27:06   Sublilaservinyl  USUARIO_CREADO      Usuario #2 "Prueba QA" (editor)                 127.0.0.1
```

- Se registran accesos, creación/edición/borrado de usuarios, tokens, carpetas,
  renombrados, eliminaciones, subidas y descargas del log.
- **Subidas rechazadas**: además de la línea en el log general, se escribe un
  diagnóstico completo en `logs/subidas-rechazadas-AAAA-MM.log` con destino,
  nombre, MIME del cliente, MIME real (`finfo`), extensión detectada, tamaño,
  primeros 24 bytes en hexadecimal y el motivo exacto. Si el validador
  anti-script bloquea el archivo, el motivo incluye el patrón encontrado, el
  byte donde aparece, un contexto legible y los bytes en hexadecimal.
- Visor en **Actividad**, con selección por mes y descarga del archivo.
- Retención automática: 180 días.

---

## 3. Estructura

```
app/administracion/
├── index.php           Panel principal (resumen, enlace propio, mini analítica)
├── estadisticas.php    Mini Google Analytics de la web pública
├── usuarios.php        Usuarios y direcciones de acceso (solo admin)
├── archivos.php        Gestor de /proyectos
├── actividad.php       Visor del log mensual
├── log_descargar.php   Descarga protegida de un log
├── api.php             API interna (JSON) de todas las acciones
├── track.php           Endpoint público de analítica (sin token)
├── analitica.php       Registro y consultas de visitas
├── auth.php            Validación de token, sesión, CSRF y roles
├── db.php              SQLite/PDO: esquema, consultas, primer token
├── logs.php            Escritura/lectura/limpieza de logs
├── archivos_lib.php    Listado y operaciones de archivos seguras
├── config.php          Rutas, constantes y utilidades
├── layout.php          Cabecera/pie comunes del panel
├── cli.php             Recuperación por consola (solo CLI)
├── assets/
│   ├── admin.css
│   └── admin.js
├── data/               administracion.sqlite + primer-acceso.txt (no versionado)
└── logs/               actividad y subidas rechazadas (no versionado)
```

### Tablas SQLite

- `usuarios`: nombre, correo, rol, activo, fechas.
- `tokens`: token único, usuario, etiqueta, activo, último acceso, contador.
- `sesiones`: sesiones emitidas al validar un token (24 h), con IP y user-agent.
- `accesos`: bitácora de intentos correctos y fallidos.
- `visitas`: analítica (ruta, referente, dispositivo, navegador, sistema,
  idioma, pantalla, hash de IP y fecha). La columna `ambito` distingue `web`
  (público) de `admin` (panel); en el panel se guarda además `token_id` y el
  visitante es el usuario autenticado.

> Los tokens se guardan en claro a propósito: es lo que permite **reconstruir la
> dirección de acceso** desde el panel. Protege el archivo
> `data/administracion.sqlite` (ya bloqueado por `.htaccess` y excluido de git).

---

## 4. Seguridad aplicada

- Autorización por token en cada petición + sesión en servidor con caducidad de **24 horas**.
- **CSRF** obligatorio (`X-CSRF-Token`) en todas las acciones POST.
- Contraseñas de sesión no se usan: el token es el único secreto.
- Cookies `HttpOnly` + `SameSite=Lax` (y `Secure` bajo HTTPS).
- Nombres de archivo saneados, sin sobrescrituras ni cambios de extensión.
- Límite de tamaño, MIME real, magic bytes, estructura del contenedor y escaneo anti-script.
- **SVG se sanea** (sin scripts ni eventos) y se guarda ya limpio; el resto de
  archivos (PHP, texto, etc.) se rechazan.
- MP4 se valida por caja `ftyp` y presencia de `moov`/`mdat`.
- Subidas restringidas a carpetas internas: la raíz de `/proyectos` no acepta archivos.
- `data/` y `logs/` bloqueados por `.htaccess` y fuera de git.
- Redirección a la app si el token no es válido, está revocado o el usuario está inactivo.

### Limitaciones conocidas

- El panel asume Apache/Laragon (usa `.htaccess`). Con `php -S` los directorios
  `data/` y `logs/` serían accesibles por URL: no los expongas así en producción.
- No hay límite de intentos por IP; si el panel se publica en internet, añade
  *rate limiting* en el servidor.
