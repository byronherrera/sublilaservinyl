<?php
/**
 * API endpoint para Sublilaservinyl - Referencias (v2.2)
 *
 * Lista las carpetas de proyectos en orden inverso natural y recorre sus
 * SUBCARPETAS de forma recursiva. Por cada carpeta y subcarpeta devuelve:
 *   - sus archivos multimedia (imágenes y videos)
 *   - sus archivos .md
 *   - sus propias subcarpetas (anidadas)
 *   - conteos propios y totales (incluyendo descendientes)
 * además de la detección de referencias, posters opcionales de video y rutas dinámicas.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header_remove('X-Powered-By');

$repoRootReal = realpath(__DIR__ . '/..');
$repoRoot = str_replace('\\', '/', $repoRootReal !== false ? $repoRootReal : dirname(__DIR__));
$repoRoot = rtrim($repoRoot, '/');

// Calcular dinámicamente el prefijo URL base del repositorio a partir de la ubicación de app/api.php
$scriptName = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '/app/api.php';
$appUrlDir = str_replace('\\', '/', dirname($scriptName));
$repoUrlBase = str_replace('\\', '/', dirname($appUrlDir));
if ($repoUrlBase === '/' || $repoUrlBase === '.' || $repoUrlBase === '\\') {
    $repoUrlBase = '';
} else {
    $repoUrlBase = rtrim($repoUrlBase, '/');
}

$proyectosDir = $repoRoot . '/proyectos';

if (!is_dir($proyectosDir)) {
    http_response_code(404);
    echo json_encode(
        ['error' => 'Directorio "proyectos" no encontrado'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

$allowedImageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg'];
$allowedVideoExts = ['mp4', 'webm', 'mov', 'm4v', 'ogv', 'ogg', 'avi', 'mkv'];

// Profundidad máxima de anidamiento de subcarpetas (protección contra bucles/enlaces)
if (!defined('MAX_SUBFOLDER_DEPTH')) {
    define('MAX_SUBFOLDER_DEPTH', 8);
}

/**
 * Codifica cada segmento de una ruta relativa conservando las barras "/".
 */
function encodeRelativePath($relativePath) {
    $trimmed = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($trimmed === '') {
        return '';
    }
    $segments = explode('/', $trimmed);
    $encoded = array_map('rawurlencode', $segments);
    return implode('/', $encoded);
}

/**
 * Extrae una descripción breve desde el contenido de un archivo Markdown.
 */
function extractMarkdownDescription($markdownText) {
    if (!is_string($markdownText) || trim($markdownText) === '') {
        return '';
    }
    if (preg_match('/^#\s+(.+)$/m', $markdownText, $matches)) {
        return trim($matches[1]);
    }
    if (preg_match('/^##\s+(.+)$/m', $markdownText, $matches)) {
        return trim($matches[1]);
    }
    $lines = preg_split('/\r\n|\r|\n/', $markdownText);
    foreach ($lines as $line) {
        $clean = trim($line);
        if (
            $clean !== '' &&
            $clean !== '#' &&
            $clean !== '##' &&
            strpos($clean, '|') !== 0 &&
            strpos($clean, '---') !== 0 &&
            strpos($clean, '```') !== 0
        ) {
            if (function_exists('mb_strlen') && mb_strlen($clean, 'UTF-8') > 140) {
                return rtrim(mb_substr($clean, 0, 137, 'UTF-8')) . '...';
            }
            if (strlen($clean) > 140) {
                return rtrim(substr($clean, 0, 137)) . '...';
            }
            return $clean;
        }
    }
    return '';
}

/**
 * Obtiene la descripción de una carpeta a partir de su README.md (o del primer .md).
 */
function resolveFolderDescription($dirPath, $mdFiles) {
    $candidates = [];

    // README.md primero, luego el resto en el orden ya calculado
    foreach ($mdFiles as $md) {
        if (strtolower($md['filename']) === 'readme.md') {
            array_unshift($candidates, $md);
        } else {
            $candidates[] = $md;
        }
    }

    foreach ($candidates as $md) {
        if (is_readable($md['fullPath'])) {
            $text = file_get_contents($md['fullPath']);
            $description = extractMarkdownDescription($text);
            if ($description !== '') {
                return $description;
            }
        }
    }

    return '';
}

/**
 * Busca si existe una miniatura estática acompañante opcional para un archivo de video
 * (por ejemplo: nombre.poster.jpg, nombre.poster.webp o .thumbs/nombre.jpg) sin escribir en proyectos/.
 */
function findOptionalVideoPoster($dirPath, $relPrefix, $videoBaseName, $repoUrlBase) {
    $posterCandidates = [
        $videoBaseName . '.poster.jpg',
        $videoBaseName . '.poster.jpeg',
        $videoBaseName . '.poster.png',
        $videoBaseName . '.poster.webp',
        '.thumbs/' . $videoBaseName . '.jpg',
        '.thumbs/' . $videoBaseName . '.png',
        '.thumbs/' . $videoBaseName . '.webp'
    ];

    foreach ($posterCandidates as $relCandidate) {
        $fullCandidate = $dirPath . '/' . $relCandidate;
        if (is_file($fullCandidate)) {
            return $repoUrlBase . '/' . encodeRelativePath($relPrefix . '/' . $relCandidate);
        }
    }
    return null;
}

/**
 * Escanea una carpeta de forma recursiva.
 *
 * Devuelve los archivos multimedia y .md propios, sus subcarpetas (anidadas) y
 * los conteos: propios ("imageCount"/"videoCount") y totales
 * ("totalImageCount"/"totalVideoCount"/"totalFileCount"/"subfolderCount").
 */
function scanMediaDirectory($dirPath, $relPrefix, $repoUrlBase, $allowedImageExts, $allowedVideoExts, $depth = 0) {
    $result = [
        'images' => [],
        'mdFiles' => [],
        'imageCount' => 0,
        'videoCount' => 0,
        'totalImageCount' => 0,
        'totalVideoCount' => 0,
        'totalFileCount' => 0,
        'subfolderCount' => 0,
        'subfolders' => []
    ];

    $handle = opendir($dirPath);
    if ($handle === false) {
        return $result;
    }

    $subdirNames = [];

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $entryPath = $dirPath . '/' . $entry;

        // Subcarpetas: se recorren después (se omiten carpetas ocultas como .thumbs o .git)
        if (is_dir($entryPath)) {
            if (substr($entry, 0, 1) !== '.') {
                $subdirNames[] = $entry;
            }
            continue;
        }

        if (!is_file($entryPath)) {
            continue;
        }

        $lowerEntry = strtolower($entry);

        // Omitir archivos ".poster.*" de la lista principal ya que actúan como carátula de un video
        if (preg_match('/\.poster\.(jpg|jpeg|png|webp)$/i', $entry)) {
            continue;
        }

        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        $baseName = pathinfo($entry, PATHINFO_FILENAME);

        $isImage = in_array($ext, $allowedImageExts, true);
        $isVideo = in_array($ext, $allowedVideoExts, true);

        if ($isImage || $isVideo) {
            // Detecta "referencia" y el typo histórico "referecia"
            $isReference = (
                strpos($lowerEntry, 'referencia') !== false ||
                strpos($lowerEntry, 'referecia') !== false
            );

            $relPath = $relPrefix . '/' . $entry;
            $webPath = $repoUrlBase . '/' . encodeRelativePath($relPath);

            $posterPath = null;
            if ($isVideo) {
                $result['videoCount']++;
                $posterPath = findOptionalVideoPoster($dirPath, $relPrefix, $baseName, $repoUrlBase);
            } else {
                $result['imageCount']++;
            }

            $result['images'][] = [
                'filename' => $entry,
                'extension' => $ext,
                'type' => $isVideo ? 'video' : 'image',
                'isVideo' => $isVideo,
                'isReference' => $isReference,
                'thumbnail' => $webPath,
                'url' => $webPath,
                'poster' => $posterPath,
                'name' => $baseName,
                'relativePath' => $relPath,
                'folderPath' => $relPrefix
            ];
        } elseif ($ext === 'md') {
            $relPath = $relPrefix . '/' . $entry;
            $webPath = $repoUrlBase . '/' . encodeRelativePath($relPath);

            $result['mdFiles'][] = [
                'filename' => $entry,
                'relativePath' => $relPath,
                'webPath' => $webPath,
                'fullPath' => str_replace('\\', '/', $entryPath)
            ];
        }
    }
    closedir($handle);

    // Ordenar elementos multimedia (imágenes y videos) en orden alfabético natural
    usort($result['images'], function ($a, $b) {
        return strnatcasecmp($a['filename'], $b['filename']);
    });

    // Ordenar archivos .md priorizando README.md primero
    usort($result['mdFiles'], function ($a, $b) {
        $aIsReadme = strtolower($a['filename']) === 'readme.md';
        $bIsReadme = strtolower($b['filename']) === 'readme.md';
        if ($aIsReadme && !$bIsReadme) {
            return -1;
        }
        if (!$aIsReadme && $bIsReadme) {
            return 1;
        }
        return strnatcasecmp($a['filename'], $b['filename']);
    });

    $result['totalImageCount'] = $result['imageCount'];
    $result['totalVideoCount'] = $result['videoCount'];
    $result['totalFileCount'] = count($result['images']) + count($result['mdFiles']);

    // Recorrer subcarpetas de forma recursiva
    usort($subdirNames, 'strnatcasecmp');

    if ($depth < MAX_SUBFOLDER_DEPTH) {
        foreach ($subdirNames as $subName) {
            $subRelPrefix = $relPrefix . '/' . $subName;
            $subScan = scanMediaDirectory(
                $dirPath . '/' . $subName,
                $subRelPrefix,
                $repoUrlBase,
                $allowedImageExts,
                $allowedVideoExts,
                $depth + 1
            );

            $subScan['name'] = $subName;
            $subScan['path'] = $subRelPrefix;
            $subScan['depth'] = $depth + 1;
            $subScan['description'] = resolveFolderDescription($dirPath . '/' . $subName, $subScan['mdFiles']);

            $result['subfolders'][] = $subScan;
            $result['totalImageCount'] += $subScan['totalImageCount'];
            $result['totalVideoCount'] += $subScan['totalVideoCount'];
            $result['totalFileCount'] += $subScan['totalFileCount'];
            $result['subfolderCount'] += 1 + $subScan['subfolderCount'];
        }
    }

    return $result;
}

// Obtener subcarpetas de proyectos en orden natural inverso (ej. 10, 09, 08 ... 01)
$folders = [];
$dirHandle = opendir($proyectosDir);
if ($dirHandle !== false) {
    while (($entry = readdir($dirHandle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (substr($entry, 0, 1) === '.') {
            continue;
        }
        if (is_dir($proyectosDir . '/' . $entry)) {
            $folders[] = $entry;
        }
    }
    closedir($dirHandle);
}

usort($folders, 'strnatcasecmp');
$folders = array_reverse($folders);

$foldersData = [];
$totalSubfolders = 0;

foreach ($folders as $folder) {
    $folderPath = $proyectosDir . '/' . $folder;
    $relPrefix = 'proyectos/' . $folder;

    $scan = scanMediaDirectory(
        $folderPath,
        $relPrefix,
        $repoUrlBase,
        $allowedImageExts,
        $allowedVideoExts,
        0
    );

    // Se muestran las carpetas con contenido propio o con subcarpetas (aunque estén vacías)
    if (count($scan['images']) === 0 && count($scan['mdFiles']) === 0 && $scan['subfolderCount'] === 0) {
        continue;
    }

    $description = resolveFolderDescription($folderPath, $scan['mdFiles']);
    if ($description === '' && count($scan['subfolders']) > 0) {
        foreach ($scan['subfolders'] as $sub) {
            if (!empty($sub['description'])) {
                $description = $sub['description'];
                break;
            }
        }
    }

    $totalSubfolders += $scan['subfolderCount'];

    $foldersData[] = [
        'name' => $folder,
        'path' => $relPrefix,
        'description' => $description,
        'images' => $scan['images'],
        'imageCount' => $scan['imageCount'],
        'videoCount' => $scan['videoCount'],
        'totalImageCount' => $scan['totalImageCount'],
        'totalVideoCount' => $scan['totalVideoCount'],
        'totalFileCount' => $scan['totalFileCount'],
        'subfolderCount' => $scan['subfolderCount'],
        'subfolders' => $scan['subfolders'],
        'mdFiles' => $scan['mdFiles']
    ];
}

// Archivos .md en la raíz del repositorio (ej. generar_imagen.md, agente.md)
$rootMdFiles = [];
$rootHandle = opendir($repoRoot);
if ($rootHandle !== false) {
    while (($entry = readdir($rootHandle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $entryPath = $repoRoot . '/' . $entry;
        if (is_file($entryPath) && strtolower(pathinfo($entry, PATHINFO_EXTENSION)) === 'md') {
            $rootMdFiles[] = [
                'filename' => $entry,
                'relativePath' => $entry,
                'webPath' => $repoUrlBase . '/' . encodeRelativePath($entry),
                'fullPath' => str_replace('\\', '/', $entryPath)
            ];
        }
    }
    closedir($rootHandle);
}
usort($rootMdFiles, function ($a, $b) {
    return strnatcasecmp($a['filename'], $b['filename']);
});

$output = [
    'folders' => $foldersData,
    'rootMdFiles' => $rootMdFiles,
    'totalFolders' => count($foldersData),
    'totalSubfolders' => $totalSubfolders,
    'referenceFilter' => false
];

echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
