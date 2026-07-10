<?php
/**
 * GalixMovie - Backfill Episodios
 * Actualiza títulos reales, still, overview y vote de episodios existentes
 * usando el endpoint de temporada de TMDB (1 llamada por temporada).
 * 
 * Uso:   php backfill_episodes.php
 *        (también accesible vía web: ?action=run)
 */
ini_set('display_errors', '0');
error_reporting(0);
set_time_limit(600);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require 'db.php';
require 'auth.php';
if (php_sapi_name() !== 'cli') checkAuth();

define('TMDB_API_KEY', $_ENV['TMDB_API_KEY'] ?? 'aa99c189865340e6421390ff192384b6');
define('TMDB_BASE',    'https://api.themoviedb.org/3');
define('TMDB_IMG',     'https://image.tmdb.org/t/p/w500');

/** Obtener todos los episodios de una temporada desde TMDB */
function tmdbGetSeasonEpisodes(int $seriesId, int $season): array {
    $url = TMDB_BASE . "/tv/$seriesId/season/$season?" . http_build_query([
        'api_key' => TMDB_API_KEY,
        'language' => 'es-MX'
    ]);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (is_resource($ch)) curl_close($ch);
    if ($code !== 200) return [];
    $data = json_decode($raw, true);
    $episodes = $data['episodes'] ?? [];
    $map = [];
    foreach ($episodes as $ep) {
        $num = (int)$ep['episode_number'];
        $map[$num] = [
            'name'     => $ep['name'] ?? '',
            'still'    => !empty($ep['still_path']) ? TMDB_IMG . $ep['still_path'] : null,
            'overview' => $ep['overview'] ?? '',
            'vote'     => $ep['vote_average'] ?? null,
        ];
    }
    return $map;
}

try {
    // ── 1. Obtener episodios que necesitan backfill ──
    $stmt = $pdo->query("
        SELECT sm.id, sm.contenido_id, sm.temporada, sm.episodio, sm.titulo_episodio, sm.episode_still, c.tmdb_id
        FROM series_metadata sm
        JOIN contenido c ON c.id = sm.contenido_id
        WHERE (sm.titulo_episodio LIKE 'Episodio %' OR sm.episode_still IS NULL)
          AND c.tmdb_id > 0
        ORDER BY sm.contenido_id, sm.temporada, sm.episodio
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = count($rows);

    // ── 2. Agrupar por contenido_id + temporada ──
    $groups = [];
    foreach ($rows as $r) {
        $gKey = $r['contenido_id'] . '_' . $r['temporada'];
        if (!isset($groups[$gKey])) {
            $groups[$gKey] = [
                'contenido_id' => $r['contenido_id'],
                'tmdb_id'      => (int)$r['tmdb_id'],
                'temporada'    => $r['temporada'],
                'episodios'    => [],
            ];
        }
        $groups[$gKey]['episodios'][] = $r;
    }

    $updated = 0;
    $errors = [];

    // ── 3. Procesar cada grupo (1 llamada TMDB por temporada) ──
    foreach ($groups as $g) {
        $epMap = tmdbGetSeasonEpisodes($g['tmdb_id'], $g['temporada']);
        if (empty($epMap)) {
            $errors[] = "Temporada {$g['temporada']} del contenido ID {$g['contenido_id']}: sin datos TMDB";
            continue;
        }
        foreach ($g['episodios'] as $ep) {
            $data = $epMap[$ep['episodio']] ?? null;
            if (!$data) {
                $errors[] = "Episodio {$ep['episodio']} Temp {$ep['temporada']} (ID {$ep['id']}): sin datos TMDB";
                continue;
            }
            $upd = $pdo->prepare("UPDATE series_metadata SET titulo_episodio=?, episode_still=?, episode_overview=?, episode_vote=? WHERE id=?");
            $upd->execute([$data['name'], $data['still'], $data['overview'], $data['vote'], $ep['id']]);
            $updated++;
        }
    }

    echo json_encode([
        'status'   => 'success',
        'total'    => $total,
        'updated'  => $updated,
        'groups'   => count($groups),
        'api_calls' => count($groups),
        'errors'   => $errors,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'status' => 'error', 'message' => $e->getMessage() . ' (line ' . $e->getLine() . ')'
    ]);
}
