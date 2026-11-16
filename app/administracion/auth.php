<?php
/**
 * Autenticación por token.
 *
 * El acceso al panel solo es posible con un token activo almacenado en SQLite
 * (tabla "tokens"). El token puede llegar por:
 *   - URL:    index.php?token=rat_xxx   (dirección de acceso restaurable)
 *   - Cookie: sublilaservinyl_admin_token       (persistencia entre visitas)
 *   - Sesión: PHP (mientras dure la sesión)
 *
 * Si no hay token válido se redirige a la app principal (../index.html).
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';

const COOKIE_TOKEN   = 'sublilaservinyl_admin_token';
const COOKIE_DIAS    = 1; // 24 horas
const SESSION_NOMBRE = 'sublilaservinyl_admin';

function sesion_iniciar(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(SESSION_NOMBRE);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_set_cookie_params([
        'lifetime' => SESSION_HORAS * 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => es_https(),
    ]);
    ini_set('session.gc_maxlifetime', (string) (SESSION_HORAS * 3600));
    session_start();

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function cookie_token_escribir(string $token): void
{
    setcookie(COOKIE_TOKEN, $token, [
        'expires'  => time() + (COOKIE_DIAS * 86400),
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => es_https(),
    ]);
    $_COOKIE[COOKIE_TOKEN] = $token;
}

function cookie_token_borrar(): void
{
    setcookie(COOKIE_TOKEN, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[COOKIE_TOKEN]);
}

/**
 * Establece la sesión autenticada a partir de una fila usuario+token.
 *
 * @param bool $recordar Si es false no se reescribe la cookie persistente
 *                       (se usa al restaurar desde la propia cookie para que
 *                       la caducidad total no se renueve en cada visita).
 */
function establecer_sesion(array $fila, bool $recordar = true): void
{
    sesion_iniciar();

    // Limpia una sesión previa de otro token antes de emitir la nueva
    if (!empty($_SESSION['auth']['session_hash'])) {
        db_sesion_borrar((string) $_SESSION['auth']['session_hash']);
    }

    $hash = hash('sha256', bin2hex(random_bytes(32)));

    db_sesion_crear((int) $fila['token_id'], $hash);
    db_token_marcar_acceso((int) $fila['token_id']);

    $_SESSION['auth'] = [
        'token_id'     => (int) $fila['token_id'],
        'session_hash' => $hash,
        'usuario_id'   => (int) $fila['id'],
        'nombre'       => $fila['nombre'],
        'rol'          => $fila['rol'],
    ];

    session_regenerate_id(true);
    if ($recordar) {
        cookie_token_escribir((string) $fila['token']);
    }
}

function cerrar_sesion(bool $borrarCookie = true): void
{
    sesion_iniciar();
    if (!empty($_SESSION['auth']['session_hash'])) {
        db_sesion_borrar((string) $_SESSION['auth']['session_hash']);
    }
    $_SESSION = [];
    if ($borrarCookie) {
        cookie_token_borrar();
    }
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/**
 * Resuelve el usuario autenticado de la petición actual.
 * Devuelve null si no hay token válido.
 */
function usuario_actual(): ?array
{
    static $cache = false;

    if ($cache !== false) {
        return $cache;
    }

    db(); // garantiza base de datos, tablas y primer token
    sesion_iniciar();

    // 1) Token explícito en la URL (permite restaurar/rotar la dirección de acceso)
    $tokenUrl = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
    if ($tokenUrl !== '' && preg_match('/^rat_[a-f0-9]{48}$/', $tokenUrl)) {
        $claveLimite = 'token_fallos:' . hash('sha256', ip_remota());
        if (db_limite_agotado($claveLimite, LIMITE_TOKEN_MAX, LIMITE_TOKEN_VENTANA)) {
            db_registrar_acceso(null, mb_substr($tokenUrl, 0, 80), 'bloqueado');
            registrar_log('ACCESO_BLOQUEADO', 'Demasiados intentos de token desde la misma IP', null);
            alertar_accesos_fallidos();
            http_response_code(429);
            header('Content-Type: text/html; charset=utf-8');
            header('Retry-After: ' . LIMITE_TOKEN_VENTANA);
            echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Demasiados intentos</title></head>'
                . '<body style="font-family:sans-serif;background:#0b1914;color:#e8e8ec;padding:40px">'
                . '<h1>429 · Demasiados intentos</h1>'
                . '<p>Se han detectado demasiados intentos de acceso fallidos desde tu conexión. '
                . 'Espera unos minutos y vuelve a intentarlo.</p></body></html>';
            exit;
        }

        $fila = db_usuario_por_token($tokenUrl);
        if ($fila) {
            establecer_sesion($fila);
            db_registrar_acceso($fila['nombre'], $tokenUrl, 'ok');
            registrar_log('ACCESO', 'Acceso mediante token en URL (' . ($fila['etiqueta'] ?: 'sin etiqueta') . ')', $fila['nombre']);
            $cache = $fila;
            return $cache;
        }

        db_registrar_acceso(null, mb_substr($tokenUrl, 0, 80), 'fallido');
        db_limite_consumir($claveLimite, LIMITE_TOKEN_MAX, LIMITE_TOKEN_VENTANA);
        registrar_log('ACCESO_FALLIDO', 'Token inexistente o revocado en URL', null);
        alertar_accesos_fallidos();
        cookie_token_borrar();
        $cache = null;
        return $cache;
    }

    // 2) Sesión PHP activa
    if (!empty($_SESSION['auth']['session_hash'])) {
        $fila = db_sesion_valida((string) $_SESSION['auth']['session_hash']);
        if ($fila) {
            $_SESSION['auth']['nombre'] = $fila['nombre'];
            $_SESSION['auth']['rol']    = $fila['rol'];
            $cache = $fila;
            return $cache;
        }
        cerrar_sesion(false);
    }

    // 3) Cookie persistente
    if (!empty($_COOKIE[COOKIE_TOKEN])) {
        $fila = db_usuario_por_token((string) $_COOKIE[COOKIE_TOKEN]);
        if ($fila) {
            establecer_sesion($fila, false);
            db_registrar_acceso($fila['nombre'], (string) $fila['token'], 'ok');
            registrar_log('ACCESO', 'Acceso restaurado desde cookie', $fila['nombre']);
            $cache = $fila;
            return $cache;
        }
        cookie_token_borrar();
    }

    $cache = null;
    return $cache;
}

/**
 * Exige autenticación. En páginas HTML redirige a la app; en la API responde 401.
 */
function require_auth(bool $esApi = false): array
{
    $usuario = usuario_actual();
    if ($usuario) {
        return $usuario;
    }

    if ($esApi) {
        json_out([
            'ok'       => false,
            'error'    => 'Token no válido o sesión expirada.',
            'redirect' => REDIRECT_SIN_TOKEN,
        ], 401);
    }

    redirigir(REDIRECT_SIN_TOKEN);
}

/** Exige que el usuario tenga uno de los roles indicados. */
function require_rol(array $roles, bool $esApi = false): array
{
    $usuario = require_auth($esApi);
    if (!in_array((string) $usuario['rol'], $roles, true)) {
        if ($esApi) {
            json_out(['ok' => false, 'error' => 'Tu rol no permite realizar esta acción.'], 403);
        }
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Sin permiso</title></head>'
            . '<body style="font-family:sans-serif;background:#0b1914;color:#e8e8ec;padding:40px">'
            . '<h1>403 · Sin permiso</h1><p>Tu rol (<strong>' . e((string) $usuario['rol']) . '</strong>) no permite ver esta sección.</p>'
            . '<p><a style="color:#C5A15B" href="index.php">Volver al panel</a></p></body></html>';
        exit;
    }
    return $usuario;
}

function csrf_token(): string
{
    sesion_iniciar();
    return (string) ($_SESSION['csrf'] ?? '');
}

function csrf_validar(): bool
{
    sesion_iniciar();
    $enviado = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    return is_string($enviado) && hash_equals((string) ($_SESSION['csrf'] ?? ''), $enviado);
}

function es_admin(array $usuario): bool
{
    return (string) $usuario['rol'] === 'admin';
}

function puede_editar_archivos(array $usuario): bool
{
    return in_array((string) $usuario['rol'], ['admin', 'editor'], true);
}

function nombre_rol(string $rol): string
{
    return ROLES[$rol] ?? $rol;
}
