# Especificación y Auditoría Técnica - Sublilaservinyl (`app2` · v2.2)

## Resumen General

**Sublilaservinyl - Referencias (`app2`)** es la aplicación web de gestión visual para colecciones y proyectos del atelier de moda femenina. Carga dinámicamente las carpetas, **imágenes**, **videos** y documentos Markdown del repositorio mediante endpoints PHP (`api.php` y `md.php`), ofreciendo exploración por lotes, búsqueda instantánea, filtrado de referencias, generación automática de miniaturas de video con respaldo de icono, reproducción en lightbox, visor de archivos `.md` y copia al portapapeles en escritorio y móvil (incluyendo iOS/Safari).

### URL Base
- **Ruta Laragon / Apache**: `http://localhost/sublilaservinyl/app2/`
- **Virtual Host / Servidor Raíz**: `http://sublilaservinyl.test/app2/` o `http://localhost:8000/app2/`
- **API Endpoints**: `api.php` y `md.php`

---

## Novedades de la Versión v2.2 — Carpetas y Subcarpetas

### 1. Explorador jerárquico (carpetas → subcarpetas → archivos)

Antes la API devolvía únicamente los archivos del **primer nivel** de cada proyecto
(`proyectos/<proyecto>/*.jpg`) y las subcarpetas se ignoraban. Ahora la app muestra la
**jerarquía completa**.

- **`api.php` — recorrido recursivo (`scanMediaDirectory`)**
  - Recorre cada proyecto y todas sus subcarpetas anidadas (hasta `MAX_SUBFOLDER_DEPTH = 8`).
  - Por cada nodo devuelve: `name`, `path` (ruta relativa al repo), `depth`, `description`,
    `images[]`, `mdFiles[]`, `subfolders[]` y los conteos `imageCount` / `videoCount`
    (propios), `totalImageCount` / `totalVideoCount` / `totalFileCount` / `subfolderCount`
    (incluyendo descendientes).
  - La raíz del proyecto conserva las claves históricas (`images`, `imageCount`, `mdFiles`),
    por lo que el contrato anterior sigue siendo válido, y añade `subfolders` y los totales.
  - Cada ítem multimedia incluye `relativePath` y `folderPath`; los videos mantienen la
    búsqueda de póster opcional (`nombre.poster.jpg` / `.thumbs/nombre.jpg`) dentro de su
    propia subcarpeta.
  - Las carpetas ocultas (`.thumbs`, `.git`, etc.) se omiten.
  - Se listan también las **subcarpetas vacías** para no perder la estructura.
  - Salida nueva a nivel raíz: `totalSubfolders`.

- **`index.html` — render por grupos**
  - `collectFolderGroups()` aplana cada proyecto en una lista de *grupos* (raíz + subcarpetas
    en profundidad) y aplica el filtro de referencias y la búsqueda a cada grupo.
  - Cada tarjeta de proyecto mantiene su cabecera (nombre, descripción, `N imágenes`,
    `N subcarpetas`, `▶ N videos`, badge de referencias ocultas y botones `.md`) y su cuerpo
    (`.folder-body`) contiene: el grid del nivel raíz y, debajo, una **sección por subcarpeta**
    (`.subfolder-block`) con su propio encabezado (icono, nombre, conteo, ruta relativa y sus
    botones `.md`) y su propio grid de archivos, indentado según la profundidad.
  - Los conteos de la cabecera suman el nivel raíz y todos los niveles descendientes.
  - La búsqueda encuentra **nombres de subcarpeta** y sus rutas; al buscar, solo se muestran
    las secciones con coincidencias (las subcarpetas vacías se ocultan para no generar ruido).
  - El scroll infinito (lotes de 50) y el lightbox funcionan sobre la lista aplanada de
    grupos; la navegación del lightbox etiqueta el origen como `proyecto / subcarpeta`.

### 2. Compatibilidad

- Los proyectos sin subcarpetas se renderizan exactamente igual que antes (una sola sección).
- `md.php` no requirió cambios: los botones `.md` de subcarpetas envían rutas relativas al
  repositorio (`proyectos/<proyecto>/<subcarpeta>/README.md`), que ya estaban soportadas y
  validadas con `realpath`.

---

## Novedades de la Versión v2.1

### 1. Soporte Integral de Videos (Listado, Búsqueda, Thumbnail y Reproducción)

- **Inclusión en listado y búsqueda (`api.php` + `index.html`)**:
  - `api.php` detecta e indexa automáticamente archivos de video (`.mp4`, `.webm`, `.mov`, `.m4v`, `.ogv`, `.ogg`, `.avi`, `.mkv`) junto con las imágenes en orden alfabético natural, devolviendo `type: "video"`, `isVideo: true`, conteo `videoCount` por carpeta y detección de referencias (`isReference`).
  - El buscador del header permite encontrar videos por **nombre de carpeta**, **nombre de archivo**, **extensión** (ej. `mp4`, `mov`) o escribiendo directamente **`video` / `videos`**.
  - Cada carpeta que contiene videos muestra una etiqueta dedicada (`▶ N videos`) en su cabecera.

- **Análisis técnico e implementación de Thumbnails para Video**:
  1. **En servidor (PHP / Laragon)**: La extensión estándar `GD` de PHP únicamente procesa imágenes rasterizadas y **no decodifica flujos de video** H.264/HEVC sin depender de binarios externos (`ffmpeg` en el `PATH`). Además, generar archivos caché dentro de `proyectos/` contaminaría los commits de `subir.bat` (`git add -A`). No obstante, `api.php` soporta carátulas opcionales si existen (`nombre.poster.jpg/png/webp` o `.thumbs/nombre.jpg`).
  2. **Extracción automática de Thumbnail en cliente (`HTML5 <video> + <canvas>`)**: Se implementó un extractor asíncrono perezoso (`IntersectionObserver` + cola limitada a `2` workers concurrentes para proteger el límite de decodificadores hardware en iOS/móvil). Carga los metadatos del video, obtiene su duración (`0:15`), se posiciona en el `15%` inicial (hasta `1.0s`, evitando fotogramas negros al inicio), dibuja el fotograma en `<canvas>`, almacena el JPEG resultante en caché de memoria (`videoThumbCache`) y libera inmediatamente el decodificador `<video>`.
  3. **Póster con Icono Fallback**: Mientras se extrae el fotograma —o de forma permanente si el navegador no soporta el códec del archivo (ej. `.avi`, `.mkv` o ciertos `.mov` HEVC)— la tarjeta muestra un **póster gráfico minimalista de la marca con icono de reproducción y etiqueta de formato (`VIDEO · MP4`)**, evitando cuadros vacíos o rotos.

- **Botón Zoom reemplazado por Play + Reproductor en Lightbox**:
  - En las tarjetas de video, el botón circular superior derecho sustituye la lupa (`fa-search`) por el icono **Play (`fa-play`)** (`zoom-icon is-play`), acompañado de un indicador central sobre el thumbnail.
  - Al pulsar la tarjeta o el botón **Play**, el Lightbox oculta `<img>`, activa el reproductor `<video controls playsinline autoplay>`, inicia la reproducción automáticamente y detiene/libera el flujo de audio y video al cerrar (`×`, `Escape`, click fuera, `touchend` en iOS) o al navegar entre elementos (`prev` / `next`).

---

### 2. Identidad de Marca: Logo e Isotipo Minimalista (*Sublilaservinyl*)

Diseñado bajo principios de branding contemporáneo para casas de moda femenina (*actual, simple, limpio y mínimo*):

- **Concepto del Isotipo (`app2/assets/isotipo.svg`)**:
  - **Halo circular abierto**: Trazo fino en degradado oro champán (`#FDE68A → #F59E0B → #D97706`) que enmarca el símbolo con amplio espacio negativo y se abre limpiamente en la base.
  - **Busto / Maniquí de costura femenino**: Silueta continua en marfil cálido (`#F4F4F6`) de hombros esculpidos, cintura entallada y caída de cadera, acentuada únicamente por dos trazos sartoriales dorados (escote y línea de talle) sobre un pedestal minimalista.
  - Legibilidad óptima tanto a `42px` en el header como a `16px` en el **favicon** de la pestaña del navegador.
- **Logotipo / Imagotipo en Header (`app2/assets/logo.svg` + SVG inline)**:
  - Combina el **isotipo** + filete vertical sutil + **wordmark tipográfico** en dos niveles: **`REBECA`** en serif editorial de alto contraste (*Cormorant Garamond*, `600`, tracking amplio) y **`ATELIER`** en sans geométrica (*Inter*, `600`, mayúsculas espaciadas en tono ámbar dorado).

---

## Revisión Punto por Punto Original (`app/` vs `app2/`)

| # | Requerimiento | Estado hallado en `app/` | Solución en `app2/` |
|---|---|---|---|
| **1** | **Visualización multimedia** | `ul` sin `list-style: none` con `float: left`, `.folder-desc` sin `padding`, badge `hasReferenceImages` calculado tras filtrar referencias, orden arbitrario `readdir()`. | Grid CSS responsivo (`5` / `3` / `1` col.), soporte unificado de imágenes y videos, orden natural determinista y badges exactos. |
| **2** | **Lightbox Popup** | `initLightbox()` nunca se invocaba (no cerraba ni navegaba); `<</span>` sin escapar. | `initLightbox()` activa al iniciar, soporte dual `<img>` + `<video>`, `Escape`, flechas de teclado, `touchend` iOS, swipe horizontal y contador. |
| **3** | **Icono Lupa / Play** | Font Awesome al final del `<body>`; sin soporte de Play para videos. | Font Awesome en `<head>`, icono **Lupa** para imágenes y **Play** para videos en esquina superior derecha. |
| **4** | **Copiar al portapapeles** | `.copy-icon` no se renderizaba en el HTML; fallo de gesto asíncrono en Safari/iOS y corrupción permanente del texto del toast en error. | `.copy-icon` visible en todas las tarjetas; `ClipboardItem` con `Promise<Blob>` para imágenes PNG y copia directa de URL para videos; `showToast()` limpio. |
| **5** | **Filtros y Buscador** | No reiniciaba `imagesRendered = 0` al buscar/filtrar (congelando la vista); filtraba solo carpetas enteras; SVG corrupto. | Reinicio limpio de lotes en cada filtro/búsqueda, coincidencia por carpeta, nombre, extensión o palabra `"video"`, botón `×` limpiar. |
| **6** | **Lazy Loading (50 por lote)** | El segundo lote sobrescribía `innerHTML` encogiendo la galería e impidiendo ver las carpetas finales; fuga de múltiples `IntersectionObserver`. | Paginación acumulativa real (`50 → 100 → 150...`) con inserción incremental en DOM sin parpadeo y observer único gestionado. |
| **7** | **Header Fijo + Logo** | `margin: 0 auto` anulaba `margin-top: 80px`; typo `"Rebeca Aterlier"`; comentarios `/* ... */` visibles en HTML; logo genérico. | `--header-offset` dinámico (`ResizeObserver`), nuevo **Logo + Isotipo** de atelier femenino, comentarios HTML válidos. |
| **8** | **Responsive Móvil** | Solapamiento del header y textos minúsculos (`0.55rem`). | Vista 100% ancho a una columna, controles táctiles amplios e iconos lupa/play + copia alineados. |
| **9** | **Visor `.md` y Backend PHP** | Sin botones `.md` en UI; `marked()` roto en v4.3; ruta fija `C:/laragon/...`; vulnerabilidad *Path Traversal* en `md.php`. | Botones `.md-btn` por carpeta y raíz, `marked.parse()` + tablas, rutas dinámicas en `api.php` y validación `realpath` en `md.php`. |

---

## Arquitectura de Archivos en `app2/`

```
sublilaservinyl/
└── app2/
    ├── assets/
    │   ├── isotipo.svg    ← Isotipo vectorial minimalista (y favicon)
    │   └── logo.svg       ← Imagotipo completo (isotipo + wordmark editorial)
    ├── index.html         ← SPA principal con soporte de imágenes, videos y nuevo branding
    ├── api.php            ← Endpoint JSON dinámico (imágenes, videos y .md)
    ├── md.php             ← Endpoint seguro de lectura de archivos Markdown
    └── especificacion.md  ← Especificación técnica v2.1
```
