<?php
/**
 * GalixMovie SUBTITLE ENGINE v1.0
 * SRT to VTT Converter on-the-fly
 */
header("Content-Type: text/vtt; charset=utf-8");
header("Access-Control-Allow-Origin: *");

$file = $_GET['file'] ?? '';
if (!$file) die("WEBVTT\n\n1\n00:00:00.000 --> 00:00:05.000\nError: Archivo no especificado");

$realPath = realpath(__DIR__ . '/../' . $file);
if (!$realPath || !file_exists($realPath)) {
    die("WEBVTT\n\n1\n00:00:00.000 --> 00:00:05.000\nError: Archivo no encontrado");
}

$content = file_get_contents($realPath);
$ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));

if ($ext === 'vtt') {
    echo $content;
    exit;
}

// Conversión Simple SRT a VTT
// 1. Reemplazar coma decimal por punto decimal
$content = preg_replace('/(\d+):(\d+):(\d+),(\d+)/', '$1:$2:$3.$4', $content);
// 2. Agregar cabecera WEBVTT
echo "WEBVTT\n\n";
echo $content;
?>
