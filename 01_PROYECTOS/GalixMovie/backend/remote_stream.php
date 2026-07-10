<?php
/**
 * GalixMovie — DHARMA Fix #67: Remote Video Streamer
 * Proxy ligero para archivos MP4/WebM remotos con Range Requests completos.
 * Usado exclusivamente para Archive.org y CDNs que bloquean CORS.
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, If-Range');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$url = $_GET['url'] ?? '';
if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'URL inválida']);
    exit;
}

$headers = [
    'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Accept: video/*,*/*;q=0.1',
    'Accept-Encoding: identity',
    'Referer: ' . parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . '/',
];

// Forward Range header si existe
if (isset($_SERVER['HTTP_RANGE'])) {
    $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // Stream directo
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

// Callback para separar headers del body y forwardarlos
$headerSize = 0;
$headersSent = false;

curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) use (&$headerSize, &$headersSent) {
    $len = strlen($header);
    $headerSize += $len;
    
    // Forward headers relevantes al cliente
    $trimmed = trim($header);
    if (stripos($trimmed, 'Content-Length:') === 0 ||
        stripos($trimmed, 'Content-Range:') === 0 ||
        stripos($trimmed, 'Content-Type:') === 0 ||
        stripos($trimmed, 'Accept-Ranges:') === 0 ||
        stripos($trimmed, 'Last-Modified:') === 0 ||
        stripos($trimmed, 'ETag:') === 0) {
        header($trimmed);
    }
    
    if ($trimmed === '' && !$headersSent) {
        $headersSent = true;
        // Status code
        preg_match('/HTTP\/[\d.]+ (\d+)/', $header, $matches);
        if (isset($matches[1])) {
            http_response_code((int)$matches[1]);
        }
    }
    
    return $len;
});

// Ejecutar y hacer stream del body directamente
curl_exec($ch);

if (curl_errno($ch)) {
    if (!$headersSent) {
        http_response_code(502);
        header('Content-Type: application/json');
        echo json_encode(['error' => curl_error($ch)]);
    }
}

curl_close($ch);
?>
