<?php
error_reporting(0);

// === ?file= endpoint for HLS segments (cached en hls_temp/seg_cache/) ===
if (isset($_GET['file'])) {
    $filePath = $_GET['file'];
    if (strpos($filePath, '..') !== false) { http_response_code(403); exit; }
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeMap = ['m3u8' => 'application/vnd.apple.mpegurl', 'ts' => 'video/MP2T'];
    $mime = $mimeMap[$ext] ?? 'application/octet-stream';

    // Cache de segmentos .ts en disco
    if ($ext === 'ts') {
        $segCacheDir = '/data/data/com.termux/files/home/hls_temp/seg_cache';
        $segHash = md5($filePath);
        $segCacheFile = $segCacheDir . '/' . $segHash[0] . '/' . $segHash[1] . '/' . $segHash;
        if (file_exists($segCacheFile) && filesize($segCacheFile) > 1000) {
            header('Content-Type: ' . $mime);
            header('Access-Control-Allow-Origin: *');
            header('Content-Length: ' . filesize($segCacheFile));
            readfile($segCacheFile);
            exit;
        }
    }

    header('Content-Type: ' . $mime);
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-cache');
    $parts = array_map('rawurlencode', explode('/', $filePath));
    $proxyUrl = 'http://127.0.0.1:8080/gdrive/' . implode('/', $parts);
    $ch = curl_init($proxyUrl);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_NOSIGNAL, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if ($ext === 'ts' && isset($segCacheFile)) {
        // Stream a buffer + cache
        $tmp = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $chunk) use (&$tmp) {
            $tmp .= $chunk;
            echo $chunk;
            flush();
            return strlen($chunk);
        });
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200 && strlen($tmp) > 1000) {
            @mkdir(dirname($segCacheFile), 0777, true);
            file_put_contents($segCacheFile, $tmp, LOCK_EX);
        }
    } else {
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) { echo $chunk; flush(); return strlen($chunk); });
        curl_exec($ch);
    }
    exit;
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, If-Range, Content-Type');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require 'db.php';

// === ?hls=1&path= for playlist .m3u8 (with stale-while-revalidate cache + pre-warming) ===
if (isset($_GET['hls']) && isset($_GET['path'])) {
    $hlsPath = $_GET['path'];
    if (strpos($hlsPath, '..') !== false) { http_response_code(403); exit; }

    $dirName = basename(dirname($hlsPath));
    $episodeCacheDir = '/data/data/com.termux/files/home/hls_temp/' . $dirName;
    if (!file_exists($episodeCacheDir)) { @mkdir($episodeCacheDir, 0777, true); }
    $cacheFile = $episodeCacheDir . '/playlist.m3u8';
    $cacheTTL  = 1800;
    $segMatches = [[], []];
    $content = null;

    if (file_exists($cacheFile) && filesize($cacheFile) > 100) {
        $content = file_get_contents($cacheFile);
    }

    $cacheAge = $content ? (time() - filemtime($cacheFile)) : PHP_INT_MAX;
    $needsRegen = !$content || $cacheAge > $cacheTTL;

    if ($needsRegen) {
        $ch = curl_init('http://127.0.0.1:9091/' . rawurlencode($hlsPath));
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($raw && $httpCode === 200 && strlen($raw) >= 100) {
            preg_match_all('/^(playlist\d+\.ts)$/m', $raw, $segMatches);
            $dir = dirname($hlsPath);
            $content = preg_replace_callback('/^(playlist\d+\.ts)$/m', function($m) use ($dir) {
                $parts = array_map('rawurlencode', explode('/', $dir . '/' . $m[1]));
                return 'stream.php?file=' . implode('/', $parts);
            }, $raw);
            $content = preg_replace('/^(#EXT-X-MEDIA-SEQUENCE:\d+)/m', "$0\n#EXT-X-PLAYLIST-TYPE:VOD", $content);
            file_put_contents($cacheFile, $content, LOCK_EX);
        } elseif (!$content) {
            http_response_code(502);
            echo json_encode(['error' => 'Error obteniendo manifest de rclone (HTTP ' . $httpCode . ')']);
            exit;
        }
    }

    $isStale = $needsRegen && $cacheAge < PHP_INT_MAX;

    // Send response (fresh, stale, or from cache)
    header_remove();
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-cache');
    echo $content;

    if (!$isStale) {
        // Fresh: pre-warm first 10 segments in background
        if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
        ignore_user_abort(true);
        set_time_limit(60);
        if (!empty($segMatches[0])) {
            $baseDir = dirname($hlsPath);
            for ($i = 0; $i < min(10, count($segMatches[0])); $i++) {
                $segFile = $baseDir . '/' . $segMatches[0][$i];
                $ch = curl_init('http://127.0.0.1:9091/' . rawurlencode($segFile));
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_NOSIGNAL, true);
                curl_exec($ch);
            }
        }
        exit;
    }

    // Stale: regenerate in background after sending stale response
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
    ignore_user_abort(true);
    set_time_limit(30);
    $ch = curl_init('http://127.0.0.1:9091/' . rawurlencode($hlsPath));
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($raw && $httpCode === 200 && strlen($raw) >= 100) {
        $dir = dirname($hlsPath);
        $newContent = preg_replace_callback('/^(playlist\d+\.ts)$/m', function($m) use ($dir) {
            $parts = array_map('rawurlencode', explode('/', $dir . '/' . $m[1]));
            return 'stream.php?file=' . implode('/', $parts);
        }, $raw);
        $newContent = preg_replace('/^(#EXT-X-MEDIA-SEQUENCE:\d+)/m', "$0\n#EXT-X-PLAYLIST-TYPE:VOD", $newContent);
        file_put_contents($cacheFile, $newContent, LOCK_EX);
    }
    exit;
}

// --- Video streaming by ID ---
$id = $_GET['id'] ?? null;
if (!$id || !is_numeric($id)) {
    http_response_code(400);
    echo json_encode(['error' => 'ID requerido']);
    exit;
}
$id = (int)$id;
$season = isset($_GET['season']) ? (int)$_GET['season'] : null;
$episode = isset($_GET['episode']) ? (int)$_GET['episode'] : null;

$filePath = null;
$fileSize = null;

try {
    if ($season !== null && $episode !== null) {
        $st = $pdo->prepare("SELECT archivo_path FROM series_metadata WHERE contenido_id = ? AND temporada = ? AND episodio = ? LIMIT 1");
        $st->execute([$id, $season, $episode]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $st = $pdo->prepare("SELECT archivo_path, file_size FROM peliculas_metadata WHERE contenido_id = ? AND season = ? AND episode = ? LIMIT 1");
            $st->execute([$id, $season, $episode]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        }
        if ($row) {
            $filePath = $row['archivo_path'];
            $fileSize = $row['file_size'] ?? null;
        }
    } else {
        $st = $pdo->prepare("SELECT archivo_path, file_size FROM peliculas_metadata WHERE contenido_id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $filePath = $row['archivo_path'];
            $fileSize = $row['file_size'] ?? null;
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error en BD']);
    exit;
}

if (!$filePath) {
    http_response_code(404);
    echo json_encode(['error' => 'Archivo no encontrado']);
    exit;
}

// Handle gdrive: URLs via rclone HTTP proxy
if (strpos($filePath, 'gdrive:') === 0) {
    $gdrivePath = substr($filePath, 7);
    $gdriveUrl = 'http://127.0.0.1:9091/' . ltrim($gdrivePath, '/');
    $ext = strtolower(pathinfo($gdrivePath, PATHINFO_EXTENSION));
    $isM3u = ($ext === 'm3u8' || $ext === 'm3u');

    // For m3u8: buffer full content, rewrite segment URLs, then serve (con cache)
    if ($isM3u) {
        $dirName = basename(dirname($filePath));
        $episodeCacheDir = '/data/data/com.termux/files/home/hls_temp/' . $dirName;
        if (!file_exists($episodeCacheDir)) { @mkdir($episodeCacheDir, 0777, true); }
        $cacheFile = $episodeCacheDir . '/playlist.m3u8';
        $cacheTTL  = 1800;
        $content = null;

        // Try cache primero
        if (file_exists($cacheFile) && filesize($cacheFile) > 100) {
            $cacheAge = time() - filemtime($cacheFile);
            if ($cacheAge < $cacheTTL) {
                $content = file_get_contents($cacheFile);
            }
        }

        if (!$content) {
            // Cache miss → fetch desde rclone
            $m3uRcloneUrl = 'http://127.0.0.1:9091/' . rawurlencode(ltrim($gdrivePath, '/'));
            $ch = curl_init($m3uRcloneUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            $raw = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode < 200 || $httpCode >= 300) {
                http_response_code(502);
                echo json_encode(['error' => 'Error fetching HLS playlist from gdrive']);
                exit;
            }

            // Rewrite segment URLs: playlist###.ts → stream.php?file=
            preg_match_all('/^(playlist\d+\.ts)$/m', $raw, $segMatches);
            $dir = dirname(ltrim($gdrivePath, '/'));
            $dir = str_replace('\\', '/', $dir);
            if ($dir === '.') $dir = '';
            $content = preg_replace_callback('/^(playlist\d+\.ts)$/m', function($m) use ($dir) {
                $parts = array_map('rawurlencode', explode('/', $dir . '/' . $m[1]));
                return 'stream.php?file=' . implode('/', $parts);
            }, $raw);
            $content = preg_replace('/^(#EXT-X-MEDIA-SEQUENCE:\d+)/m', "$0\n#EXT-X-PLAYLIST-TYPE:VOD", $content);
            file_put_contents($cacheFile, $content, LOCK_EX);
        }

        header('Content-Type: application/vnd.apple.mpegurl');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-cache');
        echo $content;

        // Pre-warming: primeros 5 segments en background
        if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
        ignore_user_abort(true);
        set_time_limit(60);
        preg_match_all('/stream\.php\?file=([^\s]+)/', $content, $segUrls);
        foreach (array_slice($segUrls[1] ?? [], 0, 5) as $segPath) {
            $segCacheDir = '/data/data/com.termux/files/home/hls_temp/seg_cache';
            $segHash = md5(urldecode($segPath));
            $segCacheFile = $segCacheDir . '/' . $segHash[0] . '/' . $segHash[1] . '/' . $segHash;
            if (file_exists($segCacheFile) && filesize($segCacheFile) > 1000) continue;
            $parts = array_map('rawurlencode', explode('/', $segPath));
            $ch = curl_init('http://127.0.0.1:8080/gdrive/' . implode('/', $parts));
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $data = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 200 && strlen($data) > 1000) {
                @mkdir(dirname($segCacheFile), 0777, true);
                file_put_contents($segCacheFile, $data, LOCK_EX);
            }
        }
        exit;
    }

    $streamRcloneUrl = 'http://127.0.0.1:9091/' . rawurlencode(ltrim($gdrivePath, '/'));
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $streamRcloneUrl);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 524288);

    $rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
    $reqHeaders = ['User-Agent: GalixMovie-Roku/1.0'];
    if ($rangeHeader) {
        $reqHeaders[] = 'Range: ' . $rangeHeader;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);
        curl_setopt($ch, CURLOPT_RANGE, $rangeHeader);
    } else {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);
    }

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) {
        echo $data;
        return strlen($data);
    });
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $headerLine) {
        $trimmed = trim($headerLine);
        if ($trimmed === '') return strlen($headerLine);
        if (preg_match('/^HTTP\//', $trimmed)) return strlen($headerLine);
        if (stripos($trimmed, 'Transfer-Encoding') !== false) return strlen($headerLine);
        header($trimmed);
        return strlen($headerLine);
    });

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 0) {
        http_response_code(502);
        echo json_encode(['error' => 'Error conectando con rclone']);
    }
    exit;
}

// Local file serving via X-Accel-Redirect (nginx sendfile)
if (!file_exists($filePath)) {
    // Fallback: si el archivo .mkv fue renombrado a .mp4, probar con .mp4
    $mp4Path = preg_replace('/\.mkv$/i', '.mp4', $filePath);
    if ($mp4Path !== $filePath && file_exists($mp4Path)) {
        $filePath = $mp4Path;
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Archivo local no encontrado']);
        exit;
    }
}

$mediaBase = '/data/data/com.termux/files/home/BUNKER/';
if (strpos($filePath, $mediaBase) === 0) {
    $relativePath = substr($filePath, strlen($mediaBase));
    $mimeType = mime_content_type($filePath) ?: 'video/mp4';
    header('Content-Type: ' . $mimeType);
    header('Accept-Ranges: bytes');
    $encodedParts = array_map('rawurlencode', explode('/', $relativePath));
    header('X-Accel-Redirect: /bunker_media/' . implode('/', $encodedParts));
    exit;
}

// Fallback: PHP streaming for files outside media/
if (!$fileSize) {
    $fileSize = filesize($filePath);
}
$mimeType = mime_content_type($filePath) ?: 'video/mp4';
$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range && preg_match('/bytes=(\d*)-(\d*)/', $range, $matches)) {
    $start = $matches[1] !== '' ? (int)$matches[1] : 0;
    $end = $matches[2] !== '' ? (int)$matches[2] : $fileSize - 1;
    if ($start > $end || $start >= $fileSize) {
        http_response_code(416);
        header('Content-Range: bytes */' . $fileSize);
        exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    header('Content-Length: ' . ($end - $start + 1));
} else {
    http_response_code(200);
    header('Content-Length: ' . $fileSize);
}
header('Content-Type: ' . $mimeType);
header('Accept-Ranges: bytes');
header('Cache-Control: no-cache');
if ($range && isset($start, $end)) {
    $fp = fopen($filePath, 'rb');
    if ($fp) {
        fseek($fp, $start);
        $toSend = $end - $start + 1;
        $chunkSize = 524288;
        while ($toSend > 0 && !feof($fp)) {
            $read = min($chunkSize, $toSend);
            $data = fread($fp, $read);
            if ($data === false) break;
            echo $data;
            $toSend -= strlen($data);
            flush();
        }
        fclose($fp);
    }
} else {
    readfile($filePath);
}
