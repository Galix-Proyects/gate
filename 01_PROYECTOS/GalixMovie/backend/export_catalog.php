<?php
error_reporting(0);
header('Content-Type: application/json');

try {
    $isCLI = (php_sapi_name() === 'cli');

    if (!$isCLI) {
        require_once 'auth.php';
        checkAuth();
    }
    require_once 'db.php';

    $repoRoot = realpath(__DIR__ . '/../../../');
    $cacheDir = $repoRoot . '/backdrops/';
    $catalogDir = $repoRoot . '/catalog/';
    $webpDir = $catalogDir . '/backdrops/';

    if (!is_dir($catalogDir)) mkdir($catalogDir, 0755, true);
    if (!is_dir($webpDir)) mkdir($webpDir, 0755, true);

    $rows = $pdo->query("
        SELECT c.id, c.tipo, c.titulo, c.sinopsis, c.poster_path, c.backdrop_path,
               c.fecha_estreno, c.tmdb_id, c.puntuacion, c.created_at
        FROM contenido c
        WHERE c.is_online = 1
        ORDER BY c.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $episodes_by_series = [];
    $epStmt = $pdo->query("
        SELECT contenido_id, temporada, episodio,
               titulo_episodio, episode_still, episode_overview, episode_vote
        FROM series_metadata
        WHERE archivo_path IS NOT NULL AND archivo_path != ''
    ");
    if ($epStmt) {
        while ($ep = $epStmt->fetch(PDO::FETCH_ASSOC)) {
            $cid = $ep['contenido_id'];
            unset($ep['contenido_id']);
            $episodes_by_series[$cid][] = $ep;
        }
    }

    $movies = [];
    $backdropCount = 0;
    $seenSeries = [];

    foreach ($rows as $row) {
        $backdropHash = null;
        $backdropSrc = $row['backdrop_path'] ?? '';
        if ($backdropSrc !== '') {
            $filename = basename(parse_url($backdropSrc, PHP_URL_PATH));
            if ($filename !== '' && $filename !== '.') {
                $hash = md5($filename) . '.jpg';
                $srcPath = $cacheDir . $hash;
                if (file_exists($srcPath)) {
                    $backdropHash = $hash;
                    $webpPath = $webpDir . md5($filename) . '.webp';
                    if (!file_exists($webpPath)) {
                        $webpDest = $webpDir . md5($filename) . '.webp';
                        shell_exec("cwebp -q 80 " . escapeshellarg($srcPath) . " -o " . escapeshellarg($webpDest) . " 2>/dev/null");
                        if (file_exists($webpDest)) $backdropCount++;
                    }
                }
            }
        }

        $movie = [
            'id' => (int)$row['id'],
            'tipo' => $row['tipo'],
            'titulo' => $row['titulo'],
            'sinopsis' => $row['sinopsis'],
            'poster' => $row['poster_path'],
            'backdrop' => $backdropHash ? '/catalog/backdrops/' . md5(basename(parse_url($row['backdrop_path'], PHP_URL_PATH))) . '.webp' : null,
            'fecha_estreno' => $row['fecha_estreno'],
            'tmdb_id' => (int)$row['tmdb_id'],
            'puntuacion' => $row['puntuacion'] ? (float)$row['puntuacion'] : null
        ];

        if ($row['tipo'] === 'series' && !isset($seenSeries[$row['id']])) {
            $seenSeries[$row['id']] = true;
            $eps = $episodes_by_series[$row['id']] ?? [];
            usort($eps, function($a, $b) {
                return ($a['temporada'] <=> $b['temporada']) ?: ($a['episodio'] <=> $b['episodio']);
            });
            $sanitized = [];
            foreach ($eps as $ep) {
                $sanitized[] = [
                    'temporada' => (int)$ep['temporada'],
                    'episodio' => (int)$ep['episodio'],
                    'titulo' => $ep['titulo_episodio'],
                    'still' => $ep['episode_still'],
                    'overview' => $ep['episode_overview'],
                    'puntuacion' => $ep['episode_vote'] ? (float)$ep['episode_vote'] : null
                ];
            }
            $movie['episodes'] = $sanitized;
        }

        $movies[] = $movie;
    }

    $catalog = [
        'updated' => date('Y-m-d H:i:s'),
        'count' => count($movies),
        'movies' => $movies
    ];

    file_put_contents($catalogDir . 'catalog.json', json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $gitOutput = '';
    $gitPush = shell_exec("cd " . escapeshellarg($repoRoot) . " && git add catalog/ 2>&1");
    $gitCommit = shell_exec("cd " . escapeshellarg($repoRoot) . " && git commit -m '📦 Catalog update: {$catalog['count']} movies' --allow-empty 2>&1");
    $gitPushResult = shell_exec("cd " . escapeshellarg($repoRoot) . " && GIT_TERMINAL_PROMPT=0 git push origin main 2>&1");
    $gitOutput = trim($gitPush . "\n" . $gitCommit . "\n" . $gitPushResult);

    echo json_encode([
        'status' => 'success',
        'movies' => count($movies),
        'backdrops' => $backdropCount,
        'git' => $gitOutput
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
