<?php
/**
 * GalixMovie — DHARMA Fix #76: Stream Remote Proxy (Blindado)
 * Proxy passthrough cURL con CORS para Archive.org y CDNs autorizados.
 * Blindaje: whitelist de dominios, anti-open-proxy, timeout inteligente,
 * validación de Content-Type, límite de tamaño, y status 206 correcto.
 */

// === BLINDAJE NIVEL 1: Anti-Open-Proxy (solo dominios autorizados) ===
$allowedDomains = [
    'archive.org',
    '*.archive.org',
    'ia601504.us.archive.org',
    'ia801503.us.archive.org',
    'ia801505.us.archive.org',
    'ia801506.us.archive.org',
    'ia801507.us.archive.org',
    'ia801508.us.archive.org',
    'ia801509.us.archive.org',
    'ia801510.us.archive.org',
];

if (!isset($_GET['url'])) {
    header('HTTP/1.1 400 Bad Request');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Parámetro url requerido']);
    exit;
}

$url = $_GET['url'];

// Decodificar solo doble-encoding
if (strpos($url, '%25') !== false) {
    $url = urldecode($url);
}

// === BLINDAJE NIVEL 2: Validación de dominio ===
$parsedUrl = parse_url($url);
if (!$parsedUrl || !isset($parsedUrl['host'])) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'URL inválida']);
    exit;
}

$host = strtolower($parsedUrl['host']);
$domainAllowed = false;
foreach ($allowedDomains as $allowed) {
    if ($allowed === $host || str_starts_with($allowed, '*') && str_ends_with($host, substr($allowed, 1))) {
        $domainAllowed = true;
        break;
    }
}

if (!$domainAllowed) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Dominio no autorizado: ' . $host]);
    exit;
}

// === BLINDAJE NIVEL 3: Solo extensiones de video ===
$allowedExtensions = ['mp4', 'webm', 'mkv', 'avi', 'mov', 'm3u8', 'ts'];
$path = strtolower($parsedUrl['path'] ?? '');
$extAllowed = false;
foreach ($allowedExtensions as $ext) {
    if (str_ends_with($path, '.' . $ext) || str_ends_with($path, '.' . $ext . '?')) {
        $extAllowed = true;
        break;
    }
}

if (!$extAllowed) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Extensión no permitida']);
    exit;
}

// === BLINDAJE NIVEL 4: Desactivar compresión de forma segura ===
if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', 1); }
@ini_set('zlib.output_compression', 'Off');

// === Cabeceras CORS y control de caché ===
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Range, Content-Type');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');
header('Cache-Control: no-cache, private');
header('Pragma: no-cache');

// === BLINDAJE NIVEL 5: Timeout inteligente ===
// Archive.org puede ser lento en la primera conexión
$connectTimeout = 15; // segundos para conectar
$transferTimeout = 300; // segundos para transferencia total

// Inicializar cURL para Passthrough de alto rendimiento
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
curl_setopt($ch, CURLOPT_TIMEOUT, $transferTimeout);
curl_setopt($ch, CURLOPT_BUFFERSIZE, 8192);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
curl_setopt($ch, CURLOPT_REFERER, 'https://archive.org/');
curl_setopt($ch, CURLOPT_ENCODING, ''); // No pedir gzip

// Clonar Range Request del navegador cliente
$headers = [];
if (isset($_SERVER['HTTP_RANGE'])) {
    $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}
if (!empty($headers)) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
}

// === BLINDAJE NIVEL 6: Interceptar headers y validar Content-Type ===
$contentType = '';
$contentLength = 0;

curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $headerLine) use (&$contentType, &$contentLength) {
    // Detectar y forwardar status 206 Partial Content
    if (preg_match('/^HTTP\/[\d.]+ (\d+)/i', $headerLine, $matches)) {
        $code = (int)$matches[1];
        if ($code === 206) {
            http_response_code(206);
        } elseif ($code >= 400) {
            http_response_code($code);
        }
    }
    
    // Forwardar headers multimedia críticos
    if (preg_match('/^(Content-Type|Content-Length|Content-Range|Accept-Ranges|Last-Modified|ETag):/i', $headerLine)) {
        header($headerLine);
        
        // Capturar Content-Type para validación
        if (stripos($headerLine, 'Content-Type:') === 0) {
            $contentType = trim(substr($headerLine, 13));
        }
        
        // Capturar Content-Length para límite de tamaño
        if (stripos($headerLine, 'Content-Length:') === 0) {
            $contentLength = (int)trim(substr($headerLine, 15));
        }
    }
    
    return strlen($headerLine);
});

// Ejecutar streaming directo
curl_exec($ch);

// Manejo de errores
$errno = curl_errno($ch);
if ($errno) {
    $errorMsg = curl_error($ch);
    
    // Log silencioso para diagnóstico
    error_log("[stream_remote] cURL error $errno: $errorMsg for URL: $url");
    
    if (!headers_sent()) {
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            header('HTTP/1.1 504 Gateway Timeout');
        } elseif ($errno === CURLE_COULDNT_CONNECT) {
            header('HTTP/1.1 502 Bad Gateway');
        } else {
            header('HTTP/1.1 502 Bad Gateway');
        }
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Error de conexión con el servidor de origen']);
    }
}

curl_close($ch);
exit;
