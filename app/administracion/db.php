<?php
/**
 * Capa de datos (SQLite / PDO) del módulo de administración.
 *
 * Tablas:
 *   usuarios  → personas con acceso al panel
 *   tokens    → direcciones de acceso (?token=...) asociadas a un usuario
 *   sesiones  → sesiones emitidas al validar un token
 *   accesos   → bitácora de intentos de acceso (correctos y fallidos)
 *   visitas   → analítica ligera de la web pública (mini Google Analytics)
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }
    $htaccess = DATA_DIR . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents(
            $htaccess,
            "Require all denied\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
        );
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    db_migrar($pdo);
    return $pdo;
}

function db_migrar(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS usuarios (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre        TEXT    NOT NULL,
            email         TEXT,
            rol           TEXT    NOT NULL DEFAULT 'editor',
            activo        INTEGER NOT NULL DEFAULT 1,
            creado_en     TEXT    NOT NULL,
            actualizado_en TEXT   NOT NULL
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tokens (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario_id    INTEGER NOT NULL,
            token         TEXT    NOT NULL UNIQUE,
            etiqueta      TEXT,
            activo        INTEGER NOT NULL DEFAULT 1,
            creado_en     TEXT    NOT NULL,
            ultimo_acceso TEXT,
            accesos       INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
        )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tokens_usuario ON tokens(usuario_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tokens_activo ON tokens(activo)');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS sesiones (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            token_id      INTEGER NOT NULL,
            session_hash  TEXT    NOT NULL UNIQUE,
            ip            TEXT,
            user_agent    TEXT,
            creado_en     TEXT    NOT NULL,
            expira_en     TEXT    NOT NULL,
            FOREIGN KEY (token_id) REFERENCES tokens(id) ON DELETE CASCADE
        )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sesiones_token ON sesiones(token_id)');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS accesos (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario    TEXT,
            token      TEXT,
            resultado  TEXT NOT NULL,
            ip         TEXT,
            user_agent TEXT,
            fecha      TEXT NOT NULL
        )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_accesos_fecha ON accesos(fecha)');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS limites (
            clave   TEXT    PRIMARY KEY,
            ventana INTEGER NOT NULL,
            contador INTEGER NOT NULL
        )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_limites_ventana ON limites(ventana)');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS visitas (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            visitante      TEXT    NOT NULL,
            ruta           TEXT    NOT NULL,
            referente      TEXT,
            referente_host TEXT,
            dispositivo    TEXT,
            navegador      TEXT,
            sistema        TEXT,
            idioma         TEXT,
            pantalla       TEXT,
            ip_hash        TEXT,
            ambito         TEXT    NOT NULL DEFAULT 'web',
            token_id       INTEGER,
            creado_en      TEXT    NOT NULL
        )"
    );
    // Migraciones para bases creadas antes de separar web y panel.
    db_migrar_columna($pdo, 'visitas', 'ambito', "TEXT NOT NULL DEFAULT 'web'");
    db_migrar_columna($pdo, 'visitas', 'token_id', 'INTEGER');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_visitas_fecha ON visitas(creado_en)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_visitas_visitante ON visitas(visitante)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_visitas_ruta ON visitas(ruta)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_visitas_ambito ON visitas(ambito, creado_en)');

    db_sembrar($pdo);
}

/** Añade una columna si aún no existe (migraciones idempotentes). */
function db_migrar_columna(PDO $pdo, string $tabla, string $columna, string $definicion): void
{
    $existentes = [];
    foreach ($pdo->query('PRAGMA table_info(' . $tabla . ')') as $fila) {
        $existentes[] = (string) $fila['name'];
    }
    if (!in_array($columna, $existentes, true)) {
        $pdo->exec('ALTER TABLE ' . $tabla . ' ADD COLUMN ' . $columna . ' ' . $definicion);
    }
}

/** Crea el primer administrador y su token si la base está vacía. */
function db_sembrar(PDO $pdo): void
{
    $total = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    if ($total > 0) {
        return;
    }

    $ahora = ahora();
    $stmt = $pdo->prepare(
        'INSERT INTO usuarios (nombre, email, rol, activo, creado_en, actualizado_en)
         VALUES (:nombre, :email, :rol, 1, :ahora, :ahora)'
    );
    $stmt->execute([
        ':nombre' => 'Sublilaservinyl',
        ':email'  => '',
        ':rol'    => 'admin',
        ':ahora'  => $ahora,
    ]);
    $usuarioId = (int) $pdo->lastInsertId();

    $token = generar_token();
    $stmt = $pdo->prepare(
        'INSERT INTO tokens (usuario_id, token, etiqueta, activo, creado_en)
         VALUES (:usuario_id, :token, :etiqueta, 1, :ahora)'
    );
    $stmt->execute([
        ':usuario_id' => $usuarioId,
        ':token'      => $token,
        ':etiqueta'   => 'Acceso principal',
        ':ahora'      => $ahora,
    ]);

    $enlace = enlace_acceso($token);
    @file_put_contents(
        PRIMER_ACCESO_PATH,
        "ACCESO INICIAL - Sublilaservinyl (administracion)\r\n"
        . "Usuario: Sublilaservinyl\r\n"
        . "Token:   " . $token . "\r\n"
        . "Enlace:  " . $enlace . "\r\n"
        . "Generado: " . $ahora . "\r\n\r\n"
        . "Guarda este enlace. Puedes recuperarlo o regenerarlo desde el panel.\r\n"
    );

    registrar_log('INSTALACION', 'Base de datos creada y token inicial generado', 'sistema');
}

/** Genera un token aleatorio recuperable (se guarda en claro para poder reconstruir el enlace). */
function generar_token(): string
{
    return 'rat_' . bin2hex(random_bytes(24));
}

/* ------------------------------------------------------------------
 * Consultas de usuarios y tokens
 * ------------------------------------------------------------------ */

function db_usuario_por_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

/** Devuelve el usuario + token validando el token recibido. */
function db_usuario_por_token(string $token): ?array
{
    $stmt = db()->prepare(
        'SELECT u.*, t.id AS token_id, t.token, t.etiqueta
           FROM tokens t
           JOIN usuarios u ON u.id = t.usuario_id
          WHERE t.token = :token AND t.activo = 1 AND u.activo = 1
          LIMIT 1'
    );
    $stmt->execute([':token' => $token]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

function db_usuarios_listar(): array
{
    $sql = 'SELECT u.*,
                   (SELECT COUNT(*) FROM tokens t WHERE t.usuario_id = u.id) AS total_tokens,
                   (SELECT COUNT(*) FROM tokens t WHERE t.usuario_id = u.id AND t.activo = 1) AS tokens_activos,
                   (SELECT MAX(t.ultimo_acceso) FROM tokens t WHERE t.usuario_id = u.id) AS ultimo_acceso
              FROM usuarios u
          ORDER BY u.activo DESC, u.nombre COLLATE NOCASE ASC';
    return db()->query($sql)->fetchAll();
}

function db_tokens_listar(): array
{
    $sql = 'SELECT t.*, u.nombre AS usuario, u.rol
              FROM tokens t
              JOIN usuarios u ON u.id = t.usuario_id
          ORDER BY t.activo DESC, t.creado_en DESC';
    return db()->query($sql)->fetchAll();
}

function db_token_crear(int $usuarioId, string $etiqueta = ''): array
{
    $token = generar_token();
    $stmt = db()->prepare(
        'INSERT INTO tokens (usuario_id, token, etiqueta, activo, creado_en)
         VALUES (:usuario_id, :token, :etiqueta, 1, :ahora)'
    );
    $stmt->execute([
        ':usuario_id' => $usuarioId,
        ':token'      => $token,
        ':etiqueta'   => $etiqueta,
        ':ahora'      => ahora(),
    ]);

    return [
        'id'     => (int) db()->lastInsertId(),
        'token'  => $token,
        'enlace' => enlace_acceso($token),
    ];
}

function db_token_revocar(int $tokenId): bool
{
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE tokens SET activo = 0 WHERE id = :id');
    $stmt->execute([':id' => $tokenId]);
    $afectados = $stmt->rowCount() > 0;

    $pdo->prepare('DELETE FROM sesiones WHERE token_id = :id')->execute([':id' => $tokenId]);
    return $afectados;
}

function db_tokens_revocar_usuario(int $usuarioId): void
{
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE tokens SET activo = 0 WHERE usuario_id = :id');
    $stmt->execute([':id' => $usuarioId]);
    $pdo->prepare(
        'DELETE FROM sesiones WHERE token_id IN (SELECT id FROM tokens WHERE usuario_id = :id)'
    )->execute([':id' => $usuarioId]);
}

function db_sesion_crear(int $tokenId, string $sessionHash): void
{
    $stmt = db()->prepare(
        'INSERT INTO sesiones (token_id, session_hash, ip, user_agent, creado_en, expira_en)
         VALUES (:token_id, :hash, :ip, :ua, :creado, :expira)'
    );
    $stmt->execute([
        ':token_id' => $tokenId,
        ':hash'     => $sessionHash,
        ':ip'       => ip_cliente(),
        ':ua'       => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255, 'UTF-8'),
        ':creado'   => ahora(),
        ':expira'   => date('Y-m-d H:i:s', time() + (SESSION_HORAS * 3600)),
    ]);
}

function db_sesion_valida(string $sessionHash): ?array
{
    $stmt = db()->prepare(
        'SELECT s.id, s.expira_en, t.id AS token_id, t.activo AS token_activo,
                u.*, t.token, t.etiqueta
           FROM sesiones s
           JOIN tokens t ON t.id = s.token_id
           JOIN usuarios u ON u.id = t.usuario_id
          WHERE s.session_hash = :hash
            AND s.expira_en > :ahora
            AND t.activo = 1
            AND u.activo = 1
          LIMIT 1'
    );
    $stmt->execute([':hash' => $sessionHash, ':ahora' => ahora()]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

function db_sesion_borrar(string $sessionHash): void
{
    $stmt = db()->prepare('DELETE FROM sesiones WHERE session_hash = :hash');
    $stmt->execute([':hash' => $sessionHash]);
}

function db_sesiones_purgar(): void
{
    db()->prepare('DELETE FROM sesiones WHERE expira_en <= :ahora')->execute([':ahora' => ahora()]);
}

function db_token_marcar_acceso(int $tokenId): void
{
    $stmt = db()->prepare(
        'UPDATE tokens SET ultimo_acceso = :ahora, accesos = accesos + 1 WHERE id = :id'
    );
    $stmt->execute([':ahora' => ahora(), ':id' => $tokenId]);
}

function db_token_por_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM tokens WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

function db_registrar_acceso(?string $usuario, ?string $token, string $resultado): void
{
    $stmt = db()->prepare(
        'INSERT INTO accesos (usuario, token, resultado, ip, user_agent, fecha)
         VALUES (:usuario, :token, :resultado, :ip, :ua, :fecha)'
    );
    $stmt->execute([
        ':usuario'   => $usuario,
        ':token'     => $token,
        ':resultado' => $resultado,
        ':ip'        => ip_cliente(),
        ':ua'        => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255, 'UTF-8'),
        ':fecha'     => ahora(),
    ]);
}

function db_accesos_listar(int $limite = 50): array
{
    $stmt = db()->prepare('SELECT * FROM accesos ORDER BY id DESC LIMIT :limite');
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Resumen de accesos fallidos recientes para el panel y las alertas.
 *
 * @return array{total:int,ips:int,ultimo:?string}
 */
function db_accesos_fallidos_resumen(int $horas = 24): array
{
    $horas = max(1, min(720, $horas));
    $desde = date('Y-m-d H:i:s', time() - ($horas * 3600));

    $stmt = db()->prepare(
        "SELECT COUNT(*) AS total,
                COUNT(DISTINCT ip) AS ips,
                MAX(fecha) AS ultimo
           FROM accesos
          WHERE resultado = 'fallido' AND fecha >= :desde"
    );
    $stmt->execute([':desde' => $desde]);
    $fila = $stmt->fetch() ?: [];

    return [
        'total'  => (int) ($fila['total'] ?? 0),
        'ips'    => (int) ($fila['ips'] ?? 0),
        'ultimo' => isset($fila['ultimo']) ? (string) $fila['ultimo'] : null,
    ];
}

/**
 * Consume una unidad de la ventana indicada.
 *
 * Devuelve true si la petición está permitida y false si se superó el máximo.
 */
function db_limite_consumir(string $clave, int $maximo, int $ventanaSegundos): bool
{
    if ($clave === '' || $maximo < 1 || $ventanaSegundos < 1) {
        return false;
    }

    $pdo = db();
    $ahora = time();
    $permitido = true;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT ventana, contador FROM limites WHERE clave = :clave');
        $stmt->execute([':clave' => $clave]);
        $fila = $stmt->fetch();

        if (!$fila || ($ahora - (int) $fila['ventana']) >= $ventanaSegundos) {
            $pdo->prepare(
                'INSERT INTO limites (clave, ventana, contador) VALUES (:clave, :ventana, 1)
                 ON CONFLICT(clave) DO UPDATE SET ventana = excluded.ventana, contador = 1'
            )->execute([':clave' => $clave, ':ventana' => $ahora]);
        } elseif ((int) $fila['contador'] >= $maximo) {
            $permitido = false;
        } else {
            $pdo->prepare('UPDATE limites SET contador = contador + 1 WHERE clave = :clave')
                ->execute([':clave' => $clave]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Ante un fallo del limitador, se permite la petición pero se registra.
        error_log('[administracion] Limitador: ' . $e->getMessage());
        return true;
    }

    return $permitido;
}

/** Comprueba si una clave ya agotó su ventana (sin consumir). */
function db_limite_agotado(string $clave, int $maximo, int $ventanaSegundos): bool
{
    $stmt = db()->prepare('SELECT ventana, contador FROM limites WHERE clave = :clave');
    $stmt->execute([':clave' => $clave]);
    $fila = $stmt->fetch();
    if (!$fila) {
        return false;
    }
    if ((time() - (int) $fila['ventana']) >= $ventanaSegundos) {
        return false;
    }
    return (int) $fila['contador'] >= $maximo;
}

/** Elimina ventanas de rate limiting ya caducadas. */
function db_limites_purgar(int $dias = 7): void
{
    $limite = time() - (max(1, $dias) * 86400);
    db()->prepare('DELETE FROM limites WHERE ventana < :limite')->execute([':limite' => $limite]);
}

/** Elimina registros antiguos de la bitácora de accesos. */
function db_accesos_purgar(): void
{
    $limite = date('Y-m-d H:i:s', time() - (ACCESOS_RETENCION_DIAS * 86400));
    db()->prepare('DELETE FROM accesos WHERE fecha < :limite')->execute([':limite' => $limite]);
}

/** Estadísticas rápidas para el panel principal. */
function db_estadisticas(): array
{
    $pdo = db();
    return [
        'usuarios'        => (int) $pdo->query('SELECT COUNT(*) FROM usuarios WHERE activo = 1')->fetchColumn(),
        'usuarios_total'  => (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn(),
        'tokens_activos'  => (int) $pdo->query('SELECT COUNT(*) FROM tokens WHERE activo = 1')->fetchColumn(),
        'tokens_total'    => (int) $pdo->query('SELECT COUNT(*) FROM tokens')->fetchColumn(),
        'accesos_ok'      => (int) $pdo->query("SELECT COUNT(*) FROM accesos WHERE resultado = 'ok'")->fetchColumn(),
        'accesos_fallidos' => (int) $pdo->query("SELECT COUNT(*) FROM accesos WHERE resultado = 'fallido'")->fetchColumn(),
    ];
}
