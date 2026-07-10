<?php
require 'auth.php';
checkAuth();
require 'db.php';
header('Content-Type: application/json');

$id = intval($_POST['id'] ?? 0);

if (!$id) {
    die(json_encode(['status' => 'error', 'message' => 'ID no proporcionado']));
}

try {
    // 1. Obtener las rutas de los archivos físicos ANTES de borrar el registro (ya que podría haber un CASCADE)
    $stmtMeta = $pdo->prepare("SELECT archivo_path, subtitulos_path FROM peliculas_metadata WHERE contenido_id = ?");
    $stmtMeta->execute([$id]);
    $meta = $stmtMeta->fetch(PDO::FETCH_ASSOC);

    // 2. Borrar de la base de datos (contenido)
    $stmt = $pdo->prepare("DELETE FROM contenido WHERE id = ?");
    $stmt->execute([$id]);

    // 3. Borrar los archivos físicos del Disco Duro
    $deletedFiles = 0;
    if ($meta) {
        if (!empty($meta['archivo_path']) && file_exists($meta['archivo_path'])) {
            @unlink($meta['archivo_path']);
            $deletedFiles++;
        }
        if (!empty($meta['subtitulos_path']) && file_exists($meta['subtitulos_path'])) {
            @unlink($meta['subtitulos_path']);
            $deletedFiles++;
        }
    }

    echo json_encode(['status' => 'success', 'deleted_physical_files' => $deletedFiles]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
