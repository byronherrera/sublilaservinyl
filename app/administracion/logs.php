<?php
/**
 * Registro de actividad en archivo de texto.
 *
 * Cada mes se crea un archivo nuevo en /app/administracion/logs:
 *   actividad-AAAA-MM.log
 *
 * Formato de cada línea (separado por tabuladores):
 *   fecha_hora | usuario | acción | detalle | ip
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function logs_asegurar_directorio(): void
{
    if (!is_dir(LOGS_DIR)) {
        @mkdir(LOGS_DIR, 0775, true);
    }
    $htaccess = LOGS_DIR . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents(
            $htaccess,
            "Require all denied\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
        );
    }
}

function log_archivo_actual(): string
{
    return LOGS_DIR . DIRECTORY_SEPARATOR . 'actividad-' . date('Y-m') . '.log';
}

/**
 * Escribe una línea en el log de actividad.
 *
 * @param string $accion  Código corto, por ejemplo "USUARIO_CREADO".
 * @param string $detalle Texto libre descriptivo.
 * @param string|null $usuario Nombre del usuario que realiza la acción.
 */
function registrar_log(string $accion, string $detalle, ?string $usuario = null): void
{
    logs_asegurar_directorio();

    $linea = implode("\t", [
        ahora(),
        $usuario ?? 'sistema',
        strtoupper($accion),
        str_replace(["\r", "\n", "\t"], ' ', $detalle),
        ip_cliente(),
    ]) . PHP_EOL;

    @file_put_contents(log_archivo_actual(), $linea, FILE_APPEND | LOCK_EX);
    log_rotar_si_grande(log_archivo_actual());

    // Las subidas rechazadas se guardan además en un log propio con todo el
    // diagnóstico (MIME, extensión detectada, cabecera binaria y motivo).
    if (strtoupper($accion) === 'SUBIDA_RECHAZADA') {
        log_subida_rechazada_detalle($linea);
    }
}

/** Rota un log que supera el tamaño máximo (conserva una copia .1). */
function log_rotar_si_grande(string $archivo): void
{
    if (!is_file($archivo)) {
        return;
    }
    clearstatcache(true, $archivo);
    if ((int) filesize($archivo) <= LOG_MAX_BYTES) {
        return;
    }
    @rename($archivo, $archivo . '.1');
}

/**
 * Activa una alerta cuando hay demasiados accesos fallidos recientes.
 *
 * La alerta queda en el log como ALERTA_ACCESOS y el panel muestra un aviso.
 * Se limita a una escritura por hora para no inundar la bitácora.
 */
function alertar_accesos_fallidos(): bool
{
    try {
        $resumen = db_accesos_fallidos_resumen(1);
        if ($resumen['total'] < ALERTA_ACCESOS_UMBRAL) {
            return false;
        }
        if (!db_limite_consumir('alerta_accesos_fallidos', 1, ALERTA_ACCESOS_ESPERA)) {
            return false;
        }
        registrar_log(
            'ALERTA_ACCESOS',
            'Accesos fallidos en la última hora: ' . $resumen['total']
            . ' desde ' . $resumen['ips'] . ' IP(s)',
            'sistema'
        );
        return true;
    } catch (Throwable $e) {
        error_log('[administracion] Alerta de accesos: ' . $e->getMessage());
        return false;
    }
}

/** Añade una línea al log específico de subidas rechazadas. */
function log_subida_rechazada_detalle(string $linea): void
{
    logs_asegurar_directorio();
    $archivo = LOGS_DIR . DIRECTORY_SEPARATOR . 'subidas-rechazadas-' . date('Y-m') . '.log';
    @file_put_contents($archivo, $linea, FILE_APPEND | LOCK_EX);
    log_rotar_si_grande($archivo);
}

/**
 * Devuelve las últimas líneas del log actual (más recientes primero).
 *
 * @return array<int, array{fecha:string,usuario:string,accion:string,detalle:string,ip:string}>
 */
function log_ultimas_lineas(int $limite = 100): array
{
    $archivo = log_archivo_actual();
    if (!is_file($archivo)) {
        return [];
    }

    $lineas = file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lineas === false) {
        return [];
    }

    $lineas = array_slice($lineas, -$limite);
    $salida = [];

    foreach (array_reverse($lineas) as $linea) {
        $partes = explode("\t", $linea);
        if (count($partes) < 5) {
            continue;
        }
        $salida[] = [
            'fecha'   => $partes[0],
            'usuario' => $partes[1],
            'accion'  => $partes[2],
            'detalle' => $partes[3],
            'ip'      => $partes[4],
        ];
    }
    return $salida;
}

/**
 * Lee un archivo de log concreto del directorio de logs.
 * Devuelve las últimas $limite líneas parseadas (más recientes primero).
 */
function log_leer_archivo(string $nombreArchivo, int $limite = 200): array
{
    $nombreArchivo = basename($nombreArchivo);
    if (!preg_match('/^actividad-\d{4}-\d{2}\.log$/', $nombreArchivo)) {
        return [];
    }
    $ruta = LOGS_DIR . DIRECTORY_SEPARATOR . $nombreArchivo;
    if (!is_file($ruta)) {
        return [];
    }
    $lineas = file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lineas = array_slice($lineas, -$limite);

    $salida = [];
    foreach (array_reverse($lineas) as $linea) {
        $partes = explode("\t", $linea);
        if (count($partes) < 5) {
            continue;
        }
        $salida[] = [
            'fecha'   => $partes[0],
            'usuario' => $partes[1],
            'accion'  => $partes[2],
            'detalle' => $partes[3],
            'ip'      => $partes[4],
        ];
    }
    return $salida;
}

/** Lista los archivos de log disponibles (más recientes primero). */
function log_archivos(): array
{
    if (!is_dir(LOGS_DIR)) {
        return [];
    }
    $archivos = glob(LOGS_DIR . DIRECTORY_SEPARATOR . 'actividad-*.log') ?: [];
    usort($archivos, static fn($a, $b) => strcmp($b, $a));

    return array_map(static function (string $ruta): array {
        return [
            'nombre' => basename($ruta),
            'bytes'  => (int) filesize($ruta),
            'lineas' => (int) count(file($ruta, FILE_SKIP_EMPTY_LINES) ?: []),
            'fecha'  => date('Y-m-d H:i', (int) filemtime($ruta)),
        ];
    }, $archivos);
}

/** Elimina archivos de log más antiguos que la retención configurada. */
function log_purgar_antiguos(): void
{
    if (!is_dir(LOGS_DIR)) {
        return;
    }
    $limite = time() - (LOG_RETENCION_DIAS * 86400);
    $patrones = [
        LOGS_DIR . DIRECTORY_SEPARATOR . 'actividad-*.log',
        LOGS_DIR . DIRECTORY_SEPARATOR . 'actividad-*.log.*',
        LOGS_DIR . DIRECTORY_SEPARATOR . 'subidas-rechazadas-*.log',
        LOGS_DIR . DIRECTORY_SEPARATOR . 'subidas-rechazadas-*.log.*',
    ];
    foreach ($patrones as $patron) {
        foreach (glob($patron) ?: [] as $archivo) {
            if (is_file($archivo) && filemtime($archivo) < $limite) {
                @unlink($archivo);
            }
        }
    }
}
