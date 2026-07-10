<?php
/**
 * GalixMovie MKV TRANSMUXER
 * Protocolo FENIX - Streaming de MKV sin conversión previa
 */

// Configuración de FFmpeg
$ffmpegPath = 'ffmpeg'; // En Termux el comando es directo

$file = $_GET['file'] ?? '';
if (!$file) die("Archivo no especificado");

// Seguridad: Solo permitir archivos dentro de la carpeta Proyectos
$baseDir = realpath(__DIR__ . '/../');
$realPath = realpath($baseDir . '/' . $file);

if (!$realPath) {
    // DHARMA FIX #21: Reintento con limpieza de slashes de Windows
    $file_linux = str_replace('\\', '/', $file);
    $realPath = realpath($baseDir . '/' . $file_linux);
}

if (!$realPath || !is_file($realPath)) {
    die("Error: Archivo no encontrado en la ruta: " . ($realPath ?: $file));
}

// Headers para streaming de video
header('Content-Type: video/mp4');
header('Accept-Ranges: bytes');
header('Cache-Control: no-cache');

// Comando de FFmpeg para transmuxear MKV a fragmented MP4 (fMP4)
$cmd = "\"$ffmpegPath\" -i \"$realPath\" -c:v copy -c:a aac -b:a 128k -f mp4 -movflags frag_keyframe+empty_moov+default_base_moof pipe:1";

// Ejecutar y pipear al navegador
$descriptorspec = [
    0 => ["pipe", "r"], 
    1 => ["pipe", "w"], 
    2 => ["pipe", "w"]  
];

$process = proc_open($cmd, $descriptorspec, $pipes);

if (is_resource($process)) {
    // Leer el output de FFmpeg y mandarlo al navegador
    while (!feof($pipes[1])) {
        echo fread($pipes[1], 8192);
        flush();
        if (connection_aborted()) break;
    }

    $stderr = stream_get_contents($pipes[2]);
    if ($stderr) {
        error_log("FFmpeg Error: " . $stderr);
    }
    
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}
?>
