<?php
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/config.php';

@ini_set("display_errors","0");
set_time_limit(0);
header('Content-Type: application/json; charset=utf-8');

define('TELEGRAM_DAEMON_URL', 'http://127.0.0.1:5050');

$action = $_GET['tg'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$endpoints = [
    'status'      => ['path' => '/status',               'method' => 'GET'],
    'sources'     => ['path' => '/sources',              'method' => 'GET'],
    'add_source'  => ['path' => '/sources',              'method' => 'POST'],
    'remove_source' => ['path' => '/sources/{id}',       'method' => 'DELETE'],
    'download'    => ['path' => '/download',             'method' => 'POST'],
    'scan'        => ['path' => '/scan',                 'method' => 'POST'],
    'downloads'   => ['path' => '/downloads',            'method' => 'GET'],
    'auth_status' => ['path' => '/auth/status',          'method' => 'GET'],
    'auth_code'   => ['path' => '/auth/code',            'method' => 'POST'],
    'auth_2fa'    => ['path' => '/auth/2fa',             'method' => 'POST'],
    'auth_resend' => ['path' => '/auth/resend', 'method' => 'POST'],
    'preview'     => ['path' => '/preview/{id}',         'method' => 'GET'],
    'info'        => ['path' => '/info/{id}',            'method' => 'GET'],
    'import_forum' => ['path' => '/import/forum',        'method' => 'POST'],
    'search_videos' => ['path' => '/search_videos',      'method' => 'GET'],
    'roku_check'  => ['path' => '/check_roku',            'method' => 'POST'],
    'download_cancel'        => ['path' => '/download/{id}',      'method' => 'DELETE'],
    'cancel_all_downloads'   => ['path' => '/downloads',         'method' => 'DELETE'],
    'backfill_channel'       => ['path' => '/backfill/channel/{id}', 'method' => 'POST'],
    'backfill_channel_status' => ['path' => '/backfill/channel/{id}/status', 'method' => 'GET'],
    'scan_channel'           => ['path' => '/scan/channel/{id}',  'method' => 'POST'],
];

if (!isset($endpoints[$action])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Unknown telegram action', 'available_actions' => array_keys($endpoints)]);
    exit;
}

$ep = $endpoints[$action];

$body = null;
if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
    $body = file_get_contents('php://input');
}

$path = $ep['path'];
if (isset($_GET['id']) && strpos($path, '{id}') !== false) {
    $path = str_replace('{id}', (int)$_GET['id'], $path);
}

$queryParams = [];
foreach (['limit', 'offset_id', 'q', 'mode'] as $p) {
    if (isset($_GET[$p])) {
        $queryParams[$p] = $_GET[$p];
    }
}
$url = TELEGRAM_DAEMON_URL . $path;
if ($queryParams) {
    $url .= '?' . http_build_query($queryParams);
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 600,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_CUSTOMREQUEST => $ep['method'],
]);

if ($body) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Error conectando con Telegram Daemon: ' . $error]);
    exit;
}

http_response_code($httpCode);
echo $response;
