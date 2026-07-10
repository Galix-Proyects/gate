<?php
/**
 * GalixMovie MKV TO HLS GENERATOR
 * Protocolo FENIX - Segmentación Dinámica para Soporte de Búsqueda (Seek)
 */

$isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
$ffmpegPath = $isWin ? 'C:\ffmpeg\ffmpeg.exe' : 'ffmpeg';
$tempDir    = '/data/data/com.termux/files/home/BUNKER/temp_hls/';

$file = $_GET['file'] ?? '';
if (!$file) die(json_encode(['status' => 'error', 'message' => 'Archivo no especificado']));

$realPath = realpath(__DIR__ . '/../' . $file);
if (!$realPath || strpos($realPath, 'Proyectos') === false) {
    die(json_encode(['status' => 'error', 'message' => 'Acceso denegado']));
}

// DHARMA CLEANUP: Borrar archivos temporales de más de 1 hora
foreach (glob($tempDir . "*.{m3u8,ts}", GLOB_BRACE) as $file_to_clean) {
    if (time() - filemtime($file_to_clean) > 3600) {
        @unlink($file_to_clean);
    }
}

// Generar un ID único basado en el archivo para evitar duplicados
$fileId = md5($realPath);
$manifestName = "stream_{$fileId}.m3u8";
$manifestPath = $tempDir . $manifestName;

// Si ya existe el manifiesto y es reciente, no reiniciar (Caché de sesión)
if (file_exists($manifestPath) && (time() - filemtime($manifestPath) < 300)) {
    echo json_encode(['status' => 'success', 'manifest' => "/bunker_media/temp_hls/{$manifestName}"]);
    exit;
}

// Limpiar archivos viejos de esta sesión si existen
foreach (glob($tempDir . "stream_{$fileId}*") as $oldFile) {
    @unlink($oldFile);
}

// Comando FFmpeg: Transmuxeo a HLS
// DHARMA FIX #21: Corregir sintaxis de 'start /B' (Windows interpreta el primer string citado como título)
$manifestPathAbsolute = realpath($tempDir) . DIRECTORY_SEPARATOR . $manifestName;
$segmentPathPattern   = realpath($tempDir) . DIRECTORY_SEPARATOR . "stream_{$fileId}_%03d.ts";

$cmd = "\"$ffmpegPath\" -i \"$realPath\" -map 0:v:0 -map 0:a:0 -c:v libx264 -preset ultrafast -crf 23 -profile:v baseline -level 3.0 -pix_fmt yuv420p -c:a aac -b:a 128k -ac 2 -sn -f hls -hls_time 2 -hls_list_size 0 -hls_flags independent_segments -hls_segment_filename \"$segmentPathPattern\" \"$manifestPathAbsolute\"";

// Ejecutar en segundo plano con redirección de errores a un archivo local para debug
$logFile = realpath($tempDir) . DIRECTORY_SEPARATOR . "ffmpeg_debug.log";
if ($isWin) {
    $finalCmd = "start /B \"\" cmd /c \"$cmd 2> \"$logFile\"\"";
} else {
    $finalCmd = "$cmd > \"$logFile\" 2>&1 &";
}
pclose(popen($finalCmd, "r"));

// Esperar a que se cree el primer fragmento (max 5 seg)
$attempts = 0;
while (!file_exists($manifestPath) && $attempts < 10) {
    usleep(500000); // 0.5s
    $attempts++;
}

if (file_exists($manifestPath)) {
    echo json_encode([
        'status'   => 'success', 
        'manifest' => "/bunker_media/temp_hls/{$manifestName}",
        'info'     => 'Iniciado motor HLS con soporte de búsqueda'
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'FFmpeg no pudo iniciar la segmentación']);
}
