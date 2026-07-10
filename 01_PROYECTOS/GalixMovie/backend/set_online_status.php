<?php
require 'auth.php';
checkAuth();
require 'db.php';
header('Content-Type: application/json');

$id = $_POST['id'] ?? '';
$status = $_POST['status'] ?? '1';

if (!$id) {
    echo json_encode(["status" => "error", "message" => "ID missing"]);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE contenido SET is_online = ? WHERE id = ?");
    $stmt->execute([$status, $id]);
    echo json_encode(["status" => "success", "message" => "Status actualizado"]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
