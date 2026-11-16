<?php
/**
 * API interna del panel de administración.
 *
 * Todas las acciones se invocan por POST con el campo "action".
 * La autenticación es por token y cada petición exige el token CSRF.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/archivos_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

if (!csrf_validar()) {
    json_out(['ok' => false, 'error' => 'La sesión de seguridad ha caducado. Recarga la página.'], 419);
}

$usuario = require_auth(true);

// Entrada: JSON o formulario multipart
$esJson = str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json');
$largoDeclarado = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($esJson && $largoDeclarado > LIMITE_API_BYTES) {
    json_out(['ok' => false, 'error' => 'La petición es demasiado grande.'], 413);
}
$entrada = $_POST;
$cuerpo = $esJson ? file_get_contents('php://input', false, null, 0, LIMITE_API_BYTES + 1) : '';
if ($esJson && is_string($cuerpo) && strlen($cuerpo) > LIMITE_API_BYTES) {
    json_out(['ok' => false, 'error' => 'La petición es demasiado grande.'], 413);
}
if ($esJson && is_string($cuerpo) && $cuerpo !== '') {
    $json = json_decode($cuerpo, true);
    if (is_array($json)) {
        $entrada = array_merge($entrada, $json);
    }
}

$accion = (string) ($entrada['action'] ?? '');

/** Helpers locales ------------------------------------------------- */

function texto(array $entrada, string $clave, int $max = 200): string
{
    $valor = trim((string) ($entrada[$clave] ?? ''));
    $valor = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $valor) ?? '';
    return mb_substr($valor, 0, $max, 'UTF-8');
}

function entero(array $entrada, string $clave): int
{
    return (int) ($entrada[$clave] ?? 0);
}

function log_actor(array $usuario): string
{
    return (string) $usuario['nombre'];
}

/** Cuenta administradores activos (para no dejar el panel sin acceso). */
function administradores_activos(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1")->fetchColumn();
}

try {
    switch ($accion) {

        /* ----------------------------------------------------------
         * Usuarios
         * ---------------------------------------------------------- */
        case 'usuarios_listar': {
            if (!es_admin($usuario)) {
                json_out(['ok' => false, 'error' => 'Solo un administrador puede ver los usuarios.'], 403);
            }
            json_out(['ok' => true, 'usuarios' => db_usuarios_listar()]);
        }

        case 'usuario_crear': {
            if (!es_admin($usuario)) {
                json_out(['ok' => false, 'error' => 'Solo un administrador puede crear usuarios.'], 403);
            }

            $nombre = texto($entrada, 'nombre', 80);
            $email  = texto($entrada, 'email', 120);
            $rol    = texto($entrada, 'rol', 20);
            $activo = entero($entrada, 'activo') ? 1 : 0;

            if (mb_strlen($nombre) < 2) {
                json_out(['ok' => false, 'error' => 'El nombre debe tener al menos 2 caracteres.'], 422);
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                json_out(['ok' => false, 'error' => 'El correo electrónico no es válido.'], 422);
            }
            if (!isset(ROLES[$rol])) {
                json_out(['ok' => false, 'error' => 'Rol no válido.'], 422);
            }

            $stmt = db()->prepare(
                'INSERT INTO usuarios (nombre, email, rol, activo, creado_en, actualizado_en)
                 VALUES (:nombre, :email, :rol, :activo, :ahora, :ahora)'
            );
            $stmt->execute([
                ':nombre' => $nombre,
                ':email'  => $email,
                ':rol'    => $rol,
                ':activo' => $activo,
                ':ahora'  => ahora(),
            ]);
            $id = (int) db()->lastInsertId();

            $token = db_token_crear($id, 'Acceso principal');

            registrar_log('USUARIO_CREADO', 'Usuario #' . $id . ' "' . $nombre . '" (' . $rol . ')', log_actor($usuario));

            json_out([
                'ok'      => true,
                'id'      => $id,
                'token'   => $token['token'],
                'enlace'  => $token['enlace'],
                'usuario' => $nombre,
            ]);
        }

        case 'usuario_actualizar': {
            if (!es_admin($usuario)) {
                json_out(['ok' => false, 'error' => 'Solo un administrador puede editar usuarios.'], 403);
            }

            $id = entero($entrada, 'id');
            $actual = db_usuario_por_id($id);
            if (!$actual) {
                json_out(['ok' => false, 'error' => 'El usuario no existe.'], 404);
            }

            $nombre = texto($entrada, 'nombre', 80);
            $email  = texto($entrada, 'email', 120);
            $rol    = texto($entrada, 'rol', 20);
            $activo = entero($entrada, 'activo') ? 1 : 0;

            if (mb_strlen($nombre) < 2) {
                json_out(['ok' => false, 'error' => 'El nombre debe tener al menos 2 caracteres.'], 422);
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                json_out(['ok' => false, 'error' => 'El correo electrónico no es válido.'], 422);
            }
            if (!isset(ROLES[$rol])) {
                json_out(['ok' => false, 'error' => 'Rol no válido.'], 422);
            }

            if ($id === (int) $usuario['id'] && ($activo === 0 || $rol !== 'admin')) {
                json_out(['ok' => false, 'error' => 'No puedes quitarte tu propio acceso de administrador.'], 422);
            }
            if ((int) $actual['rol'] === 'admin' && (int) $actual['activo'] === 1
                && ($rol !== 'admin' || $activo === 0) && administradores_activos() <= 1) {
                json_out(['ok' => false, 'error' => 'Debe existir al menos un administrador activo.'], 422);
            }

            $stmt = db()->prepare(
                'UPDATE usuarios
                    SET nombre = :nombre, email = :email, rol = :rol, activo = :activo, actualizado_en = :ahora
                  WHERE id = :id'
            );
            $stmt->execute([
                ':nombre' => $nombre,
                ':email'  => $email,
                ':rol'    => $rol,
                ':activo' => $activo,
                ':ahora'  => ahora(),
                ':id'     => $id,
            ]);

            if ($activo === 0) {
                db_tokens_revocar_usuario($id);
            }

            registrar_log(
                'USUARIO_ACTUALIZADO',
                'Usuario #' . $id . ' "' . $nombre . '" → rol ' . $rol . ', ' . ($activo ? 'activo' : 'inactivo (tokens revocados)'),
                log_actor($usuario)
            );

            json_out(['ok' => true, 'id' => $id]);
        }

        case 'usuario_eliminar': {
            if (!es_admin($usuario)) {
                json_out(['ok' => false, 'error' => 'Solo un administrador puede eliminar usuarios.'], 403);
            }

            $id = entero($entrada, 'id');
            $objetivo = db_usuario_por_id($id);
            if (!$objetivo) {
                json_out(['ok' => false, 'error' => 'El usuario no existe.'], 404);
            }
            if ($id === (int) $usuario['id']) {
                json_out(['ok' => false, 'error' => 'No puedes eliminar tu propio usuario.'], 422);
            }
            if ((int) $objetivo['rol'] === 'admin' && (int) $objetivo['activo'] === 1 && administradores_activos() <= 1) {
                json_out(['ok' => false, 'error' => 'Debe existir al menos un administrador activo.'], 422);
            }

            db()->prepare('DELETE FROM usuarios WHERE id = :id')->execute([':id' => $id]);

            registrar_log('USUARIO_ELIMINADO', 'Usuario #' . $id . ' "' . $objetivo['nombre'] . '" y sus tokens', log_actor($usuario));
            json_out(['ok' => true]);
        }

        /* ----------------------------------------------------------
         * Tokens / direcciones de acceso
         * ---------------------------------------------------------- */
        case 'tokens_listar': {
            if (!es_admin($usuario)) {
                json_out(['ok' => false, 'error' => 'Solo un administrador puede ver los tokens.'], 403);
            }
            $tokens = db_tokens_listar();
            foreach ($tokens as &$t) {
                $t['enlace'] = enlace_acceso((string) $t['token']);
            }
            unset($t);
            json_out(['ok' => true, 'tokens' => $tokens]);
        }

        case 'token_crear': {
            if (!es_admin($usuario)) {
                json_out(['ok' => false, 'error' => 'Solo un administrador puede generar tokens.'], 403);
            }
            $usuarioId = entero($entrada, 'usuario_id');
            $objetivo = db_usuario_por_id($usuarioId);
            if (!$objetivo) {
                json_out(['ok' => false, 'error' => 'El usuario no existe.'], 404);
            }

            $etiqueta = texto($entrada, 'etiqueta', 60) ?: 'Acceso ' . date('d/m/Y H:i');
            $token = db_token_crear($usuarioId, $etiqueta);
            registrar_log('TOKEN_CREADO', 'Token #' . $token['id'] . ' para "' . $objetivo['nombre'] . '" (' . $etiqueta . ')', log_actor($usuario));

            json_out([
                'ok'      => true,
                'id'      => $token['id'],
                'token'   => $token['token'],
                'enlace'  => $token['enlace'],
                'usuario' => $objetivo['nombre'],
            ]);
        }

        case 'token_regenerar': {
            if (!es_admin($usuario)) {
                json_out(['ok' => false, 'error' => 'Solo un administrador puede regenerar tokens.'], 403);
            }
            $id = entero($entrada, 'id');
            $token = db_token_por_id($id);
            if (!$token) {
                json_out(['ok' => false, 'error' => 'El token no existe.'], 404);
            }
            $objetivo = db_usuario_por_id((int) $token['usuario_id']);
            if (!$objetivo) {
                json_out(['ok' => false, 'error' => 'El usuario del token no existe.'], 404);
            }

            db_token_revocar($id);
            $nuevo = db_token_crear((int) $token['usuario_id'], $token['etiqueta'] ?: 'Acceso principal');

            registrar_log(
                'TOKEN_REGENERADO',
                'Token #' . $id . ' revocado y reemplazado por #' . $nuevo['id'] . ' para "' . $objetivo['nombre'] . '"',
                log_actor($usuario)
            );

            json_out(['ok' => true, 'id' => $nuevo['id'], 'enlace' => $nuevo['enlace'], 'usuario' => $objetivo['nombre']]);
        }

        case 'token_revocar': {
            if (!es_admin($usuario)) {
                json_out(['ok' => false, 'error' => 'Solo un administrador puede revocar tokens.'], 403);
            }
            $id = entero($entrada, 'id');
            $token = db_token_por_id($id);
            if (!$token) {
                json_out(['ok' => false, 'error' => 'El token no existe.'], 404);
            }
            if ($id === (int) ($usuario['token_id'] ?? 0)) {
                json_out([
                    'ok'    => false,
                    'error' => 'No puedes revocar el token con el que estás conectado. Usa "Regenerar" para obtener una dirección nueva.',
                ], 422);
            }
            db_token_revocar($id);
            registrar_log('TOKEN_REVOCADO', 'Token #' . $id . ' revocado', log_actor($usuario));
            json_out(['ok' => true]);
        }

        /* ----------------------------------------------------------
         * Archivos (/proyectos)
         * ---------------------------------------------------------- */
        case 'fs_listar': {
            $ruta = texto($entrada, 'ruta', 1024);
            json_out(['ok' => true] + fs_listar($ruta));
        }

        case 'fs_crear_carpeta': {
            if (!puede_editar_archivos($usuario)) {
                json_out(['ok' => false, 'error' => 'Tu rol es de solo lectura.'], 403);
            }
            $ruta = texto($entrada, 'ruta', 1024);
            $nombre = texto($entrada, 'nombre', 150);
            $creada = fs_crear_carpeta($ruta, $nombre);

            registrar_log('CARPETA_CREADA', $creada['ruta'], log_actor($usuario));
            json_out(['ok' => true, 'carpeta' => $creada]);
        }

        case 'fs_renombrar': {
            if (!puede_editar_archivos($usuario)) {
                json_out(['ok' => false, 'error' => 'Tu rol es de solo lectura.'], 403);
            }
            $ruta = texto($entrada, 'ruta', 1024);
            $nombre = texto($entrada, 'nombre', 150);
            $resultado = fs_renombrar($ruta, $nombre);

            registrar_log(
                'RENOMBRADO',
                $ruta . ' → ' . $resultado['ruta'] . (($resultado['esCarpeta'] ?? false) ? ' (carpeta)' : ''),
                log_actor($usuario)
            );
            json_out(['ok' => true, 'elemento' => $resultado]);
        }

        case 'fs_eliminar': {
            if (!puede_editar_archivos($usuario)) {
                json_out(['ok' => false, 'error' => 'Tu rol es de solo lectura.'], 403);
            }
            $ruta = texto($entrada, 'ruta', 1024);
            $resultado = fs_eliminar($ruta);

            registrar_log('ELIMINADO_' . strtoupper($resultado['tipo']), $ruta, log_actor($usuario));
            json_out(['ok' => true, 'tipo' => $resultado['tipo']]);
        }

        case 'fs_subir': {
            if (!puede_editar_archivos($usuario)) {
                json_out(['ok' => false, 'error' => 'Tu rol es de solo lectura.'], 403);
            }
            $ruta = texto($entrada, 'ruta', 1024);
            if (!isset($_FILES['archivo'])) {
                json_out(['ok' => false, 'error' => 'No se recibió ningún archivo.'], 422);
            }
            $subido = null;
            try {
                $subido = fs_subir_media($ruta, $_FILES['archivo']);
            } catch (RuntimeException $e) {
                $archivo = $_FILES['archivo'];
                $tmp = (string) ($archivo['tmp_name'] ?? '');
                $mimeReal = '';
                $extDetectada = 'desconocida';
                $cabecera = '';
                if ($tmp !== '' && is_file($tmp)) {
                    if (class_exists('finfo')) {
                        try {
                            $mimeReal = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
                        } catch (Throwable $err) {
                            $mimeReal = 'error: ' . $err->getMessage();
                        }
                    }
                    $extDetectada = extension_detectada($tmp) ?? 'desconocida';
                    $cabecera = bin2hex((string) file_get_contents($tmp, false, null, 0, 24));
                }

                $detalle = 'destino=' . ($ruta !== '' ? $ruta : '(raíz)')
                    . ' · archivo=' . (string) ($archivo['name'] ?? 'sin-nombre')
                    . ' · mime_cliente=' . (string) ($archivo['type'] ?? 'sin-tipo')
                    . ' · mime_real=' . ($mimeReal !== '' ? $mimeReal : 'no-detectado')
                    . ' · ext_detectada=' . $extDetectada
                    . ' · bytes=' . (int) ($archivo['size'] ?? 0)
                    . ' · cabecera_hex=' . ($cabecera !== '' ? $cabecera : 'vacía')
                    . ' · motivo=' . $e->getMessage();

                registrar_log('SUBIDA_RECHAZADA', $detalle, log_actor($usuario));
                throw $e;
            }

            registrar_log(
                ($subido['tipo'] ?? 'imagen') === 'video' ? 'VIDEO_SUBIDO' : 'IMAGEN_SUBIDA',
                $subido['ruta'] . ' (' . $subido['tamano'] . ')',
                log_actor($usuario)
            );
            json_out(['ok' => true, 'archivo' => $subido]);
        }

        /* ----------------------------------------------------------
         * Sesión y mantenimiento
         * ---------------------------------------------------------- */
        case 'primer_acceso_eliminar': {
            if (!es_admin($usuario)) {
                json_out(['ok' => false, 'error' => 'Solo un administrador puede realizar esta acción.'], 403);
            }
            if (is_file(PRIMER_ACCESO_PATH) && !@unlink(PRIMER_ACCESO_PATH)) {
                json_out(['ok' => false, 'error' => 'No se pudo eliminar el archivo. Revisa permisos.'], 422);
            }
            registrar_log('MANTENIMIENTO', 'Archivo de primer acceso eliminado', log_actor($usuario));
            json_out(['ok' => true]);
        }

        case 'salir': {
            registrar_log('SESION_CERRADA', 'Cierre de sesión manual', log_actor($usuario));
            cerrar_sesion();
            json_out(['ok' => true]);
        }

        default:
            json_out(['ok' => false, 'error' => 'Acción no reconocida.'], 404);
    }
} catch (RuntimeException $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[administracion] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Error interno del servidor.'], 500);
}
