<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

// Obtener la IP real del cliente considerando proxies (Cloudflare, etc.)
$ip = $_SERVER['REMOTE_ADDR'];

if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
} elseif (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
}

echo json_encode([
    "status" => "success",
    "ip" => $ip
]);
