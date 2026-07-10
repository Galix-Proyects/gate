<?php
// --- PROXY GALIXMOVIE v4.9 ---
// Blindaje DHARMA #30 (IP Spoofing + Stealth Mode)

error_reporting(0);
set_time_limit(0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: *');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$url = $_GET['url'] ?? '';
$customReferer = $_GET['ref'] ?? '';
$clientIpParam = $_GET['cip'] ?? null;
$cb = time(); 

// Intentamos obtener la IP real del usuario (Prioridad al parámetro cip enviado por app.js)
$realUserIp = $clientIpParam ? $clientIpParam : ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR']);

if (!$url) exit;

$host = parse_url($url, PHP_URL_HOST);
$scheme = parse_url($url, PHP_URL_SCHEME);
$proxyBaseUrl = "https://" . $_SERVER['HTTP_HOST'] . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 🛡️ DINAMIC REFERER v4.9.5 (Deep Sanitize)
// Si detectamos servidores críticos, ignoramos lo que mande el frontend y usamos identidad pura
$isVimeusCdn = (strpos($host, 'goodstream.one') !== false || strpos($host, 'vimeos.zip') !== false || strpos($host, 'vimeos.net') !== false || strpos($host, 'vimeus.com') !== false);

if (strpos($host, 'medixiru.com') !== false) {
    $referer = "https://medixiru.com"; 
    $origin = "https://medixiru.com";
    $userAgent = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36";
} elseif (strpos($host, 'cloudwindow-route.com') !== false || strpos($host, 'callistanise.com') !== false) {
    $referer = "https://pelisplushd.la"; // 🛡️ Sanitizado sin barra
    $origin = "https://pelisplushd.la";
    $userAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36";
} elseif ($isVimeusCdn) {
    $referer = "https://vimeus.com/"; // 🛡️ Evita 403 Forbidden simulando referer original de Vimeus
    $origin = "https://vimeus.com";
    // Heredar UA real para que coincida con el fingerprint TLS del navegador (macOS, Windows, iOS, etc.)
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36";
} elseif (strpos($host, 'ibra.lat') !== false || strpos($host, 'pelisflix') !== false) {
    $referer = "https://$host/";
    $origin = "https://$host";
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36";
} else {
    // Para el resto, usamos el referer enviado pero lo limpiamos de queries
    $rawRef = $customReferer ? $customReferer : "https://blog-peliculas.net/";
    $referer = explode('?', $rawRef)[0]; 
    $origin = rtrim($referer, '/');
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36";
}

$headers = [
    "User-Agent: $userAgent",
    "Accept: */*",
    "Accept-Language: es-MX,es;q=0.9,en-US;q=0.8,en;q=0.7",
    "Referer: $referer"
];

// Sec-Fetch es sospechoso para cURL en CDNs estrictos, solo lo inyectamos si no es Vimeus CDN
if (!$isVimeusCdn) {
    $headers[] = "Sec-Fetch-Site: cross-site";
    $headers[] = "Sec-Fetch-Mode: cors";
    $headers[] = "Sec-Fetch-Dest: empty";
}

if ($origin) $headers[] = "Origin: $origin";
// Host header ELIMINADO — curl lo genera automáticamente correcto.
// Enviar Host explícito causa ERR_SSL_PROTOCOL_ERROR cuando CURLOPT_FOLLOWLOCATION
// sigue redirecciones a subdominios CDN (ej. mdstrm.com → cdn.mdstrm.com).

// 🛡️ MODO STEALTH: No enviar IPs de reenvío para Medixiru ni Vimeus CDN
// Si el token fue generado por el servidor de extracción, el CDN del stream requiere
// que la conexión TCP provenga de la misma IP. Enviar XFF rompería esta correspondencia.
if (strpos($host, 'medixiru.com') === false && !$isVimeusCdn) {
    $headers[] = "X-Forwarded-For: $realUserIp";
    $headers[] = "X-Real-IP: $realUserIp";
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_HEADER         => true,
    CURLOPT_IPRESOLVE      => (strpos($host, 'medixiru') !== false) ? CURL_IPRESOLVE_WHATEVER : CURL_IPRESOLVE_V4, 
    CURLOPT_ENCODING       => "",
    CURLOPT_TIMEOUT        => 45
]);

$response = curl_exec($ch);
if ($response === false) {
    $err = curl_error($ch);
    curl_close($ch);
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'proxy_failed', 'message' => $err]);
    exit;
}
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$content = substr($response, $headerSize);
curl_close($ch);

if ($httpCode >= 200 && $httpCode < 400) {
    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

    if ($ext === 'm3u8' || strpos($contentType, 'mpegurl') !== false || strpos($content, '#EXTM3U') !== false) {
        header("Content-Type: application/vnd.apple.mpegurl");
        
        // 🛡️ SANEAMIENTO DE RUTA v4.9: Ignorar query params para la base
        $cleanPath = parse_url($url, PHP_URL_PATH);
        $baseUrl = $scheme . "://" . $host . substr($cleanPath, 0, strrpos($cleanPath, '/') + 1);
        
        $domainRoot = $scheme . '://' . $host;
        $parentQuery = parse_url($url, PHP_URL_QUERY);
        $parentParams = [];
        if ($parentQuery) {
            parse_str($parentQuery, $parentParams);
        }
        
        $lines = explode("\n", $content);
        $newLines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            if (strpos($line, '#') === 0) {
                // Reescribir atributos URI="..." dentro de etiquetas #EXT (ej. EXT-X-MAP, EXT-X-MEDIA, EXT-X-KEY)
                if (preg_match_all('/URI="([^"]+)"/', $line, $matches)) {
                    foreach ($matches[1] as $uri) {
                        // Ignorar data URIs
                        if (strpos($uri, 'data:') === 0) continue;
                        
                        $absoluteUri = $uri;
                        if (strpos($uri, 'http') !== 0) {
                            $absoluteUri = (strpos($uri, '/') === 0) ? $domainRoot . $uri : $baseUrl . $uri;
                        }
                        
                        // Combinar query parameters del padre de manera segura
                        if (!empty($parentParams)) {
                            $uriParts = parse_url($absoluteUri);
                            $uriParams = [];
                            if (isset($uriParts['query'])) {
                                parse_str($uriParts['query'], $uriParams);
                            }
                            $mergedParams = array_merge($parentParams, $uriParams);
                            $newQuery = http_build_query($mergedParams);
                            $absoluteUri = ($uriParts['scheme'] ?? $scheme) . "://" . ($uriParts['host'] ?? $host) . ($uriParts['path'] ?? '');
                            if (!empty($newQuery)) {
                                $absoluteUri .= '?' . $newQuery;
                            }
                        }
                        
                        $proxiedUri = "$proxyBaseUrl?url=" . urlencode($absoluteUri) . "&ref=" . urlencode($referer) . "&cip=" . urlencode($realUserIp) . "&_cb=" . $cb;
                        $line = str_replace('URI="' . $uri . '"', 'URI="' . $proxiedUri . '"', $line);
                    }
                }
            } else {
                // Reescribir URLs directas de segmentos o sub-manifests
                $absoluteUrl = $line;
                if (strpos($line, 'http') !== 0) {
                    $absoluteUrl = (strpos($line, '/') === 0) ? $domainRoot . $line : $baseUrl . $line;
                }
                
                // Combinar query parameters del padre de manera segura
                if (!empty($parentParams)) {
                    $urlParts = parse_url($absoluteUrl);
                    $urlParams = [];
                    if (isset($urlParts['query'])) {
                        parse_str($urlParts['query'], $urlParams);
                    }
                    $mergedParams = array_merge($parentParams, $urlParams);
                    $newQuery = http_build_query($mergedParams);
                    $absoluteUrl = ($urlParts['scheme'] ?? $scheme) . "://" . ($urlParts['host'] ?? $host) . ($urlParts['path'] ?? '');
                    if (!empty($newQuery)) {
                        $absoluteUrl .= '?' . $newQuery;
                    }
                }
                
                // Todo debe pasar por el proxy, de lo contrario CORS bloquea a hls.js
                // Usamos _cb para evitar que Cloudflare devuelva un caché zombi del 302 que hicimos antes
                $line = "$proxyBaseUrl?url=" . urlencode($absoluteUrl) . "&ref=" . urlencode($referer) . "&cip=" . urlencode($realUserIp) . "&_cb=" . $cb;
            }
            $newLines[] = $line;
        }
        echo implode("\n", $newLines);
        exit;
    }

    if (in_array($ext, ['ts', 'm4s', 'mp4'])) header("Content-Type: video/mp2t");
    else header("Content-Type: $contentType");
    // 🛡️ MODO ESPEJO: Inyectar <base> para que sitios en Iframe funcionen (X-Frame-Bypass)
    if (strpos($contentType, 'text/html') !== false) {
        $baseHref = parse_url($url, PHP_URL_SCHEME) . "://" . parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH);
        $baseTag = "<base href=\"$baseHref\">\n<script>window.top === window.self || (window.X_FRAME_BYPASS = true);</script>";
        
        // Inyectar justo después de <head>
        if (strpos($content, '<head>') !== false) {
            $content = str_replace('<head>', "<head>\n$baseTag", $content);
        } else {
            $content = $baseTag . $content;
        }
    }
    
    echo $content;
} else {
    $statusCode = ($httpCode > 0) ? $httpCode : 502;
    http_response_code($statusCode);
    file_put_contents('proxy_errors.log', "[".date('Y-m-d H:i:s')."] IP_SPOOF_FAIL $httpCode | URL: $url | IP: $realUserIp\n", FILE_APPEND);
}
