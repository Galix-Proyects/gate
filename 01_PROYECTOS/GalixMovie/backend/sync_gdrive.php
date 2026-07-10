<?php
/**
 * GalixMovie - GDrive HLS Sync Tool v2
 * ─────────────────────────────────────────────────────────────────
 * Escanea una carpeta de Google Drive via rclone, detecta episodios
 * en formato SxxEyy/playlist.m3u8 y los indexa automáticamente
 * si no existen en la base de datos para la serie especificada.
 *
 * DIAGNÓSTICO: Si rclone falla, se devuelve el stderr completo para
 * facilitar el debug desde el panel admin.
 * ─────────────────────────────────────────────────────────────────
 */
ini_set('display_errors', '0');
error_reporting(0);
set_time_limit(300);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require 'db.php';
require 'auth.php';

// Validar autenticación
checkAuth();

define('TMDB_API_KEY', $_ENV['TMDB_API_KEY'] ?? 'aa99c189865340e6421390ff192384b6');
define('TMDB_BASE',    'https://api.themoviedb.org/3');
define('TMDB_IMG',     'https://image.tmdb.org/t/p/w500');

/** Obtener todos los episodios de una temporada desde TMDB (1 llamada por temporada) */
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

$contenido_id = isset($_GET['contenido_id']) ? intval($_GET['contenido_id']) : 0;
$folder       = isset($_GET['folder'])       ? trim($_GET['folder'])           : 'HLS_TEST';

if ($contenido_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'ID de contenido inválido.']);
    exit;
}

// Limpiar nombre de carpeta (solo letras, números, guion, slash)
$folderClean = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $folder);
if (empty($folderClean)) {
    $folderClean = 'HLS_TEST';
}

// ─── Localizar rclone ────────────────────────────────────────────
// En Termux el binario suele estar en uno de estos paths:
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
    // Intento final: ejecutar directamente por si está en PATH de PHP
    $whichOut = shell_exec('which rclone 2>/dev/null');
    $rcloneBin = trim($whichOut ?? '');
}

if (empty($rcloneBin)) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'rclone no encontrado. Instálalo con: pkg install rclone',
        'debug'   => [
            'checked_paths' => $rcloneCandidates,
            'php_path'      => getenv('PATH'),
        ]
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // Verificar que el contenido exista y sea una serie
    $stmt = $pdo->prepare("SELECT id, titulo, tipo, tmdb_id FROM contenido WHERE id = ?");
    $stmt->execute([$contenido_id]);
    $contenido = $stmt->fetch();

    if (!$contenido) {
        echo json_encode(['status' => 'error', 'message' => 'Contenido no encontrado en la base de datos.']);
        exit;
    }

    if ($contenido['tipo'] !== 'series' && $contenido['tipo'] !== 'tv') {
        echo json_encode(['status' => 'error', 'message' => 'El contenido seleccionado no es una serie.']);
        exit;
    }

    // ─── Ejecutar rclone capturando stdout y stderr ───────────────
    $tmpErr = tempnam(sys_get_temp_dir(), 'rclone_err_');
    $escapedBin    = escapeshellarg($rcloneBin);
    $escapedFolder = escapeshellarg('gdrive:' . $folderClean . '/');

    // Usamos proc_open para capturar stderr de forma limpia
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $rcloneConfigPath = '/data/data/com.termux/files/home/.config/rclone/rclone.conf';
    $env = [
        'HOME' => '/data/data/com.termux/files/home',
        'PATH' => getenv('PATH') ?: '/data/data/com.termux/files/usr/bin:/usr/local/bin:/usr/bin:/bin'
    ];

    $process = proc_open(
        $rcloneBin . ' --config ' . escapeshellarg($rcloneConfigPath) . ' lsf ' . escapeshellarg('gdrive:' . $folderClean . '/') .
        ' --recursive --files-only --include "**/playlist.m3u8"',
        $descriptors,
        $pipes,
        null,
        $env
    );

    if (!is_resource($process)) {
        echo json_encode(['status' => 'error', 'message' => 'No se pudo iniciar rclone como proceso.']);
        exit;
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    // Si rclone falló o no produjo salida
    if ($exitCode !== 0 || ($stdout === null || trim($stdout) === '')) {
        $errMsg = 'rclone falló (exit code ' . $exitCode . ')';
        if (!empty(trim($stderr))) {
            $errMsg = trim($stderr);
        } elseif (trim($stdout ?? '') === '') {
            $errMsg = 'La carpeta gdrive:' . $folderClean . ' está vacía o no existe.';
        }
        echo json_encode([
            'status'    => 'error',
            'message'   => $errMsg,
            'exit_code' => $exitCode,
            'rclone'    => $rcloneBin,
            'stderr'    => $stderr,
            'stdout'    => $stdout,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ─── Procesar resultados ──────────────────────────────────────
    $lines   = explode("\n", trim($stdout));
    $added   = [];
    $updated = [];
    $ignored = [];

    // Cargar episodios existentes en memoria
    $stmtEx = $pdo->prepare("SELECT id, temporada, episodio, archivo_path FROM series_metadata WHERE contenido_id = ?");
    $stmtEx->execute([$contenido_id]);
    $existingMap = [];
    foreach ($stmtEx->fetchAll() as $e) {
        $existingMap[$e['temporada'] . '_' . $e['episodio']] = $e;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        // Detectar formato: S01E09/playlist.m3u8 (o con subcarpetas previas)
        if (!preg_match('/[Ss](\d{1,2})[Ee](\d{1,3})\/playlist\.m3u8$/i', $line, $m)) {
            continue;
        }

        $season      = intval($m[1]);
        $episode     = intval($m[2]);
        $key         = $season . '_' . $episode;
        $virtualPath = 'gdrive:' . $folderClean . '/' . $line;

        if (isset($existingMap[$key])) {
            $dbEp = $existingMap[$key];
            // Actualizar si el path cambió o estaba vacío
            if (empty(trim($dbEp['archivo_path'] ?? '')) || $dbEp['archivo_path'] !== $virtualPath) {
                // Eliminar posible duplicado en peliculas_metadata
                $del = $pdo->prepare("DELETE FROM peliculas_metadata WHERE contenido_id = ? AND season = ? AND episode = ?");
                $del->execute([$contenido_id, $season, $episode]);

                $upd = $pdo->prepare("UPDATE series_metadata SET archivo_path = ? WHERE id = ?");
                $upd->execute([$virtualPath, $dbEp['id']]);
                $updated[] = [
                    'temporada' => $season,
                    'episodio'  => $episode,
                    'antes'     => $dbEp['archivo_path'],
                    'ahora'     => $virtualPath
                ];
            } else {
                $ignored[] = ['temporada' => $season, 'episodio' => $episode];
            }
        } else {
            // Eliminar posible duplicado en peliculas_metadata
            $del = $pdo->prepare("DELETE FROM peliculas_metadata WHERE contenido_id = ? AND season = ? AND episode = ?");
            $del->execute([$contenido_id, $season, $episode]);

            // Insertar nuevo episodio
            $ins = $pdo->prepare(
                "INSERT INTO series_metadata (contenido_id, temporada, episodio, archivo_path, titulo_episodio, episode_still, episode_overview, episode_vote)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            static $seasonCache = [];
            $cacheKey = $contenido['tmdb_id'] . '_' . $season;
            if ($contenido['tmdb_id'] && !isset($seasonCache[$cacheKey])) {
                $seasonCache[$cacheKey] = tmdbGetSeasonEpisodes($contenido['tmdb_id'], $season);
            }
            $epData = ($contenido['tmdb_id'] && isset($seasonCache[$cacheKey])) ? ($seasonCache[$cacheKey][$episode] ?? []) : [];
            $epTitle   = $epData['name']    ?? ('Episodio ' . $episode);
            $epStill   = $epData['still']   ?? null;
            $epOverview = $epData['overview'] ?? null;
            $epVote    = $epData['vote']    ?? null;
            $ins->execute([$contenido_id, $season, $episode, $virtualPath, $epTitle, $epStill, $epOverview, $epVote]);
            $added[] = ['temporada' => $season, 'episodio' => $episode];
        }
    }

    echo json_encode([
        'status'  => 'success',
        'serie'   => $contenido['titulo'],
        'folder'  => 'gdrive:' . $folderClean,
        'rclone'  => $rcloneBin,
        'summary' => [
            'added_count'   => count($added),
            'updated_count' => count($updated),
            'ignored_count' => count($ignored),
        ],
        'added'   => $added,
        'updated' => $updated,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
