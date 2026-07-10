<?php
error_reporting(0);
set_time_limit(0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, If-Range, Content-Type');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once 'db.php';

$id = $_GET['id'] ?? null;
if (!$id || !is_numeric($id)) {
    http_response_code(400);
    echo json_encode(['error' => 'ID requerido']);
    exit;
}
$id = (int)$id;

// Obtener ruta del archivo
$season = isset($_GET['season']) ? (int)$_GET['season'] : null;
$episode = isset($_GET['episode']) ? (int)$_GET['episode'] : null;

$row = null;
if ($season !== null && $episode !== null) {
    $st = $pdo->prepare("SELECT archivo_path FROM series_metadata WHERE contenido_id = ? AND temporada = ? AND episodio = ? LIMIT 1");
    $st->execute([$id, $season, $episode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
}
if (!$row) {
    $st = $pdo->prepare("SELECT archivo_path, file_size FROM peliculas_metadata WHERE contenido_id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
}

if (!$row || !$row['archivo_path']) {
    http_response_code(404);
    echo json_encode(['error' => 'Archivo no encontrado en BD']);
    exit;
}

$filePath = $row['archivo_path'];
if (!file_exists($filePath)) {
    http_response_code(404);
    echo json_encode(['error' => 'Archivo fisico no encontrado', 'path' => $filePath]);
    exit;
}

// Inyectar entorno Termux (DHARMA #29)
$prefix = '/data/data/com.termux/files/usr';
putenv("ANDROID_DATA=/data/data/com.termux");
putenv("ANDROID_ROOT=/system");
putenv("LD_PRELOAD=");
putenv("PATH=$prefix/bin:$prefix/bin/applets:/system/bin:/system/xbin");
putenv("HOME=/data/data/com.termux/files/home");
putenv("PREFIX=$prefix");
putenv("TMPDIR=$prefix/tmp");

$ffmpeg = "$prefix/bin/ffmpeg";
$ffprobe = "$prefix/bin/ffprobe";

// Probar audio codec con ffprobe (solo primeros 5MB para rapidez)
$probeCmd = "$ffprobe -v quiet -print_format json -show_streams -read_intervals '%+5' " . escapeshellarg($filePath) . " 2>&1";
$probeOut = shell_exec($probeCmd);
$probeData = json_decode($probeOut, true);

$needsTranscode = false;
$mimeType = 'video/mp4';

if ($probeData && isset($probeData['streams'])) {
    foreach ($probeData['streams'] as $s) {
        if ($s['codec_type'] === 'audio') {
            $codec = strtolower($s['codec_name'] ?? '');
            if (in_array($codec, ['ac3', 'eac3', 'dts', 'truehd', 'mlp', 'dca'])) {
                $needsTranscode = true;
            }
        }
        if ($s['codec_type'] === 'video') {
            $vCodec = strtolower($s['codec_name'] ?? '');
            if ($vCodec === 'hevc') $mimeType = 'video/mp4';
        }
    }
}

// Si el audio es AAC/MP3/Opus/Vorbis, servir normal via X-Accel-Redirect (nginx) o PHP streaming directo
$isNginx = strpos($_SERVER['SERVER_SOFTWARE'] ?? '', 'nginx') !== false;
if (!$needsTranscode) {
    $mediaBase = '/data/data/com.termux/files/home/BUNKER/';
    if (strpos($filePath, $mediaBase) === 0) {
        $relativePath = substr($filePath, strlen($mediaBase));
        header('Content-Type: ' . $mimeType);
        header('Accept-Ranges: bytes');
        if ($isNginx) {
            header('X-Accel-Redirect: /bunker_media/' . $relativePath);
            exit;
        }
    }
    // Fallback: streaming PHP directo
    $fileSize = filesize($filePath);
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    header('Content-Type: ' . $mimeType);
    if ($range && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
        $start = $m[1] !== '' ? (int)$m[1] : 0;
        $end = $m[2] !== '' ? (int)$m[2] : $fileSize - 1;
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$fileSize");
        header('Content-Length: ' . ($end - $start + 1));
        $fp = fopen($filePath, 'rb');
        if ($fp) {
            fseek($fp, $start);
            $toSend = $end - $start + 1;
            while ($toSend > 0 && !feof($fp)) {
                $read = min(524288, $toSend);
                $data = fread($fp, $read);
                if ($data === false) break;
                echo $data;
                $toSend -= strlen($data);
                flush();
            }
            fclose($fp);
        }
    } else {
        header('Content-Length: ' . $fileSize);
        readfile($filePath);
    }
    exit;
}

// --- NECESITA TRANSCODE: AC-3/E-AC-3/DTS → AAC ---
// Para fMP4 streaming con ffmpeg, no podemos soportar Range Requests complejos.
// Servimos como fMP4 progresivo.

$fileSize = filesize($filePath);

// Detectar Range Request para soporte básico de seek
$range = $_SERVER['HTTP_RANGE'] ?? '';
$start = 0;
$seekFrom = '';

if ($range && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
    $start = $m[1] !== '' ? (int)$m[1] : 0;
    if ($start > 0) {
        $seekFrom = "-ss " . escapeshellarg($start / $fileSize * 1000 . "ms");
    }
    http_response_code(206);
    header("Accept-Ranges: bytes");
}

header('Content-Type: video/mp4');
header('Cache-Control: no-cache');
header('Access-Control-Allow-Origin: *');

$ffmpegCmd = "$ffmpeg -hide_banner -v quiet $seekFrom -i " . escapeshellarg($filePath) .
             " -c:v copy -c:a aac -b:a 192k" .
             " -f mp4 -movflags frag_keyframe+empty_moov+default_base_moof" .
             " pipe:1 2>&1";

$descriptorspec = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
$process = proc_open($ffmpegCmd, $descriptorspec, $pipes);

if (is_resource($process)) {
    fclose($pipes[0]);
    $outPipe = $pipes[1];
    $errPipe = $pipes[2];
    stream_set_blocking($outPipe, false);
    
    while (!feof($outPipe)) {
        $data = fread($outPipe, 65536);
        if ($data === false || $data === '') {
            if (feof($outPipe)) break;
            usleep(50000);
            continue;
        }
        echo $data;
        flush();
        if (connection_aborted()) break;
    }
    
    $stderr = stream_get_contents($errPipe);
    if ($stderr) {
        file_put_contents(__DIR__ . '/../logs/ac3_transcode.log',
            "[" . date('Y-m-d H:i:s') . "] ID=$id ERROR: $stderr\n", FILE_APPEND);
    }
    
    fclose($outPipe);
    fclose($errPipe);
    proc_close($process);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Error iniciando transcoder']);
}
