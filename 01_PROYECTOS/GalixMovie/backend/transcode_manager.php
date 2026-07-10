<?php
/**
 * GalixMovie Transcode Manager v1.0
 * On-demand FFmpeg transcoding for MPEG-2 → H.264
 * 
 * Endpoints:
 *   ?action=start&stream=URL  → Start transcoding, return transcoded m3u8 URL
 *   ?action=stop              → Stop transcoding
 *   ?action=status            → Check if running
 * 
 * Auto-stops after 5 minutes of idle (no requests).
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Range');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? 'status';
$streamUrl = $_GET['stream'] ?? '';

$home = getenv('HOME') ?: '/data/data/com.termux/files/home';
$transcodeDir = "$home/galix_transcode";
$pidFile = "$transcodeDir/ffmpeg.pid";
$lockFile = "$transcodeDir/transcode.lock";
$logFile = "$transcodeDir/ffmpeg.log";
$idleFile = "$transcodeDir/last_request.time";
$outputDir = "$transcodeDir/azteca7";
$playlist = "$outputDir/playlist.m3u8";
$idleTimeout = 300; // 5 minutes

// Clean stream URL (remove protocol prefix if present)
function cleanStreamUrl($url) {
    // If URL starts with proxy.php or similar, extract the actual stream URL
    if (preg_match('/proxy\.php\?url=(.+)/', $url, $m)) {
        return urldecode($m[1]);
    }
    return $url;
}

function isRunning($pidFile) {
    if (!file_exists($pidFile)) return false;
    $pid = trim(file_get_contents($pidFile));
    if (empty($pid)) return false;
    // Check if process exists
    exec("kill -0 " . intval($pid) . " 2>/dev/null", $output, $returnCode);
    return $returnCode === 0;
}

function startTranscode($streamUrl, $outputDir, $playlist, $pidFile, $logFile) {
    // Clean up old segments
    $files = glob("$outputDir/seg_*.ts");
    if ($files) {
        foreach ($files as $f) {
            @unlink($f);
        }
    }
    
    // Also check if a cached .m3u8 exists (for streams that return full file)
    @unlink("$outputDir/playlist.m3u8");
    
    $ffmpegPath = '/data/data/com.termux/files/usr/bin/ffmpeg';
    $cmd = sprintf(
        'nohup %s -hide_banner -loglevel warning -err_detect ignore_err -fflags +genpts+igndts ' .
        '-i %s ' .
        '-c:v libx264 -preset ultrafast -tune zerolatency -crf 28 ' .
        '-c:a aac -b:a 96k -ac 2 ' .
        '-f hls -hls_time 4 -hls_list_size 10 -hls_flags delete_segments+append_list ' .
        '-hls_segment_filename %s/seg_%%03d.ts ' .
        '%s >> %s 2>&1 & echo $!',
        escapeshellarg($ffmpegPath),
        escapeshellarg($streamUrl),
        escapeshellarg($outputDir),
        escapeshellarg($playlist),
        escapeshellarg($logFile)
    );
    
    exec($cmd, $output);
    $pid = trim(implode('', $output));
    
    if (!empty($pid) && ctype_digit($pid)) {
        file_put_contents($pidFile, $pid);
        return ['status' => 'started', 'pid' => $pid, 'playlist' => $playlist];
    }
    
    return ['status' => 'error', 'message' => 'Failed to start FFmpeg'];
}

function stopTranscode($pidFile) {
    if (!file_exists($pidFile)) return ['status' => 'not_running'];
    
    $pid = intval(trim(file_get_contents($pidFile)));
    if ($pid > 0) {
        exec("kill $pid 2>/dev/null");
        exec("pkill -9 -P $pid 2>/dev/null");
    }
    @unlink($pidFile);
    return ['status' => 'stopped', 'pid' => $pid];
}

function updateIdleTimer($idleFile) {
    file_put_contents($idleFile, time());
}

function isIdleExpired($idleFile, $timeout) {
    if (!file_exists($idleFile)) return true;
    $lastRequest = intval(trim(file_get_contents($idleFile)));
    return (time() - $lastRequest) > $timeout;
}

// === Main Logic ===

// Check idle timeout — auto-stop if expired
if (isRunning($pidFile) && isIdleExpired($idleFile, $idleTimeout)) {
    stopTranscode($pidFile);
    @unlink($idleFile);
    echo json_encode(['action' => 'auto_stopped', 'reason' => 'idle_timeout']);
    exit;
}

// Update idle timer on any request
updateIdleTimer($idleFile);

switch ($action) {
    case 'start':
        if (empty($streamUrl)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing stream URL']);
            exit;
        }
        
        $cleanUrl = cleanStreamUrl($streamUrl);
        
        // If already running, just return the playlist URL
        if (isRunning($pidFile)) {
            echo json_encode([
                'status' => 'already_running',
                'pid' => intval(trim(file_get_contents($pidFile))),
                'playlist' => "http://$_SERVER[HTTP_HOST]/galix_transcode/azteca7/playlist.m3u8"
            ]);
            exit;
        }
        
        // Start new transcoding process
        $result = startTranscode($cleanUrl, $outputDir, $playlist, $pidFile, $logFile);
        
        if ($result['status'] === 'started') {
            // Wait for first segment to be generated
            sleep(2);
            
            if (file_exists($playlist)) {
                $result['playlist'] = "http://$_SERVER[HTTP_HOST]/galix_transcode/azteca7/playlist.m3u8";
                $result['message'] = 'Transcoding started successfully';
            } else {
                $result['message'] = 'FFmpeg started but playlist not yet generated';
            }
        }
        
        echo json_encode($result);
        break;
        
    case 'stop':
        $result = stopTranscode($pidFile);
        @unlink($idleFile);
        echo json_encode($result);
        break;
        
    case 'status':
        $running = isRunning($pidFile);
        $pid = $running ? intval(trim(file_get_contents($pidFile))) : null;
        
        echo json_encode([
            'running' => $running,
            'pid' => $pid,
            'playlist_exists' => file_exists($playlist),
            'idle_remaining' => $running ? max(0, $idleTimeout - (time() - (file_exists($idleFile) ? intval(trim(file_get_contents($idleFile))) : 0))) : 0
        ]);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
