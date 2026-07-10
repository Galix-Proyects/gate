<?php
error_reporting(0);
require 'db.php';
header('Content-Type: application/json');

$contenido_id = intval($_POST['contenido_id'] ?? 0);
$tiempo       = intval($_POST['tiempo'] ?? 0);
$total        = intval($_POST['total'] ?? 0);
$usuario_id   = 1;

if (!$contenido_id) {
    die(json_encode(['status' => 'error', 'message' => 'Sin ID']));
}

// Upsert: actualiza si ya existe, inserta si no
$stmt = $pdo->prepare("
    INSERT INTO historial (usuario_id, contenido_id, tiempo_visto, total_tiempo)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE tiempo_visto = VALUES(tiempo_visto),
                            total_tiempo = VALUES(total_tiempo),
                            ultima_vez   = CURRENT_TIMESTAMP
");

// Necesitamos índice único para que ON DUPLICATE funcione
$stmt->execute([$usuario_id, $contenido_id, $tiempo, $total]);
echo json_encode(['status' => 'success']);
?>
