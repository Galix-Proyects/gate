<?php
require 'auth.php';
checkAuth();
require 'db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['movie_id'], $data['col1'], $data['col2'])) {
    die(json_encode(['status' => 'error', 'message' => 'Datos inválidos']));
}

$movie_id = $data['movie_id'];
$col1 = $data['col1']; // s1..s5
$col2 = $data['col2']; // s1..s5

if ($col1 === $col2) {
    echo json_encode(['status' => 'success']);
    exit;
}

$map = [
    's1' => 'archivo_path',
    's2' => 'server2',
    's3' => 'server3',
    's4' => 'server4',
    's5' => 'server5',
];

if (!isset($map[$col1]) || !isset($map[$col2])) {
    die(json_encode(['status' => 'error', 'message' => 'Columnas inválidas']));
}

$db_col1 = $map[$col1];
$db_col2 = $map[$col2];

try {
    // 1. Obtener valores actuales
    $stmt = $pdo->prepare("SELECT {$db_col1}, {$db_col2} FROM peliculas_metadata WHERE contenido_id = ?");
    $stmt->execute([$movie_id]);
    $row = $stmt->fetch();
    
    if ($row) {
        // 2. Intercambiar valores
        $val1 = $row[$db_col2];
        $val2 = $row[$db_col1];
        
        $update = $pdo->prepare("UPDATE peliculas_metadata SET {$db_col1} = ?, {$db_col2} = ? WHERE contenido_id = ?");
        $update->execute([$val1, $val2, $movie_id]);
        
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Metadata no encontrada para esta película']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
