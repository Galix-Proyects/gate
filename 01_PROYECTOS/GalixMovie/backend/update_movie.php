<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

require 'auth.php';
checkAuth();
require 'db.php';
header('Content-Type: application/json');

$id      = $_POST['id'] ?? null;
$tmdb_id = $_POST['tmdb_id'] ?? null;

if (!$id || !$tmdb_id) {
    die(json_encode(['status' => 'error', 'message' => 'Datos incompletos']));
}

try {
    // 1. Obtener datos nuevos
    $tipo = $_POST['tipo'] ?? 'movie';

    if ($tmdb_id < 0) {
        // Modo Manual: Recuperar metadatos actuales de la BD (evitar llamar a TMDB)
        $check_manual = $pdo->prepare("SELECT titulo, poster_path, backdrop_path, sinopsis, puntuacion FROM contenido WHERE id = ?");
        $check_manual->execute([$id]);
        $manual_data = $check_manual->fetch();
        
        if (!$manual_data) {
            die(json_encode(['status' => 'error', 'message' => 'Contenido manual no encontrado en DB.']));
        }
        $titulo   = $manual_data['titulo'];
        $poster   = $manual_data['poster_path'];
        $backdrop = $manual_data['backdrop_path'];
        $sinopsis = $manual_data['sinopsis'];
        $rating   = $manual_data['puntuacion'];
    } else {
        // Modo TMDB
        $TMDB_KEY = 'aa99c189865340e6421390ff192384b6';
        $api_tipo = ($tipo === 'series' || $tipo === 'tv') ? 'tv' : 'movie';
        $url = "https://api.themoviedb.org/3/$api_tipo/$tmdb_id?api_key=$TMDB_KEY&language=es-MX";
        
        function fetchTMDB($url, &$curl_err = null) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
            // 🧠 DHARMA FIX: Resolver problemas de DNS en Termux usando DNS over HTTPS y RESOLVE directo
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch, CURLOPT_RESOLVE, array("api.themoviedb.org:443:18.161.156.100"));
            if (defined('CURLOPT_DOH_URL')) {
                curl_setopt($ch, CURLOPT_DOH_URL, 'https://cloudflare-dns.com/dns-query');
            }
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $res = curl_exec($ch);
            $curl_err = curl_error($ch);
            curl_close($ch);
            
            // 🧠 DHARMA FIX: Fallback a curl nativo de consola si falla el módulo PHP cURL
            if ($res === false || empty($res)) {
                $cmd = "su 0 sh -c '/data/data/com.termux/files/usr/bin/curl -s -L -A '\\''Mozilla/5.0'\\'' '\\''" . $url . "'\\'' ' 2>&1";
                $res = shell_exec($cmd);
                if ($res && (strpos($res, '{') === 0 || strpos($res, '[') === 0)) {
                    $curl_err .= " [Resuelto vía Root cURL]";
                } else {
                    $curl_err .= " [También falló Root cURL: " . substr($res, 0, 100) . "]";
                }
            }
            return $res;
        }

        $curl_error = '';
        $res = fetchTMDB($url, $curl_error);
        $data = json_decode($res, true);
        
        if (!$data || isset($data['status_code'])) {
            $api_tipo = ($api_tipo === 'movie') ? 'tv' : 'movie';
            $url = "https://api.themoviedb.org/3/$api_tipo/$tmdb_id?api_key=$TMDB_KEY&language=es-MX";
            $res = fetchTMDB($url, $curl_error);
            $data = json_decode($res, true);
        }
        
        if (!$data || isset($data['status_code'])) {
            $debug_msg = 'ID de TMDB no válido.';
            if ($curl_error) $debug_msg .= ' Error de Red (cURL): ' . $curl_error;
            if (!$data) $debug_msg .= ' JSON vacío. RAW: ' . substr($res, 0, 100);
            else if (isset($data['status_message'])) $debug_msg .= ' TMDB Error: ' . $data['status_message'];
            die(json_encode(['status' => 'error', 'message' => $debug_msg]));
        }

        $titulo   = $data['title'] ?? $data['name'];
        $poster   = "https://image.tmdb.org/t/p/w500" . $data['poster_path'];
        $backdrop = isset($data['backdrop_path']) ? "https://image.tmdb.org/t/p/original" . $data['backdrop_path'] : null;
        $sinopsis = $data['overview'];
        $rating   = $data['vote_average'];
    }
    
    // 🖊️ Override poster/backdrop si el admin los envió manualmente
    $posterFromPost = trim($_POST['poster_path'] ?? '');
    $backdropFromPost = trim($_POST['backdrop_path'] ?? '');
    if ($posterFromPost !== '') $poster = $posterFromPost;
    if ($backdropFromPost !== '') $backdrop = $backdropFromPost;

    // 🖊️ RENOMBRAR ARCHIVO LOCAL SI SE SOLICITÓ
    $archivo_path = $_POST['archivo_path'] ?? null;
    $renamed = false;
    $newFilename = trim($_POST['new_filename'] ?? '');
    if ($newFilename !== '' && $archivo_path !== null && !preg_match('/^(http|extract:|sniper:|backend\/)/', $archivo_path)) {
        $oldFull = $archivo_path;
        $dir = dirname($oldFull);
        $newFull = $dir . DIRECTORY_SEPARATOR . $newFilename;
        if ($oldFull !== $newFull && file_exists($oldFull) && !file_exists($newFull)) {
            if (@rename($oldFull, $newFull)) {
                $archivo_path = $newFull;
                $renamed = true;
                // Renombrar subtítulos asociados
                $oldBase = pathinfo($oldFull, PATHINFO_FILENAME);
                $newBase = pathinfo($newFull, PATHINFO_FILENAME);
                foreach (['srt', 'vtt', 'ass', 'ssa', 'sub'] as $ext) {
                    $oldSub = $dir . DIRECTORY_SEPARATOR . $oldBase . '.' . $ext;
                    $newSub = $dir . DIRECTORY_SEPARATOR . $newBase . '.' . $ext;
                    if (file_exists($oldSub) && !file_exists($newSub)) {
                        @rename($oldSub, $newSub);
                    }
                }
            }
        }
    }

    // 🛡️ HEALTH-CHECK FAIL-SAFE (Native PHP)
    $servers = [$archivo_path, $_POST['server2'] ?? null, $_POST['server3'] ?? null, $_POST['server4'] ?? null, $_POST['server5'] ?? null];
    
    $anyOnline = false;
    $ctx = stream_context_create(['http' => ['timeout' => 2]]); // Solo 2 segundos de espera total
    
    foreach ($servers as $s) {
        if (empty($s)) continue;
        if (strpos($s, 'extract:') === 0) { $anyOnline = true; break; }
        if (!filter_var($s, FILTER_VALIDATE_URL)) { $anyOnline = true; break; }
        
        // 🧠 DHARMA FIX: Usar cURL Root para el health-check (get_headers cuelga por la falta de grupo inet)
        $cmd = "su 0 sh -c '/data/data/com.termux/files/usr/bin/curl -I -s --max-time 2 -A '\\''Mozilla/5.0'\\'' '\\''" . escapeshellcmd($s) . "'\\'' ' 2>/dev/null";
        $headers = shell_exec($cmd);
        if ($headers && strpos($headers, '200') !== false) {
            $anyOnline = true;
            break;
        }
    }

    // Si la verificación falló por timeout o error, pero el usuario puso un link, 
    // le damos el beneficio de la duda para no bloquear el sistema.
    $newOnlineStatus = $anyOnline ? 1 : ( (!empty($archivo_path)) ? 1 : 0 );

    // 2. Actualizar en la base de datos
    $stmt = $pdo->prepare("UPDATE contenido SET titulo = ?, tmdb_id = ?, poster_path = ?, backdrop_path = ?, sinopsis = ?, puntuacion = ?, is_online = ?, tipo = ? WHERE id = ?");
    $stmt->execute([$titulo, $tmdb_id, $poster, $backdrop, $sinopsis, $rating, $newOnlineStatus, $tipo, $id]);

    // 3. Actualizar metadata
    $server2 = $_POST['server2'] ?? null;
    $server3 = $_POST['server3'] ?? null;
    $server4 = $_POST['server4'] ?? null;
    $server5 = $_POST['server5'] ?? null;
    $meta_id = $_POST['meta_id'] ?? null;

    if ($tipo === 'series' || $tipo === 'tv') {
        $temporada = intval($_POST['temporada'] ?? 1);
        $episodio  = intval($_POST['episodio'] ?? 1);
        
        // Limpiar duplicados de ambas tablas para esta serie y episodio
        $pdo->prepare("DELETE FROM series_metadata WHERE contenido_id=? AND temporada=? AND episodio=?")->execute([$id, $temporada, $episodio]);
        $pdo->prepare("DELETE FROM peliculas_metadata WHERE contenido_id=? AND season=? AND episode=?")->execute([$id, $temporada, $episodio]);
        
        $stmt2 = $pdo->prepare("INSERT INTO series_metadata (contenido_id, temporada, episodio, archivo_path, server2, server3, server4, server5) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt2->execute([$id, $temporada, $episodio, $archivo_path, $server2, $server3, $server4, $server5]);
    } else {
        // Lógica de Autocreación para Películas
        if (empty($meta_id)) {
            $check = $pdo->prepare("SELECT id FROM peliculas_metadata WHERE contenido_id = ?");
            $check->execute([$id]);
            $existingMeta = $check->fetch();
            if ($existingMeta) {
                $stmt2 = $pdo->prepare("UPDATE peliculas_metadata SET archivo_path = ?, server2 = ?, server3 = ?, server4 = ?, server5 = ? WHERE id = ?");
                $stmt2->execute([$archivo_path, $server2, $server3, $server4, $server5, $existingMeta['id']]);
            } else {
                $stmt2 = $pdo->prepare("INSERT INTO peliculas_metadata (contenido_id, archivo_path, server2, server3, server4, server5) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt2->execute([$id, $archivo_path, $server2, $server3, $server4, $server5]);
            }
        } else {
            $stmt2 = $pdo->prepare("UPDATE peliculas_metadata SET archivo_path = ?, server2 = ?, server3 = ?, server4 = ?, server5 = ? WHERE id = ?");
            $stmt2->execute([$archivo_path, $server2, $server3, $server4, $server5, $meta_id]);
        }
    }

    echo json_encode([
        'status' => 'success', 
        'message' => 'Contenido actualizado correctamente',
        'data' => ['titulo' => $titulo, 'poster' => $poster, 'renamed' => $renamed]
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
