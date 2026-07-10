<?php
require 'db.php';

$cacheDir = __DIR__ . '/../../../backdrops/';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$rows = $pdo->query("SELECT id, titulo, backdrop_path FROM contenido WHERE backdrop_path IS NOT NULL AND backdrop_path != ''")->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
echo "Precachando $total backdrops...\n\n";

$ok = 0;
$fail = 0;
$skip = 0;
foreach ($rows as $row) {
    $url = trim($row['backdrop_path'] ?? '');
    if (empty($url)) continue;

    $filename = basename(parse_url($url, PHP_URL_PATH));
    if ($filename === '' || $filename === '.') { $fail++; continue; }

    $safeName = md5($filename) . '.jpg';
    $cachePath = $cacheDir . $safeName;

    if (file_exists($cachePath)) {
        $skip++;
        continue;
    }

    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'GalixMovie-Precache/1.0');
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_RESOLVE, array("api.themoviedb.org:443:18.161.156.100"));
    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $elapsed = round((microtime(true) - $start) * 1000);

    if ($httpCode === 200 && $imageData) {
        file_put_contents($cachePath, $imageData);
        $ok++;
        $label = substr($row['titulo'], 0, 30);
        echo "  [$ok/$total] OK ({$elapsed}ms): $label\n";
    } else {
        $fail++;
        echo "  [FAIL] ({$elapsed}ms): {$row['titulo']} (HTTP $httpCode)\n";
    }
}

echo "\n---\nTotal: $total | Nuevos: $ok | Saltados: $skip | Fallos: $fail\n";
