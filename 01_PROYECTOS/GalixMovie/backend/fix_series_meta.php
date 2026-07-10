<?php
error_reporting(0);
require 'db.php';
header('Content-Type: text/plain; charset=utf-8');

// 1. Migrate series_metadata -> peliculas_metadata with UNIQUE KEY handling
$smRows = $pdo->query("SELECT * FROM series_metadata")->fetchAll(PDO::FETCH_ASSOC);
echo "Series metadata rows to migrate: " . count($smRows) . "\n";

$inserted = 0;
foreach ($smRows as $r) {
    try {
        $pdo->prepare("INSERT INTO peliculas_metadata (contenido_id, archivo_path, server2, server3, server4, server5, subtitulos_path, season, episode, file_size)
            VALUES (?,?,?,?,?,?,?,?,?,0)
            ON DUPLICATE KEY UPDATE archivo_path = VALUES(archivo_path), season = VALUES(season), episode = VALUES(episode)")
            ->execute([
                $r['contenido_id'],
                $r['archivo_path'],
                $r['server2'],
                $r['server3'],
                $r['server4'],
                $r['server5'],
                $r['subtitulos_path'],
                $r['temporada'],
                $r['episodio'],
            ]);
        $inserted++;
        echo "  Migrated: contenido_id={$r['contenido_id']} ep=S{$r['temporada']}E{$r['episodio']}\n";
    } catch (Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}

// 2. Add a "hero" metadata row for series (without season/episode) for the LEFT JOIN
$series = $pdo->query("SELECT id FROM contenido WHERE tipo='series' AND is_online=1")->fetchAll(PDO::FETCH_COLUMN);
echo "\nSeries without hero meta: ";
$added = 0;
foreach ($series as $sid) {
    // Check if already has a non-episode metadata row
    $hasHero = $pdo->prepare("SELECT COUNT(*) FROM peliculas_metadata WHERE contenido_id = ? AND season IS NULL AND episode IS NULL");
    $hasHero->execute([$sid]);
    if ($hasHero->fetchColumn() > 0) {
        echo "H";
        continue;
    }
    
    // Find first episode to use its path as hero path
    $firstEp = $pdo->prepare("SELECT archivo_path FROM peliculas_metadata WHERE contenido_id = ? AND season IS NOT NULL ORDER BY season, episode LIMIT 1");
    $firstEp->execute([$sid]);
    $path = $firstEp->fetchColumn();
    
    if ($path) {
        try {
            $pdo->prepare("INSERT INTO peliculas_metadata (contenido_id, archivo_path, file_size) VALUES (?,?,0)")
                ->execute([$sid, $path]);
            $added++;
            echo "A";
        } catch (Exception $e) {
            echo "E";
        }
    } else {
        // Try series_metadata
        $smPath = $pdo->prepare("SELECT archivo_path FROM series_metadata WHERE contenido_id = ? LIMIT 1");
        $smPath->execute([$sid]);
        $path2 = $smPath->fetchColumn();
        if ($path2) {
            try {
                $pdo->prepare("INSERT INTO peliculas_metadata (contenido_id, archivo_path, file_size) VALUES (?,?,0)")
                    ->execute([$sid, $path2]);
                $added++;
                echo "s";
            } catch (Exception $e) {
                echo "e";
            }
        } else {
            echo ".";
        }
    }
}
echo "\nAdded hero meta: $added\n";

// 3. Summary
$totalMeta = $pdo->query("SELECT COUNT(*) FROM peliculas_metadata")->fetchColumn();
$seriesMeta = $pdo->query("SELECT COUNT(*) FROM series_metadata")->fetchColumn();
$pelSeriesEps = $pdo->query("SELECT COUNT(*) FROM peliculas_metadata WHERE season IS NOT NULL")->fetchColumn();
echo "\nFinal counts:\n";
echo "  peliculas_metadata: $totalMeta\n";
echo "  series_metadata: $seriesMeta\n";
echo "  pel_meta with season: $pelSeriesEps\n";
