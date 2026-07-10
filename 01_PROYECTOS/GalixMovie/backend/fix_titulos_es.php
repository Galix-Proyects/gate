<?php
/**
 * fix_titulos_es.php — Corrige títulos en inglés → español latino
 *
 * Uso:   php fix_titulos_es.php                (CLI — modo seco: solo muestra)
 *        php fix_titulos_es.php --apply         (CLI — aplica cambios)
 *        http://.../fix_titulos_es.php?apply=1  (web)
 *
 * ¿Qué hace?
 *   1. Recorre contenido con tmdb_id > 0
 *   2. Re-consulta TMDB con language=es-MX
 *   3. Si el título actual difiere del español, actualiza BD y renombra archivo
 */
ini_set('display_errors', '0');
error_reporting(0);
set_time_limit(300);

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    require 'auth.php';
    checkAuth();
}

require 'db.php';

define('TMDB_API_KEY', $_ENV['TMDB_API_KEY'] ?? $_ENV['TMDB_API_KEY'] ?? 'aa99c189865340e6421390ff192384b6');
define('TMDB_BASE',    'https://api.themoviedb.org/3');
define('TMDB_IMG',     'https://image.tmdb.org/t/p/w500');

$isApply = (php_sapi_name() === 'cli' && in_array('--apply', $argv ?? [])) || !empty($_GET['apply']);

function logMsg(string $msg): void {
    if (php_sapi_name() === 'cli') {
        echo $msg . "\n";
    }
}

function fetchTMDBJson(string $url): ?array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false || empty($res)) {
        $cmd = "su 0 sh -c '/data/data/com.termux/files/usr/bin/curl -s -L -A '\\''Mozilla/5.0'\\'' '\\''" . $url . "'\\'' ' 2>&1";
        $res = shell_exec($cmd);
        if (!$res || (strpos($res, '{') !== 0 && strpos($res, '[') !== 0)) return null;
    }
    $data = json_decode($res, true);
    return $data ?? null;
}

function getSpanishTitle(int $tmdbId, string $tipo): ?string {
    $apiTipo = ($tipo === 'series' || $tipo === 'tv') ? 'tv' : 'movie';
    $url = TMDB_BASE . "/$apiTipo/$tmdbId?api_key=" . TMDB_API_KEY . "&language=es-MX";
    $data = fetchTMDBJson($url);
    if (!$data || isset($data['status_code'])) return null;
    return $data['title'] ?? $data['name'] ?? null;
}

// ── 1. Obtener contenido con tmdb_id ──
$stmt = $pdo->query("SELECT id, titulo, tipo, tmdb_id FROM contenido WHERE tmdb_id > 0 ORDER BY id");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = count($rows);
logMsg("📦 Total entradas con TMDB ID: $total");

$fixed = 0;
$skipped = 0;
$errors = [];

foreach ($rows as $row) {
    $id = $row['id'];
    $currentTitle = $row['titulo'];
    $tipo = $row['tipo'];
    $tmdbId = (int)$row['tmdb_id'];

    logMsg("  [$id] Actual: \"$currentTitle\" (TMDB #$tmdbId, $tipo)");

    $esTitle = getSpanishTitle($tmdbId, $tipo);
    if ($esTitle === null) {
        logMsg("    ❌ No se pudo consultar TMDB");
        $errors[] = "ID $id: TMDB falló para tmdb_id=$tmdbId";
        continue;
    }

    if ($esTitle === $currentTitle) {
        logMsg("    ✅ Ya está en español: \"$esTitle\"");
        $skipped++;
        continue;
    }

    logMsg("    🔄 Español: \"$esTitle\"");

    if (!$isApply) {
        logMsg("    ⏸️  (modo seco — usa --apply para aplicar)");
        $skipped++;
        continue;
    }

    try {
        // ── Actualizar título en BD ──
        $pdo->prepare("UPDATE contenido SET titulo = ? WHERE id = ?")->execute([$esTitle, $id]);

        // ── Intentar renombrar archivo físico ──
        $metaStmt = $pdo->prepare("SELECT archivo_path FROM peliculas_metadata WHERE contenido_id = ? AND archivo_path IS NOT NULL LIMIT 1");
        $metaStmt->execute([$id]);
        $metaRow = $metaStmt->fetch();

        if ($metaRow && $metaRow['archivo_path']) {
            $oldPath = $metaRow['archivo_path'];

            // Solo renombrar archivos locales (no URLs, no gdrive, no extract:)
            if (strpos($oldPath, 'http') !== 0 && strpos($oldPath, 'gdrive:') !== 0 && strpos($oldPath, 'extract:') !== 0 && strpos($oldPath, 'sniper:') !== 0 && strpos($oldPath, 'backend/') !== 0 && file_exists($oldPath)) {
                $dir = dirname($oldPath);
                $ext = strtolower(pathinfo($oldPath, PATHINFO_EXTENSION));
                $safeTitle = preg_replace('/[<>:"\/\\|?*]/', '', $esTitle);

                // Extraer año del título actual si existe
                $oldBasename = pathinfo($oldPath, PATHINFO_FILENAME);
                $year = '';
                if (preg_match('/\((\d{4})\)$/', $oldBasename, $m)) {
                    $year = " ({$m[1]})";
                }

                $newFilename = $safeTitle . $year . '.' . $ext;
                $newPath = $dir . DIRECTORY_SEPARATOR . $newFilename;

                if ($oldPath !== $newPath && !file_exists($newPath)) {
                    if (@rename($oldPath, $newPath)) {
                        logMsg("    📁 Renombrado: " . basename($oldPath) . " → " . basename($newPath));
                        // Actualizar archivo_path en BD
                        $pdo->prepare("UPDATE peliculas_metadata SET archivo_path = ? WHERE contenido_id = ? AND archivo_path = ?")
                            ->execute([$newPath, $id, $oldPath]);
                        // Renombrar subtítulos asociados
                        $oldBase = pathinfo($oldPath, PATHINFO_FILENAME);
                        $newBase = pathinfo($newPath, PATHINFO_FILENAME);
                        foreach (['srt', 'vtt', 'ass', 'ssa', 'sub'] as $subExt) {
                            $oldSub = $dir . DIRECTORY_SEPARATOR . $oldBase . '.' . $subExt;
                            $newSub = $dir . DIRECTORY_SEPARATOR . $newBase . '.' . $subExt;
                            if (file_exists($oldSub) && !file_exists($newSub)) {
                                @rename($oldSub, $newSub);
                            }
                        }
                    } else {
                        logMsg("    ⚠️  No se pudo renombrar archivo (permisos?)");
                    }
                }
            }
        }

        // ── Series: actualizar en series_metadata también (archivo_path) ──
        $serStmt = $pdo->prepare("SELECT id, archivo_path FROM series_metadata WHERE contenido_id = ? AND archivo_path IS NOT NULL");
        $serStmt->execute([$id]);
        foreach ($serStmt->fetchAll() as $serRow) {
            $oldPath = $serRow['archivo_path'];
            if (strpos($oldPath, 'http') !== 0 && strpos($oldPath, 'gdrive:') !== 0 && strpos($oldPath, 'extract:') !== 0 && strpos($oldPath, 'sniper:') !== 0 && file_exists($oldPath)) {
                $dir = dirname($oldPath);
                $ext = strtolower(pathinfo($oldPath, PATHINFO_EXTENSION));
                $safeTitle = preg_replace('/[<>:"\/\\|?*]/', '', $esTitle);
                $oldBasename = pathinfo($oldPath, PATHINFO_FILENAME);
                $year = '';
                if (preg_match('/\((\d{4})\)$/', $oldBasename, $m)) {
                    $year = " ({$m[1]})";
                }
                $newFilename = $safeTitle . $year . '.' . $ext;
                $newPath = $dir . DIRECTORY_SEPARATOR . $newFilename;
                if ($oldPath !== $newPath && !file_exists($newPath)) {
                    if (@rename($oldPath, $newPath)) {
                        $pdo->prepare("UPDATE series_metadata SET archivo_path = ? WHERE id = ?")->execute([$newPath, $serRow['id']]);
                    }
                }
            }
        }

        logMsg("    ✅ Actualizado: \"$currentTitle\" → \"$esTitle\"");
        $fixed++;
    } catch (Throwable $e) {
        logMsg("    ❌ Error: " . $e->getMessage());
        $errors[] = "ID $id: " . $e->getMessage();
    }

    // Pequeña pausa para no saturar TMDB API
    usleep(250000);
}

// ── Resumen ──
logMsg("");
logMsg("═══════════════════════════════════════");
logMsg("📊 RESUMEN");
logMsg("   Total:     $total");
logMsg("   Actualizados: $fixed");
logMsg("   Sin cambio:   $skipped");
logMsg("   Errores:  " . count($errors));
if (!empty($errors)) {
    logMsg("   Detalle errores:");
    foreach ($errors as $e) logMsg("     • $e");
}
logMsg("═══════════════════════════════════════");

if (php_sapi_name() !== 'cli') {
    echo json_encode([
        'status'  => 'success',
        'total'   => $total,
        'fixed'   => $fixed,
        'skipped' => $skipped,
        'errors'  => $errors,
    ], JSON_UNESCAPED_UNICODE);
}
