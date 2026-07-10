<?php
error_reporting(0);
header('Content-Type: application/json');

try {
    require 'db.php';

    $usuario_id = 1;

    $checkOculta = $pdo->query("SHOW COLUMNS FROM `contenido` LIKE 'oculta'")->fetch();
    $ocultaFilter = $checkOculta ? " AND (c.oculta IS NULL OR c.oculta = 0)" : "";

    $stmt = $pdo->prepare("
        SELECT h.contenido_id, h.tiempo_visto, h.total_tiempo, h.ultima_vez,
               c.id, c.titulo, c.poster_path, c.tipo, c.sinopsis
        FROM historial h
        JOIN contenido c ON c.id = h.contenido_id
        WHERE h.usuario_id = ? AND h.tiempo_visto > 0 AND h.tiempo_visto < (h.total_tiempo - 30) AND c.is_online = 1 {$ocultaFilter}
        ORDER BY h.ultima_vez DESC
        LIMIT 12
    ");
    $stmt->execute([$usuario_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $episodes = $pdo->query("SELECT * FROM series_metadata")->fetchAll(PDO::FETCH_ASSOC);
    $episodes_by_series = [];
    foreach ($episodes as $ep) {
        $ep['meta_id'] = $ep['id'];
        $episodes_by_series[$ep['contenido_id']][] = $ep;
    }

    foreach ($rows as &$row) {
        if ($row['tipo'] === 'series' || $row['tipo'] === 'tv') {
            $row['episodes'] = $episodes_by_series[$row['id']] ?? [];
            $firstMeta = $pdo->prepare("SELECT archivo_path, server2, server3, server4, server5 FROM peliculas_metadata WHERE contenido_id = ? ORDER BY season, episode LIMIT 1");
            $firstMeta->execute([$row['id']]);
            $meta = $firstMeta->fetch(PDO::FETCH_ASSOC);
            if ($meta) {
                $row['archivo_path'] = $meta['archivo_path'];
                $row['server2'] = $meta['server2'];
                $row['server3'] = $meta['server3'];
                $row['server4'] = $meta['server4'];
                $row['server5'] = $meta['server5'];
            }
        } else {
            $meta = $pdo->prepare("SELECT archivo_path, server2, server3, server4, server5 FROM peliculas_metadata WHERE contenido_id = ? LIMIT 1");
            $meta->execute([$row['id']]);
            $m = $meta->fetch(PDO::FETCH_ASSOC);
            if ($m) {
                $row['archivo_path'] = $m['archivo_path'];
                $row['server2'] = $m['server2'];
                $row['server3'] = $m['server3'];
                $row['server4'] = $m['server4'];
                $row['server5'] = $m['server5'];
            }
        }
    }

    echo json_encode(['status' => 'success', 'historial' => $rows]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
