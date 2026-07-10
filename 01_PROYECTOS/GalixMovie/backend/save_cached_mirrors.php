<?php
/**
 * Galix Autopilot - Save Cached Mirrors API
 * Registra o actualiza mirrors resueltos en el caché de la base de datos.
 * ─────────────────────────────────────────────────────────────────
 */
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'db.php';
require_once 'cache_manager.php';

$contenido_id    = $_POST['contenido_id'] ?? $_GET['contenido_id'] ?? '';
$episodio_id     = $_POST['episodio_id'] ?? $_GET['episodio_id'] ?? '';
$seed_url        = $_POST['seed_url'] ?? $_GET['seed_url'] ?? '';
$resolved_url    = $_POST['resolved_url'] ?? $_GET['resolved_url'] ?? '';
$tipo_resolucion = $_POST['tipo_resolucion'] ?? $_GET['tipo_resolucion'] ?? 'hls';
$idioma          = $_POST['idioma'] ?? $_GET['idioma'] ?? 'Latino';
$servidor_nombre = $_POST['servidor_nombre'] ?? $_GET['servidor_nombre'] ?? 'Desconocido';
$calidad         = $_POST['calidad'] ?? $_GET['calidad'] ?? 'HD';

if (!$contenido_id || !$seed_url || !$resolved_url) {
    echo json_encode(["status" => "error", "message" => "Missing required parameters"]);
    exit;
}

// Convertir episodio_id vacío a null
if ($episodio_id === '') {
    $episodio_id = null;
}

$success = saveResolvedCache($pdo, $contenido_id, $episodio_id, $seed_url, $resolved_url, $tipo_resolucion, $idioma, $servidor_nombre, $calidad);

if ($success) {
    echo json_encode(["status" => "success", "message" => "Mirror saved to cache successfully"]);
} else {
    echo json_encode(["status" => "error", "message" => "Failed to save mirror to cache"]);
}
?>
