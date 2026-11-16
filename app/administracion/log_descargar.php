<?php
/**
 * Descarga protegida de un archivo de log mensual.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/auth.php';

$usuario = require_auth();

$archivo = isset($_GET['archivo']) ? basename((string) $_GET['archivo']) : '';
if (!preg_match('/^actividad-\d{4}-\d{2}\.log$/', $archivo)) {
    http_response_code(400);
    echo 'Archivo no válido.';
    exit;
}

$ruta = LOGS_DIR . DIRECTORY_SEPARATOR . $archivo;
if (!is_file($ruta)) {
    http_response_code(404);
    echo 'El archivo no existe.';
    exit;
}

registrar_log('LOG_DESCARGADO', $archivo, (string) $usuario['nombre']);

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $archivo . '"');
header('Content-Length: ' . (string) filesize($ruta));
header('Cache-Control: no-store');
readfile($ruta);
