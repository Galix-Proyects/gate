<?php
error_reporting(0);
header('Content-Type: application/json');

try {
    require 'db.php';

    $tipo = $_GET['tipo'] ?? 'all';
    $id = $_GET['id'] ?? null;
    $isAdmin = isset($_GET['admin']) && $_GET['admin'] == '1';

    $checkSub = $pdo->query("SHOW COLUMNS FROM `peliculas_metadata` LIKE 'subtitulos_path'")->fetch();
    $subCol = $checkSub ? ", m.subtitulos_path" : "";

    $checkGenero = $pdo->query("SHOW COLUMNS FROM `contenido` LIKE 'genero'")->fetch();
    $generoCol = $checkGenero ? ", c.genero" : ", NULL as genero";

    $checkOculta = $pdo->query("SHOW COLUMNS FROM `contenido` LIKE 'oculta'")->fetch();
    $ocultaCol = $checkOculta ? ", c.oculta" : ", 0 as oculta";

    foreach (['episode_still' => 'VARCHAR(500) DEFAULT NULL', 'episode_overview' => 'TEXT DEFAULT NULL', 'episode_vote' => 'DECIMAL(3,1) DEFAULT NULL'] as $col => $def) {
        $check = $pdo->query("SHOW COLUMNS FROM `series_metadata` LIKE '$col'")->fetch();
        if (!$check) {
            $pdo->query("ALTER TABLE series_metadata ADD COLUMN $col $def");
        }
    }

    $epStillCol   = ", episode_still";
    $epOverviewCol = ", episode_overview";
    $epVoteCol     = ", episode_vote";

    $sql = "SELECT c.id, c.tipo, c.titulo, c.sinopsis, c.poster_path, c.backdrop_path, c.fecha_estreno, c.tmdb_id, c.puntuacion, c.created_at, c.is_online {$ocultaCol} {$generoCol},
                   m.id as meta_id, m.archivo_path, m.server2, m.server3, m.server4, m.server5 {$subCol}, m.duracion, m.hls_path, m.file_size, c.visible_roku
            FROM contenido c 
            LEFT JOIN peliculas_metadata m ON c.id = m.contenido_id AND m.id = (SELECT MIN(m2.id) FROM peliculas_metadata m2 WHERE m2.contenido_id = c.id)
            WHERE 1=1 ";

    if (!$isAdmin) {
        $sql .= " AND c.is_online = 1";
        $sql .= " AND c.visible_roku = 1";
    }
    if ($tipo !== 'all') $sql .= " AND c.tipo = :tipo";
    if ($id !== null) $sql .= " AND c.id = :id";

    $sql .= " ORDER BY created_at DESC";

    $stmt = $pdo->prepare($sql);
    $params = [];
    if ($tipo !== 'all') $params[':tipo'] = $tipo;
    if ($id !== null) $params[':id'] = $id;
    $stmt->execute($params);
    
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $episodes_by_series = [];
    if ($id !== null) {
        $epStmt2 = $pdo->prepare("SELECT contenido_id, id as meta_id, temporada, episodio, CASE WHEN titulo_episodio LIKE 'Episodio %' THEN NULL ELSE titulo_episodio END as titulo_episodio {$epStillCol} {$epOverviewCol} {$epVoteCol}, archivo_path, server2, server3, server4, server5, subtitulos_path, duracion as hls_path, NULL as file_size FROM series_metadata WHERE archivo_path IS NOT NULL AND archivo_path != '' AND contenido_id = :id");
        $epStmt2->execute([':id' => $id]);
    } else {
        $epStmt2 = $pdo->query("SELECT contenido_id, id as meta_id, temporada, episodio, CASE WHEN titulo_episodio LIKE 'Episodio %' THEN NULL ELSE titulo_episodio END as titulo_episodio {$epStillCol} {$epOverviewCol} {$epVoteCol}, archivo_path, server2, server3, server4, server5, subtitulos_path, duracion as hls_path, NULL as file_size FROM series_metadata WHERE archivo_path IS NOT NULL AND archivo_path != ''");
    }
    
    if ($epStmt2) {
        while ($ep = $epStmt2->fetch(PDO::FETCH_ASSOC)) {
            $key = $ep['contenido_id'] . '_' . $ep['temporada'] . '_' . $ep['episodio'];
            $episodes_by_series[$key] = $ep;
        }
    }

    if ($id !== null) {
        $epStmt = $pdo->prepare("SELECT contenido_id, id as meta_id, season as temporada, episode as episodio, NULL as titulo_episodio, NULL as episode_still, NULL as episode_overview, NULL as episode_vote, archivo_path, server2, server3, server4, server5, subtitulos_path, hls_path, file_size FROM peliculas_metadata WHERE season IS NOT NULL AND episode IS NOT NULL AND contenido_id = :id");
        $epStmt->execute([':id' => $id]);
    } else {
        $epStmt = $pdo->query("SELECT contenido_id, id as meta_id, season as temporada, episode as episodio, NULL as titulo_episodio, NULL as episode_still, NULL as episode_overview, NULL as episode_vote, archivo_path, server2, server3, server4, server5, subtitulos_path, hls_path, file_size FROM peliculas_metadata WHERE season IS NOT NULL AND episode IS NOT NULL");
    }
    
    if ($epStmt) {
        while ($ep = $epStmt->fetch(PDO::FETCH_ASSOC)) {
            $key = $ep['contenido_id'] . '_' . $ep['temporada'] . '_' . $ep['episodio'];
            if (!isset($episodes_by_series[$key])) {
                $episodes_by_series[$key] = $ep;
            }
        }
    }
    $grouped = [];
    foreach ($episodes_by_series as $ep) {
        $cid = $ep['contenido_id'];
        $ep['id'] = $cid;
        unset($ep['contenido_id']);
        $grouped[$cid][] = $ep;
    }
    $episodes_by_series = $grouped;

    $cacheDir = __DIR__ . '/../../../backdrops/';
    function backdropLocalUrl($url, $cacheDir) {
        if (empty($url)) return null;
        $filename = basename(parse_url($url, PHP_URL_PATH));
        if ($filename === '' || $filename === '.') return null;
        $hash = md5($filename) . '.jpg';
        if (file_exists($cacheDir . $hash)) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            return $protocol . '://' . $host . '/backdrops/' . $hash;
        }
        return null;
    }

    $localBase = __DIR__ . '/..';
    $seenSeries = [];
    $filtered = [];
    foreach ($rows as $row) {
        $row['backdrop_url'] = backdropLocalUrl($row['backdrop_path'] ?? '', $cacheDir);
        $archivoPath = trim($row['archivo_path'] ?? '');
        $isLocalFile = !$isAdmin && !empty($archivoPath) && !str_starts_with($archivoPath, 'http') && str_contains($archivoPath, 'BUNKER');
        
        if ($isLocalFile && $row['tipo'] !== 'series') {
            $fullPath = $archivoPath;
            if (!str_starts_with($archivoPath, '/')) {
                $fullPath = $localBase . '/' . $archivoPath;
            }
            $fullPath = realpath($fullPath) ?: $fullPath;
            if (class_exists('Normalizer')) {
                $normalized = Normalizer::normalize($fullPath, Normalizer::FORM_D);
                if ($normalized !== false) {
                    $fullPath = $normalized;
                }
            }
            if (!file_exists($fullPath)) {
                $dir = dirname($fullPath);
                $targetBase = basename($fullPath);
                $found = false;
                if (is_dir($dir)) {
                    $dh = opendir($dir);
                    while (($entry = readdir($dh)) !== false) {
                        if ($entry === '.' || $entry === '..') continue;
                        $entryNorm = class_exists('Normalizer') ? Normalizer::normalize($entry, Normalizer::FORM_D) : $entry;
                        if (strcasecmp($entryNorm, $targetBase) === 0) {
                            $fullPath = $dir . '/' . $entry;
                            $found = true;
                            break;
                        }
                    }
                    closedir($dh);
                }
                if (!$found) {
                    continue;
                }
            }
        }
        
        if ($row['tipo'] === 'series') {
            if (isset($seenSeries[$row['id']])) continue;
            $seenSeries[$row['id']] = true;
            $row['episodes'] = $episodes_by_series[$row['id']] ?? [];
            usort($row['episodes'], function($a, $b) {
                return ($a['temporada'] <=> $b['temporada']) ?: ($a['episodio'] <=> $b['episodio']);
            });
            // Poblar archivo_path/server2-5 desde el primer episodio con ruta
            // para que el S1 del admin muestre el icono de la serie
            $firstEp = null;
            foreach ($row['episodes'] as $ep) {
                if (!empty($ep['archivo_path'])) { $firstEp = $ep; break; }
            }
            if ($firstEp) {
                $row['archivo_path'] = $firstEp['archivo_path'];
                $row['server2'] = $firstEp['server2'] ?? null;
                $row['server3'] = $firstEp['server3'] ?? null;
                $row['server4'] = $firstEp['server4'] ?? null;
                $row['server5'] = $firstEp['server5'] ?? null;
            }
        }
        
        $filtered[] = $row;
    }
    
    echo json_encode([
        'status' => 'success',
        'movies' => $filtered
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
