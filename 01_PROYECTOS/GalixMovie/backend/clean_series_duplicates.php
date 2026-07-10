<?php
/**
 * GalixMovie - DB Clean Series Duplicates
 * ─────────────────────────────────────────────────────────────────
 * Este script elimina los registros de la tabla peliculas_metadata 
 * que corresponden a episodios de series (tipo = 'series' o 'tv').
 * Esto corrige de raíz el error donde rutas antiguas locales 
 * en peliculas_metadata opacaban a las rutas nuevas en series_metadata.
 * ─────────────────────────────────────────────────────────────────
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: application/json');

try {
    require 'db.php';
    require 'auth.php';

    // Opcional: Validar autenticación de administrador
    // checkAuth();

    // 1. Obtener los IDs de contenidos que son series o tv
    $stmt = $pdo->query("SELECT id, titulo FROM contenido WHERE tipo IN ('series', 'tv')");
    $series = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $seriesIds = array_column($series, 'id');

    if (empty($seriesIds)) {
        echo json_encode([
            'status' => 'success',
            'message' => 'No se encontraron contenidos de tipo serie o tv en la base de datos.',
            'cleaned_count' => 0
        ]);
        exit;
    }

    // 2. Contar cuántos duplicados hay en peliculas_metadata para estos IDs
    $inClause = implode(',', array_map('intval', $seriesIds));
    $countStmt = $pdo->query("SELECT COUNT(*) FROM peliculas_metadata WHERE contenido_id IN ($inClause) AND season IS NOT NULL");
    $duplicateCount = $countStmt->fetchColumn();

    // 3. Eliminar los duplicados
    $deleteStmt = $pdo->prepare("DELETE FROM peliculas_metadata WHERE contenido_id IN ($inClause) AND season IS NOT NULL");
    $deleteStmt->execute();
    $deletedRows = $deleteStmt->rowCount();

    echo json_encode([
        'status' => 'success',
        'message' => 'Limpieza completada con éxito.',
        'series_detectadas' => count($series),
        'duplicados_encontrados' => intval($duplicateCount),
        'registros_eliminados' => $deletedRows
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
