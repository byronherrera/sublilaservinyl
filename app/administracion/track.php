<?php
/**
 * Endpoint público de analítica (mini Google Analytics).
 *
 * Recibe una visita anónima desde app/index.html mediante sendBeacon/fetch.
 * No requiere token: solo escribe estadísticas y nunca devuelve datos.
 */
declare(strict_types=1);

require_once __DIR__ . '/analitica.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo '{"ok":false}';
    exit;
}

// Solo se aceptan peticiones del mismo sitio cuando el navegador envía Origin.
$origen = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origen !== '') {
    $hostOrigen = strtolower((string) parse_url($origen, PHP_URL_HOST));
    $hostActual = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $hostActual = preg_replace('/:\d+$/', '', $hostActual) ?? $hostActual;
    if ($hostOrigen !== '' && $hostActual !== '' && $hostOrigen !== $hostActual) {
        http_response_code(403);
        echo '{"ok":false}';
        exit;
    }
}

// Límite por IP real de conexión: evita que un solo cliente llene la analítica.
$claveLimite = 'track:' . hash('sha256', ip_remota());
if (!db_limite_consumir($claveLimite, LIMITE_TRACK_MAX, LIMITE_TRACK_VENTANA)) {
    header('Retry-After: ' . LIMITE_TRACK_VENTANA);
    http_response_code(429);
    echo '{"ok":false}';
    exit;
}

$cuerpo = file_get_contents('php://input');
$datos = [];
if (is_string($cuerpo) && $cuerpo !== '') {
    if (strlen($cuerpo) > 8192) {
        http_response_code(413);
        echo '{"ok":false}';
        exit;
    }
    $json = json_decode($cuerpo, true);
    if (is_array($json)) {
        $datos = $json;
    }
}
if (!$datos && $_POST) {
    $datos = $_POST;
}

if (!$datos) {
    http_response_code(422);
    echo '{"ok":false}';
    exit;
}

try {
    $guardada = analitica_registrar($datos);
} catch (Throwable $e) {
    error_log('Analítica: ' . $e->getMessage());
    http_response_code(500);
    echo '{"ok":false}';
    exit;
}

echo json_encode(['ok' => true, 'guardada' => $guardada], JSON_UNESCAPED_UNICODE);
