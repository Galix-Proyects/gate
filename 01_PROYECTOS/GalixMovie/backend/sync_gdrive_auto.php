<?php
/**
 * GalixMovie - GDrive Auto Sync
 * Escanea TODAS las carpetas de Google Drive en busca de episodios
 * SxxEyy/playlist.m3u8, auto-crea las series en BD si no existen,
 * y sincroniza todos los episodios encontrados.
 */
ini_set('display_errors', '0');
error_reporting(0);
set_time_limit(300);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require 'db.php';
require 'auth.php';
checkAuth();

define('TMDB_API_KEY', $_ENV['TMDB_API_KEY'] ?? 'aa99c189865340e6421390ff192384b6');
define('TMDB_BASE',    'https://api.themoviedb.org/3');
define('TMDB_IMG',     'https://image.tmdb.org/t/p/w500');

/**
 * Buscar serie en TMDB por nombre.
 */
function tmdbSearchSerie(string $query): ?array {
    $url = TMDB_BASE . '/search/tv?api_key=' . TMDB_API_KEY . '&query=' . urlencode($query) . '&language=es-MX';
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $res = @file_get_contents($url, false, $ctx);
    if (!$res) return null;
    $data = json_decode($res, true);
    if (empty($data['results'])) return null;
    foreach ($data['results'] as $r) {
        if (strcasecmp(trim($r['name'] ?? ''), trim($query)) === 0) return $r;
        if (strcasecmp(trim($r['original_name'] ?? ''), trim($query)) === 0) return $r;
    }
    return $data['results'][0];
}

/**
 * Obtener detalles de TMDB por ID (para backdrop original).
 */
function tmdbSerieDetails(int $id): ?array {
    $url = TMDB_BASE . '/tv/' . $id . '?api_key=' . TMDB_API_KEY . '&language=es-MX';
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $res = @file_get_contents($url, false, $ctx);
    if (!$res) return null;
    return json_decode($res, true);
}

/** Obtener título de episodio individual desde TMDB */
function tmdbGetSeasonEpisodes(int $seriesId, int $season): array {
    $url = TMDB_BASE . "/tv/$seriesId/season/$season?" . http_build_query([
        'api_key' => TMDB_API_KEY,
        'language' => 'es-MX'
    ]);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (is_resource($ch)) curl_close($ch);
    if ($code !== 200) return [];
    $data = json_decode($raw, true);
    $episodes = $data['episodes'] ?? [];
    $map = [];
    foreach ($episodes as $ep) {
        $num = (int)$ep['episode_number'];
        $map[$num] = [
            'name'    => $ep['name'] ?? '',
            'still'   => !empty($ep['still_path']) ? TMDB_IMG . $ep['still_path'] : null,
            'overview' => $ep['overview'] ?? '',
            'vote'    => $ep['vote_average'] ?? null,
        ];
    }
    return $map;
}

$rcloneConfig = '/data/data/com.termux/files/home/.config/rclone/rclone.conf';
$configFlag   = file_exists($rcloneConfig) ? ' --config ' . escapeshellarg($rcloneConfig) : '';

// ─── Localizar rclone ────────────────────────────────────────────
$rcloneCandidates = [
    '/data/data/com.termux/files/usr/bin/rclone',
    '/usr/local/bin/rclone',
    '/usr/bin/rclone',
    trim(shell_exec('which rclone 2>/dev/null') ?: ''),
];
$rcloneBin = '';
foreach ($rcloneCandidates as $candidate) {
    if ($candidate && file_exists($candidate) && is_executable($candidate)) {
        $rcloneBin = $candidate;
        break;
    }
}
if (empty($rcloneBin)) {
    $whichOut = shell_exec('which rclone 2>/dev/null');
    $rcloneBin = trim($whichOut ?? '');
}
if (empty($rcloneBin)) {
    echo json_encode(['status' => 'error', 'message' => 'rclone no encontrado.']);
    exit;
}

// ─── Escanear GDrive recursivo ───────────────────────────────────
$env = [
    'HOME' => '/data/data/com.termux/files/home',
    'PATH' => getenv('PATH') ?: '/data/data/com.termux/files/usr/bin:/usr/local/bin:/usr/bin:/bin'
];

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open(
    $rcloneBin . $configFlag . ' lsf gdrive: --recursive --files-only --include "**/playlist.m3u8"',
    $descriptors,
    $pipes,
    null,
    $env
);

if (!is_resource($process)) {
    echo json_encode(['status' => 'error', 'message' => 'No se pudo iniciar rclone.']);
    exit;
}

fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if ($exitCode !== 0 || trim($stdout ?? '') === '') {
    $errMsg = 'rclone falló (exit ' . $exitCode . ')';
    if (!empty(trim($stderr))) $errMsg = trim($stderr);
    echo json_encode(['status' => 'error', 'message' => $errMsg], JSON_UNESCAPED_SLASHES);
    exit;
}

// ─── Procesar líneas ────────────────────────────────────────────
$lines = explode("\n", trim($stdout));

// Formato esperado: Serie Name/S01E01/playlist.m3u8
// También puede ser: Folder/Subfolder/SxxEyy/playlist.m3u8
$seriesGroups = [];

foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line)) continue;

    // Buscar SxxEyy/playlist.m3u8 al final
    if (!preg_match('/^(.*?)[\/]?[Ss](\d{1,2})[Ee](\d{1,3})\/playlist\.m3u8$/i', $line, $m)) {
        continue;
    }

    $seriesPath = trim($m[1], '/');
    $season     = intval($m[2]);
    $episode    = intval($m[3]);
    $gdrivePath = 'gdrive:/' . $line;

    // Extraer nombre de serie del path más cercano a SxxEyy
    $parts = explode('/', $seriesPath);
    $seriesName = trim($parts[count($parts) - 1]);
    if (empty($seriesName)) {
        $seriesName = 'Serie desde GDrive';
    }

    $key = $seriesName;
    if (!isset($seriesGroups[$key])) {
        $seriesGroups[$key] = [
            'name'     => $seriesName,
            'episodes' => [],
        ];
    }
    $seriesGroups[$key]['episodes'][] = [
        'season'  => $season,
        'episode' => $episode,
        'path'    => $gdrivePath,
    ];
}

$results = [];
$seriesResults = [];
$movieResults = [];

// ─── PROCESAR PELÍCULAS (archivos de video en gdrive: raíz sin SxxEyy) ───
$videoCmd = $rcloneBin . $configFlag . ' lsf gdrive: --recursive --files-only --include "*.mp4" --include "*.mkv" --include "*.avi" --include "*.mov" --include "*.webm"';
$vProc = proc_open($videoCmd, $descriptors, $vPipes, null, $env);
$vStdout = '';
$vStderr = '';
if (is_resource($vProc)) {
    fclose($vPipes[0]);
    $vStdout = stream_get_contents($vPipes[1]);
    $vStderr = stream_get_contents($vPipes[2]);
    fclose($vPipes[1]); fclose($vPipes[2]);
    proc_close($vProc);
}

$processedPaths = [];
foreach (explode("\n", trim($vStdout ?? '')) as $vLine) {
    $vLine = trim($vLine);
    if (empty($vLine)) continue;
    // Saltar archivos dentro de rutas que contengan SxxEyy (ya se procesan como series)
    if (preg_match('/[Ss]\d{1,2}[Ee]\d{1,3}\//', $vLine)) continue;

    $filename = basename($vLine);
    $nameNoExt = preg_replace('/\.[^.]+$/', '', $filename);
    $gdrivePath = 'gdrive:/' . $vLine;

    // Evitar duplicados: misma ruta ya procesada
    if (isset($processedPaths[$gdrivePath])) continue;
    $processedPaths[$gdrivePath] = true;

    // Buscar por ruta exacta
    $checkPath = $pdo->prepare("SELECT pm.id, c.id as contenido_id FROM peliculas_metadata pm JOIN contenido c ON c.id = pm.contenido_id WHERE pm.archivo_path = ? AND c.tipo = 'movie' LIMIT 1");
    $checkPath->execute([$gdrivePath]);
    $existingPath = $checkPath->fetch();

    if ($existingPath) {
        $movieResults[] = ['file' => $filename, 'path' => $gdrivePath, 'status' => 'already_exists', 'contenido_id' => $existingPath['contenido_id']];
        continue;
    }

    // Buscar por nombre en contenido
    $stmtM = $pdo->prepare("SELECT id, tmdb_id FROM contenido WHERE LOWER(TRIM(titulo)) = LOWER(TRIM(?)) AND tipo = 'movie' LIMIT 1");
    $stmtM->execute([$nameNoExt]);
    $existingMovie = $stmtM->fetch();

    if ($existingMovie) {
        $contenidoId = $existingMovie['id'];
        // Ya existe en contenido, solo agregar metadata
        $insPm = $pdo->prepare("INSERT IGNORE INTO peliculas_metadata (contenido_id, archivo_path) VALUES (?, ?)");
        $insPm->execute([$contenidoId, $gdrivePath]);
        $movieResults[] = ['file' => $filename, 'path' => $gdrivePath, 'status' => 'metadata_added', 'contenido_id' => $contenidoId];
    } else {
        // Buscar TMDB
        $tmdbResult = tmdbSearchSerie($nameNoExt);
        $tmdbId = 0; $poster = null; $backdrop = null; $sinopsis = ''; $fecha = null; $puntuacion = 0;
        $tituloFinal = $nameNoExt;
        if ($tmdbResult) {
            $tmdbId = $tmdbResult['id'] ?? 0;
            $poster = !empty($tmdbResult['poster_path']) ? TMDB_IMG . $tmdbResult['poster_path'] : null;
            $sinopsis = $tmdbResult['overview'] ?? '';
            $fecha = $tmdbResult['release_date'] ?? $tmdbResult['first_air_date'] ?? null;
            if ($fecha === '') $fecha = null;
            $puntuacion = $tmdbResult['vote_average'] ?? 0;
            $tituloFinal = $tmdbResult['title'] ?? $tmdbResult['name'] ?? $tmdbResult['original_title'] ?? $nameNoExt;
            $details = tmdbSerieDetails($tmdbId);
            if ($details && !empty($details['backdrop_path'])) {
                $backdrop = 'https://image.tmdb.org/t/p/original' . $details['backdrop_path'];
            }
        } else {
            // Intentar buscar como movie (tmdbSearchSerie busca 'tv', probar 'movie')
            $movieUrl = TMDB_BASE . '/search/movie?api_key=' . TMDB_API_KEY . '&query=' . urlencode($nameNoExt) . '&language=es-MX';
            $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
            $mvRes = @file_get_contents($movieUrl, false, $ctx);
            if ($mvRes) {
                $mvData = json_decode($mvRes, true);
                if (!empty($mvData['results'][0])) {
                    $r = $mvData['results'][0];
                    $tmdbId = $r['id'] ?? 0;
                    $poster = !empty($r['poster_path']) ? TMDB_IMG . $r['poster_path'] : null;
                    $sinopsis = $r['overview'] ?? '';
                    $fecha = $r['release_date'] ?? null;
                    if ($fecha === '') $fecha = null;
                    $puntuacion = $r['vote_average'] ?? 0;
                    $tituloFinal = $r['title'] ?? $r['original_title'] ?? $nameNoExt;
                    if (!empty($r['backdrop_path'])) {
                        $backdrop = 'https://image.tmdb.org/t/p/original' . $r['backdrop_path'];
                    }
                }
            }
        }

        $ins = $pdo->prepare(
            "INSERT INTO contenido (titulo, tipo, sinopsis, poster_path, backdrop_path, fecha_estreno, tmdb_id, puntuacion, is_online, visible_roku, created_at)
             VALUES (?, 'movie', ?, ?, ?, ?, ?, ?, 1, 1, NOW())"
        );
        $ins->execute([$tituloFinal, $sinopsis, $poster, $backdrop, $fecha, $tmdbId, $puntuacion]);
        $contenidoId = $pdo->lastInsertId();

        $insPm = $pdo->prepare("INSERT INTO peliculas_metadata (contenido_id, archivo_path) VALUES (?, ?)");
        $insPm->execute([$contenidoId, $gdrivePath]);
        $movieResults[] = ['file' => $filename, 'path' => $gdrivePath, 'status' => 'created', 'contenido_id' => $contenidoId];
    }
}

// ─── PROCESAR SERIES (playlists HLS) ────────────────────────────
if (empty($seriesGroups)) {
    echo json_encode([
        'status'  => 'success',
        'message' => 'Sincronización completada.',
        'movies'  => $movieResults,
        'series'  => [],
    ]);
    exit;
}

foreach ($seriesGroups as $key => $group) {
    $serieName  = $group['name'];
    $episodes   = $group['episodes'];

    // ─── Buscar o crear serie en contenido ─────────────────────
    $stmt = $pdo->prepare("SELECT id FROM contenido WHERE LOWER(TRIM(titulo)) = LOWER(TRIM(?)) AND tipo = 'series' LIMIT 1");
    $stmt->execute([$serieName]);
    $existing = $stmt->fetch();

    if ($existing) {
        $contenidoId = $existing['id'];
        $wasCreated = false;

        // Si existe pero no tiene poster, buscar TMDB y actualizar
        $checkMeta = $pdo->prepare("SELECT poster_path, tmdb_id FROM contenido WHERE id = ?");
        $checkMeta->execute([$contenidoId]);
        $rowMeta = $checkMeta->fetch();
        $tmdbId = (int)($rowMeta['tmdb_id'] ?? 0);
        if (empty($rowMeta['poster_path'])) {
            $tmdbResult = tmdbSearchSerie($serieName);
            if ($tmdbResult) {
                $tmdbId = $tmdbResult['id'] ?? 0;
                $poster = !empty($tmdbResult['poster_path']) ? TMDB_IMG . $tmdbResult['poster_path'] : null;
                $sinopsis = $tmdbResult['overview'] ?? '';
                $fecha = $tmdbResult['first_air_date'] ?? null;
                if ($fecha === '') $fecha = null;
                $puntuacion = $tmdbResult['vote_average'] ?? 0;
                $tituloFinal = $tmdbResult['name'] ?? $tmdbResult['original_name'] ?? $serieName;
                $backdrop = null;
                $details = tmdbSerieDetails($tmdbId);
                if ($details && !empty($details['backdrop_path'])) {
                    $backdrop = 'https://image.tmdb.org/t/p/original' . $details['backdrop_path'];
                }
                $upd = $pdo->prepare(
                    "UPDATE contenido SET titulo=?, sinopsis=?, poster_path=?, backdrop_path=?, fecha_estreno=?, tmdb_id=?, puntuacion=? WHERE id=?"
                );
                $upd->execute([$tituloFinal, $sinopsis, $poster, $backdrop, $fecha, $tmdbId, $puntuacion, $contenidoId]);
            }
        }
    } else {
        // Buscar por aproximación (contiene)
        $stmt2 = $pdo->prepare("SELECT id FROM contenido WHERE LOWER(TRIM(titulo)) LIKE ? AND tipo = 'series' LIMIT 1");
        $stmt2->execute(['%' . strtolower(trim($serieName)) . '%']);
        $approx = $stmt2->fetch();

        if ($approx) {
            $contenidoId = $approx['id'];
            $wasCreated = false;
            $getTmdb = $pdo->prepare("SELECT tmdb_id FROM contenido WHERE id = ?");
            $getTmdb->execute([$contenidoId]);
            $tmdbId = (int)$getTmdb->fetchColumn();
        } else {
            // Buscar en TMDB para obtener metadatos completos
            $tmdbResult = tmdbSearchSerie($serieName);
            $tmdbId = 0;
            $poster = null;
            $backdrop = null;
            $sinopsis = '';
            $fecha = null;
            $puntuacion = 0;
            $tituloFinal = $serieName;

            if ($tmdbResult) {
                $tmdbId = $tmdbResult['id'] ?? 0;
                $poster = !empty($tmdbResult['poster_path']) ? TMDB_IMG . $tmdbResult['poster_path'] : null;
                $sinopsis = $tmdbResult['overview'] ?? '';
                $fecha = $tmdbResult['first_air_date'] ?? null;
                if ($fecha === '') $fecha = null;
                $puntuacion = $tmdbResult['vote_average'] ?? 0;
                $tituloFinal = $tmdbResult['name'] ?? $tmdbResult['original_name'] ?? $serieName;

                // Obtener backdrop del detalle
                $details = tmdbSerieDetails($tmdbId);
                if ($details && !empty($details['backdrop_path'])) {
                    $backdrop = 'https://image.tmdb.org/t/p/original' . $details['backdrop_path'];
                }
            }

            $ins = $pdo->prepare(
                "INSERT INTO contenido (titulo, tipo, sinopsis, poster_path, backdrop_path, fecha_estreno, tmdb_id, puntuacion, is_online, visible_roku, created_at)
                 VALUES (?, 'series', ?, ?, ?, ?, ?, ?, 1, 1, NOW())"
            );
            $ins->execute([$tituloFinal, $sinopsis, $poster, $backdrop, $fecha, $tmdbId, $puntuacion]);
            $contenidoId = $pdo->lastInsertId();
            $wasCreated = true;
        }
    }

    // ─── Cargar episodios existentes ───────────────────────────
    $stmtEx = $pdo->prepare("SELECT temporada, episodio, archivo_path FROM series_metadata WHERE contenido_id = ?");
    $stmtEx->execute([$contenidoId]);
    $existingMap = [];
    foreach ($stmtEx->fetchAll() as $e) {
        $existingMap[$e['temporada'] . '_' . $e['episodio']] = $e['archivo_path'];
    }

    $added   = [];
    $updated = [];
    $ignored = [];

    foreach ($episodes as $ep) {
        $key = $ep['season'] . '_' . $ep['episode'];

        if (isset($existingMap[$key])) {
            if (empty($existingMap[$key]) || $existingMap[$key] !== $ep['path']) {
                $upd = $pdo->prepare("UPDATE series_metadata SET archivo_path = ?, titulo_episodio = COALESCE(titulo_episodio, ?), episode_still = COALESCE(episode_still, ?), episode_overview = COALESCE(episode_overview, ?), episode_vote = COALESCE(episode_vote, ?) WHERE contenido_id = ? AND temporada = ? AND episodio = ?");
                $upd->execute([$ep['path'], null, null, null, null, $contenidoId, $ep['season'], $ep['episode']]);
                $updated[] = ['temporada' => $ep['season'], 'episodio' => $ep['episode']];
            } else {
                $ignored[] = ['temporada' => $ep['season'], 'episodio' => $ep['episode']];
            }
        } else {
            // Limpiar posible duplicado en peliculas_metadata
            $del = $pdo->prepare("DELETE FROM peliculas_metadata WHERE contenido_id = ? AND season = ? AND episode = ?");
            $del->execute([$contenidoId, $ep['season'], $ep['episode']]);

            $ins = $pdo->prepare(
                "INSERT INTO series_metadata (contenido_id, temporada, episodio, archivo_path, titulo_episodio, episode_still, episode_overview, episode_vote)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            static $seasonCache = [];
            $cacheKey = $tmdbId . '_' . $ep['season'];
            if ($tmdbId && !isset($seasonCache[$cacheKey])) {
                $seasonCache[$cacheKey] = tmdbGetSeasonEpisodes($tmdbId, $ep['season']);
            }
            $epData = ($tmdbId && isset($seasonCache[$cacheKey])) ? ($seasonCache[$cacheKey][$ep['episode']] ?? []) : [];
            $epTitle   = $epData['name']    ?? ('Episodio ' . $ep['episode']);
            $epStill   = $epData['still']   ?? null;
            $epOverview = $epData['overview'] ?? null;
            $epVote    = $epData['vote']    ?? null;
            $ins->execute([$contenidoId, $ep['season'], $ep['episode'], $ep['path'], $epTitle, $epStill, $epOverview, $epVote]);
            $added[] = ['temporada' => $ep['season'], 'episodio' => $ep['episode']];
        }
    }

    // ─── Crear/actualizar peliculas_metadata para que se vea en S1 ───
    $gdriveDir = $group['episodes'][0]['path'] ?? '';
    $gdriveBaseDir = '';
    if ($gdriveDir) {
        // Extraer carpeta base: gdrive:/El juego del calamar/ (sin /SxxExx/playlist.m3u8)
        $gdriveBaseDir = preg_replace('/\/[^\/]+\/[^\/]+$/', '', $gdriveDir);
    }
    if ($gdriveBaseDir) {
        $stmtPm = $pdo->prepare("SELECT id FROM peliculas_metadata WHERE contenido_id = ? AND (archivo_path IS NULL OR archivo_path = '') LIMIT 1");
        $stmtPm->execute([$contenidoId]);
        $existingPm = $stmtPm->fetch();

        if ($existingPm) {
            $pdo->prepare("UPDATE peliculas_metadata SET archivo_path = ? WHERE id = ?")
                ->execute([$gdriveBaseDir, $existingPm['id']]);
        } else {
            $pdo->prepare("INSERT INTO peliculas_metadata (contenido_id, archivo_path) VALUES (?, ?)")
                ->execute([$contenidoId, $gdriveBaseDir]);
        }
    }

    $results[] = [
        'serie'       => $serieName,
        'contenido_id'=> $contenidoId,
        'creada'      => $wasCreated,
        'episodios'   => count($episodes),
        'gdrive_dir'  => $gdriveBaseDir,
        'added'       => $added,
        'updated'     => $updated,
        'ignored'     => $ignored,
    ];
}

echo json_encode([
    'status'  => 'success',
    'message' => 'Sincronización completada.',
    'movies'  => $movieResults,
    'series'  => $results,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
