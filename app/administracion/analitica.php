<?php
/**
 * Analítica ligera (mini Google Analytics).
 *
 * Se registran dos ámbitos en la tabla `visitas`:
 *   web   → visitas anónimas a app/index.html, enviadas desde el navegador.
 *   admin → páginas del panel de administración, registradas en servidor.
 *
 * No se usan servicios externos ni cookies de terceros. El identificador de
 * visitante web se genera en el navegador y se guarda en localStorage; en el
 * panel se usa el id del usuario autenticado. La IP solo se almacena como hash.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/** Retención de la analítica (días). */
const ANALITICA_RETENCION_DIAS = 365;

/** Ámbitos válidos de la analítica. */
const ANALITICA_AMBITOS = ['web', 'admin'];

/** Devuelve un ámbito válido (por defecto web). */
function analitica_ambito(string $ambito): string
{
    return in_array($ambito, ANALITICA_AMBITOS, true) ? $ambito : 'web';
}

/** Limpia controles y limita la longitud de un texto para la base. */
function analitica_texto(mixed $valor, int $max = 300): string
{
    $texto = trim((string) $valor);
    $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texto) ?? '';
    return mb_substr($texto, 0, $max, 'UTF-8');
}

/** Clasifica el User-Agent en Móvil, Tablet o Escritorio. */
function analitica_dispositivo(string $ua): string
{
    if (preg_match('/ipad|tablet|playbook|silk/i', $ua)) {
        return 'Tablet';
    }
    if (preg_match('/android/i', $ua) && !preg_match('/mobile/i', $ua)) {
        return 'Tablet';
    }
    if (preg_match('/mobi|iphone|ipod|android|blackberry|opera mini|iemobile|windows phone/i', $ua)) {
        return 'Móvil';
    }
    return 'Escritorio';
}

/** Navegador principal a partir del User-Agent. */
function analitica_navegador(string $ua): string
{
    $reglas = [
        'Samsung Internet' => '/SamsungBrowser/i',
        'Edge'             => '/Edg\/|EdgA\/|Edge\//i',
        'Opera'            => '/OPR\/|Opera/i',
        'Chrome'           => '/CriOS\/|Chrome\//i',
        'Firefox'          => '/FxiOS\/|Firefox\//i',
        'Safari'           => '/Safari\//i',
    ];
    foreach ($reglas as $nombre => $patron) {
        if (preg_match($patron, $ua)) {
            return $nombre;
        }
    }
    return 'Otro';
}

/** Sistema operativo a partir del User-Agent. */
function analitica_sistema(string $ua): string
{
    $reglas = [
        'iOS'     => '/iPhone|iPad|iPod/i',
        'Android' => '/Android/i',
        'Windows' => '/Windows/i',
        'macOS'   => '/Macintosh|Mac OS X/i',
        'Linux'   => '/Linux/i',
    ];
    foreach ($reglas as $nombre => $patron) {
        if (preg_match($patron, $ua)) {
            return $nombre;
        }
    }
    return 'Otro';
}

/** Host de un referente, sin www. Devuelve '' para visitas directas. */
function analitica_host_referente(string $referente): string
{
    if ($referente === '') {
        return '';
    }
    $host = parse_url($referente, PHP_URL_HOST);
    $host = is_string($host) ? strtolower($host) : '';
    return preg_replace('/^www\./', '', $host) ?? '';
}

/** Nombres legibles de las secciones del panel. */
const ANALITICA_PAGINAS_ADMIN = [
    'panel'        => 'Panel',
    'usuarios'     => 'Usuarios y accesos',
    'archivos'     => 'Archivos',
    'actividad'    => 'Actividad',
    'estadisticas' => 'Estadísticas',
];

/** Etiqueta legible de una ruta interna. */
function analitica_etiqueta_ruta(string $ruta): string
{
    $ruta = urldecode($ruta);
    if (str_starts_with($ruta, 'admin/')) {
        $clave = substr($ruta, 6);
        return 'Admin · ' . (ANALITICA_PAGINAS_ADMIN[$clave] ?? ucfirst($clave));
    }
    if ($ruta === '' || $ruta === 'index.html' || $ruta === '/') {
        return 'Inicio';
    }
    return $ruta;
}

/**
 * Registra una visita. Devuelve false si se descarta por duplicado reciente.
 *
 * @param array<string,mixed> $datos
 */
function analitica_registrar(array $datos, string $ambito = 'web'): bool
{
    $ambito = analitica_ambito($ambito);
    $visitante = analitica_texto($datos['v'] ?? '', 40);
    if (!preg_match('/^[A-Za-z0-9_-]{4,40}$/', $visitante)) {
        $visitante = 'anon_' . bin2hex(random_bytes(6));
    }

    $ruta = analitica_texto($datos['ruta'] ?? 'index.html', 300);
    if ($ruta === '') {
        $ruta = 'index.html';
    }

    $tokenId = isset($datos['token_id']) ? (int) $datos['token_id'] : 0;
    if ($tokenId <= 0) {
        $tokenId = null;
    }

    $ahora = ahora();
    $pdo = db();

    // Evita duplicados por recargas rápidas del mismo visitante y ruta.
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM visitas
          WHERE visitante = :v AND ruta = :r AND ambito = :a AND creado_en > :limite'
    );
    $stmt->execute([
        ':v'      => $visitante,
        ':r'      => $ruta,
        ':a'      => $ambito,
        ':limite' => date('Y-m-d H:i:s', time() - 2),
    ]);
    if ((int) $stmt->fetchColumn() > 0) {
        return false;
    }

    $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500, 'UTF-8');
    $referente = analitica_texto($datos['ref'] ?? '', 500);
    $ip = ip_cliente();
    $ipHash = $ip !== '' ? hash('sha256', $ip . '|' . date('Y-m-d')) : '';

    $insert = $pdo->prepare(
        'INSERT INTO visitas
            (visitante, ruta, referente, referente_host, dispositivo, navegador,
             sistema, idioma, pantalla, ip_hash, ambito, token_id, creado_en)
         VALUES
            (:visitante, :ruta, :referente, :ref_host, :dispositivo, :navegador,
             :sistema, :idioma, :pantalla, :ip_hash, :ambito, :token_id, :creado)'
    );
    $insert->execute([
        ':visitante'   => $visitante,
        ':ruta'        => $ruta,
        ':referente'   => $referente,
        ':ref_host'    => analitica_host_referente($referente),
        ':dispositivo' => analitica_dispositivo($ua),
        ':navegador'   => analitica_navegador($ua),
        ':sistema'     => analitica_sistema($ua),
        ':idioma'      => analitica_texto($datos['idioma'] ?? '', 20),
        ':pantalla'    => analitica_texto($datos['pantalla'] ?? '', 20),
        ':ip_hash'     => $ipHash,
        ':ambito'      => $ambito,
        ':token_id'    => $tokenId,
        ':creado'      => $ahora,
    ]);

    return true;
}

/**
 * Registra la visita a una página del panel (ámbito admin).
 *
 * El visitante se identifica con el id del usuario y se guarda el token usado,
 * de modo que las estadísticas pueden desglosar actividad por usuario y token.
 *
 * @param array<string,mixed> $usuario Fila devuelta por require_auth().
 */
function analitica_registrar_admin(array $usuario, string $pagina): bool
{
    $pagina = strtolower(trim($pagina));
    if (!preg_match('/^[a-z0-9-]{1,40}$/', $pagina)) {
        return false;
    }

    $id = (int) ($usuario['id'] ?? 0);

    try {
        return analitica_registrar([
            'v'        => 'admin_' . ($id > 0 ? $id : 'anon'),
            'ruta'     => 'admin/' . $pagina,
            'ref'      => '',
            'idioma'   => mb_substr((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 20, 'UTF-8'),
            'token_id' => (int) ($usuario['token_id'] ?? 0),
        ], 'admin');
    } catch (Throwable $e) {
        // La analítica nunca debe romper el panel.
        error_log('Analítica admin: ' . $e->getMessage());
        return false;
    }
}

/** Elimina visitas fuera de la ventana de retención. */
function analitica_purgar(): void
{
    db()->prepare('DELETE FROM visitas WHERE creado_en < :limite')
        ->execute([':limite' => date('Y-m-d H:i:s', strtotime('-' . ANALITICA_RETENCION_DIAS . ' days'))]);
}

/** Número de visitas (y opcionalmente visitantes únicos) desde una fecha. */
function analitica_contar(string $desde, string $hasta = '', bool $unicos = false, string $ambito = 'web'): int
{
    $ambito = analitica_ambito($ambito);
    $sql = $unicos
        ? 'SELECT COUNT(DISTINCT visitante) FROM visitas WHERE creado_en >= :desde AND ambito = :ambito'
        : 'SELECT COUNT(*) FROM visitas WHERE creado_en >= :desde AND ambito = :ambito';
    $params = [':desde' => $desde . ' 00:00:00', ':ambito' => $ambito];
    if ($hasta !== '') {
        $sql .= ' AND creado_en <= :hasta';
        $params[':hasta'] = $hasta . ' 23:59:59';
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Resumen general de visitas de un ámbito.
 *
 * @return array<string,int|float|string>
 */
function analitica_resumen(string $ambito = 'web'): array
{
    $ambito = analitica_ambito($ambito);
    $hoy    = date('Y-m-d');
    $ayer   = date('Y-m-d', strtotime('-1 day'));
    $semana = date('Y-m-d', strtotime('-6 days'));
    $mes    = date('Y-m-d', strtotime('-29 days'));

    $stmt = db()->prepare('SELECT COUNT(*) FROM visitas WHERE ambito = :ambito');
    $stmt->execute([':ambito' => $ambito]);
    $total = (int) $stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(DISTINCT visitante) FROM visitas WHERE ambito = :ambito');
    $stmt->execute([':ambito' => $ambito]);
    $totalUnicos = (int) $stmt->fetchColumn();

    $stmt = db()->prepare('SELECT MIN(creado_en) FROM visitas WHERE ambito = :ambito');
    $stmt->execute([':ambito' => $ambito]);
    $primera = (string) $stmt->fetchColumn();

    $diasActivos = 1;
    if ($primera !== '') {
        $diasActivos = max(1, (int) ceil((time() - (int) strtotime($primera)) / 86400));
    }

    $hoyTotal = analitica_contar($hoy, '', false, $ambito);
    $ayerTotal = analitica_contar($ayer, $ayer, false, $ambito);

    return [
        'hoy'            => $hoyTotal,
        'hoy_unicos'     => analitica_contar($hoy, '', true, $ambito),
        'ayer'           => $ayerTotal,
        'variacion'      => $ayerTotal > 0 ? (($hoyTotal - $ayerTotal) / $ayerTotal) * 100 : null,
        'semana'         => analitica_contar($semana, '', false, $ambito),
        'semana_unicos'  => analitica_contar($semana, '', true, $ambito),
        'mes'            => analitica_contar($mes, '', false, $ambito),
        'mes_unicos'     => analitica_contar($mes, '', true, $ambito),
        'total'          => $total,
        'total_unicos'   => $totalUnicos,
        'media_diaria'   => $total > 0 ? round($total / $diasActivos, 1) : 0,
    ];
}

/**
 * Serie diaria de los últimos días.
 *
 * @return array{datos:array<int,array{fecha:string,etiqueta:string,total:int,unicos:int}>,max:int}
 */
function analitica_serie(int $dias = 14, string $ambito = 'web'): array
{
    $ambito = analitica_ambito($ambito);
    $dias = max(1, min(90, $dias));
    $inicio = date('Y-m-d', strtotime('-' . ($dias - 1) . ' days'));

    $stmt = db()->prepare(
        'SELECT substr(creado_en, 1, 10) AS dia,
                COUNT(*) AS total,
                COUNT(DISTINCT visitante) AS unicos
           FROM visitas
          WHERE creado_en >= :desde AND ambito = :ambito
          GROUP BY dia'
    );
    $stmt->execute([':desde' => $inicio . ' 00:00:00', ':ambito' => $ambito]);

    $porDia = [];
    foreach ($stmt->fetchAll() as $fila) {
        $porDia[(string) $fila['dia']] = [
            'total'  => (int) $fila['total'],
            'unicos' => (int) $fila['unicos'],
        ];
    }

    $datos = [];
    $max = 0;
    for ($i = 0; $i < $dias; $i++) {
        $fecha = date('Y-m-d', strtotime('-' . ($dias - 1 - $i) . ' days'));
        $total = $porDia[$fecha]['total'] ?? 0;
        $unicos = $porDia[$fecha]['unicos'] ?? 0;
        $max = max($max, $total);
        $datos[] = [
            'fecha'    => $fecha,
            'etiqueta' => date('d/m', strtotime($fecha)),
            'total'    => $total,
            'unicos'   => $unicos,
        ];
    }

    return ['datos' => $datos, 'max' => max(1, $max)];
}

/**
 * Agrupa visitas por una columna permitida.
 *
 * @return array<int,array{etiqueta:string,total:int,unicos:int}>
 */
function analitica_agrupado(string $campo, int $limite = 8, string $ambito = 'web'): array
{
    $ambito = analitica_ambito($ambito);
    $permitidos = ['ruta', 'referente_host', 'dispositivo', 'navegador', 'sistema', 'idioma', 'visitante'];
    if (!in_array($campo, $permitidos, true)) {
        return [];
    }
    $limite = max(1, min(50, $limite));
    $stmt = db()->prepare(
        "SELECT COALESCE(NULLIF($campo, ''), '(directo)') AS etiqueta,
                COUNT(*) AS total,
                COUNT(DISTINCT visitante) AS unicos
           FROM visitas
          WHERE ambito = :ambito
          GROUP BY etiqueta
          ORDER BY total DESC
          LIMIT $limite"
    );
    $stmt->execute([':ambito' => $ambito]);

    $salida = [];
    foreach ($stmt->fetchAll() as $fila) {
        $salida[] = [
            'etiqueta' => (string) $fila['etiqueta'],
            'total'    => (int) $fila['total'],
            'unicos'   => (int) $fila['unicos'],
        ];
    }
    return $salida;
}

/** Últimas visitas registradas. */
function analitica_ultimas(int $limite = 20, string $ambito = 'web'): array
{
    $ambito = analitica_ambito($ambito);
    $limite = max(1, min(100, $limite));
    $stmt = db()->prepare(
        "SELECT visitante, ruta, referente_host, dispositivo, navegador, sistema, token_id, creado_en
           FROM visitas
          WHERE ambito = :ambito
          ORDER BY id DESC
          LIMIT $limite"
    );
    $stmt->execute([':ambito' => $ambito]);
    return $stmt->fetchAll();
}

/**
 * Actividad por usuario del panel: páginas vistas, última actividad y tokens.
 *
 * @return array<int,array<string,mixed>>
 */
function analitica_usuarios_actividad(int $limite = 30): array
{
    $limite = max(1, min(100, $limite));
    $stmt = db()->prepare(
        "SELECT u.id, u.nombre, u.rol, u.activo,
                (SELECT COUNT(*) FROM visitas v
                  WHERE v.ambito = 'admin' AND v.visitante = 'admin_' || u.id) AS paginas,
                (SELECT MAX(v.creado_en) FROM visitas v
                  WHERE v.ambito = 'admin' AND v.visitante = 'admin_' || u.id) AS ultima_pagina,
                (SELECT COUNT(*) FROM tokens t WHERE t.usuario_id = u.id) AS total_tokens,
                (SELECT COUNT(*) FROM tokens t WHERE t.usuario_id = u.id AND t.activo = 1) AS tokens_activos,
                (SELECT COALESCE(SUM(t.accesos), 0) FROM tokens t WHERE t.usuario_id = u.id) AS accesos_token,
                (SELECT MAX(t.ultimo_acceso) FROM tokens t WHERE t.usuario_id = u.id) AS ultimo_acceso
           FROM usuarios u
          ORDER BY paginas DESC, ultimo_acceso DESC, u.nombre COLLATE NOCASE
          LIMIT $limite"
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Uso por token: accesos de login, páginas vistas y última actividad.
 *
 * @return array<int,array<string,mixed>>
 */
function analitica_tokens_uso(int $limite = 30): array
{
    $limite = max(1, min(100, $limite));
    $stmt = db()->prepare(
        "SELECT t.id, t.etiqueta, t.activo, t.accesos, t.creado_en, t.ultimo_acceso,
                u.nombre AS usuario, u.rol,
                (SELECT COUNT(*) FROM visitas v
                  WHERE v.ambito = 'admin' AND v.token_id = t.id) AS paginas,
                (SELECT MAX(v.creado_en) FROM visitas v
                  WHERE v.ambito = 'admin' AND v.token_id = t.id) AS ultima_pagina
           FROM tokens t
           JOIN usuarios u ON u.id = t.usuario_id
          ORDER BY t.activo DESC, t.accesos DESC, t.ultimo_acceso DESC, t.creado_en DESC
          LIMIT $limite"
    );
    $stmt->execute();
    return $stmt->fetchAll();
}
