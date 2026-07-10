<?php
error_reporting(0);
$url = $_GET["url"] ?? "";
if (!$url || !preg_match("/^https?:\/\//i", $url)) {
    header("Content-Type: application/json");
    http_response_code(400);
    die(json_encode(["error" => "URL invalida"]));
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
    ]
]);
$content  = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
$mimeMap = [
    "js"   => "application/javascript",
    "css"  => "text/css",
    "woff" => "font/woff2",
    "woff2"=> "font/woff2",
    "ttf"  => "font/ttf",
    "svg"  => "image/svg+xml",
    "png"  => "image/png",
    "jpg"  => "image/jpeg",
    "jpeg" => "image/jpeg",
    "webp" => "image/webp",
    "gif"  => "image/gif",
];
$mimeType = $mimeMap[$ext] ?? "application/octet-stream";

if ($content === false || $httpCode !== 200) {
    header("Content-Type: application/json");
    http_response_code(502);
    die(json_encode(["error" => "Error al descargar el asset", "http" => $httpCode, "mime" => $mimeType]));
}

$isImage = in_array($ext, ["png", "jpg", "jpeg", "webp", "gif", "svg"], true);
if ($isImage) {
    header("Cache-Control: public, max-age=604800, immutable");
    header("Expires: " . gmdate("D, d M Y H:i:s", time() + 604800) . " GMT");
}

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: " . $mimeType);
echo $content;
