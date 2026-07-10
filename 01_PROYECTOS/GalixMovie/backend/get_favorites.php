<?php
error_reporting(0);
require 'db.php';
header('Content-Type: application/json');

$usuario_id = 1;

$checkOculta = $pdo->query("SHOW COLUMNS FROM `contenido` LIKE 'oculta'")->fetch();
$ocultaFilter = $checkOculta ? " AND (c.oculta IS NULL OR c.oculta = 0)" : "";

$stmt = $pdo->prepare("
    SELECT c.id, c.titulo, c.poster_path, c.tipo, c.puntuacion
    FROM favoritos f
    JOIN contenido c ON c.id = f.contenido_id
    WHERE f.usuario_id = ? AND c.is_online = 1 {$ocultaFilter}
    ORDER BY f.created_at DESC
");
$stmt->execute([$usuario_id]);
$rows = $stmt->fetchAll();

echo json_encode(['status' => 'success', 'favoritos' => $rows]);
?>
