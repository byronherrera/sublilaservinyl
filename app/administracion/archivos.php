<?php
/**
 * Gestor de archivos de /proyectos: navegación por carpetas, subida de
 * imágenes/videos y administración (renombrar / borrar) exclusivamente de medios.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$usuario  = require_auth();
$editable = puede_editar_archivos($usuario);

render_inicio('Archivos', 'archivos', $usuario, 'archivos');
seccion_titulo('Archivos de proyectos', 'Explora /proyectos, crea carpetas y administra sus imágenes y videos. Los demás archivos no se muestran ni se pueden editar.');
?>

<div class="toolbar">
    <?php if ($editable): ?>
        <button type="button" class="btn btn-primary" id="btn-subir">
            <i class="fa-solid fa-cloud-arrow-up"></i> Subir archivos
        </button>
        <button type="button" class="btn" id="btn-nueva-carpeta">
            <i class="fa-solid fa-folder-plus"></i> Nueva carpeta
        </button>
    <?php endif; ?>
    <span class="separador"></span>
    <span class="badge badge-lectura"><i class="fa-regular fa-images"></i> <span id="contador-imagenes">0</span> archivos en esta carpeta</span>
</div>

<nav class="migas" id="migas" aria-label="Ruta de carpetas">
    <a href="#/" data-ruta=""><i class="fa-solid fa-folder-tree"></i> proyectos</a>
</nav>

<div id="gestor" data-editable="<?= $editable ? '1' : '0' ?>">

    <?php if ($editable): ?>
    <div class="aviso" id="aviso-raiz">
        <i class="fa-solid fa-circle-info"></i>
        Para subir archivos, entra primero en una carpeta de proyecto. No se permite subir nada en la raíz de <code class="mono">/proyectos</code>.
    </div>
    <div class="zona-subida oculto" id="zona-subida" role="button" tabindex="0" aria-label="Subir imágenes y videos">
        <i class="fa-solid fa-cloud-arrow-up"></i>
        <strong>Arrastra imágenes o videos aquí</strong> o haz clic para seleccionarlos
        <small>JPG · JPEG · PNG · SVG · HEIC/HEIF (iPhone) · MP4 — máximo <?= e(formato_bytes(MAX_UPLOAD_BYTES)) ?> por archivo</small>
        <div class="progreso" id="progreso"><span id="progreso-barra"></span></div>
    </div>
    <input type="file" id="input-archivos" accept=".jpg,.jpeg,.png,.svg,.heic,.heif,.mp4,image/jpeg,image/png,image/svg+xml,image/heic,image/heif,video/mp4" multiple class="oculto">
    <?php endif; ?>

    <section aria-labelledby="titulo-carpetas" id="seccion-carpetas">
        <div class="card-head" style="margin-bottom:12px">
            <h2 id="titulo-carpetas">Carpetas</h2>
            <p class="page-desc" id="ayuda-carpetas">Entra en una carpeta para ver y administrar sus imágenes y videos.</p>
        </div>
        <div class="lista-carpetas" id="lista-carpetas">
            <div class="vacio" style="grid-column:1/-1"><i class="fa-solid fa-spinner fa-spin"></i>Cargando…</div>
        </div>
    </section>

    <section class="card" aria-labelledby="titulo-imagenes">
        <div class="card-head">
            <div class="card-titulo"><i class="fa-regular fa-images"></i><h2 id="titulo-imagenes">Imágenes y videos</h2></div>
            <p class="page-desc" id="resumen-imagenes">Solo se muestran imágenes y videos.</p>
        </div>
        <div class="grid-archivos" id="grid-archivos">
            <div class="vacio" style="grid-column:1/-1"><i class="fa-solid fa-spinner fa-spin"></i>Cargando archivos…</div>
        </div>
    </section>
</div>

<?php render_fin(); ?>
