# Sublilaservinyl

**Fabricantes de apliques textiles**
**ATENCIÓN SOLO PREVIA CITA**
Realizamos envíos a todo el país 🇪🇨🚛
WhatsApp: 0997609140 / 0987877975

https://www.instagram.com/sublilaservinyl/

---

Repositorio de Sublilaservinyl. Contiene varias aplicaciones independientes
que comparten el mismo lenguaje visual (isotipo dorado, tipografía editorial):

## 0. ActivaR Industrias — gestión inteligente de activos (`ActivarIndustrias/`)

Mockup funcional de una plataforma SaaS B2B para gestión integral de activos industriales
(CMMS + EAM + APM): multitenant, 2 organizaciones de demostración, 16+ usuarios con 10
roles y matriz RBAC, 17 equipos, 36 componentes con trazabilidad de vida útil, órdenes
de trabajo con flujo de 8 estados, inventario con movimientos auditables, proveedores,
alertas, MTBF/MTTR e indicadores derivados. Stack: React 18 + TypeScript + Vite +
Tailwind + Recharts, sin backend (persistencia en localStorage).

La plataforma aplica la identidad corporativa de **ActivaR Industrias**: paleta
petróleo/turquesa, Manrope + Inter + IBM Plex Mono autoalojadas y logotipo vectorial
generado (la «pata de activación» de la R).

```bash
cd ActivarIndustrias
npm install
npm run dev   # http://localhost:5199
```

Documentación: [`ActivarIndustrias/README.md`](ActivarIndustrias/README.md) ·
arquitectura, matriz de roles, modelo de datos y guion de pruebas en
[`ActivarIndustrias/docs/`](ActivarIndustrias/docs/).

### Manual de identidad (`IdentidadCorportativa/`)

Manual básico de identidad + micrositio navegable + 23 láminas + SVG de la marca:

```bash
python3 IdentidadCorportativa/tools/serve.py 5300
# manual   → http://localhost:5300/
# plataforma → http://localhost:5300/app/
```

Contenido: [`IdentidadCorportativa/MANUAL-IDENTIDAD.md`](IdentidadCorportativa/MANUAL-IDENTIDAD.md).

## 1. Referencias visuales (`app/`)

Explorador de colecciones y proyectos: recorre `proyectos/` de forma recursiva, muestra
imágenes, videos y documentos `.md` con lightbox, búsqueda, filtros y copiado al portapapeles.

- Requiere PHP (Laragon / Apache) por `api.php` y `md.php`.
- Especificación: [`app/especificacion.md`](app/especificacion.md)

## 2. Sistema biométrico facial (`biometrico/`)

Kiosco de marcación por reconocimiento facial con integración a **Odoo**
(asistencia y nómina). Incluye panel administrativo, cálculo de rol de pagos
de Ecuador y conector JSON-RPC con cola de reintentos.

```bash
cd biometrico
node server.js
# Kiosco:  http://localhost:8787/
# Admin:   http://localhost:8787/admin.html     (PIN demo 1122)
```

- Requiere Node 22+ (usa `node:sqlite`), sin dependencias externas.
- Documentación: [`biometrico/README.md`](biometrico/README.md) ·
  arquitectura completa en [`biometrico/docs/especificacion.md`](biometrico/docs/especificacion.md)

## 3. Material de generación de imágenes

`generar_imagen.md`, `generar_imagen-buso.md` y `fondos/` documentan el flujo de trabajo
usado para producir las imágenes de producto de cada proyecto.

## 4. Panel de administración (`app/administracion/`)

Acceso por **token** para gestionar usuarios y los archivos de `proyectos/`:
crear carpetas, subir imágenes, renombrar y borrar. Los tokens se guardan en SQLite y
todas las acciones quedan en un log mensual.

- Entrada: `http://localhost/sublilaservinyl/app/administracion/`
- Guía: [`app/administracion/README.md`](app/administracion/README.md)

## 5. Medidor de Ruido (`medidor-ruido/`)

Aplicación web (PWA) para medir el nivel de ruido en decibeles con el
micrófono del dispositivo: muestras con historial de medición, ubicación GPS,
fotografía del evento y grabación de audio. Acceso sin usuario, funciona sin
conexión (localStorage) y sincroniza automáticamente a SQLite en el servidor.
Stack: PHP + React + SQLite.

- Entrada: `http://localhost/sublilaservinyl/medidor-ruido/`
- Documentación: [`medidor-ruido/README.md`](medidor-ruido/README.md) ·
  PRD/TRD y guías en [`medidor-ruido/documentacion/`](medidor-ruido/documentacion/)
