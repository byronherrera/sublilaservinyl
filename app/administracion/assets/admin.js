/* ==================================================================
   Sublilaservinyl · Panel de administración
   Utilidades compartidas + lógica de cada página
   ================================================================== */
'use strict';

const ADMIN = window.ADMIN || { csrf: '', pagina: '' };

const $  = (sel, raiz = document) => raiz.querySelector(sel);
const $$ = (sel, raiz = document) => Array.from(raiz.querySelectorAll(sel));

const esc = (valor) => String(valor ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
}[c]));

/* ------------------------------ Toasts ------------------------------ */
function toast(mensaje, tipo = 'ok') {
    const cont = $('[data-toasts]');
    if (!cont) return;
    const el = document.createElement('div');
    el.className = 'toast ' + tipo;
    el.innerHTML = '<i class="fa-solid ' + (tipo === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation') + '"></i><div>' + esc(mensaje) + '</div>';
    cont.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateY(6px)'; }, 3600);
    setTimeout(() => el.remove(), 4000);
}

/* ------------------------------ API ------------------------------ */
async function api(accion, datos = {}) {
    let respuesta;
    try {
        respuesta = await fetch('api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': ADMIN.csrf
            },
            body: JSON.stringify(Object.assign({ action: accion }, datos))
        });
    } catch (err) {
        throw new Error('No se pudo conectar con el servidor.');
    }

    if (respuesta.status === 401) {
        window.location.href = '../index.html';
        throw new Error('Sesión expirada.');
    }

    let json;
    try {
        json = await respuesta.json();
    } catch (err) {
        throw new Error('Respuesta no válida del servidor.');
    }

    if (!respuesta.ok || json.ok === false) {
        throw new Error(json.error || 'Error inesperado.');
    }
    return json;
}

/* ------------------------------ Portapapeles ------------------------------ */
async function copiar(texto) {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(texto);
        } else {
            const ta = document.createElement('textarea');
            ta.value = texto;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
        }
        toast('Copiado al portapapeles.');
        return true;
    } catch (err) {
        toast('No se pudo copiar. Copia manualmente: ' + texto, 'error');
        return false;
    }
}

/* ------------------------------ Modal ------------------------------ */
function confirmar(titulo, texto) {
    const modal = $('[data-modal]');
    return new Promise((resolve) => {
        if (!modal) { resolve(window.confirm(titulo + '\n\n' + texto)); return; }
        $('[data-modal-titulo]', modal).textContent = titulo;
        $('[data-modal-texto]', modal).textContent = texto;
        modal.hidden = false;

        const cerrar = (valor) => {
            modal.hidden = true;
            $('[data-modal-ok]', modal).onclick = null;
            $('[data-modal-cancelar]', modal).onclick = null;
            modal.querySelectorAll('[data-modal-cancelar]').forEach((b) => { b.onclick = null; });
            resolve(valor);
        };
        $('[data-modal-ok]', modal).onclick = () => cerrar(true);
        $('[data-modal-cancelar]', modal).onclick = () => cerrar(false);
        modal.onclick = (ev) => { if (ev.target === modal) cerrar(false); };
    });
}

function pedirTexto(titulo, valor = '', etiqueta = 'Nombre') {
    return new Promise((resolve) => {
        const modal = $('[data-modal]');
        if (!modal) { resolve(window.prompt(titulo, valor)); return; }

        const caja = $('.modal-caja', modal);
        const original = caja.innerHTML;
        caja.innerHTML =
            '<h3>' + esc(titulo) + '</h3>' +
            '<div class="campo" style="margin-top:12px"><label>' + esc(etiqueta) + '</label>' +
            '<input type="text" data-prompt-input value="' + esc(valor) + '" maxlength="150"></div>' +
            '<div class="modal-acciones"><button type="button" class="btn btn-ghost" data-prompt-cancelar>Cancelar</button>' +
            '<button type="button" class="btn btn-primary" data-prompt-ok>Aceptar</button></div>';
        modal.hidden = false;

        const input = $('[data-prompt-input]', modal);
        const cerrar = (resultado) => {
            modal.hidden = true;
            caja.innerHTML = original;
            modal.onclick = null;
            resolve(resultado);
        };

        const aceptar = () => {
            const texto = input.value.trim();
            if (texto === '') { input.focus(); return; }
            cerrar(texto);
        };

        $('[data-prompt-ok]', modal).onclick = aceptar;
        $('[data-prompt-cancelar]', modal).onclick = () => cerrar(null);
        modal.onclick = (ev) => { if (ev.target === modal) cerrar(null); };
        input.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') { ev.preventDefault(); aceptar(); }
            if (ev.key === 'Escape') cerrar(null);
        });
        setTimeout(() => { input.focus(); input.select(); }, 30);
    });
}

/* ------------------------------ Global ------------------------------ */
document.addEventListener('DOMContentLoaded', () => {
    // Menú móvil
    const toggle = $('[data-nav-toggle]');
    if (toggle) {
        toggle.addEventListener('click', () => {
            const nav = $('[data-nav]');
            if (nav) nav.classList.toggle('abierto');
        });
    }

    // Copiar cualquier elemento con data-copiar
    document.addEventListener('click', (ev) => {
        const boton = ev.target.closest('[data-copiar]');
        if (!boton) return;
        ev.preventDefault();
        copiar(boton.getAttribute('data-copiar') || '');
    });

    // Cerrar sesión
    const salir = $('[data-salir]');
    if (salir) {
        salir.addEventListener('click', async () => {
            const ok = await confirmar('Cerrar sesión', 'Se cerrará la sesión y el token guardado en este navegador. Podrás volver a entrar con tu enlace de acceso.');
            if (!ok) return;
            try { await api('salir'); } catch (err) { /* ignorar */ }
            window.location.href = '../index.html';
        });
    }

    if (ADMIN.pagina === 'usuarios') initUsuarios();
    if (ADMIN.pagina === 'archivos') initArchivos();
    if (ADMIN.pagina === 'panel') initPanel();
});

/* ==================================================================
   PÁGINA: PANEL
   ================================================================== */
function initPanel() {
    const btn = $('#btn-eliminar-primer-acceso');
    if (!btn) return;
    btn.addEventListener('click', async () => {
        const ok = await confirmar(
            'Eliminar archivo de acceso inicial',
            'Confirma que ya guardaste tu enlace de acceso. El archivo data/primer-acceso.txt se borrará del servidor.'
        );
        if (!ok) return;
        try {
            await api('primer_acceso_eliminar');
            $('#aviso-primer-acceso')?.remove();
            toast('Archivo de primer acceso eliminado.');
        } catch (err) { toast(err.message, 'error'); }
    });
}

/* ==================================================================
   PÁGINA: USUARIOS Y TOKENS
   ================================================================== */
function initUsuarios() {
    const form = $('#form-usuario');
    const btnCancelar = $('#btn-cancelar-edicion');
    const cajaNuevoEnlace = $('#nuevo-enlace');

    const reiniciarFormulario = () => {
        if (!form) return;
        form.reset();
        form.elements.id.value = '';
        form.elements.activo.checked = true;
        $('#titulo-form-usuario').textContent = 'Nuevo usuario';
        btnCancelar?.classList.add('oculto');
    };

    async function cargar() {
        try {
            const [usuarios, tokens] = await Promise.all([
                api('usuarios_listar'),
                api('tokens_listar')
            ]);
            pintarUsuarios(usuarios.usuarios);
            pintarTokens(tokens.tokens);
            const contU = $('#contador-usuarios');
            const contT = $('#contador-tokens');
            if (contU) contU.textContent = usuarios.usuarios.length + ' usuario(s)';
            if (contT) contT.textContent = tokens.tokens.length + ' token(s)';
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function pintarUsuarios(lista) {
        const tbody = $('#tabla-usuarios tbody');
        if (!tbody) return;
        if (!lista.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="texto-muted">Sin usuarios registrados.</td></tr>';
            return;
        }
        tbody.innerHTML = lista.map((u) => {
            const inicial = esc((u.nombre || '?').trim().charAt(0).toUpperCase());
            return '<tr>' +
                '<td><div class="celda-usuario"><span class="avatar">' + inicial + '</span><div>' +
                    '<strong>' + esc(u.nombre) + '</strong><small>' + esc(u.email || 'sin correo') + '</small></div></div></td>' +
                '<td>' + badgeRol(u.rol) + '</td>' +
                '<td>' + (u.activo === 1 || u.activo === '1'
                    ? '<span class="badge badge-on">Activo</span>'
                    : '<span class="badge badge-off">Inactivo</span>') + '</td>' +
                '<td class="mono">' + (u.tokens_activos || 0) + ' / ' + (u.total_tokens || 0) + '</td>' +
                '<td class="texto-muted ahorarap">' + esc(u.ultimo_acceso || '—') + '</td>' +
                '<td><div class="acciones">' +
                    '<button class="btn btn-sm" data-editar-usuario="' + u.id + '"><i class="fa-solid fa-pen"></i> Editar</button>' +
                    '<button class="btn btn-sm" data-nuevo-token="' + u.id + '"><i class="fa-solid fa-key"></i> Token</button>' +
                    '<button class="btn btn-sm btn-danger" data-eliminar-usuario="' + u.id + '" data-nombre="' + esc(u.nombre) + '"><i class="fa-solid fa-trash"></i></button>' +
                '</div></td></tr>';
        }).join('');

        tbody.querySelectorAll('[data-editar-usuario]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const u = lista.find((x) => String(x.id) === btn.dataset.editarUsuario);
                if (!u) return;
                form.elements.id.value = u.id;
                form.elements.nombre.value = u.nombre;
                form.elements.email.value = u.email || '';
                form.elements.rol.value = u.rol;
                form.elements.activo.checked = u.activo === 1 || u.activo === '1';
                $('#titulo-form-usuario').textContent = 'Editar usuario #' + u.id;
                btnCancelar?.classList.remove('oculto');
                form.scrollIntoView({ behavior: 'smooth', block: 'center' });
                form.elements.nombre.focus();
            });
        });

        tbody.querySelectorAll('[data-eliminar-usuario]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const ok = await confirmar('Eliminar usuario', 'Se eliminará a "' + btn.dataset.nombre + '" y todos sus tokens de acceso. Esta acción no se puede deshacer.');
                if (!ok) return;
                try {
                    await api('usuario_eliminar', { id: Number(btn.dataset.eliminarUsuario) });
                    toast('Usuario eliminado.');
                    cargar();
                } catch (err) { toast(err.message, 'error'); }
            });
        });

        tbody.querySelectorAll('[data-nuevo-token]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                try {
                    const datos = await api('token_crear', { usuario_id: Number(btn.dataset.nuevoToken) });
                    mostrarEnlaceNuevo(datos.enlace, 'Token generado para ' + datos.usuario);
                    toast('Token generado correctamente.');
                    cargar();
                } catch (err) { toast(err.message, 'error'); }
            });
        });
    }

    function pintarTokens(lista) {
        const tbody = $('#tabla-tokens tbody');
        if (!tbody) return;
        if (!lista.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="texto-muted">Sin tokens registrados.</td></tr>';
            return;
        }
        tbody.innerHTML = lista.map((t) => {
            const enlace = t.enlace || '';
            const activo = t.activo === 1 || t.activo === '1';
            return '<tr>' +
                '<td><div><strong>' + esc(t.usuario) + '</strong><small class="texto-muted" style="display:block">' + esc(t.etiqueta || 'Sin etiqueta') + '</small></div></td>' +
                '<td><code class="mono">' + esc(String(t.token).slice(0, 14)) + '…' + esc(String(t.token).slice(-6)) + '</code></td>' +
                '<td>' + (activo ? '<span class="badge badge-on">Activo</span>' : '<span class="badge badge-off">Revocado</span>') + '</td>' +
                '<td class="texto-muted ahorarap">' + esc(t.ultimo_acceso || '—') + '</td>' +
                '<td class="mono">' + (t.accesos || 0) + '</td>' +
                '<td><div class="acciones">' +
                    (activo
                        ? '<button class="btn btn-sm" data-copiar="' + esc(enlace) + '" title="Copiar enlace"><i class="fa-solid fa-link"></i></button>' +
                          '<a class="btn btn-sm" href="' + esc(enlace) + '" target="_blank" rel="noopener" title="Abrir enlace"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>' +
                          '<button class="btn btn-sm" data-regenerar-token="' + t.id + '" title="Regenerar"><i class="fa-solid fa-rotate"></i></button>' +
                          '<button class="btn btn-sm btn-danger" data-revocar-token="' + t.id + '" title="Revocar"><i class="fa-solid fa-ban"></i></button>'
                        : '<span class="texto-muted">—</span>') +
                '</div></td></tr>';
        }).join('');

        tbody.querySelectorAll('[data-revocar-token]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const ok = await confirmar('Revocar token', 'El enlace dejará de funcionar de inmediato. Podrás generar uno nuevo cuando quieras.');
                if (!ok) return;
                try {
                    await api('token_revocar', { id: Number(btn.dataset.revocarToken) });
                    toast('Token revocado.');
                    cargar();
                } catch (err) { toast(err.message, 'error'); }
            });
        });

        tbody.querySelectorAll('[data-regenerar-token]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const ok = await confirmar('Regenerar token', 'Se revocará el token actual y se creará uno nuevo con una dirección de acceso distinta.');
                if (!ok) return;
                try {
                    const datos = await api('token_regenerar', { id: Number(btn.dataset.regenerarToken) });
                    mostrarEnlaceNuevo(datos.enlace, 'Nueva dirección de acceso');
                    toast('Token regenerado.');
                    cargar();
                } catch (err) { toast(err.message, 'error'); }
            });
        });
    }

    function mostrarEnlaceNuevo(enlace, titulo) {
        if (!cajaNuevoEnlace) return;
        cajaNuevoEnlace.classList.remove('oculto');
        $('#nuevo-enlace-titulo').textContent = titulo;
        $('#nuevo-enlace-url').textContent = enlace;
        $('#nuevo-enlace-copiar').dataset.copiar = enlace;
        $('#nuevo-enlace-abrir').href = enlace;
        cajaNuevoEnlace.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    form?.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const datos = {
            id: form.elements.id.value ? Number(form.elements.id.value) : 0,
            nombre: form.elements.nombre.value.trim(),
            email: form.elements.email.value.trim(),
            rol: form.elements.rol.value,
            activo: form.elements.activo.checked ? 1 : 0
        };
        try {
            const respuesta = await api(datos.id ? 'usuario_actualizar' : 'usuario_crear', datos);
            toast(datos.id ? 'Usuario actualizado.' : 'Usuario creado.');
            if (respuesta.enlace) mostrarEnlaceNuevo(respuesta.enlace, 'Acceso de ' + datos.nombre);
            reiniciarFormulario();
            cargar();
        } catch (err) { toast(err.message, 'error'); }
    });

    btnCancelar?.addEventListener('click', reiniciarFormulario);

    if (cajaNuevoEnlace) cajaNuevoEnlace.classList.add('oculto');
    reiniciarFormulario();
    cargar();
}

function badgeRol(rol) {
    const mapa = {
        admin: ['badge-admin', 'Administrador'],
        editor: ['badge-editor', 'Editor'],
        lectura: ['badge-lectura', 'Solo lectura']
    };
    const [clase, texto] = mapa[rol] || ['badge-lectura', rol];
    return '<span class="badge ' + clase + '">' + esc(texto) + '</span>';
}

/* ==================================================================
   PÁGINA: ARCHIVOS (/proyectos)
   ================================================================== */
function initArchivos() {
    const estado = { ruta: '', imagenes: [], indice: 0 };
    const contenedor = $('#gestor');
    if (!contenedor) return;

    const migas = $('#migas');
    const listaCarpetas = $('#lista-carpetas');
    const seccionCarpetas = $('#seccion-carpetas');
    const ayudaCarpetas = $('#ayuda-carpetas');
    const gridArchivos = $('#grid-archivos');
    const contadorImagenes = $('#contador-imagenes');
    const resumenImagenes = $('#resumen-imagenes');
    const zona = $('#zona-subida');
    const avisoRaiz = $('#aviso-raiz');
    const btnSubir = $('#btn-subir');
    const inputArchivos = $('#input-archivos');
    const progreso = $('#progreso');
    const barra = $('#progreso-barra');
    const permisoEdicion = contenedor.dataset.editable === '1';

    /* ---------- Visor para ampliar imágenes ---------- */
    const visor = document.createElement('div');
    visor.className = 'visor';
    visor.hidden = true;
    visor.innerHTML =
        '<div class="visor-caja" role="dialog" aria-modal="true" aria-label="Visor de imagen">' +
            '<button type="button" class="visor-cerrar" data-visor-cerrar aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button>' +
            '<button type="button" class="visor-nav visor-prev" data-visor-prev aria-label="Imagen anterior"><i class="fa-solid fa-chevron-left"></i></button>' +
            '<img data-visor-img alt="">' +
            '<video data-visor-video class="oculto" controls playsinline preload="metadata"></video>' +
            '<button type="button" class="visor-nav visor-next" data-visor-next aria-label="Imagen siguiente"><i class="fa-solid fa-chevron-right"></i></button>' +
            '<div class="visor-pie">' +
                '<div class="visor-datos"><strong data-visor-nombre></strong><small data-visor-meta></small></div>' +
                '<div class="acciones">' +
                    '<button type="button" class="btn btn-sm" data-visor-copiar><i class="fa-solid fa-copy"></i> Copiar imagen</button>' +
                    '<a class="btn btn-sm" data-visor-abrir target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> Abrir original</a>' +
                    (permisoEdicion
                        ? '<button type="button" class="btn btn-sm" data-visor-renombrar><i class="fa-solid fa-pen"></i> Renombrar</button>' +
                          '<button type="button" class="btn btn-sm btn-danger" data-visor-eliminar><i class="fa-solid fa-trash"></i> Borrar</button>'
                        : '') +
                '</div>' +
            '</div>' +
            '<div class="visor-contador" data-visor-contador></div>' +
        '</div>';
    document.body.appendChild(visor);

    const visorImg = $('[data-visor-img]', visor);
    const visorVideo = $('[data-visor-video]', visor);
    const visorNombre = $('[data-visor-nombre]', visor);
    const visorMeta = $('[data-visor-meta]', visor);
    const visorContador = $('[data-visor-contador]', visor);
    const visorAbrir = $('[data-visor-abrir]', visor);
    const visorCopiar = $('[data-visor-copiar]', visor);

    function esVideoItem(item) {
        return Boolean(item && (item.tipo === 'video' || item.extension === 'mp4'));
    }

    function visorActual() {
        return estado.imagenes[estado.indice] || null;
    }

    function visorMostrar(indice) {
        if (!estado.imagenes.length) return;
        const total = estado.imagenes.length;
        estado.indice = ((indice % total) + total) % total;
        const img = visorActual();
        if (!img) return;

        if (esVideoItem(img)) {
            visorImg.classList.add('oculto');
            visorImg.removeAttribute('src');
            visorVideo.classList.remove('oculto');
            visorVideo.src = img.url;
            visorCopiar.innerHTML = '<i class="fa-solid fa-link"></i> Copiar URL';
        } else {
            visorVideo.pause();
            visorVideo.classList.add('oculto');
            visorVideo.removeAttribute('src');
            visorImg.classList.remove('oculto');
            visorImg.src = img.url;
            visorImg.alt = img.nombre;
            visorCopiar.innerHTML = '<i class="fa-solid fa-copy"></i> Copiar imagen';
        }

        visorNombre.textContent = img.nombre;
        visorMeta.textContent = img.tamano + ' · ' + img.extension.toUpperCase() + ' · ' + img.modificado;
        visorContador.textContent = (estado.indice + 1) + ' / ' + total;
        visorAbrir.href = img.url;
        visor.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function visorCerrar() {
        visor.hidden = true;
        visorImg.removeAttribute('src');
        try { visorVideo.pause(); } catch (err) { /* ignorar */ }
        visorVideo.removeAttribute('src');
        document.body.style.overflow = '';
    }

    $('[data-visor-cerrar]', visor).addEventListener('click', visorCerrar);
    $('[data-visor-prev]', visor).addEventListener('click', () => visorMostrar(estado.indice - 1));
    $('[data-visor-next]', visor).addEventListener('click', () => visorMostrar(estado.indice + 1));
    visor.addEventListener('click', (ev) => { if (ev.target === visor) visorCerrar(); });
    document.addEventListener('keydown', (ev) => {
        if (visor.hidden) return;
        if (ev.key === 'Escape') visorCerrar();
        if (ev.key === 'ArrowLeft') visorMostrar(estado.indice - 1);
        if (ev.key === 'ArrowRight') visorMostrar(estado.indice + 1);
    });

    $('[data-visor-renombrar]', visor)?.addEventListener('click', async () => {
        const img = visorActual();
        if (!img) return;
        const nuevo = await pedirTexto('Renombrar imagen', img.nombre, 'Nuevo nombre');
        if (!nuevo || nuevo === img.nombre) return;
        try {
            const res = await api('fs_renombrar', { ruta: img.ruta, nombre: nuevo });
            visorCerrar();
            limpiarSeleccion(true);
            toast('Renombrado a «' + (res.elemento ? res.elemento.nombre : nuevo) + '».');
            cargar(estado.ruta);
        } catch (err) { toast(err.message, 'error'); }
    });

    $('[data-visor-eliminar]', visor)?.addEventListener('click', async () => {
        const img = visorActual();
        if (!img) return;
        const ok = await confirmar('Borrar imagen', 'Se eliminará "' + img.nombre + '". Esta acción no se puede deshacer.');
        if (!ok) return;
        try {
            await api('fs_eliminar', { ruta: img.ruta });
            visorCerrar();
            limpiarSeleccion(true);
            toast('Imagen eliminada.');
            cargar(estado.ruta);
        } catch (err) { toast(err.message, 'error'); }
    });

    /* ---------- Copiar imágenes al portapapeles (selección múltiple) ---------- */
    const seleccion = new Map(); // clave: URL absoluta -> { url, nombre }

    const bandeja = document.createElement('div');
    bandeja.className = 'copy-tray';
    bandeja.hidden = true;
    bandeja.setAttribute('role', 'status');
    bandeja.setAttribute('aria-live', 'polite');
    bandeja.innerHTML =
        '<span class="copy-tray-count" data-bandeja-cuenta>0 imágenes listas</span>' +
        '<button type="button" class="btn btn-sm btn-primary" data-bandeja-copiar><i class="fa-solid fa-copy"></i> Copiar todo</button>' +
        '<button type="button" class="btn btn-sm" data-bandeja-vaciar>Vaciar</button>';
    document.body.appendChild(bandeja);

    const bandejaCuenta = $('[data-bandeja-cuenta]', bandeja);

    function claveCopia(url) {
        try {
            return new URL(url, window.location.href).href;
        } catch (err) {
            return String(url || '');
        }
    }

    /** Convierte la imagen en un Blob PNG mediante canvas. */
    function imagenABlobPng(url) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.onload = () => {
                try {
                    const lienzo = document.createElement('canvas');
                    lienzo.width = img.naturalWidth || img.width;
                    lienzo.height = img.naturalHeight || img.height;
                    const ctx = lienzo.getContext('2d');
                    ctx.drawImage(img, 0, 0);
                    lienzo.toBlob((blob) => {
                        if (blob) resolve(blob);
                        else reject(new Error('No se pudo convertir la imagen a PNG.'));
                    }, 'image/png');
                } catch (err) {
                    reject(err);
                }
            };
            img.onerror = () => reject(new Error('No se pudo cargar la imagen para copiar.'));
            img.src = url;
        });
    }

    /** Copia texto con fallback para contextos sin Clipboard API. */
    async function copiarTexto(texto) {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            await navigator.clipboard.writeText(texto);
            return true;
        }
        const area = document.createElement('textarea');
        area.value = texto;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.top = '-9999px';
        document.body.appendChild(area);
        area.select();
        let copiado = false;
        try { copiado = document.execCommand('copy'); } finally { document.body.removeChild(area); }
        if (!copiado) throw new Error('No se pudo copiar el texto.');
        return true;
    }

    /** Copia una imagen como bitmap PNG (o su URL si se indica). */
    async function copiarImagen(url, copiarUrl = false) {
        const absoluta = new URL(url, window.location.href).href;

        if (copiarUrl) {
            try {
                await copiarTexto(absoluta);
                toast('URL copiada al portapapeles.');
            } catch (err) {
                toast('No se pudo copiar la URL.', 'error');
            }
            return;
        }

        if (!window.isSecureContext || !navigator.clipboard || typeof navigator.clipboard.write !== 'function' || typeof window.ClipboardItem === 'undefined') {
            toast('Para copiar la imagen abre el panel con https:// o en localhost.', 'error');
            return;
        }

        try {
            const png = imagenABlobPng(url);
            try {
                await navigator.clipboard.write([new ClipboardItem({ 'image/png': png })]);
            } catch (err) {
                const blob = await png;
                await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
            }
            toast('Imagen copiada al portapapeles.');
        } catch (err) {
            toast('No se pudo copiar la imagen al portapapeles.', 'error');
        }
    }

    /** Escribe toda la selección; si el navegador solo admite una, copia la última. */
    async function copiarSeleccion(avisar = true) {
        const entradas = Array.from(seleccion.values());
        if (!entradas.length) return false;

        if (entradas.length === 1) {
            await copiarImagen(entradas[0].url);
            return true;
        }

        if (!window.isSecureContext || !navigator.clipboard || typeof navigator.clipboard.write !== 'function' || typeof window.ClipboardItem === 'undefined') {
            if (avisar) toast('Para copiar imágenes abre el panel con https:// o en localhost.', 'error');
            return false;
        }

        try {
            const items = entradas.map((item) => new ClipboardItem({ 'image/png': imagenABlobPng(item.url) }));
            await navigator.clipboard.write(items);
            if (avisar) toast(entradas.length + ' imágenes copiadas (el sistema puede pegar solo una).');
            return true;
        } catch (err) {
            try {
                const ultima = entradas[entradas.length - 1];
                await navigator.clipboard.write([new ClipboardItem({ 'image/png': imagenABlobPng(ultima.url) })]);
                if (avisar) toast('Selección de ' + entradas.length + ' imágenes; el navegador copió la última.', 'error');
                return true;
            } catch (err2) {
                if (avisar) toast('No se pudieron copiar las imágenes.', 'error');
                return false;
            }
        }
    }

    /** Refleja el estado de la selección en las tarjetas y en la bandeja inferior. */
    function sincronizarSeleccion() {
        gridArchivos.querySelectorAll('.archivo-card').forEach((tarjeta) => {
            const boton = tarjeta.querySelector('[data-copiar-img]');
            if (!boton || boton.dataset.video === '1') return;
            const marcada = seleccion.has(claveCopia(boton.dataset.copiarImg));
            tarjeta.classList.toggle('is-copy-selected', marcada);
            boton.classList.toggle('activo', marcada);
            boton.setAttribute('aria-pressed', String(marcada));
            boton.title = marcada ? 'Quitar de la selección' : 'Copiar imagen (selección múltiple)';
            const icono = boton.querySelector('i');
            if (icono) icono.className = marcada ? 'fa-solid fa-check' : 'fa-solid fa-copy';
        });

        const total = seleccion.size;
        bandeja.hidden = total === 0;
        if (bandejaCuenta) {
            bandejaCuenta.textContent = total === 1 ? '1 imagen lista' : total + ' imágenes listas';
        }
    }

    /** Añade o quita una imagen de la selección y copia el resultado. */
    async function alternarSeleccion(url, nombre) {
        const clave = claveCopia(url);
        if (seleccion.has(clave)) {
            seleccion.delete(clave);
            sincronizarSeleccion();
            if (seleccion.size > 0) await copiarSeleccion(false);
            toast('Imagen quitada de la selección.');
            return;
        }
        seleccion.set(clave, { url, nombre });
        sincronizarSeleccion();
        await copiarSeleccion();
    }

    function limpiarSeleccion(silencioso = false) {
        seleccion.clear();
        sincronizarSeleccion();
        if (!silencioso) toast('Selección vaciada.');
    }

    bandeja.querySelector('[data-bandeja-copiar]').addEventListener('click', () => copiarSeleccion());
    bandeja.querySelector('[data-bandeja-vaciar]').addEventListener('click', () => limpiarSeleccion());

    $('[data-visor-copiar]', visor).addEventListener('click', () => {
        const img = visorActual();
        if (!img) return;
        if (esVideoItem(img)) copiarImagen(img.url, true);
        else alternarSeleccion(img.url, img.nombre);
    });

    /* ---------- Navegación ---------- */
    function rutaDesdeHash() {
        return decodeURIComponent(window.location.hash.replace(/^#\/?/, ''));
    }

    function actualizarHash(ruta) {
        const nuevo = ruta ? '#/' + ruta.split('/').map(encodeURIComponent).join('/') : '';
        if (window.location.hash !== nuevo) {
            history.pushState(null, '', nuevo || window.location.pathname + window.location.search);
        }
    }

    async function cargar(ruta, usarHash = false) {
        if (typeof ruta === 'string') estado.ruta = ruta;
        try {
            const datos = await api('fs_listar', { ruta: estado.ruta });
            estado.ruta = datos.ruta;
            estado.imagenes = datos.imagenes;
            visorCerrar();
            pintar(datos);
            if (!usarHash) actualizarHash(datos.ruta);
        } catch (err) {
            toast(err.message, 'error');
            if (estado.ruta !== '') { cargar(''); }
        }
    }

    function pintar(datos) {
        // La subida solo está disponible dentro de una carpeta (nunca en /proyectos)
        const enRaiz = datos.ruta === '';
        zona?.classList.toggle('oculto', enRaiz);
        avisoRaiz?.classList.toggle('oculto', !enRaiz);
        btnSubir?.classList.toggle('oculto', enRaiz);

        // Migas
        let htmlMigas = '<a href="#/" data-ruta=""><i class="fa-solid fa-folder-tree"></i> proyectos</a>';
        datos.migas.forEach((m) => {
            htmlMigas += ' <i class="fa-solid fa-chevron-right"></i> <a href="#/' + m.ruta.split('/').map(encodeURIComponent).join('/') + '" data-ruta="' + esc(m.ruta) + '">' + esc(m.nombre) + '</a>';
        });
        if (!datos.migas.length) {
            htmlMigas = '<span class="actual"><i class="fa-solid fa-folder-tree"></i> proyectos</span>';
        } else {
            const ultima = datos.migas[datos.migas.length - 1];
            htmlMigas = htmlMigas.replace('<a href="#/' + ultima.ruta.split('/').map(encodeURIComponent).join('/') + '" data-ruta="' + esc(ultima.ruta) + '">' + esc(ultima.nombre) + '</a>',
                '<span class="actual">' + esc(ultima.nombre) + '</span>');
        }
        migas.innerHTML = htmlMigas;
        migas.querySelectorAll('[data-ruta]').forEach((a) => {
            a.addEventListener('click', (ev) => {
                ev.preventDefault();
                cargar(a.dataset.ruta);
            });
        });

        // Carpetas
        if (seccionCarpetas) {
            seccionCarpetas.classList.toggle('oculto', datos.carpetas.length === 0 && datos.ruta !== '');
        }
        if (!datos.carpetas.length) {
            listaCarpetas.innerHTML = '<div class="vacio" style="grid-column:1/-1"><i class="fa-regular fa-folder-open"></i>Esta carpeta no tiene subcarpetas.</div>';
            if (ayudaCarpetas) ayudaCarpetas.textContent = datos.ruta === '' ? 'Crea una carpeta para organizar las imágenes.' : 'Puedes crear subcarpetas dentro de esta carpeta.';
        } else {
            listaCarpetas.innerHTML = datos.carpetas.map((c) => {
                const portada = c.portada
                    ? '<span class="carpeta-portada"><img src="' + esc(c.portada) + '" alt="" loading="lazy"></span>'
                    : '<span class="carpeta-portada carpeta-portada-vacia"><i class="fa-solid fa-folder"></i></span>';
                const detalle = c.imagenes + ' imagen' + (c.imagenes === 1 ? '' : 'es')
                    + (c.videos ? ' · ' + c.videos + ' video' + (c.videos === 1 ? '' : 's') : '')
                    + (c.subcarpetas ? ' · ' + c.subcarpetas + ' subcarpeta' + (c.subcarpetas === 1 ? '' : 's') : '');
                return '<div class="carpeta-item" data-entrar="' + esc(c.ruta) + '" role="link" tabindex="0">' +
                    portada +
                    '<div class="datos"><a href="#/' + c.ruta.split('/').map(encodeURIComponent).join('/') + '" data-entrar="' + esc(c.ruta) + '">' + esc(c.nombre) + '</a>' +
                    '<small>' + detalle + ' · ' + esc(c.modificado) + '</small></div>' +
                    (permisoEdicion
                        ? '<div class="carpeta-acciones">' +
                            '<button type="button" class="btn-icon" data-renombrar="' + esc(c.ruta) + '" data-nombre="' + esc(c.nombre) + '" title="Renombrar carpeta"><i class="fa-solid fa-pen"></i></button>' +
                            '<button type="button" class="btn-icon" data-eliminar="' + esc(c.ruta) + '" data-nombre="' + esc(c.nombre) + '" data-tipo="carpeta" title="Borrar carpeta vacía"><i class="fa-solid fa-trash"></i></button>' +
                          '</div>'
                        : '') +
                '</div>';
            }).join('');

            listaCarpetas.querySelectorAll('.carpeta-item').forEach((tarjeta) => {
                const entrar = () => cargar(tarjeta.dataset.entrar);
                tarjeta.addEventListener('click', (ev) => {
                    if (ev.target.closest('button')) return;
                    ev.preventDefault();
                    entrar();
                });
                tarjeta.addEventListener('keydown', (ev) => {
                    if (ev.target !== tarjeta) return;
                    if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); entrar(); }
                });
            });
            if (ayudaCarpetas) ayudaCarpetas.textContent = 'Haz clic en una carpeta para entrar y administrar sus imágenes.';
        }

        // Imágenes y videos
        contadorImagenes.textContent = datos.imagenes.length;
        if (resumenImagenes) {
            const videos = datos.imagenes.filter((m) => esVideoItem(m)).length;
            const imagenes = datos.imagenes.length - videos;
            const partes = [];
            if (imagenes) partes.push(imagenes + ' imagen' + (imagenes === 1 ? '' : 'es'));
            if (videos) partes.push(videos + ' video' + (videos === 1 ? '' : 's'));
            resumenImagenes.textContent = datos.imagenes.length
                ? partes.join(' · ') + ' · haz clic para ampliar, renombrar o borrar.'
                : 'Solo se muestran imágenes y videos.';
        }

        if (!datos.imagenes.length) {
            gridArchivos.innerHTML = '<div class="vacio" style="grid-column:1/-1"><i class="fa-regular fa-images"></i>No hay imágenes ni videos en esta carpeta.' +
                (permisoEdicion && datos.ruta !== '' ? '<br><small>Usa “Subir imágenes” o arrastra archivos aquí.</small>' : '') + '</div>';
        } else {
            gridArchivos.innerHTML = datos.imagenes.map((media, indice) => {
                const video = esVideoItem(media);
                const sinVista = media.extension === 'heic' || media.extension === 'heif';
                let contenido;
                if (video) {
                    contenido = '<video src="' + esc(media.url) + '#t=0.1" muted playsinline preload="metadata"></video>' +
                        '<span class="archivo-tipo"><i class="fa-solid fa-play"></i> MP4</span>';
                } else if (sinVista) {
                    contenido = '<span class="archivo-placeholder"><i class="fa-solid fa-image"></i><small>' + esc(media.extension.toUpperCase()) + '</small></span>';
                } else {
                    contenido = '<img src="' + esc(media.url) + '" alt="' + esc(media.nombre) + '" loading="' + (indice < 12 ? 'eager' : 'lazy') + '" decoding="async">';
                }
                return '<article class="archivo-card' + (video ? ' es-video' : '') + '">' +
                    '<button type="button" class="archivo-thumb" data-ver="' + indice + '" title="' + (video ? 'Reproducir video' : 'Ampliar imagen') + '" aria-label="Abrir ' + esc(media.nombre) + '">' +
                        contenido +
                        '<span class="archivo-zoom"><i class="fa-solid ' + (video ? 'fa-play' : 'fa-expand') + '"></i></span>' +
                    '</button>' +
                    '<div class="archivo-info">' +
                        '<div class="nombre" title="' + esc(media.nombre) + '">' + esc(media.nombre) + '</div>' +
                        '<div class="archivo-acciones">' +
                            '<button type="button" class="btn-icon" data-copiar-img="' + esc(media.url) + '" data-nombre="' + esc(media.nombre) + '" data-indice="' + indice + '"' + (video ? ' data-video="1"' : '') + ' aria-pressed="false" title="' + (video ? 'Copiar URL del video' : 'Copiar imagen (selección múltiple)') + '"><i class="fa-solid ' + (video ? 'fa-link' : 'fa-copy') + '"></i></button>' +
                            '<button type="button" class="btn-icon" data-copiar="' + esc(media.url) + '" title="Copiar URL"><i class="fa-solid fa-link"></i></button>' +
                            (permisoEdicion
                                ? '<button type="button" class="btn-icon" data-renombrar="' + esc(media.ruta) + '" data-nombre="' + esc(media.nombre) + '" title="Renombrar"><i class="fa-solid fa-pen"></i></button>' +
                                  '<button type="button" class="btn-icon peligro" data-eliminar="' + esc(media.ruta) + '" data-nombre="' + esc(media.nombre) + '" data-tipo="archivo" title="Borrar"><i class="fa-solid fa-trash"></i></button>'
                                : '') +
                        '</div>' +
                    '</div>' +
                '</article>';
            }).join('');

            gridArchivos.querySelectorAll('[data-ver]').forEach((btn) => {
                btn.addEventListener('click', () => visorMostrar(Number(btn.dataset.ver)));
            });

            gridArchivos.querySelectorAll('[data-copiar-img]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const media = estado.imagenes[Number(btn.dataset.indice)];
                    if (!media) return;
                    if (esVideoItem(media)) copiarImagen(media.url, true);
                    else alternarSeleccion(media.url, media.nombre);
                });
            });
        }

        sincronizarSeleccion();

        // Acciones de renombrar / borrar
        document.querySelectorAll('[data-renombrar]').forEach((btn) => {
            btn.onclick = async () => {
                const nuevo = await pedirTexto('Renombrar', btn.dataset.nombre, 'Nuevo nombre');
                if (!nuevo || nuevo === btn.dataset.nombre) return;
                try {
                    await api('fs_renombrar', { ruta: btn.dataset.renombrar, nombre: nuevo });
                    limpiarSeleccion(true);
                    toast('Renombrado correctamente.');
                    cargar(estado.ruta);
                } catch (err) { toast(err.message, 'error'); }
            };
        });

        document.querySelectorAll('[data-eliminar]').forEach((btn) => {
            btn.onclick = async () => {
                const esCarpeta = btn.dataset.tipo === 'carpeta';
                const ok = await confirmar(
                    esCarpeta ? 'Borrar carpeta' : 'Borrar archivo',
                    'Se eliminará "' + btn.dataset.nombre + '".' + (esCarpeta ? ' Solo es posible si está vacía.' : '') + ' Esta acción no se puede deshacer.'
                );
                if (!ok) return;
                try {
                    await api('fs_eliminar', { ruta: btn.dataset.eliminar });
                    limpiarSeleccion(true);
                    toast(esCarpeta ? 'Carpeta eliminada.' : 'Archivo eliminado.');
                    cargar(estado.ruta);
                } catch (err) { toast(err.message, 'error'); }
            };
        });
    }

    /* -------- Crear carpeta -------- */
    $('#btn-nueva-carpeta')?.addEventListener('click', async () => {
        const nombre = await pedirTexto('Nueva carpeta', '', 'Nombre de la carpeta');
        if (!nombre) return;
        try {
            await api('fs_crear_carpeta', { ruta: estado.ruta, nombre });
            toast('Carpeta creada.');
            cargar(estado.ruta);
        } catch (err) { toast(err.message, 'error'); }
    });

    /* -------- Subida de imágenes -------- */
    const PATRON_MEDIA = /\.(jpe?g|png|svg|heic|heif|mp4)$/i;
    const PATRON_EXTENSION = /\.([a-z0-9]{1,8})$/i;

    /**
     * Decide si un archivo puede intentarse subir.
     *
     * Las fotos de la cámara del iPhone a veces llegan sin extensión o con un
     * nombre genérico ("image", "blob"). En esos casos se deja pasar y el
     * servidor decide por el contenido real.
     */
    function esMediaSeleccionada(archivo) {
        if (PATRON_MEDIA.test(archivo.name || '')) return true;
        const tipo = (archivo.type || '').toLowerCase();
        if (tipo.startsWith('image/')) return true;
        if (tipo === 'video/mp4' || tipo === 'application/mp4') return true;
        if (!PATRON_EXTENSION.test(archivo.name || '')) return true;
        return tipo === '' || tipo === 'application/octet-stream';
    }

    $('#btn-subir')?.addEventListener('click', () => {
        if (estado.ruta === '') {
            toast('Entra en una carpeta de proyecto para subir imágenes.', 'error');
            return;
        }
        inputArchivos?.click();
    });

    function subirArchivo(archivo) {
        return new Promise((resolve, reject) => {
            const datos = new FormData();
            datos.append('action', 'fs_subir');
            datos.append('ruta', estado.ruta);
            datos.append('archivo', archivo);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'api.php', true);
            xhr.setRequestHeader('X-CSRF-Token', ADMIN.csrf);
            xhr.setRequestHeader('Accept', 'application/json');

            xhr.upload.onprogress = (ev) => {
                if (!ev.lengthComputable) return;
                barra.style.width = Math.round((ev.loaded / ev.total) * 100) + '%';
            };
            xhr.onload = () => {
                if (xhr.status === 401) { window.location.href = '../index.html'; reject(new Error('Sesión expirada.')); return; }
                let json = {};
                try { json = JSON.parse(xhr.responseText); } catch (e) { /* respuesta inválida */ }
                if (xhr.status >= 200 && xhr.status < 300 && json.ok) resolve(json);
                else reject(new Error(json.error || 'No se pudo subir "' + archivo.name + '".'));
            };
            xhr.onerror = () => reject(new Error('Error de red al subir "' + archivo.name + '".'));
            xhr.send(datos);
        });
    }

    async function subirLista(files) {
        if (estado.ruta === '') {
            toast('No se permite subir archivos en la raíz de /proyectos. Entra en una carpeta.', 'error');
            return;
        }

        const validas = Array.from(files).filter(esMediaSeleccionada);
        const rechazadas = files.length - validas.length;

        if (!validas.length) {
            toast('Solo se permiten JPG, JPEG, PNG, SVG, HEIC/HEIF y MP4.', 'error');
            return;
        }

        progreso.classList.add('visible');
        barra.style.width = '0%';
        let subidas = 0;
        const errores = [];

        for (const archivo of validas) {
            try {
                await subirArchivo(archivo);
                subidas++;
            } catch (err) {
                errores.push(err.message);
            }
        }

        barra.style.width = '100%';
        setTimeout(() => { progreso.classList.remove('visible'); barra.style.width = '0%'; }, 700);

        if (subidas) toast(subidas + ' archivo' + (subidas === 1 ? '' : 's') + ' subido' + (subidas === 1 ? '' : 's') + '.');
        if (rechazadas) toast(rechazadas + ' archivo(s) omitido(s): formato no permitido.', 'error');
        errores.slice(0, 3).forEach((mensaje) => toast(mensaje, 'error'));

        if (subidas) cargar(estado.ruta);
    }

    zona?.addEventListener('click', () => inputArchivos.click());
    zona?.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); inputArchivos.click(); }
    });
    inputArchivos?.addEventListener('change', () => {
        if (inputArchivos.files.length) subirLista(inputArchivos.files);
        inputArchivos.value = '';
    });

    ['dragenter', 'dragover'].forEach((evento) => {
        zona?.addEventListener(evento, (ev) => { ev.preventDefault(); zona.classList.add('arrastrando'); });
    });
    ['dragleave', 'drop'].forEach((evento) => {
        zona?.addEventListener(evento, (ev) => { ev.preventDefault(); zona.classList.remove('arrastrando'); });
    });
    zona?.addEventListener('drop', (ev) => {
        if (ev.dataTransfer?.files?.length) subirLista(ev.dataTransfer.files);
    });

    /* -------- Navegación con historial -------- */
    window.addEventListener('popstate', () => cargar(rutaDesdeHash(), true));

    cargar(rutaDesdeHash(), true);
}
