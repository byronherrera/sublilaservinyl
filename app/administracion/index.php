<?php
/**
 * Panel principal de administración.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/archivos_lib.php';
require_once __DIR__ . '/analitica.php';
require_once __DIR__ . '/layout.php';

$usuario = require_auth();

log_purgar_antiguos();
db_sesiones_purgar();
db_accesos_purgar();
db_limites_purgar();
analitica_purgar();

$stats  = db_estadisticas();
$fs     = fs_estadisticas();
$logs   = log_ultimas_lineas(8);
$accesos = db_accesos_listar(6);
$enlaceActual = enlace_acceso((string) $usuario['token']);
$primerAcceso = is_file(PRIMER_ACCESO_PATH);
$analitica     = analitica_resumen('web');
$topRutas      = analitica_agrupado('ruta', 3, 'web');
$analiticaAdmin = analitica_resumen('admin');
$accesosFallidos = db_accesos_fallidos_resumen(ALERTA_ACCESOS_HORAS);
$alertaAccesos = $accesosFallidos['total'] >= ALERTA_ACCESOS_UMBRAL;

render_inicio('Panel', 'panel', $usuario, 'panel');
seccion_titulo('Panel de administración', 'Control de accesos, usuarios y archivos de /proyectos.');
?>

<div class="card">
    <div class="card-head">
        <div class="card-titulo"><i class="fa-solid fa-chart-simple"></i><h2>Resumen</h2></div>
        <span class="badge badge-on"><i class="fa-solid fa-user-shield"></i> <?= e((string) $usuario['nombre']) ?> · <?= e(ucfirst((string) $usuario['rol'])) ?></span>
    </div>
    <div class="grid grid-stats">
        <div class="form-acciones" style="margin-top:16px">
            <a class="btn btn-primary" href="archivos.php"><i class="fa-solid fa-folder-open"></i> Ir a imágenes, proyectos y archivos</a>
        </div>
        <div class="stat">
            <div class="stat-label">Usuarios activos</div>
            <div class="stat-valor"><?= (int) $stats['usuarios'] ?></div>
            <div class="stat-extra"><?= (int) $stats['usuarios_total'] ?> registrados en total</div>
        </div>
        <div class="stat">
            <div class="stat-label">Tokens activos</div>
            <div class="stat-valor"><?= (int) $stats['tokens_activos'] ?></div>
            <div class="stat-extra"><?= (int) $stats['tokens_total'] ?> emitidos históricamente</div>
        </div>
        <div class="stat">
            <div class="stat-label">Proyectos</div>
            <div class="stat-valor"><?= (int) $fs['proyectos'] ?></div>
            <div class="stat-extra"><?= (int) $fs['carpetas'] ?> carpetas internas</div>
        </div>
        <div class="stat">
            <div class="stat-label">Imágenes</div>
            <div class="stat-valor"><?= (int) $fs['imagenes'] ?></div>
            <div class="stat-extra"><?= e($fs['peso']) ?> en /proyectos</div>
        </div>
        <div class="stat">
            <div class="stat-label">Accesos correctos</div>
            <div class="stat-valor"><?= (int) $stats['accesos_ok'] ?></div>
            <div class="stat-extra"><?= (int) $stats['accesos_fallidos'] ?> intentos fallidos</div>
        </div>
    </div>
</div>

<?php if ($alertaAccesos): ?>
<div class="card" id="aviso-accesos-fallidos" style="border-color:rgba(239,68,68,.5);margin-top:20px">
    <div class="card-titulo" style="margin-bottom:8px"><i class="fa-solid fa-triangle-exclamation"></i><h2>Alerta de accesos fallidos</h2></div>
    <p class="page-desc">
        Se han registrado <strong><?= (int) $accesosFallidos['total'] ?></strong> intentos de acceso fallidos
        en las últimas <?= (int) ALERTA_ACCESOS_HORAS ?> horas desde
        <strong><?= (int) $accesosFallidos['ips'] ?></strong> IP(s).
        <?php if ($accesosFallidos['ultimo'] !== null): ?>
            Último intento: <code class="mono"><?= e($accesosFallidos['ultimo']) ?></code>.
        <?php endif; ?>
    </p>
    <p class="page-desc">
        Revisa la bitácora en <a href="actividad.php">Actividad</a>. Si el volumen es anómalo,
        revoca los tokens afectados desde <a href="usuarios.php">Usuarios y accesos</a>.
    </p>
</div>
<?php endif; ?>

<?php if ($primerAcceso): ?>
<div class="card" id="aviso-primer-acceso" style="border-color:rgba(197,161,91,.4);margin-top:20px">
    <div class="card-titulo" style="margin-bottom:8px"><i class="fa-solid fa-key"></i><h2>Acceso inicial generado</h2></div>
    <p class="page-desc">
        Se creó la base de datos y un token de administrador. El enlace está guardado en
        <code class="mono">app/administracion/data/primer-acceso.txt</code>.
        Guarda tu dirección y elimina ese archivo cuando ya la tengas a mano.
    </p>
    <div class="form-acciones">
        <button type="button" class="btn btn-sm btn-danger" id="btn-eliminar-primer-acceso">
            <i class="fa-solid fa-trash"></i> Ya guardé mi enlace, eliminar archivo
        </button>
    </div>
</div>
<?php endif; ?>

<div class="card" style="margin-top:20px">
    <div class="card-head">
        <div class="card-titulo"><i class="fa-solid fa-link"></i><h2>Tu dirección de acceso</h2></div>
        <span class="badge badge-on"><i class="fa-solid fa-shield-halved"></i> Token activo</span>
    </div>
    <p class="page-desc">Guarda este enlace: es la única forma de entrar al panel. Si lo pierdes, un administrador puede restaurarlo desde <a href="usuarios.php">Usuarios y accesos</a>.</p>
    <div class="enlace-caja">
        <code id="enlace-actual"><?= e($enlaceActual) ?></code>
        <button class="btn btn-sm" data-copiar="<?= e($enlaceActual) ?>"><i class="fa-solid fa-copy"></i> Copiar</button>
        <a class="btn btn-sm btn-primary" href="<?= e($enlaceActual) ?>"><i class="fa-solid fa-arrow-right-to-bracket"></i> Abrir</a>
    </div>
</div>

<div class="card mt-24">
    <div class="card-head">
        <div class="card-titulo"><i class="fa-solid fa-chart-line"></i><h2>Mini analítica de la web</h2></div>
        <a class="btn btn-sm" href="estadisticas.php">Ver estadísticas</a>
    </div>
    <p class="page-desc">Visitas anónimas de la web pública y uso del panel. Sin cookies de terceros ni servicios externos.</p>
    <div class="mini-analitica">
        <div class="stat">
            <div class="stat-label">Web hoy</div>
            <div class="stat-valor"><?= (int) $analitica['hoy'] ?></div>
            <div class="stat-extra"><?= (int) $analitica['hoy_unicos'] ?> visitantes únicos</div>
        </div>
        <div class="stat">
            <div class="stat-label">Web 7 días</div>
            <div class="stat-valor"><?= (int) $analitica['semana'] ?></div>
            <div class="stat-extra"><?= (int) $analitica['semana_unicos'] ?> únicos</div>
        </div>
        <div class="stat">
            <div class="stat-label">Web 30 días</div>
            <div class="stat-valor"><?= (int) $analitica['mes'] ?></div>
            <div class="stat-extra"><?= (int) $analitica['mes_unicos'] ?> únicos</div>
        </div>
        <div class="stat">
            <div class="stat-label">Panel hoy</div>
            <div class="stat-valor"><?= (int) $analiticaAdmin['hoy'] ?></div>
            <div class="stat-extra"><?= (int) $analiticaAdmin['hoy_unicos'] ?> usuarios activos</div>
        </div>
        <div class="stat">
            <div class="stat-label">Total web</div>
            <div class="stat-valor"><?= (int) $analitica['total'] ?></div>
            <div class="stat-extra"><?= e((string) $analitica['media_diaria']) ?> visitas/día</div>
        </div>
    </div>
    <?php if ($topRutas): ?>
        <div class="rank mt-24">
            <?php $maxMini = max(array_column($topRutas, 'total')); ?>
            <?php foreach ($topRutas as $fila): ?>
                <div class="rank-item">
                    <span class="rank-nombre"><?= e(analitica_etiqueta_ruta($fila['etiqueta'])) ?></span>
                    <span class="rank-total"><?= (int) $fila['total'] ?></span>
                    <div class="rank-barra"><span style="width:<?= max(3, (int) round(($fila['total'] / max(1, $maxMini)) * 100)) ?>%"></span></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="page-desc mt-24">Aún no hay visitas. Abre la web pública para empezar a medir.</p>
    <?php endif; ?>
</div>

<div class="grid grid-2 mt-24">
    <div class="card">
        <div class="card-head">
            <div class="card-titulo"><i class="fa-solid fa-clock-rotate-left"></i><h2>Actividad reciente</h2></div>
            <a class="btn btn-sm" href="actividad.php">Ver todo</a>
        </div>
        <?php if (!$logs): ?>
            <div class="vacio"><i class="fa-regular fa-clock"></i>Aún no hay actividad registrada.</div>
        <?php else: ?>
            <?php foreach ($logs as $linea): ?>
                <div class="log-linea">
                    <span class="log-fecha"><?= e($linea['fecha']) ?></span>
                    <span class="log-usuario"><?= e($linea['usuario']) ?></span>
                    <span><span class="log-accion"><?= e($linea['accion']) ?></span> · <?= e($linea['detalle']) ?></span>
                    <span class="log-ip"><?= e($linea['ip']) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head">
            <div class="card-titulo"><i class="fa-solid fa-right-to-bracket"></i><h2>Últimos accesos</h2></div>
            <a class="btn btn-sm" href="usuarios.php">Gestionar tokens</a>
        </div>
        <?php if (!$accesos): ?>
            <div class="vacio"><i class="fa-regular fa-user"></i>Sin registros de acceso.</div>
        <?php else: ?>
            <div class="tabla-wrap">
                <table class="tabla">
                    <thead><tr><th>Fecha</th><th>Usuario</th><th>Resultado</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($accesos as $a): ?>
                        <tr>
                            <td class="texto-muted ahorarap"><?= e($a['fecha']) ?></td>
                            <td><?= e($a['usuario'] ?: '—') ?></td>
                            <td>
                                <?php if ($a['resultado'] === 'ok'): ?>
                                    <span class="badge badge-on">Correcto</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Fallido</span>
                                <?php endif; ?>
                            </td>
                            <td class="mono"><?= e($a['ip']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php render_fin(); ?>
