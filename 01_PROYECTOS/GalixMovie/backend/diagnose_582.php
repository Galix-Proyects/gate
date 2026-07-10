<?php
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 582;

echo "=== DIAGNÓSTICO PARA CONTENIDO ID $id ===\n\n";

// 1. Contenido row
$stmt = $pdo->prepare("SELECT id, tipo, titulo, sinopsis, poster_path, backdrop_path, tmdb_id, puntuacion, fecha_estreno FROM contenido WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo "1. CONTENIDO:\n";
print_r($row);

// 2. Episodes from series_metadata
$epStmt = $pdo->prepare("SELECT id, temporada, episodio, titulo_episodio, archivo_path, server2, server3, server4, server5, subtitulos_path, episode_still, episode_overview, episode_vote, duracion FROM series_metadata WHERE contenido_id = ? ORDER BY temporada, episodio");
$epStmt->execute([$id]);
$eps = $epStmt->fetchAll(PDO::FETCH_ASSOC);
echo "\n2. SERIES_METADATA EPISODIOS (" . count($eps) . "):\n";
foreach ($eps as $ep) {
    echo "  T{$ep['temporada']}E{$ep['episodio']}: {$ep['titulo_episodio']}\n";
    echo "    archivo_path: {$ep['archivo_path']}\n";
    echo "    server2: {$ep['server2']}\n";
    echo "    server3: {$ep['server3']}\n";
    echo "    server4: {$ep['server4']}\n";
    echo "    server5: {$ep['server5']}\n";
    echo "    still: {$ep['episode_still']}\n";
    echo "    exists: " . (file_exists($ep['archivo_path'] ?? '') ? 'YES' : 'NO') . "\n";
    echo "\n";
}

// 3. Episodes from peliculas_metadata (legacy)
$epStmt2 = $pdo->prepare("SELECT id, season, episode, archivo_path, server2, server3, server4, server5, hls_path FROM peliculas_metadata WHERE contenido_id = ? AND season IS NOT NULL ORDER BY season, episode");
$epStmt2->execute([$id]);
$eps2 = $epStmt2->fetchAll(PDO::FETCH_ASSOC);
echo "3. PELICULAS_METADATA EPISODIOS (" . count($eps2) . "):\n";
foreach ($eps2 as $ep) {
    echo "  T{$ep['season']}E{$ep['episode']}\n";
    echo "    archivo_path: {$ep['archivo_path']}\n";
    echo "    exists: " . (file_exists($ep['archivo_path'] ?? '') ? 'YES' : 'NO') . "\n\n";
}

// 4. Check file existence for each episode path
echo "4. FILE EXISTENCE CHECKS:\n";
$allPaths = [];
foreach ($eps as $ep) {
    foreach (['archivo_path', 'server2', 'server3', 'server4', 'server5'] as $col) {
        if (!empty($ep[$col])) {
            $path = $ep[$col];
            if (!str_starts_with($path, 'http') && !str_starts_with($path, 'gdrive:')) {
                echo "  $col: $path - " . (file_exists($path) ? 'EXISTS' : 'MISSING') . "\n";
            } else {
                echo "  $col: $path (remote)\n";
            }
        }
    }
}
foreach ($eps2 as $ep) {
    foreach (['archivo_path', 'server2', 'server3', 'server4', 'server5'] as $col) {
        if (!empty($ep[$col])) {
            $path = $ep[$col];
            if (!str_starts_with($path, 'http') && !str_starts_with($path, 'gdrive:')) {
                echo "  $col: $path - " . (file_exists($path) ? 'EXISTS' : 'MISSING') . "\n";
            } else {
                echo "  $col: $path (remote)\n";
            }
        }
    }
}

// 5. Check if there's a hero row
echo "\n5. SERIES HERO ROW:\n";
$heroStmt = $pdo->prepare("SELECT id, temporada, episodio, archivo_path FROM series_metadata WHERE contenido_id = ? AND episodio = 0");
$heroStmt->execute([$id]);
$hero = $heroStmt->fetch(PDO::FETCH_ASSOC);
if ($hero) {
    echo "  Hero row exists: ID={$hero['id']}, T{$hero['temporada']}E{$hero['episodio']}, path={$hero['archivo_path']}\n";
} else {
    echo "  No hero row (episodio=0) — may need to create one\n";
}

echo "\n=== FIN ===\n";
