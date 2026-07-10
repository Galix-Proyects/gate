<?php
// HARDCORE ERROR LOGGING
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/delete_dead_servers_error.log');
error_reporting(E_ALL);

try {
    require 'auth.php';
    checkAuth();
    require 'db.php';
    header('Content-Type: application/json');

    $raw = file_get_contents('php://input');
    error_log("Raw input: " . $raw);
    
    $data = json_decode($raw, true);

    if (!$data || !is_array($data)) {
        die(json_encode(['status' => 'error', 'message' => 'Datos inválidos']));
    }

    $count = 0;
    $pdo->beginTransaction();

    foreach ($data as $item) {
        $movie_id = $item['id'];
        $column = $item['column']; // s1, s2, s3, s4, s5
        
        $db_col = '';
        if ($column === 's1') $db_col = 'archivo_path';
        elseif ($column === 's2') $db_col = 'server2';
        elseif ($column === 's3') $db_col = 'server3';
        elseif ($column === 's4') $db_col = 'server4';
        elseif ($column === 's5') $db_col = 'server5';
        else continue;

        $stmt = $pdo->prepare("UPDATE peliculas_metadata SET {$db_col} = NULL WHERE contenido_id = ? AND {$db_col} = ?");
        $stmt->execute([$movie_id, $item['url']]);
        
        $count += $stmt->rowCount();
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'deleted' => $count]);

} catch (Throwable $e) {
    error_log("FATAL ERROR: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
