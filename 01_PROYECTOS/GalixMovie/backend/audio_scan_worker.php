<?php
error_reporting(0);
set_time_limit(0);

$TERMUX_BIN = '/data/data/com.termux/files/usr/bin';
$HOME = '/data/data/com.termux/files/home';
$MEDIA_DIR = $HOME . '/BUNKER';
$CACHE_FILE = __DIR__ . '/mp4_audio_scan_cache.json';
$PROGRESS_FILE = __DIR__ . '/mp4_audio_scan_progress.json';
$PID_FILE = __DIR__ . '/mp4_audio_scan.pid';

function xexec($cmd, &$out = null, &$code = null) {
    exec('export PATH=' . $GLOBALS['TERMUX_BIN'] . ':$PATH ANDROID_DATA=/data ANDROID_ROOT=/system; ' . $cmd, $out, $code);
    return $code;
}

function writeProgress($status, $total, $scanned, $pct) {
    file_put_contents($GLOBALS['PROGRESS_FILE'], json_encode([
        'status' => $status,
        'total' => $total,
        'scanned' => $scanned,
        'pct' => $pct
    ]), LOCK_EX);
}

$resolved = realpath($MEDIA_DIR);
if ($resolved === false || !is_dir($resolved)) {
    writeProgress('error', 0, 0, 0);
    exit(1);
}
$resolved .= '/';

writeProgress('running', 0, 0, 0);

$cache = [];
if (file_exists($CACHE_FILE)) {
    $c = json_decode(file_get_contents($CACHE_FILE), true);
    if (is_array($c)) $cache = $c;
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($resolved, RecursiveDirectoryIterator::SKIP_DOTS)
);

$results = [];
$toScan = [];
$totalFiles = 0;

foreach ($it as $path => $fi) {
    $relPath = str_replace($resolved, '', $path);
    if (str_starts_with($fi->getFilename(), '._')) continue;
    $ext = strtolower($fi->getExtension());
    if (!in_array($ext, ['mp4', 'mkv'], true)) continue;

    $totalFiles++;
    $size = $fi->getSize();
    $mtime = $fi->getMTime();

    $cached = $cache[$relPath] ?? null;
    if ($cached && isset($cached['size'], $cached['mtime']) && $cached['size'] === $size && $cached['mtime'] === $mtime) {
        $results[$relPath] = $cached;
        continue;
    }

    $toScan[] = $path;
}

$totalToScan = count($toScan);
$scanned = count($results);

if ($totalToScan === 0) {
    ksort($results);
    file_put_contents($CACHE_FILE, json_encode($results));
    writeProgress('done', $totalFiles, $totalFiles, 100);
    exit(0);
}

file_put_contents($PID_FILE, getmypid());
writeProgress('running', $totalFiles, $scanned, round($scanned / max(1, $totalFiles) * 100));

$batchSize = 30;
$processedInBatch = 0;

for ($i = 0; $i < $totalToScan; $i += $batchSize) {
    $batch = array_slice($toScan, $i, $batchSize);

    $tmpFile = tempnam(sys_get_temp_dir(), 'mp4scan_');
    $fp = fopen($tmpFile, 'w');
    foreach ($batch as $bp) {
        fwrite($fp, $bp . "\n");
    }
    fclose($fp);

    $cmd = 'export PATH=' . $TERMUX_BIN . ':$PATH ANDROID_DATA=/data ANDROID_ROOT=/system; '
         . 'cat ' . escapeshellarg($tmpFile) . ' | xargs -P4 -I {} sh -c \''
         . 'f="{}"; '
         . 'ext="${f##*.}"; ext=$(echo "$ext" | tr "[:upper:]" "[:lower:]"); '
         . 'size=$(stat -c%s "$f" 2>/dev/null || echo 0); '
         . 'mtime=$(stat -c%Y "$f" 2>/dev/null || echo 0); '
         . 'if [ "$ext" = "mkv" ]; then '
         . 'echo "RES|$f|MKV|1|Formato MKV||$size|$mtime"; '
         . 'else '
         . 'codec=$(ffprobe -v error -select_streams a:0 -show_entries stream=codec_name -of default=noprint_wrappers=1 "$f" 2>/dev/null | grep codec_name= | cut -d= -f2); '
         . 'if [ -n "$codec" ] && [ "$codec" != "aac" ]; then '
         . 'ucodec=$(echo "$codec" | tr "[:lower:]" "[:upper:]"); '
         . 'echo "RES|$f|MP4|1|Audio: $ucodec|$codec|$size|$mtime"; '
         . 'else '
         . 'echo "RES|$f|MP4|0||$codec|$size|$mtime"; '
         . 'fi; fi\' 2>/dev/null';

    $output = [];
    xexec($cmd, $output);

    foreach ($output as $line) {
        $line = trim($line);
        if (!str_starts_with($line, 'RES|')) continue;
        $parts = explode('|', $line, 8);
        if (count($parts) < 8) continue;

        $fullPath = $parts[1];
        $repairReason = $parts[4];
        $audioCodec = $parts[5];
        $size = (int)$parts[6];
        $mtime = (int)$parts[7];
        $needsRepair = ($parts[3] === '1');

        $rel = str_replace($resolved, '', $fullPath);
        $results[$rel] = [
            'path' => $rel,
            'size' => $size,
            'mtime' => $mtime,
            'needs_repair' => $needsRepair,
            'repair_reason' => $repairReason,
            'audio_codec' => $audioCodec
        ];
    }

    unlink($tmpFile);

    $processedInBatch += count($batch);
    $scanned = count($results);
    writeProgress('running', $totalFiles, $scanned, round($scanned / max(1, $totalFiles) * 100));
}

ksort($results);
file_put_contents($CACHE_FILE, json_encode($results), LOCK_EX);
writeProgress('done', $totalFiles, $scanned, 100);
@unlink($PID_FILE);
