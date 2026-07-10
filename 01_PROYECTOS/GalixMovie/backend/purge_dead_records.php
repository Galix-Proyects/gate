<?php
// HARDCORE ERROR LOGGING
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/purge_dead_records_error.log');
error_reporting(E_ALL);

try {
    require 'auth.php';
    checkAuth();
    require 'db.php';
    header('Content-Type: application/json');

    // 1. Obtener todas las películas y sus metadatos
    $movies = $pdo->query("SELECT c.id, c.titulo, m.archivo_path, m.server2, m.server3, m.server4, m.server5 
                           FROM contenido c 
                           JOIN peliculas_metadata m ON c.id = m.contenido_id")->fetchAll(PDO::FETCH_ASSOC);

    // 2. Obtener todos los episodios y sus metadatos
    $episodes = $pdo->query("SELECT s.id as episode_id, c.id as contenido_id, c.titulo, s.temporada, s.episodio, s.archivo_path, s.server2, s.server3, s.server4, s.server5 
                             FROM contenido c 
                             JOIN series_metadata s ON c.id = s.contenido_id")->fetchAll(PDO::FETCH_ASSOC);

    $deletedMovies = 0;
    $deletedEpisodes = 0;
    $deletedSeries = 0;
    $deletedTitles = [];

    $pdo->beginTransaction();

    // 3. Procesar películas
    foreach ($movies as $movie) {
        $movieId = $movie['id'];
        $title = $movie['titulo'];
        $servers = [
            $movie['archivo_path'], $movie['server2'], $movie['server3'], $movie['server4'], $movie['server5']
        ];
        
        $hasMissingLocalFile = false;
        foreach ($servers as $url) {
            if (empty($url)) continue;
            // No es seed y no empieza con http
            $isSeed = strpos($url, 'extract:') === 0 || strpos($url, 'sniper:') === 0;
            $isHttp = strpos($url, 'http') === 0;
            if (!$isSeed && !$isHttp) {
                // Es un archivo local. Verificar si existe.
                if (!file_exists($url)) {
                    $hasMissingLocalFile = true;
                    break;
                }
            }
        }

        if ($hasMissingLocalFile) {
            // Eliminar película de contenido (cascada borrará peliculas_metadata, historial, favoritos)
            $stmt = $pdo->prepare("DELETE FROM contenido WHERE id = ?");
            $stmt->execute([$movieId]);
            $deletedMovies++;
            $deletedTitles[] = $title;
        }
    }

    // 4. Procesar episodios de series
    foreach ($episodes as $ep) {
        $episodeId = $ep['episode_id'];
        $contenidoId = $ep['contenido_id'];
        $title = $ep['titulo'] . " (T" . $ep['temporada'] . " E" . $ep['episodio'] . ")";
        $servers = [
            $ep['archivo_path'], $ep['server2'], $ep['server3'], $ep['server4'], $ep['server5']
        ];

        $hasMissingLocalFile = false;
        foreach ($servers as $url) {
            if (empty($url)) continue;
            $isSeed = strpos($url, 'extract:') === 0 || strpos($url, 'sniper:') === 0;
            $isHttp = strpos($url, 'http') === 0;
            if (!$isSeed && !$isHttp) {
                if (!file_exists($url)) {
                    $hasMissingLocalFile = true;
                    break;
                }
            }
        }

        if ($hasMissingLocalFile) {
            // Eliminar episodio específico
            $stmt = $pdo->prepare("DELETE FROM series_metadata WHERE id = ?");
            $stmt->execute([$episodeId]);
            $deletedEpisodes++;
            $deletedTitles[] = $title;
        }
    }

    // 5. Eliminar series que se quedaron sin episodios
    $emptySeries = $pdo->query("SELECT id, titulo FROM contenido WHERE tipo = 'series' AND NOT EXISTS (SELECT 1 FROM series_metadata WHERE series_metadata.contenido_id = contenido.id)")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($emptySeries as $series) {
        $stmt = $pdo->prepare("DELETE FROM contenido WHERE id = ?");
        $stmt->execute([$series['id']]);
        $deletedSeries++;
        $deletedTitles[] = $series['titulo'] . " (Serie vaciada)";
    }

    // 6. Actualizar el archivo de progreso del autopilot para que el reporte visual refleje la limpieza
    $progressFile = null;
    $candidates = [
        sys_get_temp_dir() . '/autopilot_progress.json',
        __DIR__ . '/autopilot_progress.json',
    ];
    foreach ($candidates as $path) {
        if (file_exists($path)) {
            $progressFile = $path;
            break;
        }
    }

    if ($progressFile) {
        $raw = file_get_contents($progressFile);
        $data = json_decode($raw, true);
        if ($data && isset($data['report']) && isset($data['report']['static_dead_detected'])) {
            $cleanedList = [];
            foreach ($data['report']['static_dead_detected'] as $d) {
                // Si la URL contiene "(ARCHIVO FALTANTE)", fue un archivo local que ya purgamos
                if (strpos($d['url'], '(ARCHIVO FALTANTE)') === false) {
                    $cleanedList[] = $d;
                }
            }
            $data['report']['static_dead_detected'] = $cleanedList;
            file_put_contents($progressFile, json_encode($data), LOCK_EX);
        }
    }

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'deleted_movies_count' => $deletedMovies,
        'deleted_episodes_count' => $deletedEpisodes,
        'deleted_series_count' => $deletedSeries,
        'deleted_titles' => $deletedTitles
    ]);

} catch (Throwable $e) {
    error_log("FATAL ERROR IN PURGE: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
