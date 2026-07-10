<?php
header('Content-Type: text/plain; charset=utf-8');

$TERMUX_BIN = '/data/data/com.termux/files/usr/bin';
putenv("PATH=$TERMUX_BIN:/system/bin:/system/xbin");
putenv('HOME=/data/data/com.termux/files/home');
putenv('PREFIX=/data/data/com.termux/files/usr');
putenv('TMPDIR=/data/data/com.termux/files/usr/tmp');

$files = [
    ['id' => 714, 'path' => '/data/data/com.termux/files/home/BUNKER/HDD_500GB/PELICULAS2/Avatar The Way of Water (2022).mp4'],
    ['id' => 647, 'path' => '/data/data/com.termux/files/home/BUNKER/HDD_500GB/PELICULAS2/Avatar Fire and Ash (2025).mp4']
];

$progressFile = __DIR__ . '/audio_fix_progress.json';

$action = $_GET['action'] ?? '';

if ($action === 'start') {
    // Verificar si ya hay uno corriendo
    if (file_exists($progressFile)) {
        $prev = json_decode(file_get_contents($progressFile), true);
        if (($prev['status'] ?? '') === 'running') {
            echo "ERROR: Ya hay un proceso de reparación en curso.\n";
            exit;
        }
    }

    $progress = ['status' => 'running', 'total' => count($files), 'done' => 0, 'current' => 'Iniciando...', 'pct' => 0, 'results' => []];
    file_put_contents($progressFile, json_encode($progress));

    // Trigger en background via script bash
    $fixScript = __DIR__ . '/run_fix_audio.sh';
    $script = "#!/data/data/com.termux/files/usr/bin/bash\n";
    $script .= "export PATH=$TERMUX_BIN:/system/bin:/system/xbin\n";
    $script .= "export HOME=/data/data/com.termux/files/home\n";
    $script .= "export PREFIX=/data/data/com.termux/files/usr\n";
    $script .= "export TMPDIR=/data/data/com.termux/files/usr/tmp\n\n";

    foreach ($files as $i => $f) {
        $base = basename($f['path']);
        $dir = dirname($f['path']);
        $name = pathinfo($base, PATHINFO_FILENAME);
        $tempPath = "$dir/{$name}_temp.mp4";
        $backupPath = "$dir/{$name}_backup.mp4";
        $outPath = $f['path'];

        $script .= "echo '{\"status\":\"running\",\"total\":" . count($files) . ",\"done\":$i,\"current\":\"Procesando: $base\",\"pct\":" . round($i/count($files)*100) . "}' > $progressFile\n";
        $script .= "ffmpeg -y -loglevel error -i " . escapeshellarg($outPath) . " -c:v copy -c:a aac -ac 2 -b:a 128k -movflags +faststart " . escapeshellarg($tempPath) . " 2>" . escapeshellarg("{$tempPath}.stderr") . "\n";
        $script .= "if [ \$? -eq 0 ] && [ -f " . escapeshellarg($tempPath) . " ]; then\n";
        $script .= "  mv " . escapeshellarg($outPath) . " " . escapeshellarg($backupPath) . "\n";
        $script .= "  mv " . escapeshellarg($tempPath) . " " . escapeshellarg($outPath) . "\n";
        $script .= "  echo '{\"status\":\"running\",\"total\":" . count($files) . ",\"done\":$((i+1)),\"current\":\"Completado: $base\",\"pct\":" . round(($i+1)/count($files)*100) . "}' > $progressFile\n";
        $script .= "else\n";
        $script .= "  echo '{\"status\":\"running\",\"total\":" . count($files) . ",\"done\":$((i+1)),\"current\":\"FALLÓ: $base\",\"pct\":" . round(($i+1)/count($files)*100) . "}' > $progressFile\n";
        $script .= "  rm -f " . escapeshellarg($tempPath) . "\n";
        $script .= "fi\n\n";
    }

    $script .= "echo '{\"status\":\"completed\",\"total\":" . count($files) . ",\"done\":" . count($files) . ",\"current\":\"Reparación completada\",\"pct\":100}' > $progressFile\n";

    file_put_contents($fixScript, $script);
    chmod($fixScript, 0755);

    exec("bash $fixScript > /dev/null 2>&1 &");

    echo "STARTED: Reparación lanzada en background para " . count($files) . " archivos.\n";
    echo "Los archivos originales se guardan como *_backup.mp4\n";
    exit;
}

if ($action === 'status') {
    if (!file_exists($progressFile)) {
        echo "STATUS: idle\n";
        exit;
    }
    $data = json_decode(file_get_contents($progressFile), true);
    echo "STATUS: {$data['status']} | Progreso: {$data['pct']}% | Actual: {$data['current']}\n";
    if (isset($data['results'])) {
        foreach ($data['results'] as $r) {
            echo "  - {$r['file']}: {$r['status']}\n";
        }
    }
    exit;
}

if ($action === 'clean') {
    @unlink($progressFile);
    // Limpiar temp files huérfanos
    foreach ($files as $f) {
        $dir = dirname($f['path']);
        $name = pathinfo(basename($f['path']), PATHINFO_FILENAME);
        @unlink("$dir/{$name}_temp.mp4");
        @unlink("$dir/{$name}_temp.mp4.stderr");
        @unlink(__DIR__ . '/run_fix_audio.sh');
    }
    echo "CLEANED\n";
    exit;
}

// Diagnóstico
$hasProgress = file_exists($progressFile);
echo "=== FIX AUDIO AVATARS ===\n";
echo "Progreso exists: " . ($hasProgress ? "SI" : "NO") . "\n";
if ($hasProgress) {
    echo file_get_contents($progressFile) . "\n";
}
echo "\nArchivos a reparar:\n";
foreach ($files as $f) {
    $path = $f['path'];
    $exists = file_exists($path) ? 'EXISTE' : 'NO EXISTE';
    $size = file_exists($path) ? round(filesize($path) / (1024*1024*1024), 2) . ' GB' : '?';
    echo "  ID {$f['id']}: $path [$exists, $size]\n";
    $dir = dirname($path);
    $name = pathinfo(basename($path), PATHINFO_FILENAME);
    $temp = "$dir/{$name}_temp.mp4";
    $backup = "$dir/{$name}_backup.mp4";
    if (file_exists($temp)) echo "    Temp: " . round(filesize($temp) / (1024*1024), 1) . " MB\n";
    if (file_exists($backup)) echo "    Backup: " . round(filesize($backup) / (1024*1024*1024), 2) . " GB\n";
}
