<?php
/**
 * Script de recuperación por consola (solo CLI).
 *
 * Uso:
 *   php app/administracion/cli.php listar
 *   php app/administracion/cli.php tokens <usuario_id>
 *   php app/administracion/cli.php nuevo-token <usuario_id> ["etiqueta"]
 *   php app/administracion/cli.php nuevo-usuario "Nombre" [admin|editor|lectura]
 *   php app/administracion/cli.php url <token>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo puede ejecutarse por consola.');
}

// Base URL aproximada para reconstruir enlaces desde consola.
// Puedes ajustar el host con la variable de entorno ADMIN_HOST.
$repoCarpeta = basename(dirname(dirname(__DIR__)));
$_SERVER['HTTP_HOST']       = getenv('ADMIN_HOST') ?: 'localhost';
$_SERVER['HTTPS']           = getenv('ADMIN_HTTPS') === 'on' ? 'on' : 'off';
$_SERVER['DOCUMENT_ROOT']   = getenv('ADMIN_DOCROOT') ?: dirname(__DIR__, 3);
$_SERVER['SCRIPT_NAME']     = '/' . ($repoCarpeta !== '' ? $repoCarpeta . '/' : '') . 'app/administracion/index.php';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';

$argv = $_SERVER['argv'] ?? [];
$comando = $argv[1] ?? 'listar';

function linea(string $texto = ''): void
{
    echo $texto . PHP_EOL;
}

switch ($comando) {
    case 'listar':
        linea('Usuarios registrados:');
        foreach (db_usuarios_listar() as $u) {
            linea(sprintf(
                '  #%d  %-28s %-10s %-9s tokens activos: %d',
                $u['id'],
                $u['nombre'],
                $u['rol'],
                ((int) $u['activo'] === 1 ? 'activo' : 'inactivo'),
                (int) $u['tokens_activos']
            ));
        }
        linea();
        linea('Tokens activos:');
        foreach (db_tokens_listar() as $t) {
            if ((int) $t['activo'] !== 1) {
                continue;
            }
            linea(sprintf('  %s  →  %s (%s)', $t['token'], $t['usuario'], $t['etiqueta'] ?: 'sin etiqueta'));
        }
        break;

    case 'tokens':
        $id = (int) ($argv[2] ?? 0);
        foreach (db_tokens_listar() as $t) {
            if ((int) $t['usuario_id'] === $id) {
                linea(sprintf('#%d %s [%s] %s', $t['id'], $t['token'], ((int) $t['activo'] === 1 ? 'activo' : 'revocado'), $t['etiqueta']));
            }
        }
        break;

    case 'nuevo-token':
        $id = (int) ($argv[2] ?? 0);
        $usuario = db_usuario_por_id($id);
        if (!$usuario) {
            linea('El usuario no existe.');
            exit(1);
        }
        $etiqueta = $argv[3] ?? 'Recuperación por consola';
        $token = db_token_crear($id, $etiqueta);
        registrar_log('TOKEN_CREADO', 'Token generado por consola para "' . $usuario['nombre'] . '"', 'cli');
        linea('Token:  ' . $token['token']);
        linea('Ruta:   app/administracion/index.php?token=' . $token['token']);
        break;

    case 'nuevo-usuario':
        $nombre = $argv[2] ?? '';
        $rol = $argv[3] ?? 'editor';
        if (mb_strlen($nombre) < 2 || !isset(ROLES[$rol])) {
            linea('Uso: php cli.php nuevo-usuario "Nombre" [admin|editor|lectura]');
            exit(1);
        }
        $stmt = db()->prepare(
            'INSERT INTO usuarios (nombre, email, rol, activo, creado_en, actualizado_en)
             VALUES (:n, "", :r, 1, :a, :a)'
        );
        $stmt->execute([':n' => $nombre, ':r' => $rol, ':a' => ahora()]);
        $id = (int) db()->lastInsertId();
        $token = db_token_crear($id, 'Acceso principal');
        registrar_log('USUARIO_CREADO', 'Usuario "' . $nombre . '" creado por consola', 'cli');
        linea('Usuario #' . $id . ' creado con rol ' . $rol);
        linea('Token:  ' . $token['token']);
        break;

    case 'url':
        $token = $argv[2] ?? '';
        if (!preg_match('/^rat_[a-f0-9]{48}$/', $token)) {
            linea('Token no válido.');
            exit(1);
        }
        linea(enlace_acceso($token));
        break;

    default:
        linea('Comandos: listar | tokens <usuario_id> | nuevo-token <usuario_id> ["etiqueta"] | nuevo-usuario "Nombre" [rol] | url <token>');
}
