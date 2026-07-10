<?php
// =============================================================================
// get_cast.php — Proxy de reparto TMDB para GalixMovie Roku
// Devuelve hasta 10 actores principales con foto y nombre del personaje.
// Uso: get_cast.php?tmdb_id=12345&type=movie|tv
// =============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=3600'); // cachear 1 hora

$TMDB_KEY = 'aa99c189865340e6421390ff192384b6';

$tmdb_id = isset($_GET['tmdb_id']) ? trim($_GET['tmdb_id']) : '';
$type    = isset($_GET['type'])    ? trim($_GET['type'])    : 'movie';

// Validar
if ($tmdb_id === '' || !preg_match('/^\d+$/', $tmdb_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'tmdb_id invalido', 'cast' => []]);
    exit;
}

if ($type !== 'tv') $type = 'movie';

// Construir URL de TMDB
// movie: /3/movie/{id}/credits
// tv:    /3/tv/{id}/credits
$url = "https://api.themoviedb.org/3/{$type}/{$tmdb_id}/credits"
     . "?api_key={$TMDB_KEY}&language=es-MX";

$ctx = stream_context_create([
    'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    'http' => [
        'ignore_errors' => true,
        'timeout'       => 6,
        'header'        => "User-Agent: GalixMovie-Roku/1.0\r\nAccept-Encoding: gzip\r\n"
    ]
]);

$raw = @file_get_contents($url, false, $ctx);

if ($raw === false) {
    http_response_code(502);
    echo json_encode(['error' => 'No se pudo conectar con TMDB', 'cast' => []]);
    exit;
}

// TMDB puede responder con gzip (si el header Accept-Encoding lo activa)
// Intentar descomprimir si es binario
if (substr($raw, 0, 2) === "\x1f\x8b") {
    $raw = gzdecode($raw);
}

$data = json_decode($raw, true);

if (!$data || !isset($data['cast'])) {
    // Puede ser que sea TV con 'aggregate_cast'
    $altUrl = "https://api.themoviedb.org/3/{$type}/{$tmdb_id}/aggregate_credits"
            . "?api_key={$TMDB_KEY}&language=es-MX";
    $raw2 = @file_get_contents($altUrl, false, $ctx);
    if ($raw2 !== false) {
        if (substr($raw2, 0, 2) === "\x1f\x8b") $raw2 = gzdecode($raw2);
        $data2 = json_decode($raw2, true);
        if ($data2 && isset($data2['cast'])) {
            $data = $data2;
        }
    }
}

if (!$data || !isset($data['cast'])) {
    echo json_encode(['cast' => [], 'source' => 'tmdb_no_data']);
    exit;
}

// Filtrar y limitar a 10 actores principales con foto
$actors = [];
foreach ($data['cast'] as $member) {
    $name    = isset($member['name'])         ? $member['name']         : '';
    $char    = isset($member['character'])    ? $member['character']    : '';
    $profile = isset($member['profile_path']) ? $member['profile_path'] : '';
    $order   = isset($member['order'])        ? (int)$member['order']   : 99;

    if ($name === '') continue;

    // TV aggregate_credits tiene roles[]
    if ($char === '' && isset($member['roles']) && count($member['roles']) > 0) {
        $char = $member['roles'][0]['character'] ?? '';
    }

    $actors[] = [
        'name'         => $name,
        'character'    => $char,
        'profile_path' => $profile,
        'order'        => $order
    ];
}

// Ordenar por orden de aparición y limitar a 10
usort($actors, function($a, $b) { return $a['order'] - $b['order']; });
$actors = array_slice($actors, 0, 10);

// Limpiar el campo order antes de devolver
foreach ($actors as &$a) unset($a['order']);

echo json_encode([
    'tmdb_id' => $tmdb_id,
    'type'    => $type,
    'cast'    => $actors
]);
