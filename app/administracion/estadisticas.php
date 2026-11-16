<?php
/**
 * Estadísticas de la web pública (mini Google Analytics).
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/analitica.php';
require_once __DIR__ . '/layout.php';

$usuario = require_auth();
analitica_purgar();

$resumen      = analitica_resumen('web');
$serie        = analitica_serie(14, 'web');
$rutas        = analitica_agrupado('ruta', 8, 'web');
$referentes   = analitica_agrupado('referente_host', 6, 'web');
$dispositivos = analitica_agrupado('dispositivo', 5, 'web');
$navegadores  = analitica_agrupado('navegador', 6, 'web');
$sistemas     = analitica_agrupado('sistema', 6, 'web');
$ultimas      = analitica_ultimas(15, 'web');

// Seguimiento del panel: páginas, usuarios y uso de tokens.
$resumenAdmin   = analitica_resumen('admin');
$serieAdmin     = analitica_serie(14, 'admin');
$paginasAdmin   = analitica_agrupado('ruta', 8, 'admin');
$usuariosRank   = analitica_agrupado('visitante', 6, 'admin');
$ultimasAdmin   = analitica_ultimas(15, 'admin');
$actividadUsers = analitica_usuarios_actividad(30);
$tokensUso      = analitica_tokens_uso(30);

$nombresUsuarios = [];
foreach (db_usuarios_listar() as $filaUsuario) {
    $nombresUsuarios[(int) $filaUsuario['id']] = (string) $filaUsuario['nombre'];
}

/** Nombre del usuario a partir del visitante "admin_<id>". */
function nombre_visitante_admin(string $visitante, array $nombres): string
{
    if (preg_match('/^admin_(\d+)$/', $visitante, $m)) {
        $id = (int) $m[1];
        return $nombres[$id] ?? ('Usuario #' . $id);
    }
    return 'Anónimo';
}

render_inicio('Estadísticas', 'estadisticas', $usuario, 'estadisticas');
seccion_titulo(
    'Estadísticas',
    'Mini Google Analytics propio: visitas de la web pública y seguimiento del panel, los usuarios y el uso de tokens.'
);
?>

<div class="toolbar">
    <a class="btn btn-primary" href="../index.html" target="_blank" rel="noopener">
        <i class="fa-solid fa-arrow-up-right-from-square"></i> Abrir la web
    </a>
    <span class="separador"></span>
    <span class="badge badge-on"><i class="fa-solid fa-shield-halved"></i> Datos anónimos, sin servicios externos</span>
</div>

<div class="card">
    <div class="card-head">
        <div class="card-titulo"><i class="fa-solid fa-chart-simple"></i><h2>Resumen</h2></div>
        <span class="badge badge-lectura">Últimos 30 días</span>
    </div>
    <div class="grid grid-stats">
        <div class="stat">
            <div class="stat-label">Visitas hoy</div>
            <div class="stat-valor"><?= (int) $resumen['hoy'] ?></div>
            <div class="stat-extra">
                <?php if ($resumen['variacion'] === null): ?>
                    Sin datos de ayer
                <?php else: ?>
                    <?= $resumen['variacion'] >= 0 ? '+' : '' ?><?= number_format((float) $resumen['variacion'], 0, ',', '.') ?>% vs. ayer
                <?php endif; ?>
            </div>
        </div>
        <div class="stat">
            <div class="stat-label">Visitantes únicos hoy</div>
            <div class="stat-valor"><?= (int) $resumen['hoy_unicos'] ?></div>
            <div class="stat-extra"><?= (int) $resumen['ayer'] ?> visitas ayer</div>
        </div>
        <div class="stat">
            <div class="stat-label">Visitas 7 días</div>
            <div class="stat-valor"><?= (int) $resumen['semana'] ?></div>
            <div class="stat-extra"><?= (int) $resumen['semana_unicos'] ?> visitantes únicos</div>
        </div>
        <div class="stat">
            <div class="stat-label">Visitas 30 días</div>
            <div class="stat-valor"><?= (int) $resumen['mes'] ?></div>
            <div class="stat-extra"><?= (int) $resumen['mes_unicos'] ?> visitantes únicos</div>
        </div>
        <div class="stat">
            <div class="stat-label">Total histórico</div>
            <div class="stat-valor"><?= (int) $resumen['total'] ?></div>
            <div class="stat-extra"><?= e((string) $resumen['media_diaria']) ?> visitas/día de media</div>
        </div>
    </div>
</div>

<div class="card mt-24">
    <div class="card-head">
        <div class="card-titulo"><i class="fa-solid fa-chart-column"></i><h2>Visitas de los últimos 14 días</h2></div>
        <span class="badge badge-lectura">Máximo: <?= (int) $serie['max'] ?></span>
    </div>
    <?php if ((int) $resumen['total'] === 0): ?>
        <div class="vacio"><i class="fa-solid fa-chart-line"></i>Aún no hay visitas registradas. Abre la web pública para empezar a medir.</div>
    <?php else: ?>
        <div class="chart" role="img" aria-label="Gráfico de visitas diarias de los últimos 14 días">
            <?php foreach ($serie['datos'] as $dia): ?>
                <?php
                $altura = $serie['max'] > 0 ? max(2, (int) round(($dia['total'] / $serie['max']) * 100)) : 2;
                $esHoy = $dia['fecha'] === date('Y-m-d');
                ?>
                <div class="chart-col<?= $esHoy ? ' hoy' : '' ?>"
                     title="<?= e($dia['fecha']) ?>: <?= (int) $dia['total'] ?> visitas · <?= (int) $dia['unicos'] ?> únicos">
                    <span class="chart-valor"><?= (int) $dia['total'] ?></span>
                    <div class="chart-bar" style="height:<?= $altura ?>%"></div>
                    <span class="chart-fecha"><?= e($esHoy ? 'hoy' : $dia['etiqueta']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="grid grid-2 mt-24">
    <div class="card">
        <div class="card-head">
            <div class="card-titulo"><i class="fa-solid fa-folder-tree"></i><h2>Páginas y carpetas más vistas</h2></div>
        </div>
        <?php if (!$rutas): ?>
            <div class="vacio">Sin datos todavía.</div>
        <?php else: ?>
            <?php $maxRuta = max(array_column($rutas, 'total')); ?>
            <div class="rank">
                <?php foreach ($rutas as $fila): ?>
                    <div class="rank-item">
                        <span class="rank-nombre" title="<?= e(analitica_etiqueta_ruta($fila['etiqueta'])) ?>"><?= e(analitica_etiqueta_ruta($fila['etiqueta'])) ?></span>
                        <span class="rank-total"><?= (int) $fila['total'] ?></span>
                        <div class="rank-barra"><span style="width:<?= max(3, (int) round(($fila['total'] / max(1, $maxRuta)) * 100)) ?>%"></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head">
            <div class="card-titulo"><i class="fa-solid fa-arrow-up-right-dots"></i><h2>Orígenes del tráfico</h2></div>
        </div>
        <?php if (!$referentes): ?>
            <div class="vacio">Sin datos todavía.</div>
        <?php else: ?>
            <?php $maxRef = max(array_column($referentes, 'total')); ?>
            <div class="rank">
                <?php foreach ($referentes as $fila): ?>
                    <div class="rank-item">
                        <span class="rank-nombre"><?= e($fila['etiqueta'] === '(directo)' ? 'Directo / enlace guardado' : $fila['etiqueta']) ?></span>
                        <span class="rank-total"><?= (int) $fila['total'] ?></span>
                        <div class="rank-barra"><span style="width:<?= max(3, (int) round(($fila['total'] / max(1, $maxRef)) * 100)) ?>%"></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-3 mt-24">
    <div class="card">
        <div class="card-head">
            <div class="card-titulo"><i class="fa-solid fa-mobile-screen"></i><h2>Dispositivos</h2></div>
        </div>
        <?php if (!$dispositivos): ?>
            <div class="vacio">Sin datos.</div>
        <?php else: ?>
            <div class="rank">
                <?php foreach ($dispositivos as $fila): ?>
                    <div class="rank-item">
                        <span class="rank-nombre"><?= e($fila['etiqueta']) ?></span>
                        <span class="rank-total"><?= (int) $fila['total'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head">
            <div class="card-titulo"><i class="fa-solid fa-window-maximize"></i><h2>Navegadores</h2></div>
        </div>
        <?php if (!$navegadores): ?>
            <div class="vacio">Sin datos.</div>
        <?php else: ?>
            <div class="rank">
                <?php foreach ($navegadores as $fila): ?>
                    <div class="rank-item">
                        <span class="rank-nombre"><?= e($fila['etiqueta']) ?></span>
                        <span class="rank-total"><?= (int) $fila['total'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head">
            <div class="card-titulo"><i class="fa-solid fa-laptop"></i><h2>Sistemas</h2></div>
        </div>
        <?php if (!$sistemas): ?>
            <div class="vacio">Sin datos.</div>
        <?php else: ?>
            <div class="rank">
                <?php foreach ($sistemas as $fila): ?>
                    <div class="rank-item">
                        <span class="rank-nombre"><?= e($fila['etiqueta']) ?></span>
                        <span class="rank-total"><?= (int) $fila['total'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-24">
    <div class="card-head">
        <div class="card-titulo"><i class="fa-solid fa-clock-rotate-left"></i><h2>Últimas visitas</h2></div>
    </div>
    <?php if (!$ultimas): ?>
        <div class="vacio"><i class="fa-regular fa-eye"></i>Todavía no se ha registrado ninguna visita.</div>
    <?php else: ?>
        <div class="tabla-wrap">
            <table class="tabla">
                <thead>
                    <tr><th>Fecha</th><th>Página</th><th>Origen</th><th>Dispositivo</th><th>Navegador</th></tr>
                </thead>
                <tbody>
                <?php foreach ($ultimas as $visita): ?>
                    <tr>
                        <td class="texto-muted ahorarap"><?= e((string) $visita['creado_en']) ?></td>
                        <td><?= e(analitica_etiqueta_ruta((string) $visita['ruta'])) ?></td>
                        <td><?= e($visita['referente_host'] !== '' && $visita['referente_host'] !== null ? (string) $visita['referente_host'] : 'Directo') ?></td>
                        <td><?= e((string) $visita['dispositivo']) ?></td>
                        <td><?= e((string) $visita['navegador']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<hr class="separador-seccion">

<div class="card">
    <div class="card-head">
        <div class="card-titulo"><i class="fa-solid fa-user-shield"></i><h2>Panel de administración</h2></div>
        <span class="badge badge-lectura">Seguimiento interno</span>
    </div>
    <p class="page-desc">Páginas del panel visitadas, actividad por usuario y uso de cada token de acceso.</p>
    <div class="grid grid-stats">
        <div class="stat">
            <div class="stat-label">Páginas hoy</div>
            <div class="stat-valor"><?= (int) $resumenAdmin['hoy'] ?></div>
            <div class="stat-extra">
                <?php if ($resumenAdmin['variacion'] === null): ?>
                    Sin datos de ayer
                <?php else: ?>
                    <?= $resumenAdmin['variacion'] >= 0 ? '+' : '' ?><?= number_format((float) $resumenAdmin['variacion'], 0, ',', '.') ?>% vs. ayer
                <?php endif; ?>
            </div>
        </div>
        <div class="stat">
            <div class="stat-label">Usuarios activos hoy</div>
            <div class="stat-valor"><?= (int) $resumenAdmin['hoy_unicos'] ?></div>
            <div class="stat-extra"><?= (int) $resumenAdmin['ayer'] ?> páginas ayer</div>
        </div>
        <div class="stat">
            <div class="stat-label">Páginas 7 días</div>
            <div class="stat-valor"><?= (int) $resumenAdmin['semana'] ?></div>
            <div class="stat-extra"><?= (int) $resumenAdmin['semana_unicos'] ?> usuarios únicos</div>
        </div>
        <div class="stat">
            <div class="stat-label">Páginas 30 días</div>
            <div class="stat-valor"><?= (int) $resumenAdmin['mes'] ?></div>
            <div class="stat-extra"><?= (int) $resumenAdmin['mes_unicos'] ?> usuarios únicos</div>
        </div>
        <div class="stat">
            <div class="stat-label">Total histórico</div>
            <div class="stat-valor"><?= (int) $resumenAdmin['total'] ?></div>
            <div class="stat-extra"><?= e((string) $resumenAdmin['media_diaria']) ?> páginas/día</div>
        </div>
    </div>
</div>

<div class="card mt-24">
    <div class="card-head">
        <div class="card-titulo"><i class="fa-solid fa-chart-column"></i><h2>Uso del panel en los últimos 14 días</h2></div>
        <span class="badge badge-lectura">Máximo: <?= (int) $serieAdmin['max'] ?></span>
    </div>
    <?php if ((int) $resumenAdmin['total'] === 0): ?>
        <div class="vacio"><i class="fa-solid fa-chart-line"></i>Aún no hay páginas del panel registradas.</div>
    <?php else: ?>
        <div class="chart" role="img" aria-label="Gráfico de páginas del panel visitadas en los últimos 14 días">
            <?php foreach ($serieAdmin['datos'] as $dia): ?>
                <?php
                $altura = $serieAdmin['max'] > 0 ? max(2, (int) round(($dia['total'] / $serieAdmin['max']) * 100)) : 2;
                $esHoy = $dia['fecha'] === date('Y-m-d');
                ?>
                <div class="chart-col<?= $esHoy ? ' hoy' : '' ?>"
                     title="<?= e($dia['fecha']) ?>: <?= (int) $dia['total'] ?> páginas · <?= (int) $dia['unicos'] ?> usuarios">
                    <span class="chart-valor"><?= (int) $dia['total'] ?></span>
                    <div class="chart-bar" style="height:<?= $altura ?>%"></div>
                    <span class="chart-fecha"><?= e($esHoy ? 'hoy' : $dia['etiqueta']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="grid grid-2 mt-24">
    <div class="card">
        <div class="card-head">
            <div class="card-titulo"><i class="fa-solid fa-folder-tree"></i><h2>Secciones más usadas</h2></div>
        </div>
        <?php if (!$paginasAdmin): ?>
            <div class="vacio">Sin datos todavía.</div>
        <?php else: ?>
            <?php $maxPagina = max(array_column($paginasAdmin, 'total')); ?>
            <div class="rank">
                <?php foreach ($paginasAdmin as $fila): ?>
                    <div class="rank-item">
                        <span class="rank-nombre"><?= e(analitica_etiqueta_ruta($fila['etiqueta'])) ?></span>
                        <span class="rank-total"><?= (int) $fila['total'] ?></span>
                        <div class="rank-barra"><span style="width:<?= max(3, (int) round(($fila['total'] / max(1, $maxPagina)) * 100)) ?>%"></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head">
            <div class="card-titulo"><i class="fa-solid fa-users"></i><h2>Usuarios con más actividad</h2></div>
        </div>
        <?php if (!$usuariosRank): ?>
            <div class="vacio">Sin datos todavía.</div>
        <?php else: ?>
            <?php $maxUsuario = max(array_column($usuariosRank, 'total')); ?>
            <div class="rank">
                <?php foreach ($usuariosRank as $fila): ?>
                    <div class="rank-item">
                        <span class="rank-nombre"><?= e(nombre_visitante_admin($fila['etiqueta'], $nombresUsuarios)) ?></span>
                        <span class="rank-total"><?= (int) $fila['total'] ?></span>
                        <div class="rank-barra"><span style="width:<?= max(3, (int) round(($fila['total'] / max(1, $maxUsuario)) * 100)) ?>%"></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-24">
    <div class="card-head">
        <div class="card-titulo"><i class="fa-solid fa-users-gear"></i><h2>Actividad por usuario</h2></div>
    </div>
    <?php if (!$actividadUsers): ?>
        <div class="vacio">Todavía no hay usuarios registrados.</div>
    <?php else: ?>
        <div class="tabla-wrap">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>Usuario</th><th>Rol</th><th>Estado</th><th>Páginas</th>
                        <th>Tokens activos</th><th>Accesos de login</th><th>Última actividad</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($actividadUsers as $fila): ?>
                    <?php $ultima = (string) ($fila['ultima_pagina'] ?: $fila['ultimo_acceso']); ?>
                    <tr>
                        <td><strong><?= e((string) $fila['nombre']) ?></strong></td>
                        <td><?= badge_rol((string) $fila['rol']) ?></td>
                        <td><?= (int) $fila['activo'] === 1 ? 'Activo' : 'Desactivado' ?></td>
                        <td><?= (int) $fila['paginas'] ?></td>
                        <td><?= (int) $fila['tokens_activos'] ?> / <?= (int) $fila['total_tokens'] ?></td>
                        <td><?= (int) $fila['accesos_token'] ?></td>
                        <td class="texto-muted ahorarap"><?= e($ultima !== '' ? $ultima : 'Sin actividad') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card mt-24">
    <div class="card-head">
        <div class="card-titulo"><i class="fa-solid fa-key"></i><h2>Uso de tokens</h2></div>
    </div>
    <?php if (!$tokensUso): ?>
        <div class="vacio">No hay tokens creados.</div>
    <?php else: ?>
        <div class="tabla-wrap">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>Token</th><th>Usuario</th><th>Estado</th><th>Accesos de login</th>
                        <th>Páginas vistas</th><th>Creado</th><th>Último acceso</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tokensUso as $fila): ?>
                    <?php
                    $etiquetaToken = trim((string) ($fila['etiqueta'] ?? ''));
                    $etiquetaToken = $etiquetaToken !== '' ? $etiquetaToken : 'Token #' . (int) $fila['id'];
                    ?>
                    <tr>
                        <td><?= e($etiquetaToken) ?></td>
                        <td><?= e((string) $fila['usuario']) ?></td>
                        <td><?= (int) $fila['activo'] === 1 ? 'Activo' : 'Revocado' ?></td>
                        <td><?= (int) $fila['accesos'] ?></td>
                        <td><?= (int) $fila['paginas'] ?></td>
                        <td class="texto-muted ahorarap"><?= e((string) $fila['creado_en']) ?></td>
                        <td class="texto-muted ahorarap"><?= e($fila['ultimo_acceso'] !== null && $fila['ultimo_acceso'] !== '' ? (string) $fila['ultimo_acceso'] : 'Nunca') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card mt-24">
    <div class="card-head">
        <div class="card-titulo"><i class="fa-solid fa-clock-rotate-left"></i><h2>Últimas páginas del panel</h2></div>
    </div>
    <?php if (!$ultimasAdmin): ?>
        <div class="vacio"><i class="fa-regular fa-eye"></i>Todavía no se ha registrado actividad en el panel.</div>
    <?php else: ?>
        <div class="tabla-wrap">
            <table class="tabla">
                <thead>
                    <tr><th>Fecha</th><th>Usuario</th><th>Página</th><th>Dispositivo</th><th>Navegador</th></tr>
                </thead>
                <tbody>
                <?php foreach ($ultimasAdmin as $visita): ?>
                    <tr>
                        <td class="texto-muted ahorarap"><?= e((string) $visita['creado_en']) ?></td>
                        <td><?= e(nombre_visitante_admin((string) $visita['visitante'], $nombresUsuarios)) ?></td>
                        <td><?= e(analitica_etiqueta_ruta((string) $visita['ruta'])) ?></td>
                        <td><?= e((string) $visita['dispositivo']) ?></td>
                        <td><?= e((string) $visita['navegador']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php render_fin(); ?>
