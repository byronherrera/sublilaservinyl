<?php
/**
 * Operaciones de archivos sobre /proyectos.
 *
 * Todas las rutas son relativas a /proyectos y se validan con ruta_proyecto()
 * para impedir accesos fuera del directorio (path traversal).
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Lista el contenido de una carpeta de /proyectos.
 *
 * @return array{ruta:string,migas:array,carpetas:array,imagenes:array,otros:array,padre:?string}
 */
function fs_listar(string $relativa): array
{
    $absoluta = ruta_proyecto($relativa, true);
    if ($absoluta === null || !is_dir($absoluta)) {
        throw new RuntimeException('La carpeta no existe o la ruta no es válida.');
    }

    $ruta = normalizar_ruta($relativa) ?? '';
    $carpetas = [];
    $imagenes = [];

    $entradas = scandir($absoluta);
    if ($entradas === false) {
        throw new RuntimeException('No se pudo leer la carpeta.');
    }

    foreach ($entradas as $entrada) {
        if ($entrada === '.' || $entrada === '..') {
            continue;
        }
        if (str_starts_with($entrada, '.')) {
            continue; // ocultos (.thumbs, .git, ...)
        }

        $rutaRel = $ruta === '' ? $entrada : $ruta . '/' . $entrada;
        $rutaAbs = $absoluta . DIRECTORY_SEPARATOR . $entrada;

        if (is_dir($rutaAbs)) {
            // Conteo visible de la carpeta: imágenes/videos y subcarpetas
            $imgHijos = 0;
            $vidHijos = 0;
            $subHijos = 0;
            $nombresImagenes = [];
            foreach (@scandir($rutaAbs) ?: [] as $hijo) {
                if ($hijo === '.' || $hijo === '..' || str_starts_with($hijo, '.')) {
                    continue;
                }
                $rutaHijo = $rutaAbs . DIRECTORY_SEPARATOR . $hijo;
                if (is_dir($rutaHijo)) {
                    $subHijos++;
                } elseif (is_file($rutaHijo) && es_media_permitida($hijo)) {
                    if (es_video_permitido($hijo)) {
                        $vidHijos++;
                    } else {
                        $imgHijos++;
                        // Portada solo con formatos que el navegador puede mostrar.
                        [, $extHijo] = partes_nombre($hijo);
                        if (in_array($extHijo, ['jpg', 'jpeg', 'png', 'svg'], true)) {
                            $nombresImagenes[] = $hijo;
                        }
                    }
                }
            }

            $portada = null;
            if ($nombresImagenes) {
                usort($nombresImagenes, 'strnatcasecmp');
                $portada = url_archivo('proyectos/' . $rutaRel . '/' . $nombresImagenes[0]);
            }

            $carpetas[] = [
                'nombre'      => $entrada,
                'ruta'        => $rutaRel,
                'imagenes'    => $imgHijos,
                'videos'      => $vidHijos,
                'subcarpetas' => $subHijos,
                'portada'     => $portada,
                'modificado'  => date('Y-m-d H:i', (int) filemtime($rutaAbs)),
            ];
            continue;
        }

        if (!is_file($rutaAbs)) {
            continue;
        }

        // El gestor solo administra imágenes y videos: el resto se ignora.
        if (!es_media_permitida($entrada)) {
            continue;
        }

        $esVideo = es_video_permitido($entrada);
        [$base, $ext] = partes_nombre($entrada);
        $imagenes[] = [
            'nombre'     => $entrada,
            'base'       => $base,
            'extension'  => $ext,
            'tipo'       => $esVideo ? 'video' : 'imagen',
            'ruta'       => $rutaRel,
            'bytes'      => (int) filesize($rutaAbs),
            'tamano'     => formato_bytes((int) filesize($rutaAbs)),
            'modificado' => date('Y-m-d H:i', (int) filemtime($rutaAbs)),
            'url'        => url_archivo('proyectos/' . $rutaRel),
        ];
    }

    // En la raíz de /proyectos las carpetas se muestran en orden inverso
    // (la más reciente primero); dentro de cada proyecto, orden natural.
    if ($ruta === '') {
        usort($carpetas, static fn($a, $b) => strnatcasecmp($b['nombre'], $a['nombre']));
    } else {
        usort($carpetas, static fn($a, $b) => strnatcasecmp($a['nombre'], $b['nombre']));
    }
    usort($imagenes, static fn($a, $b) => strnatcasecmp($a['nombre'], $b['nombre']));

    // Migas de pan
    $migas = [];
    if ($ruta !== '') {
        $acumulada = '';
        foreach (explode('/', $ruta) as $parte) {
            $acumulada = $acumulada === '' ? $parte : $acumulada . '/' . $parte;
            $migas[] = ['nombre' => $parte, 'ruta' => $acumulada];
        }
    }

    $padre = null;
    if ($ruta !== '') {
        $partes = explode('/', $ruta);
        array_pop($partes);
        $padre = implode('/', $partes);
    }

    return [
        'ruta'     => $ruta,
        'padre'    => $padre,
        'migas'    => $migas,
        'carpetas' => $carpetas,
        'imagenes' => $imagenes,
    ];
}

function fs_crear_carpeta(string $relativa, string $nombre): array
{
    $padre = ruta_proyecto($relativa, true);
    if ($padre === null || !is_dir($padre)) {
        throw new RuntimeException('La ubicación de destino no es válida.');
    }

    $limpio = limpiar_nombre($nombre);
    if ($limpio === null) {
        throw new RuntimeException('El nombre de la carpeta no es válido.');
    }

    $destino = $padre . DIRECTORY_SEPARATOR . $limpio;
    if (file_exists($destino)) {
        throw new RuntimeException('Ya existe una carpeta o archivo con ese nombre.');
    }
    if (!@mkdir($destino, 0775)) {
        throw new RuntimeException('No se pudo crear la carpeta. Revisa permisos.');
    }

    $rutaRel = normalizar_ruta($relativa) ?? '';
    return [
        'nombre' => $limpio,
        'ruta'   => $rutaRel === '' ? $limpio : $rutaRel . '/' . $limpio,
    ];
}

function fs_renombrar(string $relativa, string $nuevoNombre): array
{
    $absoluta = ruta_proyecto($relativa, true);
    if ($absoluta === null || $absoluta === realpath(PROYECTOS_DIR)) {
        throw new RuntimeException('El elemento a renombrar no es válido.');
    }

    $esCarpeta = is_dir($absoluta);
    if (!$esCarpeta && !es_media_permitida(basename($absoluta))) {
        throw new RuntimeException('Solo se pueden administrar imágenes y videos.');
    }
    $limpio = limpiar_nombre($nuevoNombre);
    if ($limpio === null) {
        throw new RuntimeException('El nuevo nombre no es válido.');
    }

    if (!$esCarpeta) {
        [, $extActual] = partes_nombre($absoluta);
        [, $extNueva]  = partes_nombre($limpio);
        if ($extNueva === '') {
            $limpio .= '.' . $extActual;
        } elseif ($extNueva !== $extActual) {
            throw new RuntimeException('No se puede cambiar la extensión del archivo (.' . $extActual . ').');
        }
    }

    $destino = dirname($absoluta) . DIRECTORY_SEPARATOR . $limpio;
    if ($destino === $absoluta) {
        return ['nombre' => $limpio, 'ruta' => $relativa];
    }
    if (file_exists($destino)) {
        throw new RuntimeException('Ya existe un elemento con el nombre "' . $limpio . '".');
    }
    if (!@rename($absoluta, $destino)) {
        throw new RuntimeException('No se pudo renombrar. Revisa permisos.');
    }

    $ruta = normalizar_ruta($relativa) ?? '';
    $partes = explode('/', $ruta);
    array_pop($partes);
    $nuevaRuta = implode('/', array_merge($partes, [$limpio]));

    return ['nombre' => $limpio, 'ruta' => $nuevaRuta, 'esCarpeta' => $esCarpeta];
}

function fs_eliminar(string $relativa): array
{
    $absoluta = ruta_proyecto($relativa, true);
    if ($absoluta === null || $absoluta === realpath(PROYECTOS_DIR)) {
        throw new RuntimeException('El elemento a borrar no es válido.');
    }

    if (is_file($absoluta) && !es_media_permitida(basename($absoluta))) {
        throw new RuntimeException('Solo se pueden administrar imágenes y videos.');
    }

    if (is_dir($absoluta)) {
        $resto = array_diff(scandir($absoluta) ?: [], ['.', '..']);
        if (count($resto) > 0) {
            throw new RuntimeException('La carpeta no está vacía. Borra primero su contenido.');
        }
        if (!@rmdir($absoluta)) {
            throw new RuntimeException('No se pudo borrar la carpeta vacía.');
        }
        return ['tipo' => 'carpeta', 'ruta' => $relativa];
    }

    if (!@unlink($absoluta)) {
        throw new RuntimeException('No se pudo borrar el archivo. Revisa permisos.');
    }
    return ['tipo' => 'archivo', 'ruta' => $relativa];
}

/**
 * Valida y guarda un archivo subido (imagen o video).
 *
 * Formatos aceptados: JPG/JPEG, PNG, SVG (saneado), HEIC/HEIF (iPhone) y MP4.
 * Nunca se permite subir a la raíz de /proyectos. Se verifica extensión, MIME,
 * firma binaria, estructura real y ausencia de código incrustado.
 *
 * @param array $archivo Entrada de $_FILES
 * @return array{nombre:string,ruta:string,tamano:string,tipo:string}
 */
function fs_subir_media(string $relativa, array $archivo): array
{
    $rutaNormalizada = normalizar_ruta($relativa);
    if ($rutaNormalizada === null) {
        throw new RuntimeException('La carpeta de destino no es válida.');
    }
    if ($rutaNormalizada === '') {
        throw new RuntimeException('No se permite subir archivos en la raíz de /proyectos. Entra en una carpeta de proyecto.');
    }

    $destinoDir = ruta_proyecto($rutaNormalizada, true);
    if ($destinoDir === null || !is_dir($destinoDir)) {
        throw new RuntimeException('La carpeta de destino no es válida.');
    }

    if (!isset($archivo['error']) || is_array($archivo['error'])) {
        throw new RuntimeException('Solicitud de subida no válida.');
    }

    switch ((int) $archivo['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new RuntimeException('El archivo supera el tamaño máximo permitido por el servidor.');
        case UPLOAD_ERR_NO_FILE:
            throw new RuntimeException('No se seleccionó ningún archivo.');
        default:
            throw new RuntimeException('Error al subir el archivo (código ' . (int) $archivo['error'] . ').');
    }

    if ((int) $archivo['size'] <= 0 || (int) $archivo['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('El archivo debe pesar entre 1 byte y ' . formato_bytes(MAX_UPLOAD_BYTES) . '.');
    }
    if (!is_uploaded_file((string) $archivo['tmp_name'])) {
        throw new RuntimeException('Archivo temporal no válido.');
    }

    $tmp = (string) $archivo['tmp_name'];
    $nombreOriginal = (string) ($archivo['name'] ?? '');
    $limpio = limpiar_nombre($nombreOriginal);
    if ($limpio === null) {
        throw new RuntimeException('El nombre del archivo no es válido.');
    }

    [$base, $extNombre] = partes_nombre($limpio);

    // El formato real lo decide el contenido, no el nombre: las fotos tomadas
    // con la cámara del iPhone pueden llegar sin extensión o con un nombre
    // genérico (por ejemplo "image" o "blob").
    $extContenido = extension_detectada($tmp);
    if ($extContenido === null) {
        throw new RuntimeException('El archivo no es una imagen o video compatible (JPG, PNG, SVG, HEIC/HEIF o MP4).');
    }

    // Conserva la variante del nombre cuando coincide con el contenido.
    $ext = $extContenido;
    if ($extContenido === 'jpg' && $extNombre === 'jpeg') {
        $ext = 'jpeg';
    } elseif ($extContenido === 'heic' && $extNombre === 'heif') {
        $ext = 'heif';
    }

    if (!in_array($ext, MEDIA_EXTS, true)) {
        throw new RuntimeException('Solo se permiten imágenes JPG, PNG, SVG, HEIC/HEIF y video MP4.');
    }

    if ($base === '') {
        $base = 'archivo';
    }

    // 1) MIME real (finfo) — solo informativo; el contenido ya está clasificado.
    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
    }
    $mimesPermitidos = [
        'jpg'  => ['image/jpeg', 'image/jpg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/jpg', 'image/pjpeg'],
        'png'  => ['image/png', 'image/x-png'],
        'svg'  => ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain', 'text/html'],
        'heic' => ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence', 'application/octet-stream'],
        'heif' => ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence', 'application/octet-stream'],
        'mp4'  => ['video/mp4', 'application/mp4', 'video/quicktime', 'video/x-m4v', 'application/octet-stream'],
    ];
    if ($mime !== '' && !in_array($mime, $mimesPermitidos[$ext], true)) {
        // El MIME de finfo es solo orientativo: en Windows devuelve a menudo
        // "application/octet-stream" y distintas versiones de iOS etiquetan
        // igual los HEIC. El formato real ya se dedujo del contenido, así que
        // solo se bloquean tipos claramente ejecutables o de texto.
        $mimesEjecutables = [
            'application/x-php', 'application/x-httpd-php', 'text/x-php',
            'application/x-sh', 'text/x-shellscript', 'application/x-csh',
        ];
        if (in_array($mime, $mimesEjecutables, true)) {
            throw new RuntimeException('El archivo contiene código ejecutable y fue rechazado.');
        }
    }

    // 2) Firma binaria (magic bytes)
    if (!media_firma_valida($tmp, $ext)) {
        throw new RuntimeException('El archivo no tiene una firma válida de .' . $ext . '.');
    }

    // 3) Estructura real del contenido
    $contenidoFinal = null;
    if ($ext === 'svg') {
        // Se guarda una versión saneada: sin scripts, eventos ni enlaces peligrosos.
        $contenidoFinal = svg_sanear((string) file_get_contents($tmp));
    } elseif (in_array($ext, ['heic', 'heif', 'mp4'], true)) {
        // HEIC/HEIF y MP4 no los interpreta getimagesize(): se comprueba la
        // caja ftyp y que exista la estructura mínima del contenedor.
        $crudoContenedor = (string) file_get_contents($tmp);
        if ($ext === 'mp4') {
            if (strpos($crudoContenedor, 'moov') === false && strpos($crudoContenedor, 'mdat') === false) {
                throw new RuntimeException('El archivo MP4 no es válido o está dañado.');
            }
        } elseif (strpos($crudoContenedor, 'meta') === false && strpos($crudoContenedor, 'mdat') === false) {
            throw new RuntimeException('El archivo ' . strtoupper($ext) . ' no es válido o está dañado.');
        }
    } else {
        $info = @getimagesize($tmp);
        if ($info === false) {
            throw new RuntimeException('El archivo no es una imagen válida o está dañado.');
        }
        $mimeDetectado = (string) ($info['mime'] ?? '');
        if ($mimeDetectado !== '' && !in_array($mimeDetectado, $mimesPermitidos[$ext], true)) {
            throw new RuntimeException('El tipo de imagen detectado no coincide con la extensión.');
        }
    }

    // 4) Bloquear contenido ejecutable o de texto incrustado (el SVG ya está saneado)
    if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        // Los contenedores HEIC/HEIF y MP4 son binarios y no ejecutan scripts;
        // escanearlos entero solo produce falsos positivos.
        $crudo = (string) file_get_contents($tmp);
        $patronesProhibidos = ['<?php', '<?=', '<script', '<html', '<iframe', 'javascript:', '#!/'];
        foreach ($patronesProhibidos as $patron) {
            $posicion = stripos($crudo, $patron);
            if ($posicion === false) {
                continue;
            }

            // Diagnóstico exacto: patrón, byte donde aparece, contexto legible
            // y bytes en hexadecimal para poder revisar el archivo real.
            $contexto = (string) substr($crudo, max(0, $posicion - 40), 100);
            $contexto = preg_replace('/[^\x20-\x7E]/', '.', $contexto) ?? '';
            $hex = bin2hex((string) substr($crudo, $posicion, 16));

            throw new RuntimeException(
                'El archivo contiene código no permitido ['
                . $patron . '] en el byte ' . $posicion . '. '
                . 'Contexto: "' . $contexto . '" '
                . 'Hex: ' . $hex
            );
        }
    }

    // 5) Evitar sobrescrituras: nombre.jpg → nombre-1.jpg
    $nombreFinal = $base . '.' . $ext;
    $contador = 0;
    while (file_exists($destinoDir . DIRECTORY_SEPARATOR . $nombreFinal)) {
        $contador++;
        $nombreFinal = $base . '-' . $contador . '.' . $ext;
        if ($contador > 500) {
            throw new RuntimeException('Demasiadas colisiones de nombre. Renombra el archivo e inténtalo de nuevo.');
        }
    }

    $rutaFinal = $destinoDir . DIRECTORY_SEPARATOR . $nombreFinal;
    if ($ext === 'svg') {
        if (@file_put_contents($rutaFinal, (string) $contenidoFinal) === false) {
            throw new RuntimeException('No se pudo guardar el SVG. Revisa permisos de la carpeta.');
        }
    } elseif (!@move_uploaded_file($tmp, $rutaFinal)) {
        throw new RuntimeException('No se pudo guardar el archivo. Revisa permisos de la carpeta.');
    }
    @chmod($rutaFinal, 0644);

    return [
        'nombre' => $nombreFinal,
        'ruta'   => $rutaNormalizada . '/' . $nombreFinal,
        'tamano' => formato_bytes((int) filesize($rutaFinal)),
        'tipo'   => es_video_permitido($nombreFinal) ? 'video' : 'imagen',
    ];
}

/**
 * Devuelve las marcas (brands) de la caja ISO-BMFF `ftyp`, si existe.
 *
 * Recorre las cajas desde el inicio (la norma permite cajas `free`, `wide` o
 * `uuid` antes de `ftyp`, y tamaños de 64 bits) en lugar de asumir que `ftyp`
 * está siempre en el offset 4. Incluye la marca principal y todas las
 * compatibles para reconocer archivos de iPhone (HEIC/HEIF) y MP4.
 *
 * @return string[]
 */
function ftyp_marcas(string $cabecera): array
{
    $longitud = strlen($cabecera);
    $offset = 0;

    while ($offset + 8 <= $longitud) {
        $tamano = (int) (unpack('N', substr($cabecera, $offset, 4))[1] ?? 0);
        $tipo = substr($cabecera, $offset + 4, 4);
        $cabeceraCaja = 8;

        if ($tamano === 1) {
            // Largesize de 64 bits.
            if ($offset + 16 > $longitud) {
                break;
            }
            $tamano = (int) (unpack('J', substr($cabecera, $offset + 8, 8))[1] ?? 0);
            $cabeceraCaja = 16;
        } elseif ($tamano === 0) {
            $tamano = $longitud - $offset;
        }

        if ($tamano < $cabeceraCaja) {
            break;
        }

        if ($tipo === 'ftyp') {
            $caja = substr($cabecera, $offset, min($tamano, $longitud - $offset));
            if (strlen($caja) < 16) {
                return [];
            }
            $marcas = [substr($caja, 8, 4)];
            for ($i = 16; $i + 4 <= strlen($caja); $i += 4) {
                $marcas[] = substr($caja, $i, 4);
            }
            return array_values(array_unique(array_filter(
                $marcas,
                static fn ($m) => $m !== '' && $m !== "\0\0\0\0"
            )));
        }

        $offset += $tamano;
    }

    return [];
}

/**
 * Detecta el formato real de un archivo por su contenido.
 *
 * @return string|null jpg, png, svg, heic, heif o mp4; null si no es compatible
 */
function extension_detectada(string $ruta): ?string
{
    $cabecera = (string) file_get_contents($ruta, false, null, 0, 4096);
    if ($cabecera === '') {
        return null;
    }

    if (str_starts_with($cabecera, "\xFF\xD8\xFF")) {
        return 'jpg';
    }
    if (str_starts_with($cabecera, "\x89PNG\r\n\x1A\n")) {
        return 'png';
    }
    if (stripos($cabecera, '<svg') !== false) {
        return 'svg';
    }

    $marcas = ftyp_marcas($cabecera);
    if (!$marcas) {
        return null;
    }

    // AVIF no está permitido: se rechaza explícitamente.
    if (array_intersect($marcas, ['avif', 'avis'])) {
        return null;
    }

    $marcasHeic = [
        'heic', 'heix', 'hevc', 'heim', 'heis', 'hevm', 'hevs',
        'heif', 'mif1', 'mif2', 'msf1',
    ];
    if (array_intersect($marcas, $marcasHeic)) {
        return str_contains(implode(' ', $marcas), 'heif') ? 'heif' : 'heic';
    }

    $marcasMp4 = [
        'isom', 'iso2', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1',
        'dash', 'M4V ', 'MSNV', '3gp4', '3gp5', 'mmp4', 'qt  ',
    ];
    if (array_intersect($marcas, $marcasMp4)) {
        return 'mp4';
    }

    // Último recurso: estructura del contenedor.
    $crudo = (string) file_get_contents($ruta);
    if (strpos($crudo, 'moov') !== false) {
        return 'mp4';
    }
    if (strpos($crudo, 'meta') !== false) {
        return 'heic';
    }

    return null;
}

/** Verifica que el contenido real coincida con la extensión indicada. */
function media_firma_valida(string $ruta, string $ext): bool
{
    $detectada = extension_detectada($ruta);
    if ($detectada === null) {
        return false;
    }

    if ($detectada === 'jpg') {
        return in_array($ext, ['jpg', 'jpeg'], true);
    }
    if ($detectada === 'heic' || $detectada === 'heif') {
        return in_array($ext, ['heic', 'heif'], true);
    }

    return $detectada === $ext;
}

/**
 * Sanea un SVG eliminando scripts, manejadores de eventos y enlaces peligrosos.
 * Devuelve el XML saneado que se guarda en disco.
 */
function svg_sanear(string $contenido): string
{
    if (stripos($contenido, '<!DOCTYPE') !== false || stripos($contenido, '<!ENTITY') !== false) {
        throw new RuntimeException('El SVG contiene declaraciones no permitidas.');
    }

    $anterior = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $ok = $doc->loadXML($contenido, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($anterior);

    $raiz = $doc->documentElement;
    if (!$ok || !$raiz instanceof DOMElement || strtolower($raiz->localName) !== 'svg') {
        throw new RuntimeException('El archivo SVG no es válido.');
    }

    $prohibidos = [
        'script', 'foreignobject', 'iframe', 'object', 'embed', 'handler', 'listener',
        'audio', 'video', 'style', 'set',
    ];

    $nodos = [];
    foreach ((new DOMXPath($doc))->query('//*') ?: [] as $nodo) {
        $nodos[] = $nodo;
    }

    foreach ($nodos as $nodo) {
        if (!$nodo instanceof DOMElement) {
            continue;
        }

        $nombreNodo = strtolower($nodo->localName);
        if (in_array($nombreNodo, $prohibidos, true)) {
            if ($nodo->parentNode !== null) {
                $nodo->parentNode->removeChild($nodo);
            }
            continue;
        }

        // Los elementos de animación pueden reescribir atributos de evento o
        // enlaces (p. ej. <animate attributeName="href" values="javascript:...">).
        if (str_starts_with($nombreNodo, 'animate')) {
            $atributoAnimado = strtolower(trim((string) $nodo->getAttribute('attributeName')));
            if (str_starts_with($atributoAnimado, 'on')
                || $atributoAnimado === 'href'
                || $atributoAnimado === 'xlink:href'
                || $atributoAnimado === 'style') {
                if ($nodo->parentNode !== null) {
                    $nodo->parentNode->removeChild($nodo);
                }
                continue;
            }
        }

        $atributos = [];
        foreach ($nodo->attributes ?? [] as $atributo) {
            $atributos[] = $atributo;
        }
        foreach ($atributos as $atributo) {
            $nombre  = strtolower($atributo->nodeName);
            $valor   = trim((string) $atributo->nodeValue);
            $valorMin = strtolower($valor);

            $peligroso = str_starts_with($nombre, 'on')
                || str_contains($valorMin, 'javascript:')
                || str_contains($valorMin, 'vbscript:')
                || str_contains($valorMin, 'data:text/html')
                || str_contains($valorMin, 'expression(')
                || str_contains($valorMin, '@import')
                || str_contains($valorMin, '-moz-binding')
                || str_contains($valorMin, 'behavior:');

            // Cualquier url(...) externa puede exfiltrar datos o cargar recursos;
            // sí se permiten referencias internas (url(#gradiente)) y data:image.
            if (!$peligroso && str_contains($valorMin, 'url(')
                && !preg_match('/url\(\s*[\'"]?(?:#|data:image\/(?:png|jpe?g|gif|webp)[;,\)])/i', $valorMin)) {
                $peligroso = true;
            }

            // Los estilos inline solo se permiten sin referencias externas.
            if ($nombre === 'style' && str_contains($valorMin, 'url(')) {
                $peligroso = true;
            }

            if (($nombre === 'href' || $nombre === 'xlink:href')) {
                $permitido = str_starts_with($valor, '#')
                    || preg_match('#^data:image/(?:png|jpe?g|gif|webp);#i', $valor) === 1;
                if (!$permitido) {
                    $peligroso = true;
                }
            }

            if ($peligroso && $nodo->attributes->getNamedItem($atributo->nodeName) !== null) {
                $nodo->removeAttributeNode($atributo);
            }
        }
    }

    $salida = $doc->saveXML();
    if ($salida === false
        || stripos($salida, '<script') !== false
        || stripos($salida, 'javascript:') !== false
        || stripos($salida, '@import') !== false
        || stripos($salida, '<set') !== false) {
        throw new RuntimeException('El SVG contiene contenido no permitido.');
    }

    return $salida;
}

/** Estadísticas globales del directorio /proyectos (para el panel). */
function fs_estadisticas(): array
{
    $raiz = realpath(PROYECTOS_DIR);
    if ($raiz === false) {
        return ['proyectos' => 0, 'imagenes' => 0, 'videos' => 0, 'carpetas' => 0, 'bytes' => 0, 'peso' => '0 B'];
    }

    $proyectos = 0;
    $imagenes = 0;
    $videos = 0;
    $carpetas = 0;
    $bytes = 0;

    foreach (scandir($raiz) ?: [] as $entrada) {
        if ($entrada === '.' || $entrada === '..' || str_starts_with($entrada, '.')) {
            continue;
        }
        if (is_dir($raiz . DIRECTORY_SEPARATOR . $entrada)) {
            $proyectos++;
        }
    }

    $iterador = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterador as $item) {
        /** @var SplFileInfo $item */
        $nombre = $item->getFilename();
        if (str_starts_with($nombre, '.')) {
            continue;
        }
        if ($item->isDir()) {
            $carpetas++;
            continue;
        }
        $bytes += $item->getSize();
        if (in_array(strtolower($item->getExtension()), IMAGE_EXTS, true)) {
            $imagenes++;
        } elseif (in_array(strtolower($item->getExtension()), VIDEO_EXTS, true)) {
            $videos++;
        }
    }

    return [
        'proyectos' => $proyectos,
        'imagenes'  => $imagenes,
        'videos'    => $videos,
        'carpetas'  => $carpetas,
        'bytes'     => $bytes,
        'peso'      => formato_bytes($bytes),
    ];
}
