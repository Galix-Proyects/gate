<?php
/**
 * Galix Autopilot - Get Cached Mirrors API
 * Devuelve los mirrors cacheados válidos para una película o episodio y una semilla.
 * ─────────────────────────────────────────────────────────────────
 */
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'db.php';
require_once 'cache_manager.php';

$contenido_id = $_GET['contenido_id'] ?? '';
$episodio_id  = $_GET['episodio_id'] ?? '';
$seed_url     = $_GET['seed_url'] ?? '';

if (!$contenido_id || !$seed_url) {
    echo json_encode(["status" => "error", "message" => "Missing required parameters"]);
    exit;
}

// Convertir episodio_id vacío a null
if ($episodio_id === '') {
    $episodio_id = null;
}

$mirrors = getResolvedCache($pdo, $contenido_id, $episodio_id, $seed_url);

echo json_encode([
    "status"  => "success",
    "mirrors" => $mirrors
]);
?>
