<?php
/**
 * GalixMovie - Módulo de Autenticación Proactiva
 * ─────────────────────────────────────────────────────────────────
 */
$is_secure = true; // Por defecto seguro para Cloudflare/Iframes
if (isset($_SERVER['HTTP_HOST']) && preg_match('/^(localhost|127\.0\.0\.1|100\.\d+\.\d+\.\d+|192\.168\.\d+\.\d+)(:\d+)?$/', $_SERVER['HTTP_HOST'])) {
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        $is_secure = false; // Solo desactivar si es acceso local directo por HTTP
    }
}

session_set_cookie_params([
    'samesite' => $is_secure ? 'None' : 'Lax',
    'secure' => $is_secure,
    'httponly' => true
]);

$sid = $_GET['sid'] ?? $_POST['sid'] ?? $_COOKIE['PHPSESSID'] ?? null;
if ($sid && preg_match('/^[a-zA-Z0-9,-]+$/', $sid)) {
    session_id($sid);
}

session_start();

if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if(!file_exists($path)) return;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach($lines as $line) {
            if(strpos(trim($line), '#') === 0) continue;
            $parts = explode('=', $line, 2);
            if(count($parts) == 2) {
                $_ENV[trim($parts[0])] = trim($parts[1]);
            }
        }
    }
}

loadEnv(__DIR__ . '/../../../00_SISTEMA/.env');

function checkAuth() {
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
        exit;
    }
}

function isAdmin() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}
?>
