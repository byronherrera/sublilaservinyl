<?php
/**
 * Configuración general del módulo de administración de Sublilaservinyl.
 *
 * Define rutas, constantes de seguridad y utilidades compartidas por todas
 * las páginas y por la API interna.
 */
declare(strict_types=1);

/** ------------------------------------------------------------------
 * Rutas del proyecto
 * ------------------------------------------------------------------ */
define('ADMIN_DIR', __DIR__);
define('APP_DIR', dirname(__DIR__));
define('REPO_ROOT', dirname(APP_DIR));

define('PROYECTOS_DIR', REPO_ROOT . DIRECTORY_SEPARATOR . 'proyectos');
define('DATA_DIR', ADMIN_DIR . DIRECTORY_SEPARATOR . 'data');
define('LOGS_DIR', ADMIN_DIR . DIRECTORY_SEPARATOR . 'logs');
define('DB_PATH', DATA_DIR . DIRECTORY_SEPARATOR . 'administracion.sqlite');
define('PRIMER_ACCESO_PATH', DATA_DIR . DIRECTORY_SEPARATOR . 'primer-acceso.txt');

/** Página de la app a la que se redirige cuando no hay token válido. */
define('REDIRECT_SIN_TOKEN', '../index.html');

/** ------------------------------------------------------------------
 * Seguridad y límites
 * ------------------------------------------------------------------ */
const ROLES = [
    'admin'   => 'Administrador',
    'editor'  => 'Editor',
    'lectura' => 'Solo lectura',
];

/**
 * Formatos que se pueden subir.
 *
 * - Imágenes: JPG/JPEG, PNG, SVG (saneado) y formatos de iPhone (HEIC/HEIF).
 * - Video: MP4.
 */
const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'svg', 'heic', 'heif'];
const VIDEO_EXTS = ['mp4'];
const MEDIA_EXTS = ['jpg', 'jpeg', 'png', 'svg', 'heic', 'heif', 'mp4'];


const MAX_UPLOAD_BYTES = 26214400; // 25 MB por archivo
const MAX_PROFUNDIDAD   = 12;      // niveles de subcarpeta permitidos
const LOG_RETENCION_DIAS = 180;    // retención de archivos de log
const LOG_MAX_BYTES      = 5242880; // 5 MB por archivo de log antes de rotar
const ACCESOS_RETENCION_DIAS = 180; // retención de la bitácora de accesos

/** Duración de la sesión autenticada (en horas). */
const SESSION_HORAS = 24;

/** ------------------------------------------------------------------
 * Límites de peticiones (rate limiting)
 * ------------------------------------------------------------------ */
const LIMITE_TRACK_MAX      = 120;  // peticiones de analítica por IP
const LIMITE_TRACK_VENTANA  = 300;  // en 5 minutos
const LIMITE_TOKEN_MAX      = 10;   // intentos de token inválido por IP
const LIMITE_TOKEN_VENTANA  = 900;  // en 15 minutos
const LIMITE_API_BYTES      = 1048576; // 1 MB de JSON en la API

/** ------------------------------------------------------------------
 * Alertas de accesos fallidos
 * ------------------------------------------------------------------ */
const ALERTA_ACCESOS_UMBRAL = 10;   // fallos en la ventana para activar alerta
const ALERTA_ACCESOS_HORAS  = 24;   // ventana de la alerta mostrada en el panel
const ALERTA_ACCESOS_ESPERA = 3600; // no repetir el aviso más de una vez por hora

/**
 * Proxies de confianza (separados por coma en ADMIN_PROXIES_CONFIABLES).
 * Solo si REMOTE_ADDR pertenece a esta lista se aceptan CF-Connecting-IP y
 * X-Forwarded-For; en cualquier otro caso se usa la IP real de la conexión.
 */
function proxies_confiables(): array
{
    static $lista = null;
    if (is_array($lista)) {
        return $lista;
    }

    $configurados = getenv('ADMIN_PROXIES_CONFIABLES');
    $texto = is_string($configurados) && trim($configurados) !== ''
        ? $configurados
        : '127.0.0.1,::1';

    $lista = [];
    foreach (explode(',', $texto) as $proxy) {
        $proxy = trim($proxy);
        if ($proxy !== '') {
            $lista[] = $proxy;
        }
    }
    return $lista;
}

/** IP real de la conexión, sin cabeceras que el cliente pueda falsificar. */
function ip_remota(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'desconocida';
}

/** Indica si la petición llega desde un proxy de confianza. */
function es_proxy_confiable(): bool
{
    $remota = ip_remota();
    if ($remota === 'desconocida') {
        return false;
    }
    foreach (proxies_confiables() as $proxy) {
        if ($proxy === $remota) {
            return true;
        }
        // Rangos CIDR sencillos (IPv4): 10.0.0.0/8, 192.168.0.0/16…
        if (str_contains($proxy, '/') && filter_var($remota, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            [$red, $bits] = explode('/', $proxy, 2);
            if (filter_var($red, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ctype_digit($bits)) {
                $mascara = (int) $bits;
                if ($mascara >= 0 && $mascara <= 32) {
                    $mascaraBin = $mascara === 0 ? 0 : (0xFFFFFFFF << (32 - $mascara)) & 0xFFFFFFFF;
                    if ((ip2long($remota) & $mascaraBin) === (ip2long($red) & $mascaraBin)) {
                        return true;
                    }
                }
            }
        }
    }
    return false;
}

/** Indica si la petición usa HTTPS (directo o tras un proxy de confianza). */
function es_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (es_proxy_confiable()) {
        $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($proto === 'https') {
            return true;
        }
    }
    return false;
}

/** Nonce de CSP para los scripts inline del panel. */
function csp_nonce(): string
{
    static $nonce = null;
    if (!is_string($nonce)) {
        $nonce = base64_encode(random_bytes(18));
    }
    return $nonce;
}

/**
 * Envía las cabeceras de seguridad del panel.
 *
 * El CSP está pensado para las páginas PHP del panel: scripts propios con
 * nonce, estilos propios + Google Fonts + Font Awesome y sin frames.
 */
function seguridad_cabeceras(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');

    $nonce = csp_nonce();
    $csp = "default-src 'self'; "
        . "script-src 'self' 'nonce-" . $nonce . "'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
        . "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:; "
        . "img-src 'self' data: blob:; "
        . "media-src 'self' blob:; "
        . "connect-src 'self'; "
        . "frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'";
    header('Content-Security-Policy: ' . $csp);

    if (es_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

seguridad_cabeceras();

/** ------------------------------------------------------------------
 * Utilidades
 * ------------------------------------------------------------------ */

function e(?string $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ahora(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * IP del cliente.
 *
 * Solo se confía en CF-Connecting-IP / X-Forwarded-For cuando la conexión
 * llega desde un proxy declarado en ADMIN_PROXIES_CONFIABLES. El resto de
 * peticiones usan REMOTE_ADDR, que no puede falsificar el cliente.
 */
function ip_cliente(): string
{
    if (es_proxy_confiable()) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $clave) {
            if (empty($_SERVER[$clave])) {
                continue;
            }
            $partes = explode(',', (string) $_SERVER[$clave]);
            $candidata = trim($partes[0]);
            if (filter_var($candidata, FILTER_VALIDATE_IP)) {
                return $candidata;
            }
        }
    }
    return ip_remota();
}

/**
 * Ruta web del módulo (por ejemplo /sublilaservinyl/app/administracion),
 * calculada desde DOCUMENT_ROOT para funcionar también cuando se entra a la
 * carpeta con barra final (/app/administracion/).
 */
function ruta_web_admin(): string
{
    $docRoot  = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $adminDir = realpath(__DIR__);
    $docReal  = $docRoot !== '' ? realpath($docRoot) : false;

    if ($adminDir !== false && $docReal !== false && esta_dentro($adminDir, $docReal)) {
        $relativa = substr($adminDir, strlen($docReal));
        $relativa = str_replace('\\', '/', (string) $relativa);
        return '/' . trim($relativa, '/');
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/app/administracion/index.php');
    $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if (basename($dir) !== 'administracion') {
        $dir .= '/administracion';
    }
    return $dir === '' ? '/app/administracion' : $dir;
}

/** URL base del módulo (sin barra final). */
function admin_url_base(): string
{
    $scheme = es_https() ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host . ruta_web_admin();
}

/** URL base del repositorio (raíz donde viven /proyectos y /app). */
function repo_url_base(): string
{
    $scheme = es_https() ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $repo = rtrim(str_replace('\\', '/', dirname(dirname(ruta_web_admin()))), '/');
    if ($repo === '/' || $repo === '.' || $repo === '\\') {
        $repo = '';
    }

    return $scheme . '://' . $host . $repo;
}

/** Enlace público (con token) para entrar a la administración. */
function enlace_acceso(string $token): string
{
    return admin_url_base() . '/index.php?token=' . rawurlencode($token);
}

/** URL pública de un archivo dentro de /proyectos. */
function url_archivo(string $relativa): string
{
    $segmentos = explode('/', ltrim(str_replace('\\', '/', $relativa), '/'));
    return repo_url_base() . '/' . implode('/', array_map('rawurlencode', $segmentos));
}

/**
 * Normaliza una ruta relativa dentro de /proyectos.
 * Devuelve null si contiene segmentos peligrosos (.., ., vacíos, absolutos).
 */
function normalizar_ruta(string $relativa): ?string
{
    $relativa = trim(str_replace('\\', '/', $relativa));
    $relativa = trim($relativa, '/');

    if ($relativa === '' || $relativa === '.') {
        return '';
    }
    if (strlen($relativa) > 1024 || strpos($relativa, "\0") !== false) {
        return null;
    }

    $segmentos = [];
    foreach (explode('/', $relativa) as $segmento) {
        if ($segmento === '' || $segmento === '.' || $segmento === '..') {
            return null;
        }
        $segmentos[] = $segmento;
    }
    if (count($segmentos) > MAX_PROFUNDIDAD + 1) {
        return null;
    }

    return implode('/', $segmentos);
}

/**
 * Resuelve una ruta relativa a /proyectos y garantiza que no se escape del
 * directorio raíz (protección contra path traversal).
 *
 * @param bool $debeExistir  Si true, la ruta final debe existir.
 */
function ruta_proyecto(string $relativa, bool $debeExistir = true): ?string
{
    $normalizada = normalizar_ruta($relativa);
    if ($normalizada === null) {
        return null;
    }

    $raiz = realpath(PROYECTOS_DIR);
    if ($raiz === false) {
        return null;
    }

    $destino = $relativa === '' ? $raiz : $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizada);

    if ($debeExistir) {
        $real = realpath($destino);
        if ($real === false) {
            return null;
        }
        return esta_dentro($real, $raiz) ? $real : null;
    }

    // Para rutas que aún no existen validamos el directorio padre.
    $padre = realpath(dirname($destino));
    if ($padre === false || !esta_dentro($padre, $raiz)) {
        return null;
    }
    return $destino;
}

function esta_dentro(string $ruta, string $raiz): bool
{
    $ruta = rtrim(str_replace('\\', '/', $ruta), '/');
    $raiz = rtrim(str_replace('\\', '/', $raiz), '/');
    return $ruta === $raiz || strpos($ruta . '/', $raiz . '/') === 0;
}

/**
 * Limpia un nombre de archivo o carpeta.
 *
 * Reglas: minúsculas, solo letras (a-z), números, puntos y guiones. Los
 * espacios y cualquier otro carácter se convierten en "-". Se eliminan
 * acentos (á → a, ñ → n, …) para evitar problemas de codificación.
 */
function limpiar_nombre(string $nombre): ?string
{
    $nombre = trim($nombre);

    // Separadores de ruta y caracteres nulos nunca forman parte del nombre.
    $nombre = str_replace(['/', '\\', "\0"], '-', $nombre);

    // Minúsculas y transliteración de acentos/ñ.
    $nombre = mb_strtolower($nombre, 'UTF-8');
    $nombre = strtr($nombre, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c', 'ý' => 'y', 'ÿ' => 'y',
        'æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss',
    ]);

    // Cualquier carácter no permitido (incluidos espacios) pasa a "-".
    $nombre = preg_replace('/[^a-z0-9.-]+/u', '-', $nombre) ?? '';
    $nombre = preg_replace('/-+/', '-', $nombre) ?? '';
    $nombre = preg_replace('/\.{2,}/', '.', $nombre) ?? '';
    $nombre = preg_replace('/-+\./', '.', $nombre) ?? '';
    $nombre = preg_replace('/\.-+/', '.', $nombre) ?? '';
    $nombre = trim($nombre, " .-");

    if ($nombre === '' || $nombre === '.' || $nombre === '..') {
        return null;
    }
    if (mb_strlen($nombre, 'UTF-8') > 150) {
        $nombre = mb_substr($nombre, 0, 150, 'UTF-8');
        $nombre = rtrim($nombre, " .-");
        if ($nombre === '') {
            return null;
        }
    }
    return $nombre;
}

/** Devuelve nombre, extensión y base de un archivo. */
function partes_nombre(string $nombre): array
{
    $ext  = strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION));
    $base = (string) pathinfo($nombre, PATHINFO_FILENAME);
    return [$base, $ext];
}

/** Tamaño legible (B, KB, MB, GB). */
function formato_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $unidades = ['KB', 'MB', 'GB', 'TB'];
    $valor = $bytes / 1024;
    $i = 0;
    while ($valor >= 1024 && $i < count($unidades) - 1) {
        $valor /= 1024;
        $i++;
    }
    return number_format($valor, $valor >= 10 ? 1 : 2, ',', '.') . ' ' . $unidades[$i];
}

/** Comprueba que la extensión sea de imagen permitida. */
function es_imagen_permitida(string $nombre): bool
{
    [, $ext] = partes_nombre($nombre);
    return in_array($ext, IMAGE_EXTS, true);
}

/** Comprueba que la extensión sea de video permitido. */
function es_video_permitido(string $nombre): bool
{
    [, $ext] = partes_nombre($nombre);
    return in_array($ext, VIDEO_EXTS, true);
}

/** Comprueba que el archivo sea un medio administrable (imagen o video). */
function es_media_permitida(string $nombre): bool
{
    [, $ext] = partes_nombre($nombre);
    return in_array($ext, MEDIA_EXTS, true);
}

function json_out(array $datos, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function redirigir(string $destino): void
{
    header('Location: ' . $destino);
    exit;
}
