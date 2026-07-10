<?php
/**
 * Fix audio AC3 5.1 → AAC 2ch for both Avatar movies
 * Usage: ?action=start&file=1  (file 1 or 2)
 *        ?action=status
 *        ?action=clean
 */

putenv('PATH=/data/data/com.termux/files/usr/bin:/system/bin:/system/xbin');
putenv('HOME=/data/data/com.termux/files/home');
putenv('PREFIX=/data/data/com.termux/files/usr');

$base = '/data/data/com.termux/files/home/BUNKER/HDD_500GB/PELICULAS2';
$progressFile = __DIR__ . '/audio_progress.json';

$files = [
    1 => ['title' => 'Avatar The Way of Water (2022)', 'file' => 'Avatar The Way of Water (2022).mp4'],
    2 => ['title' => 'Avatar Fuego y ceniza (2025)',     'file' => 'Avatar Fuego y ceniza (2025).mp4'],
];

function readProgress() {
    global $progressFile;
    return file_exists($progressFile) ? json_decode(file_get_contents($progressFile), true) : ['status' => 'idle', 'current' => null, 'files' => []];
}

function writeProgress($data) {
    global $progressFile;
    file_put_contents($progressFile, json_encode($data));
}

$action = $_GET['action'] ?? 'status';

if ($action === 'clean') {
    // Kill all ffmpeg and bash scripts
    exec("pkill -9 -f 'ffmpeg' 2>/dev/null");
    exec("pkill -9 -f 'fix_audio.php' 2>/dev/null");
    // Remove temp files
    foreach ($files as $f) {
        $in = "{$base}/{$f['file']}";
        $temp = "{$base}/{$f['title']}_temp.mp4";
        if (file_exists($temp)) exec("rm -f \"$temp\"");
        $backup = str_replace('.mp4', '_backup.mp4', $in);
        if (file_exists($backup) && filesize($backup) > 0) {
            exec("mv \"$backup\" \"$in\" 2>/dev/null");
        }
    }
    writeProgress(['status' => 'idle', 'current' => null, 'files' => []]);
    echo "CLEANED";
    exit;
}

if ($action === 'kill') {
    exec("kill -9 \$(ps -eo pid,comm | grep ffmpeg | grep -v grep | awk '{print \$1}') 2>/dev/null");
    exec("/data/data/com.termux/files/usr/bin/killall -9 ffmpeg 2>/dev/null");
    writeProgress(['status' => 'idle', 'current' => null, 'files' => []]);
    echo "KILLED";
    exit;
}

if ($action === 'start') {
    $fileIdx = (int)($_GET['file'] ?? 1);
    if (!isset($files[$fileIdx])) { echo "ERROR: invalid file $fileIdx"; exit; }

    $info = $files[$fileIdx];
    $input = "{$base}/{$info['file']}";
    $temp  = "{$base}/{$info['title']}_temp.mp4";
    $backup = str_replace('.mp4', '_backup.mp4', $input);

    if (!file_exists($input)) { echo "ERROR: input not found"; exit; }

    // Kill any existing ffmpeg first
    exec("kill -9 \$(ps -eo pid,comm | grep ffmpeg | grep -v grep | awk '{print \$1}') 2>/dev/null");
    exec("/data/data/com.termux/files/usr/bin/killall -9 ffmpeg 2>/dev/null");
    sleep(1);

    // Clean previous temp
    if (file_exists($temp)) exec("rm -f \"$temp\"");
    if (file_exists("{$temp}.stderr")) exec("rm -f \"{$temp}.stderr\"");

    $sizeGb = round(filesize($input) / 1073741824, 2);
    writeProgress([
        'status' => 'running',
        'current' => $fileIdx,
        'title' => $info['title'],
        'start' => time(),
        'files' => []
    ]);

    // Build ffmpeg command (nice + ionice to reduce heat)
    $cmd = "/data/data/com.termux/files/usr/bin/nice -n 19 /data/data/com.termux/files/usr/bin/ffmpeg -y -loglevel error -i " . escapeshellarg($input) . " -c:v copy -c:a aac -ac 2 -b:a 128k -movflags +faststart " . escapeshellarg($temp) . " > " . escapeshellarg("{$temp}.stderr") . " 2>&1 & echo \$!";
    $pid = trim(shell_exec($cmd));
    
    writeProgress(readProgress() + ['pid' => (int)$pid]);
    echo "STARTED file $fileIdx: {$info['title']}, PID: $pid";
    exit;
}

if ($action === 'status') {
    $prog = readProgress();

    // Check if ffmpeg is still running
    $running = 0;
    if (!empty($prog['pid'])) {
        exec("ps -p {$prog['pid']} -o stat= 2>/dev/null", $out, $rc);
        $running = ($rc === 0 && !empty($out[0]));
    }

    // Get current temp file size
    $tempSize = 0;
    if (!empty($prog['current'])) {
        $info = $files[$prog['current']];
        $temp = "{$base}/{$info['title']}_temp.mp4";
        if (file_exists($temp)) $tempSize = filesize($temp);
    }

    echo "STATUS: " . ($running ? 'running' : ($prog['status'] ?? 'idle')) . "\n";
    echo "CURRENT: " . ($prog['title'] ?? '-') . "\n";
    if ($tempSize > 0) echo "TEMP SIZE: " . round($tempSize / 1048576, 1) . " MB\n";
    if (!empty($prog['pid'])) echo "PID: {$prog['pid']}\n";
    echo "RUNNING: " . ($running ? 'YES' : 'NO') . "\n";

    // If was running but now not, clean up
    if ($prog['status'] === 'running' && !$running && !empty($prog['current'])) {
        $info = $files[$prog['current']];
        $input = "{$base}/{$info['file']}";
        $temp = "{$base}/{$info['title']}_temp.mp4";
        $backup = str_replace('.mp4', '_backup.mp4', $input);
        $stderr = "{$temp}.stderr";

        // Check stderr for errors
        $errLog = file_exists($stderr) ? file_get_contents($stderr) : '';
        
        if (file_exists($temp) && filesize($temp) > 1048576 && strpos($errLog, 'Error') === false) {
            // Success: swap files
            exec("mv \"$input\" \"$backup\" 2>/dev/null");
            exec("mv \"$temp\" \"$input\" 2>/dev/null");
            echo "\nRESULT: COMPLETED - {$info['file']}\n";
        } else {
            echo "\nRESULT: FAILED - check stderr:\n";
            echo substr($errLog, -500) . "\n";
        }
        if (file_exists($stderr)) exec("rm -f \"$stderr\"");
        if (file_exists($temp)) exec("rm -f \"$temp\"");
        writeProgress(['status' => 'idle', 'current' => null, 'files' => []]);
    }
    exit;
}

echo "Actions: ?action=start&file=1, ?action=status, ?action=clean, ?action=kill";
