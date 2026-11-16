<?php
/**
 * Administración de usuarios y direcciones de acceso (tokens).
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

$usuario = require_rol(['admin']);
render_inicio('Usuarios y accesos', 'usuarios', $usuario, 'usuarios');
seccion_titulo('Usuarios y direcciones de acceso', 'Cada usuario entra con un token único. Los tokens se guardan en SQLite y pueden revocarse o regenerarse en cualquier momento.');
?>

<div class="card oculto" id="nuevo-enlace">
    <div class="card-head">
        <div class="card-titulo"><i class="fa-solid fa-key"></i><h2 id="nuevo-enlace-titulo">Dirección de acceso</h2></div>
        <span class="badge badge-admin">Nuevo</span>
    </div>
    <p class="page-desc">Comparte este enlace con la persona correspondiente. Cualquiera que lo tenga podrá entrar al panel.</p>
    <div class="enlace-caja">
        <code id="nuevo-enlace-url"></code>
        <button class="btn btn-sm" id="nuevo-enlace-copiar" data-copiar=""><i class="fa-solid fa-copy"></i> Copiar</button>
        <a class="btn btn-sm btn-primary" id="nuevo-enlace-abrir" href="#" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> Abrir</a>
    </div>
</div>

<div class="grid grid-2 mt-24">
    <div class="card">
        <div class="card-head">
            <div class="card-titulo"><i class="fa-solid fa-user-plus"></i><h2 id="titulo-form-usuario">Nuevo usuario</h2></div>
        </div>
        <form id="form-usuario" autocomplete="off">
            <input type="hidden" name="id" value="">
            <div class="form-grid">
                <div class="campo">
                    <label for="u-nombre">Nombre</label>
                    <input id="u-nombre" name="nombre" type="text" maxlength="80" required placeholder="Ej. Rebeca Martínez">
                </div>
                <div class="campo">
                    <label for="u-email">Correo (opcional)</label>
                    <input id="u-email" name="email" type="email" maxlength="120" placeholder="correo@dominio.com">
                </div>
                <div class="campo">
                    <label for="u-rol">Rol</label>
                    <select id="u-rol" name="rol">
                        <option value="admin">Administrador · todo el panel</option>
                        <option value="editor" selected>Editor · gestiona archivos</option>
                        <option value="lectura">Solo lectura</option>
                    </select>
                </div>
                <div class="campo">
                    <label>Estado</label>
                    <label class="fila" style="text-transform:none;letter-spacing:0;font-size:.86rem;color:var(--fg)">
                        <input type="checkbox" name="activo" value="1" checked style="width:auto">
                        Usuario activo
                    </label>
                </div>
            </div>
            <div class="form-acciones">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Guardar usuario</button>
                <button type="button" class="btn btn-ghost oculto" id="btn-cancelar-edicion">Cancelar edición</button>
            </div>
            <p class="page-desc mt-16">Al crear un usuario se genera automáticamente su primer token de acceso.</p>
        </form>
    </div>

    <div class="card">
        <div class="card-head">
            <div class="card-titulo"><i class="fa-solid fa-circle-info"></i><h2>Restaurar una dirección</h2></div>
        </div>
        <p class="page-desc">
            Las direcciones de acceso se guardan en la tabla <code class="mono">tokens</code> de SQLite,
            por lo que siempre puedes recuperarlas desde aquí:
        </p>
        <ul class="page-desc" style="margin-top:12px;padding-left:18px;line-height:1.9">
            <li><strong>Copiar enlace</strong> de un token activo para volver a usarlo.</li>
            <li><strong>Regenerar</strong> crea una dirección nueva y anula la anterior.</li>
            <li><strong>Revocar</strong> bloquea el acceso de inmediato (también cierra sus sesiones).</li>
            <li>Desactivar un usuario revoca todos sus tokens automáticamente.</li>
        </ul>
        <p class="page-desc mt-16">
            Respaldo de la base: <code class="mono">app/administracion/data/administracion.sqlite</code>.
        </p>
    </div>
</div>

<div class="card mt-24">
    <div class="card-head">
        <div class="card-titulo"><i class="fa-solid fa-users"></i><h2>Usuarios</h2></div>
        <span class="badge badge-lectura" id="contador-usuarios"></span>
    </div>
    <div class="tabla-wrap">
        <table class="tabla" id="tabla-usuarios">
            <thead>
                <tr>
                    <th>Usuario</th>
                    <th>Rol</th>
                    <th>Estado</th>
                    <th>Tokens activos / total</th>
                    <th>Último acceso</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="6" class="texto-muted">Cargando…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card mt-24">
    <div class="card-head">
        <div class="card-titulo"><i class="fa-solid fa-key"></i><h2>Direcciones de acceso (tokens)</h2></div>
        <span class="badge badge-lectura" id="contador-tokens"></span>
    </div>
    <div class="tabla-wrap">
        <table class="tabla" id="tabla-tokens">
            <thead>
                <tr>
                    <th>Usuario</th>
                    <th>Token</th>
                    <th>Estado</th>
                    <th>Último acceso</th>
                    <th>Accesos</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="6" class="texto-muted">Cargando…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<?php render_fin(); ?>
