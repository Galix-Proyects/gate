<?php
$path = $_GET['path'] ?? '';
if ($path === '') {
    http_response_code(400);
    exit;
}

// Extraer el nombre del archivo del path o URL completa
$filename = basename(parse_url($path, PHP_URL_PATH));
if ($filename === '' || $filename === '.') {
    http_response_code(400);
    exit;
}

$cacheDir = __DIR__ . '/../backdrops/';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$safeName = md5($filename) . '.jpg';
$cachePath = $cacheDir . $safeName;

if (file_exists($cachePath)) {
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($cachePath));
    header('Cache-Control: public, max-age=31536000');
    readfile($cachePath);
    exit;
}

// Si es URL completa de TMDB, usarla directo; si no, construir desde w1280
if (strpos($path, 'image.tmdb.org') !== false) {
    $tmdbUrl = $path;
} elseif (strpos($path, 'http') === 0) {
    $tmdbUrl = $path;
} else {
    $tmdbUrl = 'https://image.tmdb.org/t/p/w1280' . $path;
}

$ch = curl_init($tmdbUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'GalixMovie-Roku/1.0');
$imageData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || empty($imageData)) {
    http_response_code(404);
    exit;
}

file_put_contents($cachePath, $imageData);

header('Content-Type: image/jpeg');
header('Content-Length: ' . strlen($imageData));
header('Cache-Control: public, max-age=31536000');
echo $imageData;
