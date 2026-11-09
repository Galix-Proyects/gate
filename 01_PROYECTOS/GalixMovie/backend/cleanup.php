<?php
/**
 * GalixMovie - Cleanup de Catálogo v2.0
 * ────────────────────────────────────────────────
 * Busca entries en DB cuyo archivo local ya no existe en disco
 * y las marca is_online = 0.
 *
 * DHARMA #74 — Blindaje contra discos desmontados:
 *   v1 marcaba OFFLINE todo el disco si el HDD estaba desmontado
 *   (scanMedia devolvía [] y file_exists fallaba en masa). Ahora:
 *   - Guarda por disco: si el mount no está activo, se OMITE (no se toca).
 *   - Guarda de ratio: si >10% de los títulos "faltan" en una corrida, aborta.
 *   - Guarda de scan vacío: si el escaneo no ve archivos, aborta.
 *   - NO borra historial (Continuar Viendo se conserva).
 *   - Ejecución requiere sesión admin + confirm=1. Preview es público.
 *
 * Uso:
 *   GET ?preview=1              -> análisis (no escribe)
 *   GET ?confirm=1              -> ejecuta (requiere auth)
 *   GET ?confirm=1&force=1      -> fuerza aunque salte el guarda de ratio (auth)
 */
error_reporting(0);
header('Content-Type: application/json');

$isPreview = isset($_GET['preview']) && $_GET['preview'] == '1';
$isConfirm = isset($_GET['confirm']) && $_GET['confirm'] == '1';
$isForce   = isset($_GET['force']) && $_GET['force'] == '1';

// Preview: público (solo lectura). Ejecución: solo admin autenticado.
if (!$isPreview) {
    require 'auth.php';
    checkAuth();
    if (!$isConfirm) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Falta confirm=1 para ejecutar la limpieza',
        ]);
        exit;
    }
}

require 'db.php';

$localBase = __DIR__ . '/..';
define('MEDIA_DIR', '/data/data/com.termux/files/home/BUNKER');
define('RATIO_GUARD', 0.10); // abandona si >10% del catálogo local "desaparece"
define('MIN_ABSOLUTE_GUARD', 5);

/**
 * Escaneo de archivos (misma lógica que scrapper.php)
 */
function scanMedia(string $dir): array {
    $exts = ['mp4', 'mkv', 'avi', 'mov', 'webm'];
    $results = [];
    if (!is_dir($dir)) return $results;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $f) {
        if (!$f->isFile()) continue;
        $basename = $f->getBasename();
        if (str_starts_with($basename, '._')) continue;
        if (!in_array(strtolower($f->getExtension()), $exts)) continue;
        $p = realpath($f->getPathname()) ?: $f->getPathname();
        $results[] = ['path' => $p, 'name' => $f->getBasename('.' . $f->getExtension())];
    }
    return $results;
}

/**
 * Devuelve true si el disco BUNKER que contiene $path está realmente montado.
 * Evita confundir "disco ausente" con "archivos borrados".
 */
function diskMountedFor(string $path): bool {
    if (!preg_match('#^(.*/BUNKER/[^/]+)#', $path, $m)) return true; // fuera de BUNKER: no aplica
    $mountPoint = $m[1];
    $canonical = realpath($mountPoint);
    $mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($mounts as $line) {
        $parts = preg_split('/\s+/', $line);
        if (!isset($parts[1])) continue;
        if ($parts[1] === $mountPoint || ($canonical && $parts[1] === $canonical)) return true;
    }
    return false;
}

function abortar(string $motivo, array $extra = []): void {
    echo json_encode(array_merge([
        'status'       => 'aborted',
        'message'      => $motivo,
        'cleaned'      => 0,
        'cleaned_list' => [],
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (!is_dir(MEDIA_DIR)) {
        abortar('BUNKER no accesible desde PHP; limpieza cancelada por seguridad');
    }

    $diskFiles = scanMedia(MEDIA_DIR);
    if (count($diskFiles) === 0) {
        abortar('El escaneo no encontró archivos de video (¿discos desmontados?); limpieza cancelada');
    }

    $diskNames = [];
    foreach ($diskFiles as $df) {
        $diskNames[$df['name']] = $df['path'];
    }

    // Obtener todas las entries de DB con archivo local
    $stmt = $pdo->query("SELECT c.id, c.tmdb_id, c.titulo, c.tipo, pm.archivo_path
                         FROM contenido c
                         JOIN peliculas_metadata pm ON pm.contenido_id = c.id
                         WHERE c.is_online = 1
                           AND pm.archivo_path IS NOT NULL AND pm.archivo_path != ''
                           AND pm.archivo_path NOT LIKE 'http%'
                           AND pm.archivo_path NOT LIKE 'extract:%'
                           AND pm.archivo_path NOT LIKE 'sniper:%'
                           AND pm.archivo_path NOT LIKE 'backend/%'
                           AND pm.archivo_path NOT LIKE 'gdrive:%'");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cleaned     = 0;
    $renamed     = 0;
    $unmounted   = 0;
    $errors      = [];
    $cleanedList = [];
    $skippedList = [];

    foreach ($rows as $row) {
        $archivoPath = trim($row['archivo_path'] ?? '');
        if (empty($archivoPath)) continue;

        // Normalizar path
        $fullPath = $archivoPath;
        if (!str_starts_with($archivoPath, '/')) {
            $fullPath = $localBase . '/' . $archivoPath;
        }
        $fullPath = realpath($fullPath) ?: $fullPath;

        // 0. Guarda: si el disco no está montado, NO es un huérfano.
        if (!diskMountedFor($fullPath)) {
            $unmounted++;
            $skippedList[] = ['id' => $row['id'], 'titulo' => $row['titulo'], 'motivo' => 'DISCO_NO_MONTADO'];
            continue;
        }

        // 1. Verificar si el archivo existe en su path original
        if (is_file($fullPath)) continue;

        // 2. Verificar si existe con el MISMO NOMBRE en scanMedia (renombrado/movido)
        $dbName = pathinfo($archivoPath, PATHINFO_FILENAME);
        if (isset($diskNames[$dbName])) {
            $renamed++;
            continue;
        }

        // 3. No existe en disco de ninguna forma
        $cleanedList[] = ['id' => $row['id'], 'titulo' => $row['titulo']];
        $cleaned++;
    }

    // Guarda de ratio: una caída masiva casi siempre es un disco ausente, no 100 borrados reales.
    $limite = max(MIN_ABSOLUTE_GUARD, (int)ceil(count($rows) * RATIO_GUARD));
    if (!$isPreview && !$isForce && $cleaned > $limite) {
        abortar(
            "Guarda de seguridad: {$cleaned} de " . count($rows) . " archivos locales aparecen faltantes (límite {$limite}). " .
            "Probable disco desmontado o ruta incorrecta. Usa force=1 si estás seguro.",
            ['would_clean' => $cleaned, 'cleaned_list' => $cleanedList]
        );
    }

    // Solo escribir en DB si NO es preview
    if (!$isPreview) {
        foreach ($cleanedList as $item) {
            try {
                // Marcar offline en catálogo público (el historial se conserva)
                $upd = $pdo->prepare("UPDATE contenido SET is_online = 0 WHERE id = ?");
                $upd->execute([$item['id']]);
            } catch (Exception $e) {
                $errors[] = ['id' => $item['id'], 'titulo' => $item['titulo'], 'error' => $e->getMessage()];
            }
        }
    }

    echo json_encode([
        'status'           => 'success',
        'preview'          => $isPreview,
        'cleaned'          => $cleaned,
        'cleaned_list'     => $cleanedList,
        'renamed'          => $renamed,
        'skipped_unmounted'=> $unmounted,
        'skipped_list'     => $skippedList,
        'total_checked'    => count($rows),
        'errors'           => $errors,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
