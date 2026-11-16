<?php
/**
 * Visor del log de actividades (archivos logs/actividad-AAAA-MM.log).
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$usuario = require_auth();

$archivos = log_archivos();
$seleccionado = '';
if (isset($_GET['archivo']) && is_string($_GET['archivo'])) {
    $candidato = basename($_GET['archivo']);
    if (preg_match('/^actividad-\d{4}-\d{2}\.log$/', $candidato)) {
        $seleccionado = $candidato;
    }
}
if ($seleccionado === '') {
    $seleccionado = 'actividad-' . date('Y-m') . '.log';
}

$lineas = log_leer_archivo($seleccionado, 300);

render_inicio('Actividad', 'actividad', $usuario, 'actividad');
seccion_titulo('Registro de actividad', 'Cada acción del panel queda registrada en un archivo mensual dentro de app/administracion/logs.');
?>

<div class="card">
    <div class="card-head">
        <div class="card-titulo"><i class="fa-solid fa-clock-rotate-left"></i><h2>Bitácora</h2></div>
        <div class="acciones">
            <a class="btn btn-sm" href="log_descargar.php?archivo=<?= e(rawurlencode($seleccionado)) ?>">
                <i class="fa-solid fa-download"></i> Descargar archivo
            </a>
        </div>
    </div>

    <?php if ($archivos): ?>
        <div class="fila" style="margin-bottom:16px">
            <?php foreach ($archivos as $archivo): ?>
                <a class="btn btn-sm<?= $archivo['nombre'] === $seleccionado ? ' btn-primary' : '' ?>"
                   href="actividad.php?archivo=<?= e(rawurlencode($archivo['nombre'])) ?>">
                    <i class="fa-regular fa-calendar"></i> <?= e($archivo['nombre']) ?>
                    <span class="texto-muted">(<?= (int) $archivo['lineas'] ?>)</span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="page-desc" style="margin-bottom:14px">
        Mostrando <strong><?= count($lineas) ?></strong> registro(s) de
        <code class="mono">logs/<?= e($seleccionado) ?></code> (más recientes primero).
        Retención: <?= (int) LOG_RETENCION_DIAS ?> días.
    </p>

    <?php if (!$lineas): ?>
        <div class="vacio"><i class="fa-regular fa-clock"></i>No hay actividad registrada en este periodo.</div>
    <?php else: ?>
        <div class="tabla-wrap">
            <?php foreach ($lineas as $linea): ?>
                <div class="log-linea">
                    <span class="log-fecha"><?= e($linea['fecha']) ?></span>
                    <span class="log-usuario"><i class="fa-solid fa-user" style="font-size:.7rem;opacity:.6"></i> <?= e($linea['usuario']) ?></span>
                    <span><span class="log-accion"><?= e($linea['accion']) ?></span> · <?= e($linea['detalle']) ?></span>
                    <span class="log-ip"><?= e($linea['ip']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php render_fin(); ?>
