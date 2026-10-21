<?php
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

@ini_set("display_errors","0");
@set_time_limit(0);
@ignore_user_abort(true);
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$downloadId = (int)($input['download_id'] ?? 0);
$path       = trim((string)($input['path'] ?? ''));
$title      = trim((string)($input['title'] ?? ''));
$tmdbId     = (int)($input['tmdb_id'] ?? 0);

// ── Resolver la ruta del archivo (desde download_id si no viene) ──
if ($path === '' && $downloadId > 0) {
    $pdo = getDB();
    $st = $pdo->prepare("SELECT final_path FROM downloads WHERE id=?");
    $st->execute([$downloadId]);
    $path = (string)$st->fetchColumn();
}

$real = galix_bunker_path($path);
if (!$real || !is_file($real)) {
    echo json_encode(['success' => false, 'error' => 'Archivo inválido o fuera de BUNKER']);
    exit;
}
$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
if (!in_array($ext, ['mp4', 'mkv', 'avi', 'mov', 'webm'])) {
    echo json_encode(['success' => false, 'error' => 'No es un archivo de video']);
    exit;
}
if ($title === '' && $tmdbId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Indica un nombre o un TMDB ID']);
    exit;
}

// ── Evitar indexaciones concurrentes (daemon/admin/otra petición) ──
$running = trim((string)@shell_exec("pgrep -f " . escapeshellarg('php.*scrapper\.php') . " 2>/dev/null"));
if ($running !== '') {
    echo json_encode(['success' => false, 'busy' => true, 'error' => 'Ya hay una indexación en curso; espera un momento']);
    exit;
}

$scrapper = '/data/data/com.termux/files/home/Proyectos/01_PROYECTOS/GalixMovie/backend/scrapper.php';
if (!is_file($scrapper)) {
    echo json_encode(['success' => false, 'error' => 'Scrapper no encontrado']);
    exit;
}

// ── ¿El archivo ya está indexado en el catálogo? → fix por ID (cambia nombre/TMDB) ──
$contenidoId = 0;
try {
    $gx = new PDO('mysql:unix_socket=' . DB_SOCKET . ';dbname=galix_movie;charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $q = $gx->prepare("SELECT contenido_id FROM peliculas_metadata WHERE archivo_path=? UNION SELECT contenido_id FROM series_metadata WHERE archivo_path=? LIMIT 1");
    $q->execute([$real, $real]);
    $contenidoId = (int)$q->fetchColumn();
} catch (Throwable $e) {
    $contenidoId = 0;
}

// ── Construir entorno del scrapper ──
$home   = getenv('HOME') ?: '/data/data/com.termux/files/home';
$prefix = '/data/data/com.termux/files/usr';
$php    = is_file($prefix . '/bin/php') ? $prefix . '/bin/php' : 'php';
$nice   = is_file($prefix . '/bin/nice') ? $prefix . '/bin/nice' : '';

$env = [
    'HOME'   => $home,
    'PREFIX' => $prefix,
    'PATH'   => $prefix . '/bin:' . $prefix . '/bin/applets:/system/bin',
    'TMPDIR' => $prefix . '/tmp',
];

if ($contenidoId > 0) {
    // Fix: reindexar la MISMA ficha (renombra archivo + actualiza TMDB)
    $year = 0;
    $cleanTitle = $title;
    if ($title !== '' && preg_match('/\((\d{4})\)/', $title, $m)) {
        $year = (int)$m[1];
        $cleanTitle = trim(preg_replace('/\s*\(\d{4}\)\s*/', ' ', $title));
    }
    $env['GALIX_FIX_ID'] = (string)$contenidoId;
    $env['GALIX_ONLY_PATHS'] = $real; // limitar el scan a este archivo (evita recorrer todo BUNKER)
    if ($cleanTitle !== '') $env['GALIX_FIX_TITLE'] = $cleanTitle;
    if ($year > 0)           $env['GALIX_FIX_YEAR']  = (string)$year;
    if ($tmdbId > 0)         $env['GALIX_FIX_TMDB']  = (string)$tmdbId;
    $mode = 'fix';
} else {
    // Indexado nuevo: modo dirigido SOLO a este archivo
    $env['GALIX_ONLY_PATHS'] = $real;
    if ($title !== '')  $env['GALIX_CUSTOM_TITLE'] = $title;
    if ($tmdbId > 0)    $env['GALIX_TMDB_ID']      = (string)$tmdbId;
    else                $env['GALIX_NO_FALLBACK']  = '1';
    $mode = 'index';
}

// ── Ejecutar el scrapper capturando su salida (timeout interno 180s) ──
$cmd = array_merge($nice !== '' ? [$nice, '-n', '19'] : [], [$php, $scrapper]);
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = @proc_open($cmd, $descriptors, $pipes, null, $env);
if (!is_resource($proc)) {
    echo json_encode(['success' => false, 'error' => 'No se pudo lanzar el scrapper']);
    exit;
}

fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$out = '';
$err = '';
$deadline = time() + 180;
$timedOut = false;
while (true) {
    $out .= (string)stream_get_contents($pipes[1]);
    $err .= (string)stream_get_contents($pipes[2]);
    $status = proc_get_status($proc);
    if (!$status['running']) {
        $out .= (string)stream_get_contents($pipes[1]);
        $err .= (string)stream_get_contents($pipes[2]);
        break;
    }
    if (time() > $deadline) { $timedOut = true; proc_terminate($proc); break; }
    usleep(200000);
}
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($proc);

$out = trim($out);
$done   = preg_match('/\[OK ✅\]|\[FALLBACK 📥\]|\[🔄 RENOMBRE MANUAL\]|\[⚠️ BAJA CONFIANZA\]|\[SERIE 📁\]/u', $out) === 1;
$review = strpos($out, '[REVIEW ⚠️]') !== false;
$fixErr = strpos($out, '[FIX ERR]') !== false;
$ok = $done && !$fixErr && !$timedOut;

if ($timedOut)        $msg = 'La indexación tardó demasiado (180s). Revisa en un momento.';
elseif ($review)      $msg = 'Requiere un TMDB ID válido para indexar con seguridad.';
elseif ($fixErr)      $msg = 'No se pudo aplicar el cambio.';
elseif (!$ok)         $msg = 'Sin cambios (quizá el archivo ya estaba indexado o no hubo coincidencia).';
else                  $msg = 'Indexado correctamente.';

// ── Registrar la resolución (para que el aviso de Telegram muestre qué pasó y dónde quedó) ──
if ($ok && $downloadId > 0) {
    $finalTitle = $title;
    if (preg_match('/\[OK ✅\] Indexado como: \'([^\']+)\'/u', $out, $m))               $finalTitle = $m[1];
    elseif (preg_match('/\[OK ✅\] Reindexado: "([^"]+)"/u', $out, $m))                $finalTitle = $m[1];
    elseif (preg_match('/\[FALLBACK 📥\] Reindexado manual: "([^"]+)"/u', $out, $m))   $finalTitle = $m[1];
    $finalPath = '';
    if (preg_match('/\[SAVED 📁\]\s*(.+)/u', $out, $m)) $finalPath = trim($m[1]);
    if ($finalPath === '') $finalPath = $real;

    $logFile = ($home ?: '/data/data/com.termux/files/home') . '/Proyectos/00_SISTEMA/_Poderes/TELEMETRY/telegram_index_log.json';
    try {
        $log = ['entries' => []];
        if (is_file($logFile)) {
            $dec = json_decode((string)@file_get_contents($logFile), true);
            if (is_array($dec) && isset($dec['entries']) && is_array($dec['entries'])) $log = $dec;
        }
        $log['entries'][(string)$downloadId] = [
            'action' => ($mode === 'fix' ? 'renamed' : 'indexed'),
            'title'  => (string)$finalTitle,
            'path'   => (string)$finalPath,
            'origin' => 'web',
            'at'     => date('Y-m-d H:i:s'),
        ];
        if (count($log['entries']) > 500) $log['entries'] = array_slice($log['entries'], -500, null, true);
        $log['updated'] = date('c');
        @file_put_contents($logFile, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    } catch (Throwable $e) {
        // no bloquear la indexación por no poder registrar la resolución
    }
}

echo json_encode([
    'success'  => $ok,
    'mode'     => $mode,
    'message'  => $msg,
    'output'   => $out,
    'timed_out' => $timedOut,
    'exit'     => $exitCode,
], JSON_UNESCAPED_UNICODE);
