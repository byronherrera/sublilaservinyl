/* Sublilaservinyl · Tema claro / oscuro.
   Guarda la elección del usuario en localStorage y la aplica en <html data-theme>.
   Por defecto se usa el tema oscuro. */
(function () {
    var CLAVE = 'sublilaservinyl.tema';
    var raiz = document.documentElement;

    function temaGuardado() {
        try { return localStorage.getItem(CLAVE) || localStorage.getItem('atelierGio.tema'); } catch (e) { return null; }
    }

    function aplicar(tema) {
        raiz.setAttribute('data-theme', tema);
        var botones = document.querySelectorAll('[data-tema-toggle]');
        var claro = tema === 'claro';
        for (var i = 0; i < botones.length; i++) {
            var b = botones[i];
            var texto = claro ? 'Cambiar a tema oscuro' : 'Cambiar a tema claro';
            b.setAttribute('aria-label', texto);
            b.setAttribute('title', texto);
            b.setAttribute('aria-pressed', String(claro));
            var icono = b.querySelector('i');
            if (icono) icono.className = claro ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
        }
    }

    aplicar(temaGuardado() === 'claro' ? 'claro' : 'oscuro');

    document.addEventListener('DOMContentLoaded', function () {
        var botones = document.querySelectorAll('[data-tema-toggle]');
        for (var i = 0; i < botones.length; i++) {
            botones[i].addEventListener('click', function () {
                var nuevo = raiz.getAttribute('data-theme') === 'claro' ? 'oscuro' : 'claro';
                try { localStorage.setItem(CLAVE, nuevo); } catch (e) { /* almacenamiento no disponible */ }
                aplicar(nuevo);
            });
        }
        aplicar(raiz.getAttribute('data-theme'));
    });
})();
