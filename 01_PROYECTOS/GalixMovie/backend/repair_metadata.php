<?php
/**
 * GalixMovie - Reparador de metadatos v1.0
 * ────────────────────────────────────────────────────────────────
 * Uso (CLI):
 *   php backend/repair_metadata.php            -> dry-run (no escribe nada)
 *   php backend/repair_metadata.php --apply    -> aplica los cambios
 *
 * Qué hace:
 *  1) Deduplica peliculas_metadata (películas, season IS NULL): conserva la
 *     fila más antigua, fusiona las rutas repetidas en los espejos
 *     server2..server5 y elimina las filas sobrantes.
 *     (DHARMA #75: el UNIQUE con season/episode NULL no protege películas.)
 *  2) Re-vincula rutas locales que ya no existen en disco cuando el nombre de
 *     archivo es ÚNICO en todo BUNKER (ej. episodios movidos de carpeta).
 *     (DHARMA #78)
 *
 * Nunca toca rutas gdrive:, http(s) ni filas con season/episode.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/db.php';

$apply = in_array('--apply', ($argv ?? []), true);
define('MEDIA_ROOT', '/data/data/com.termux/files/home/BUNKER');
const MIRROR_COLS = ['server2', 'server3', 'server4', 'server5'];
const VIDEO_EXTS = ['mp4', 'mkv', 'avi', 'mov', 'webm'];

echo "=== GalixMovie repair_metadata (" . ($apply ? 'APPLY' : 'DRY-RUN') . ") ===\n";

/** Índice de archivos en disco: basename => [rutas] */
function diskIndex(string $root): array {
    $index = [];
    if (!is_dir($root)) return $index;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $base = $f->getBasename();
        if (str_starts_with($base, '._')) continue;
        if (!in_array(strtolower($f->getExtension()), VIDEO_EXTS, true)) continue;
        $index[$base][] = $f->getPathname();
    }
    return $index;
}

function isLocalPath(string $p): bool {
    if ($p === '') return false;
    if (stripos($p, 'http://') === 0 || stripos($p, 'https://') === 0) return false;
    if (str_starts_with($p, 'gdrive:') || str_starts_with($p, 'extract:') || str_starts_with($p, 'sniper:')) return false;
    return true;
}

$disk = diskIndex(MEDIA_ROOT);
echo "Archivos de video en disco: " . array_sum(array_map('count', $disk)) . "\n";

// ── 1. DEDUPE DE PELÍCULAS ──────────────────────────────────────────
$dupGroups = $pdo->query("
    SELECT contenido_id, COUNT(*) n
      FROM peliculas_metadata
     WHERE season IS NULL
     GROUP BY contenido_id
    HAVING n > 1
")->fetchAll(PDO::FETCH_ASSOC);

$merged = 0;
$deleted = 0;

foreach ($dupGroups as $g) {
    $cid = (int)$g['contenido_id'];
    $stmt = $pdo->prepare("SELECT * FROM peliculas_metadata WHERE contenido_id = ? AND season IS NULL ORDER BY id");
    $stmt->execute([$cid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) < 2) continue;

    $main = array_shift($rows);

    // Reunir rutas (archivo_path + espejos) en orden de aparición, sin duplicados
    $paths = [];
    foreach (array_merge([$main], $rows) as $r) {
        $candidates = array_merge([$r['archivo_path'] ?? ''], array_map(fn($c) => $r[$c] ?? '', MIRROR_COLS));
        foreach ($candidates as $p) {
            $p = trim((string)$p);
            if ($p === '' || in_array($p, $paths, true)) continue;
            $paths[] = $p;
        }
    }

    $newMainPath = $paths[0] ?? '';
    $mirrors = array_slice($paths, 1, count(MIRROR_COLS));

    echo "- contenido {$cid}: " . (count($rows) + 1) . " filas -> 1";
    echo " | main=" . ($newMainPath !== '' ? basename($newMainPath) : '(vacío)');
    echo " | espejos=" . count($mirrors) . "\n";

    if ($apply) {
        $sql = "UPDATE peliculas_metadata SET archivo_path = ?, server2 = ?, server3 = ?, server4 = ?, server5 = ? WHERE id = ?";
        $vals = [$newMainPath];
        for ($i = 0; $i < count(MIRROR_COLS); $i++) $vals[] = $mirrors[$i] ?? null;
        $vals[] = $main['id'];
        $pdo->prepare($sql)->execute($vals);

        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM peliculas_metadata WHERE id IN ($in)")->execute($ids);
        $deleted += count($ids);
    }
    $merged++;
}

echo "Grupos deduplicados: {$merged}" . ($apply ? " | filas borradas: {$deleted}" : " (dry-run)") . "\n";

// ── 2. RE-VINCULAR RUTAS MOVIDAS ────────────────────────────────────
$used = [];
foreach (['peliculas_metadata', 'series_metadata'] as $t) {
    foreach ($pdo->query("SELECT archivo_path FROM `$t` WHERE archivo_path IS NOT NULL AND archivo_path != ''") as $r) {
        $used[$r['archivo_path']] = true;
    }
}

$relinked = 0;
$ambiguous = 0;
$unresolved = 0;

foreach (['peliculas_metadata', 'series_metadata'] as $table) {
    $rows = $pdo->query("SELECT id, archivo_path FROM `$table` WHERE archivo_path IS NOT NULL AND archivo_path != ''")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $p = $row['archivo_path'];
        if (!isLocalPath($p)) continue;
        if (file_exists($p)) continue;

        $base = basename($p);
        $cands = $disk[$base] ?? [];

        // Descartar candidatos ya usados por otra fila
        $free = array_values(array_filter($cands, fn($c) => !isset($used[$c])));

        if (count($free) === 1) {
            echo "- {$table}#{$row['id']}: " . basename($p) . " -> " . $free[0] . "\n";
            if ($apply) {
                $pdo->prepare("UPDATE `$table` SET archivo_path = ? WHERE id = ?")->execute([$free[0], $row['id']]);
            }
            $used[$free[0]] = true;
            unset($used[$p]);
            $relinked++;
        } elseif (count($free) > 1) {
            echo "- {$table}#{$row['id']}: " . basename($p) . " AMBIGUO (" . count($free) . " candidatos)\n";
            $ambiguous++;
        } else {
            $unresolved++;
        }
    }
}

echo "Rutas re-vinculadas: {$relinked}" . ($apply ? "" : " (dry-run)") . " | ambiguas: {$ambiguous} | sin candidato: {$unresolved}\n";
echo "=== fin ===\n";
