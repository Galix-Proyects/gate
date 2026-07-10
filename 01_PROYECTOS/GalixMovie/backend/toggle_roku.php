<?php
error_reporting(0);
require 'db.php';
require 'auth.php';
checkAuth();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$visible_roku = (int)($_POST['visible_roku'] ?? 0);

if (!$id) {
    echo json_encode(['status' => 'error', 'message' => 'ID inválido']);
    exit;
}

$stmt = $pdo->prepare('UPDATE contenido SET visible_roku = ? WHERE id = ?');
$stmt->execute([$visible_roku, $id]);

if ($stmt->rowCount() > 0) {
    echo json_encode(['status' => 'success', 'visible_roku' => $visible_roku]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'No se actualizó ningún registro']);
}
