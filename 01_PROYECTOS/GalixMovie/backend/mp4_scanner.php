<?php
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? '';

$TERMUX_BIN = '/data/data/com.termux/files/usr/bin';
$HOME = '/data/data/com.termux/files/home';

function xexec($cmd, &$out = null, &$code = null) {
    exec('export PATH=' . $GLOBALS['TERMUX_BIN'] . ':$PATH ANDROID_DATA=/data ANDROID_ROOT=/system; ' . $cmd, $out, $code);
    return $code;
}

if ($action === 'scan') {
    set_time_limit(0);
    $mediaDir = $HOME . '/BUNKER';
    $resolved = realpath($mediaDir);
    $files = [];
    $totalScanned = 0;
    $detectAudio = isset($_GET['audio']) && $_GET['audio'] === '1';

    $audioCache = [];
    if ($detectAudio) {
        $acFile = __DIR__ . '/mp4_audio_scan_cache.json';
        if (file_exists($acFile)) {
            $ac = json_decode(file_get_contents($acFile), true);
            if (is_array($ac)) $audioCache = $ac;
        }
    }

    if ($resolved !== false && is_dir($resolved)) {
        $resolved .= '/';
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolved, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $path => $fileInfo) {
            $file = $fileInfo->getFilename();
            if (str_starts_with($file, '._')) continue;
            $ext = strtolower($fileInfo->getExtension());
            if (!in_array($ext, ['mp4', 'mkv'], true)) continue;
            $relPath = str_replace($resolved, '', $path);
            $size = filesize($path);
            $needsRepair = false;
            $repairReason = '';
            $audioCodec = '';
            if ($ext === 'mkv') {
                $needsRepair = true;
                $repairReason = 'Formato MKV';
            } else {
                $fp = fopen($path, 'rb');
                $header = fread($fp, 64);
                fclose($fp);
                $hasFtyp = strpos($header, 'ftyp') !== false;
                if (!$hasFtyp) {
                    $needsRepair = true;
                    $repairReason = 'Contenedor corrupto';
                }
                if ($detectAudio && isset($audioCache[$relPath])) {
                    $ac = $audioCache[$relPath];
                    if (($ac['size'] ?? -1) === $size) {
                        $audioCodec = $ac['audio_codec'] ?? '';
                        if ($ac['needs_repair'] && str_starts_with($ac['repair_reason'] ?? '', 'Audio:')) {
                            $needsRepair = true;
                            $repairReason = $ac['repair_reason'];
                        }
                    }
                }
            }
            $files[] = [
                'name' => $relPath,
                'size' => $size,
                'size_human' => formatBytes($size),
                'needs_repair' => $needsRepair,
                'repair_reason' => $repairReason,
                'audio_codec' => $audioCodec
            ];
            $totalScanned++;
        }
    }
    echo json_encode(['files' => $files, '_total' => $totalScanned]);
    exit;
}

if ($action === 'convert') {
    $progressFile = __DIR__ . '/mp4_repair_progress.json';

    // Evitar ejecuciones concurrentes
    if (file_exists($progressFile)) {
        $prev = json_decode(file_get_contents($progressFile), true);
        if ($prev && ($prev['status'] ?? '') === 'running') {
            echo json_encode(['status' => 'error', 'message' => 'Ya hay una reparación en curso. Espera a que termine o recarga la página.']);
            exit;
        }
    }

    $files = $_GET['files'] ?? [];
    if (!is_array($files)) $files = [$files];
    $modes = $_GET['modes'] ?? [];
    if (!is_array($modes)) $modes = [$modes];
    if (empty($files)) {
        echo json_encode(['status' => 'error', 'message' => 'No se seleccionaron archivos']);
        exit;
    }

    $mediaDir = $HOME . '/BUNKER';
    $resolved = realpath($mediaDir);
    if ($resolved === false || !is_dir($resolved)) {
        echo json_encode(['status' => 'error', 'message' => 'Directorio BUNKER no encontrado']);
        exit;
    }
    $resolved .= '/';

        $progress = [
            'status' => 'running',
            'total' => count($files),
            'done' => 0,
            'current' => 'Inicializando...',
            'pct' => 0,
            'start_time' => time(),
            'results' => []
        ];
    file_put_contents($progressFile, json_encode($progress), LOCK_EX);

    ignore_user_abort(true);
    set_time_limit(0);
    while (ob_get_level() > 0) ob_end_clean();
    header("Connection: close\r\n");
    header("Content-Encoding: none\r\n");
    header("Content-Type: application/json");
    $response = json_encode(["status" => "started", "total" => count($files)]);
    header("Content-Length: " . strlen($response));
    echo $response;
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    flush();

    // Limpiar temporales de ejecuciones anteriores
    $staleIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($staleIt as $spath => $sinfo) {
        $sname = $sinfo->getFilename();
        if (str_ends_with($sname, '_temp.mp4') || str_ends_with($sname, '_temp.mp4.stderr') || str_ends_with($sname, '_temp.mkv') || str_ends_with($sname, '_temp.mkv.stderr')) {
            @unlink($spath);
        }
    }

    foreach ($files as $i => $file) {
        $safeFile = basename($file);
        $inputPath = $resolved . $file;
        if (!file_exists($inputPath)) {
            $progress['results'][] = ['file' => $safeFile, 'status' => 'error', 'error' => 'File not found'];
            $progress['done'] = $i + 1;
            $progress['pct'] = round(($i + 1) / count($files) * 100);
            file_put_contents($progressFile, json_encode($progress), LOCK_EX);
            continue;
        }
        $tempDir = dirname($inputPath);
        $ext = strtolower(pathinfo($safeFile, PATHINFO_EXTENSION));
        $isMkv = ($ext === 'mkv');
        $tempPath = $tempDir . '/' . pathinfo($safeFile, PATHINFO_FILENAME) . '_temp.mp4';
        $stderrFile = $tempPath . '.stderr';

        $progress['current'] = "Analizando: $safeFile";
        $progress['pct'] = round(($i / count($files)) * 100);
        file_put_contents($progressFile, json_encode($progress), LOCK_EX);

        $totalDuration = 0;
        xexec('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1 ' . escapeshellarg($inputPath) . ' 2>/dev/null', $durOut, $durCode);
        if ($durCode === 0 && !empty($durOut[0])) {
            $totalDuration = floatval(trim($durOut[0]));
        }

        $audioCodec = '';
        $audioChannels = 0;
        xexec('ffprobe -v error -select_streams a:0 -show_entries stream=codec_name,channels -of default=noprint_wrappers=1 ' . escapeshellarg($inputPath) . ' 2>/dev/null', $probeOut, $probeExit);
        if ($probeExit === 0) {
            foreach ($probeOut as $line) {
                $line = trim($line);
                if (str_starts_with($line, 'codec_name=')) {
                    $audioCodec = substr($line, 11);
                } elseif (str_starts_with($line, 'channels=')) {
                    $audioChannels = (int) substr($line, 9);
                }
            }
        }

        $forceMode = $modes[$i] ?? '';
        if ($forceMode === 'audio') {
            $needsAudioEncode = true;
        } elseif ($forceMode === 'container') {
            $needsAudioEncode = false;
        } else {
            $needsAudioEncode = $audioCodec !== '' && ($audioCodec !== 'aac' || $audioChannels > 2);
        }

        $progress['current'] = "Procesando: $safeFile";
        $progress['pct'] = round(($i / count($files)) * 100);
        file_put_contents($progressFile, json_encode($progress), LOCK_EX);

        if ($needsAudioEncode) {
            $cmd = sprintf('ffmpeg -y -loglevel error -i %s -c:v copy -c:a aac -ac 2 -b:a 128k -movflags +faststart -progress pipe:3 %s',
                escapeshellarg($inputPath), escapeshellarg($tempPath));
        } else {
            $cmd = sprintf('ffmpeg -y -loglevel error -i %s -c copy -movflags +faststart -progress pipe:3 %s',
                escapeshellarg($inputPath), escapeshellarg($tempPath));
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'w']];
        $env = ['PATH' => $TERMUX_BIN . ':/usr/local/bin:/usr/bin:/bin', 'ANDROID_DATA' => '/data', 'ANDROID_ROOT' => '/system'];
        $process = proc_open($cmd, $descriptors, $pipes, null, $env);
        $stderrContent = '';

        if (is_resource($process)) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            stream_set_blocking($pipes[2], 0);
            stream_set_blocking($pipes[3], 0);

            $lastPct = -1;
            while (true) {
                $status = proc_get_status($process);
                if (!$status['running']) break;

                $prog = fread($pipes[3], 8192);
                if ($prog !== false && $prog !== '') {
                    if ($totalDuration > 0 && preg_match('/out_time=(\d{2}):(\d{2}):(\d{2}\.\d+)/', $prog, $m)) {
                        $cur = intval($m[1]) * 3600 + intval($m[2]) * 60 + floatval($m[3]);
                        $pct = min(99, round(($cur / $totalDuration) * 100));
                        if ($pct !== $lastPct) {
                            $lastPct = $pct;
                            $progress['pct'] = round(($i / count($files)) * 100 + (1 / max(1, count($files))) * $pct);
                            file_put_contents($progressFile, json_encode($progress), LOCK_EX);
                        }
                    } elseif ($totalDuration == 0) {
                        $origSize = @filesize($inputPath);
                        if ($origSize > 0) {
                            $tempSize = @filesize($tempPath);
                            if ($tempSize > 0) {
                                $pctBySize = min(99, round(($tempSize / $origSize) * 100));
                                if ($pctBySize !== $lastPct) {
                                    $lastPct = $pctBySize;
                                    $progress['pct'] = round(($i / count($files)) * 100 + (1 / max(1, count($files))) * $pctBySize);
                                    file_put_contents($progressFile, json_encode($progress), LOCK_EX);
                                }
                            }
                        }
                    }
                }

                $err = fread($pipes[2], 8192);
                if ($err !== false && $err !== '') {
                    $stderrContent .= $err;
                }

                if ($prog !== false && strpos($prog, 'progress=end') !== false) {
                    $progress['current'] = "Finalizando: $safeFile";
                    file_put_contents($progressFile, json_encode($progress), LOCK_EX);
                }

                usleep(500000);
            }

            fclose($pipes[3]);
            $remaining = stream_get_contents($pipes[2]);
            if ($remaining !== false) $stderrContent .= $remaining;
            fclose($pipes[2]);
            $exitCode = proc_close($process);
        } else {
            $exitCode = -1;
            $stderrContent = 'proc_open failed';
        }

        file_put_contents($stderrFile, $stderrContent);

        if ($exitCode === 0 && file_exists($tempPath)) {
            if ($isMkv) {
                $newPath = preg_replace('/\.mkv$/i', '.mp4', $inputPath);
                @unlink($newPath);
                rename($inputPath, $newPath);
                rename($tempPath, $newPath);
                $outFile = preg_replace('/\.mkv$/i', '.mp4', $safeFile);
            } else {
                @unlink($inputPath);
                rename($tempPath, $inputPath);
                $outFile = $safeFile;
            }
            $mode = $needsAudioEncode ? 'audio_encoded' : 'container_only';
            $progress['results'][] = ['file' => $outFile, 'status' => 'ok', 'mode' => $mode];
        } else {
            if (file_exists($tempPath)) unlink($tempPath);
            $errMsg = mb_substr(trim($stderrContent), 0, 2000);
            $progress['results'][] = ['file' => $safeFile, 'status' => 'error', 'error' => $errMsg];
        }

        $progress['done'] = $i + 1;
        $progress['pct'] = round(($i + 1) / count($files) * 100);
        file_put_contents($progressFile, json_encode($progress), LOCK_EX);
    }

    $progress['status'] = 'completed';
    $progress['pct'] = 100;
    $progress['current'] = 'Proceso completado';
    file_put_contents($progressFile, json_encode($progress), LOCK_EX);

    @unlink(__DIR__ . '/mp4_audio_scan_cache.json');

    exit;
}

if ($action === 'progress') {
    $progressFile = __DIR__ . '/mp4_repair_progress.json';
    if (!file_exists($progressFile)) {
        echo json_encode(['status' => 'idle']);
        exit;
    }
    $data = json_decode(file_get_contents($progressFile), true);
    echo json_encode($data);
    if (isset($data['status']) && in_array($data['status'], ['completed', 'failed'])) {
        @unlink($progressFile);
    }
    exit;
}

if ($action === 'reset') {
    $progressFile = __DIR__ . '/mp4_repair_progress.json';
    if (file_exists($progressFile)) unlink($progressFile);
    echo json_encode(['status' => 'idle']);
    exit;
}

if ($action === 'clean') {
    $root = realpath(__DIR__ . '/../../../') . '/';
    $deleted = [];
    if (is_dir($root)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $path => $fileInfo) {
            $name = $fileInfo->getFilename();
            if (str_starts_with($name, '._')) {
                if (unlink($path)) {
                    $deleted[] = str_replace($root, '', $path);
                }
            }
        }
    }
    echo json_encode([
        'status' => 'ok',
        'deleted_count' => count($deleted),
        'deleted_files' => $deleted
    ]);
    exit;
}

if ($action === 'diagnostics') {
    xexec('ffmpeg -version 2>&1', $ffVerOut, $ffVerCode);
    xexec('ffprobe -version 2>&1', $fpVerOut, $fpVerCode);
    $result = [
        'PATH' => getenv('PATH') ?: '(not set)',
        'TERMUX_BIN' => $TERMUX_BIN,
        'xexec: ffmpeg -version exit_code' => $ffVerCode,
        'xexec: ffmpeg -version output' => $ffVerCode === 0 ? (implode("\n", $ffVerOut) ?: '(empty)') : '(failed)',
        'xexec: ffprobe -version exit_code' => $fpVerCode,
        'xexec: ffprobe -version output' => $fpVerCode === 0 ? (implode("\n", $fpVerOut) ?: '(empty)') : '(failed)',
        'exec_enabled' => function_exists('exec') ? 'yes' : 'no',
        'open_basedir' => ini_get('open_basedir') ?: '(not set)',
        'disable_functions' => ini_get('disable_functions') ?: '(none)',
        'php_uname' => php_uname('a'),
        'is_dir(TermuxBin)' => is_dir($TERMUX_BIN) ? 'YES' : 'NO',
        'is_file(ffmpeg)' => is_file("$TERMUX_BIN/ffmpeg") ? 'YES' : 'NO',
        'is_executable(ffmpeg)' => is_executable("$TERMUX_BIN/ffmpeg") ? 'YES' : 'NO',
        'is_file(ffprobe)' => is_file("$TERMUX_BIN/ffprobe") ? 'YES' : 'NO',
        'is_executable(ffprobe)' => is_executable("$TERMUX_BIN/ffprobe") ? 'YES' : 'NO',
    ];
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== DIAGNÓSTICO mp4_scanner ===\n\n";
    foreach ($result as $k => $v) {
        echo str_pad($k, 50) . "=> " . $v . "\n";
    }
    exit;
}

if ($action === 'audio_scan_start') {
    $cacheFile = __DIR__ . '/mp4_audio_scan_cache.json';
    $progressFile = __DIR__ . '/mp4_audio_scan_progress.json';

    if (file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cache) && count($cache) > 0) {
            $files = array_values($cache);
            echo json_encode(['status' => 'cached', '_total' => count($files), 'files' => $files]);
            exit;
        }
    }

    if (file_exists($progressFile)) {
        $progress = json_decode(file_get_contents($progressFile), true);
        if (($progress['status'] ?? '') === 'running') {
            echo json_encode(['status' => 'scanning', 'progress' => $progress]);
            exit;
        }
    }

    $workerScript = __DIR__ . '/audio_scan_worker.php';
    xexec('nohup ' . $TERMUX_BIN . '/php ' . escapeshellarg($workerScript) . ' > /dev/null 2>&1 &');
    usleep(300000);

    echo json_encode(['status' => 'scanning', 'progress' => ['status' => 'running', 'total' => 0, 'scanned' => 0, 'pct' => 0]]);
    exit;
}

if ($action === 'audio_scan_status') {
    $progressFile = __DIR__ . '/mp4_audio_scan_progress.json';
    $cacheFile = __DIR__ . '/mp4_audio_scan_cache.json';

    if (!file_exists($progressFile)) {
        if (file_exists($cacheFile)) {
            $cache = json_decode(file_get_contents($cacheFile), true);
            $files = array_values($cache);
            echo json_encode(['status' => 'cached', '_total' => count($files), 'files' => $files]);
        } else {
            echo json_encode(['status' => 'idle']);
        }
        exit;
    }

    $progress = json_decode(file_get_contents($progressFile), true);

    if (($progress['status'] ?? '') === 'done' && file_exists($cacheFile)) {
        @unlink($progressFile);
        $cache = json_decode(file_get_contents($cacheFile), true);
        $files = array_values($cache);
        echo json_encode(['status' => 'cached', '_total' => count($files), 'files' => $files]);
        exit;
    }

    echo json_encode(['status' => 'scanning', 'progress' => $progress]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Acción no válida']);

function formatBytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1024, 1) . ' KB';
}
