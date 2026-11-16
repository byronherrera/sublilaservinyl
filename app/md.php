<?php
/**
 * Endpoint seguro para servir archivos .md dentro de /proyectos.
 * Acepta rutas relativas al repositorio (ej. proyectos/07-sacon/README.md),
 * rutas web o rutas absolutas dentro de /proyectos, y bloquea cualquier
 * intento de salir de esa carpeta (path traversal).
 */

header('Content-Type: text/markdown; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header_remove('X-Powered-By');

$file = isset($_GET['file']) ? trim((string) $_GET['file']) : '';

if ($file === '') {
    http_response_code(400);
    echo 'Parámetro "file" requerido';
    exit;
}

if (strpos($file, '%') !== false) {
    $file = rawurldecode($file);
}

$file = str_replace('\\', '/', $file);

$repoRootReal = realpath(__DIR__ . '/..');
if ($repoRootReal === false) {
    http_response_code(500);
    echo 'No se pudo resolver la raíz del repositorio';
    exit;
}
$repoRootNorm = rtrim(str_replace('\\', '/', $repoRootReal), '/');

$proyectosReal = realpath($repoRootReal . DIRECTORY_SEPARATOR . 'proyectos');
if ($proyectosReal === false) {
    http_response_code(500);
    echo 'No se pudo resolver la carpeta de proyectos';
    exit;
}
$proyectosNorm = rtrim(str_replace('\\', '/', $proyectosReal), '/');

// Calcular prefijo URL base dinámico (ej. "/sublilaservinyl")
$scriptName = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '/app2/md.php';
$appUrlDir = str_replace('\\', '/', dirname($scriptName));
$repoUrlBase = str_replace('\\', '/', dirname($appUrlDir));
if ($repoUrlBase === '/' || $repoUrlBase === '.' || $repoUrlBase === '\\') {
    $repoUrlBase = '';
} else {
    $repoUrlBase = rtrim($repoUrlBase, '/');
}

// Construir candidatos de resolución dentro del repositorio
$candidates = [];

// 1) Si ya es una ruta absoluta del sistema (Windows "C:/..." o Unix "/home/...")
if (preg_match('/^[A-Za-z]:\//', $file) || strpos($file, $repoRootNorm . '/') === 0) {
    $candidates[] = $file;
}

// 2) Si empieza con el prefijo web del repositorio (ej. "/sublilaservinyl/proyectos/...")
if ($repoUrlBase !== '' && strpos($file, $repoUrlBase . '/') === 0) {
    $stripped = substr($file, strlen($repoUrlBase) + 1);
    $candidates[] = $repoRootNorm . '/' . ltrim($stripped, '/');
}

// 3) Ruta relativa respecto a la raíz del repositorio
$candidates[] = $repoRootNorm . '/' . ltrim($file, '/');

// 4) Ruta relativa respecto a la carpeta actual (app2/)
$candidates[] = str_replace('\\', '/', __DIR__) . '/' . ltrim($file, '/');

$resolvedPath = false;
foreach ($candidates as $candidate) {
    $real = realpath($candidate);
    if ($real !== false && is_file($real)) {
        $resolvedPath = str_replace('\\', '/', $real);
        break;
    }
}

if ($resolvedPath === false) {
    http_response_code(404);
    echo 'Archivo .md no encontrado';
    exit;
}

// Validar que el archivo esté estrictamente dentro del repositorio
$prefix = $repoRootNorm . '/';
$isWindows = (DIRECTORY_SEPARATOR === '\\');
$insideRepo = $isWindows
    ? (strncasecmp($resolvedPath, $prefix, strlen($prefix)) === 0)
    : (strncmp($resolvedPath, $prefix, strlen($prefix)) === 0);

if (!$insideRepo) {
    http_response_code(403);
    echo 'Acceso denegado fuera del repositorio';
    exit;
}

// Solo se sirven documentos de /proyectos (nunca README del panel ni código).
$prefixProyectos = $proyectosNorm . '/';
$insideProyectos = $isWindows
    ? (strncasecmp($resolvedPath, $prefixProyectos, strlen($prefixProyectos)) === 0)
    : (strncmp($resolvedPath, $prefixProyectos, strlen($prefixProyectos)) === 0);

if (!$insideProyectos) {
    http_response_code(403);
    echo 'Solo se permiten documentos dentro de /proyectos';
    exit;
}

// Validar extensión .md
if (strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION)) !== 'md') {
    http_response_code(403);
    echo 'Solo se permiten archivos Markdown (.md)';
    exit;
}

$content = file_get_contents($resolvedPath);
if ($content === false) {
    http_response_code(500);
    echo 'Error al leer el archivo .md';
    exit;
}

echo $content;
