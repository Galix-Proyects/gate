<?php
require 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
    exit;
}

$id = intval($_POST['id'] ?? 0);
$oculta = intval($_POST['oculta'] ?? 0);

if (!$id) {
    echo json_encode(['status' => 'error', 'message' => 'ID requerido']);
    exit;
}

$check = $pdo->query("SHOW COLUMNS FROM `contenido` LIKE 'oculta'")->fetch();
if (!$check) {
    $pdo->exec("ALTER TABLE `contenido` ADD COLUMN oculta TINYINT(1) DEFAULT 0");
}

$stmt = $pdo->prepare("UPDATE contenido SET oculta = ? WHERE id = ?");
$stmt->execute([$oculta, $id]);

echo json_encode(['status' => 'success', 'id' => $id, 'oculta' => $oculta]);
