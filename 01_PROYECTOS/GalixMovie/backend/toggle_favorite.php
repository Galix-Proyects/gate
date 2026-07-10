<?php
error_reporting(0);
require 'db.php';
header('Content-Type: application/json');

$contenido_id = intval($_POST['contenido_id'] ?? $_GET['contenido_id'] ?? 0);
$usuario_id   = 1;

if (!$contenido_id) die(json_encode(['status' => 'error']));

// Verificar si ya existe
$check = $pdo->prepare("SELECT id FROM favoritos WHERE usuario_id = ? AND contenido_id = ?");
$check->execute([$usuario_id, $contenido_id]);

if ($check->fetch()) {
    // Quitar de favoritos
    $pdo->prepare("DELETE FROM favoritos WHERE usuario_id = ? AND contenido_id = ?")
        ->execute([$usuario_id, $contenido_id]);
    echo json_encode(['status' => 'success', 'action' => 'removed']);
} else {
    // Añadir a favoritos
    $pdo->prepare("INSERT INTO favoritos (usuario_id, contenido_id) VALUES (?, ?)")
        ->execute([$usuario_id, $contenido_id]);
    echo json_encode(['status' => 'success', 'action' => 'added']);
}
?>
