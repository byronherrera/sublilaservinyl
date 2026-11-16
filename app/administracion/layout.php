<?php
/**
 * Cabecera y pie comunes del panel de administración.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/analitica.php';

function render_inicio(string $titulo, string $activo, array $usuario, string $pagina = ''): void
{
    // Seguimiento de las páginas del panel (ámbito admin), por usuario y token.
    if ($pagina !== '') {
        analitica_registrar_admin($usuario, $pagina);
    }

    $nombre = (string) ($usuario['nombre'] ?? 'Invitado');
    $rol    = nombre_rol((string) ($usuario['rol'] ?? 'lectura'));
    $csrf   = csrf_token();

    $enlaces = [
        'panel'    => ['index.php',    'fa-gauge-high',    'Panel'],
        'archivos' => ['archivos.php', 'fa-folder-open',   'Archivos'],
        'estadisticas' => ['estadisticas.php', 'fa-chart-line', 'Estadísticas'],
        'usuarios' => ['usuarios.php', 'fa-users-gear',    'Usuarios'],
        'actividad' => ['actividad.php', 'fa-clock-rotate-left', 'Actividad'],
    ];
    if (!es_admin($usuario)) {
        unset($enlaces['usuarios']);
    }
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($titulo) ?> · Administración Sublilaservinyl</title>
    <link rel="icon" type="image/svg+xml" href="../assets/isotipo.svg">
    <script src="../assets/tema.js"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/admin.css?v=<?= (int) @filemtime(__DIR__ . '/assets/admin.css') ?>">
    <meta name="csrf-token" content="<?= e($csrf) ?>">
    <script nonce="<?= e(csp_nonce()) ?>">window.ADMIN = { csrf: <?= json_encode($csrf) ?>, pagina: <?= json_encode($pagina) ?> };</script>
</head>
<body data-page="<?= e($pagina) ?>">
<header class="topbar">
    <div class="topbar-inner">
        <a class="marca" href="index.php">
            <img src="../assets/logo.svg" alt="Sublilaservinyl" class="marca-logo logo-img--oscuro" width="1200" height="390">
            <img src="../assets/logo-color.svg" alt="" aria-hidden="true" class="marca-logo logo-img--claro" width="1200" height="390">
        </a>

        <button type="button" class="tema-toggle" data-tema-toggle aria-label="Cambiar a tema claro" title="Cambiar a tema claro"><i class="fa-solid fa-sun" aria-hidden="true"></i></button>

        <button class="nav-toggle" type="button" aria-label="Abrir menú" data-nav-toggle>
            <i class="fa-solid fa-bars"></i>
        </button>

        <nav class="nav" data-nav>
            <?php foreach ($enlaces as $clave => [$href, $icono, $texto]): ?>
                <a href="<?= e($href) ?>" class="nav-link<?= $clave === $activo ? ' activo' : '' ?>">
                    <i class="fa-solid <?= e($icono) ?>"></i><span><?= e($texto) ?></span>
                </a>
            <?php endforeach; ?>
            <a href="../index.html" class="nav-link nav-app" target="_blank" rel="noopener">
                <i class="fa-solid fa-arrow-up-right-from-square"></i><span>Ver app</span>
            </a>
        </nav>

        <div class="usuario-chip">
            <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($nombre, 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
            <span class="usuario-datos">
                <strong><?= e($nombre) ?></strong>
                <small><?= e($rol) ?></small>
            </span>
            <button type="button" class="btn-icon" data-salir title="Cerrar sesión" aria-label="Cerrar sesión">
                <i class="fa-solid fa-right-from-bracket"></i>
            </button>
        </div>
    </div>
</header>

<main class="contenido">
    <?php
}

function render_fin(): void
{
    ?>
</main>

<div class="toasts" data-toasts aria-live="polite"></div>

<div class="modal" data-modal hidden>
    <div class="modal-caja" role="dialog" aria-modal="true">
        <h3 data-modal-titulo>Confirmar</h3>
        <p data-modal-texto></p>
        <div class="modal-acciones">
            <button type="button" class="btn btn-ghost" data-modal-cancelar>Cancelar</button>
            <button type="button" class="btn btn-danger" data-modal-ok>Confirmar</button>
        </div>
    </div>
</div>

<script src="assets/admin.js?v=<?= (int) @filemtime(__DIR__ . '/assets/admin.js') ?>"></script>
</body>
</html>
    <?php
}

/** Cabecera de sección con título y descripción. */
function seccion_titulo(string $titulo, string $descripcion = ''): void
{
    ?>
    <div class="page-head">
        <div>
            <h1><?= e($titulo) ?></h1>
            <?php if ($descripcion !== ''): ?>
                <p class="page-desc"><?= e($descripcion) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function badge_rol(string $rol): string
{
    $clase = match ($rol) {
        'admin'   => 'badge badge-admin',
        'editor'  => 'badge badge-editor',
        default   => 'badge badge-lectura',
    };
    return '<span class="' . $clase . '">' . e(nombre_rol($rol)) . '</span>';
}
