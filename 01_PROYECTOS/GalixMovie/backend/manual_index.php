<?php
/**
 * GalixMovie - Indexación Manual / Híbrida
 * Soporta archivos locales en /media y URLs remotas (HLS/m3u8).
 */
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

require 'auth.php';
checkAuth();
require 'db.php';
header('Content-Type: application/json');$archivo  = trim($_POST['archivo'] ?? '');
$tmdb_id  = intval($_POST['tmdb_id']  ?? 0);
$tipo     = $_POST['tipo']            ?? 'movie';
$isManualInsert = ($_POST['manual_insert'] ?? '0') === '1';

$s2 = trim($_POST['server2'] ?? '');
$s3 = trim($_POST['server3'] ?? '');
$s4 = trim($_POST['server4'] ?? '');
$s5 = trim($_POST['server5'] ?? '');

// En modo manual, tmdb_id viene como 0 (el campo contenía el título, no un número)
if (!$isManualInsert && !$tmdb_id) {
    die(json_encode(['status' => 'error', 'message' => 'Faltan parámetros: tmdb_id es obligatorio']));
}
if (!$archivo && !$s2 && !$s3 && !$s4 && !$s5) {
    die(json_encode(['status' => 'error', 'message' => 'Faltan parámetros: al menos un servidor es obligatorio']));
}

$finalPath = $archivo;
$isExternal = false;
$isLocal = false;

if ($archivo) {
    $isIframe = (strpos($archivo, '<iframe') !== false);
    $isEmbed  = (strpos($archivo, '/e/') !== false || strpos($archivo, '/embed/') !== false);
    $isRemote = (strpos($archivo, 'http') === 0 || strpos($archivo, 'blob:') === 0);
    $isExternal = $isIframe || $isEmbed || $isRemote;

    if (!$isExternal) {
        $filePath = '/data/data/com.termux/files/home/BUNKER/' . basename($archivo);
        if (file_exists($filePath)) {
            $isLocal = true;
        }
    }
}

// ── RESOLUCIÓN DE METADATOS ───────────────────────────────────────────
$genero = null; // null por defecto, solo se asigna para TV en Vivo

if ($isManualInsert) {
    // ═══ MODO MANUAL: Sin consultar TMDB ═══
    $titulo   = trim($_POST['titulo_manual'] ?? 'Sin título');
    $poster   = trim($_POST['poster_manual'] ?? '') ?: null;
    $backdrop = trim($_POST['backdrop_manual'] ?? '') ?: $poster; // Fallback: usar poster como backdrop
    $sinopsis = '';
    $fecha    = date('Y-m-d');
    $year     = date('Y');
    $rating   = 0;
    $tmdb_id  = -1 * time(); // ID negativo único para evitar conflicto UNIQUE

    // Mapeo de categoría TV en Vivo
    if ($tipo === 'tv') {
        $genero = 'tv_live';
    }
} else {
    // ═══ MODO TMDB: Consulta automática ═══
    $key = 'aa99c189865340e6421390ff192384b6';
    $api_tipo = ($tipo === 'tv') ? 'movie' : $tipo; // tv sin TMDB no existe, pero por si acaso
    if ($tipo === 'series') $api_tipo = 'tv';
    $url = "https://api.themoviedb.org/3/$api_tipo/$tmdb_id?api_key=$key&language=es-MX";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    // 🧠 DHARMA FIX: Resolver problemas de DNS en Termux
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_RESOLVE, array("api.themoviedb.org:443:18.161.156.100"));
    if (defined('CURLOPT_DOH_URL')) {
        curl_setopt($ch, CURLOPT_DOH_URL, 'https://cloudflare-dns.com/dns-query');
    }
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $raw = curl_exec($ch);
    $curl_error_msg = curl_error($ch);
    curl_close($ch);

    // 🧠 DHARMA FIX: Fallback a curl nativo de consola si falla el módulo PHP cURL
    if ($raw === false || empty($raw)) {
        $cmd = "su 0 sh -c '/data/data/com.termux/files/usr/bin/curl -s -L -A '\\''Mozilla/5.0'\\'' '\\''". $url . "'\\'' ' 2>&1";
        $raw = shell_exec($cmd);
        if ($raw && (strpos($raw, '{') === 0 || strpos($raw, '[') === 0)) {
            $curl_error_msg .= " [Resuelto vía Root cURL]";
        } else {
            $curl_error_msg .= " [También falló Root cURL: " . substr($raw, 0, 100) . "]";
        }
    }

    $data = null;
    if (is_string($raw) && !empty($raw)) {
        $data = json_decode($raw, true);
    }

    if (!$data || isset($data['status_code'])) {
        $debug_msg = "TMDB ID $tmdb_id no encontrado ($tipo).";
        if ($curl_error_msg) $debug_msg .= " cURL Error: " . $curl_error_msg;
        if ($raw) $debug_msg .= " Response: " . substr($raw, 0, 100);
        die(json_encode(['status' => 'error', 'message' => $debug_msg]));
    }

    $poster   = !empty($data['poster_path'])   ? 'https://image.tmdb.org/t/p/w500'     . $data['poster_path']   : null;
    $backdrop = !empty($data['backdrop_path']) ? 'https://image.tmdb.org/t/p/original' . $data['backdrop_path'] : null;
    $titulo   = $data['title'] ?? $data['name'] ?? 'Sin título';
    $sinopsis = $data['overview'] ?? '';
    $fecha    = $data['release_date'] ?? $data['first_air_date'] ?? null;
    $year     = $fecha ? substr($fecha, 0, 4) : '';
    $rating   = $data['vote_average'] ?? 0;
}

// ── MANEJO DE RUTA Y RENOMBRADO ───────────────────────────────────────────
$finalPath = $archivo;
$renamed   = false;
$nuevo_nombre = $archivo ? basename($archivo) : 'Link Remoto/Mirror';

// ── DETECCIÓN DE SUBTÍTULOS ─────────────────────────────────────────────
$subPath = null;
if ($isLocal && isset($filePath)) {
    $subExts  = ['vtt', 'srt', 'ass'];
    $baseName = pathinfo($filePath, PATHINFO_FILENAME);
    foreach ($subExts as $se) {
        $potential = dirname($filePath) . DIRECTORY_SEPARATOR . $baseName . '.' . $se;
        if (file_exists($potential)) {
            $subPath = $potential;
            break;
        }
    }
}

if ($isLocal && isset($filePath)) {
    $ext         = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $safeTitle   = preg_replace('/[<>:"\/\\|?*]/', '', $titulo);
    $nuevo_nombre = $safeTitle . ($year ? " ($year)" : '') . '.' . $ext;
    $newFilePath = dirname($filePath) . DIRECTORY_SEPARATOR . $nuevo_nombre;

    if ($filePath !== $newFilePath && !file_exists($newFilePath)) {
        if (rename($filePath, $newFilePath)) {
            $finalPath = $newFilePath;
            $renamed = true;

            // Renombrar también el subtítulo si existía
            if ($subPath) {
                $subExt = pathinfo($subPath, PATHINFO_EXTENSION);
                $newSubName = $safeTitle . ($year ? " ($year)" : '') . '.' . $subExt;
                $newSubPath = dirname($finalPath) . DIRECTORY_SEPARATOR . $newSubName;
                if (rename($subPath, $newSubPath)) {
                    $subPath = $newSubPath;
                }
            }
        }
    } else if (file_exists($newFilePath)) {
        $finalPath = $newFilePath;
    }
    $finalPath = realpath($finalPath) ?: $finalPath;
}

// ── INSERTAR EN BASE DE DATOS ─────────────────────────────────────────────
$db_tipo = $tipo;
if ($tipo === 'tv') {
    // Si es modo manual, tv = TV en Vivo -> va a películas
    // Si es legacy/TMDB, tv = Series -> va a series
    $db_tipo = $isManualInsert ? 'movie' : 'series';
}

try {
    $pdo->prepare("INSERT INTO contenido (tipo, titulo, sinopsis, poster_path, backdrop_path, fecha_estreno, tmdb_id, puntuacion, genero) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) 
                   ON DUPLICATE KEY UPDATE 
                   titulo=VALUES(titulo), sinopsis=VALUES(sinopsis), poster_path=VALUES(poster_path), 
                   backdrop_path=VALUES(backdrop_path), puntuacion=VALUES(puntuacion), genero=COALESCE(VALUES(genero), genero)")
        ->execute([$db_tipo, $titulo, $sinopsis, $poster, $backdrop, $fecha, $tmdb_id, $rating, $genero]);

    $contenido_id = $pdo->lastInsertId() ?: $pdo->query("SELECT id FROM contenido WHERE tmdb_id=$tmdb_id")->fetchColumn();

    $s2 = $_POST['server2'] ?? null;
    $s3 = $_POST['server3'] ?? null;
    $s4 = $_POST['server4'] ?? null;
    $s5 = $_POST['server5'] ?? null;
    $remoteSubs = $_POST['subtitles'] ?? null;
    $finalSubs = $subPath ?: $remoteSubs;

    if ($db_tipo === 'series') {
        $temporada = intval($_POST['temporada'] ?? 1);
        $episodio  = intval($_POST['episodio'] ?? 1);
        
        // Evitar duplicados eliminando el episodio anterior si existe
        $pdo->prepare("DELETE FROM series_metadata WHERE contenido_id=? AND temporada=? AND episodio=?")
            ->execute([$contenido_id, $temporada, $episodio]);

        $pdo->prepare("INSERT INTO series_metadata (contenido_id, temporada, episodio, archivo_path, server2, server3, server4, server5, subtitulos_path) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$contenido_id, $temporada, $episodio, $finalPath, $s2, $s3, $s4, $s5, $finalSubs]);
    } else {
        $pdo->prepare("INSERT INTO peliculas_metadata (contenido_id, archivo_path, server2, server3, server4, server5, subtitulos_path) 
                       VALUES (?, ?, ?, ?, ?, ?, ?) 
                       ON DUPLICATE KEY UPDATE 
                       archivo_path=VALUES(archivo_path),
                       server2=VALUES(server2),
                       server3=VALUES(server3),
                       server4=VALUES(server4),
                       server5=VALUES(server5),
                       subtitulos_path=VALUES(subtitulos_path)")
            ->execute([$contenido_id, $finalPath, $s2, $s3, $s4, $s5, $finalSubs]);
    }

    echo json_encode([
        'status'  => 'success',
        'id'      => $contenido_id,
        'titulo'  => $titulo,
        'rating'  => $rating,
        'poster'  => $poster,
        'renamed' => $renamed,
        'nuevo_nombre' => $nuevo_nombre
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
