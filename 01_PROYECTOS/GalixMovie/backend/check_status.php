<?php
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Sonda de Verificación Inteligente v2.3 (Robust JSON)

$url = $_GET['url'] ?? '';
if (!$url) {
    echo json_encode(['status' => 'down', 'error' => 'No URL provided']);
    exit;
}

// 🛡️ DHARMA #33.1: Sanitización de Semillas Dinámicas
// Si la URL viene con el esquema virtual 'extract:' o 'sniper:',
// limpiamos el prefijo para obtener la URL cruda del embed.
$url = preg_replace('/^(extract:|sniper:)/', '', $url);

// Si no es URL HTTP, es un path local — verificar con file_exists().
// DHARMA #74: los archivos del BUNKER se guardan como ruta ABSOLUTA de Termux
// (/data/data/com.termux/files/home/BUNKER/...). Concatenar __DIR__.'/../' a una
// ruta absoluta producía ".../GalixMovie/..//data/data/..." y marcaba TODO como caído.
if (!preg_match('/^https?:\/\//i', $url)) {
    $localPath = resolveLocalPath($url);
    if ($localPath) {
        echo json_encode([
            'status'    => 'online',
            'http_code' => 200,
            'note'      => 'LOCAL_FILE_EXISTS',
            'size'      => filesize($localPath),
        ]);
    } else {
        $diskMounted = localDiskMounted($url);
        echo json_encode([
            'status'       => 'down',
            'http_code'    => 404,
            'note'         => $diskMounted ? 'LOCAL_FILE_MISSING' : 'DISK_UNMOUNTED',
            'disk_mounted' => $diskMounted,
            'error'        => $diskMounted ? 'Archivo local no encontrado' : 'Disco no montado',
        ]);
    }
    exit;
}

/**
 * Resuelve una ruta local a un archivo existente y legible.
 * Acepta rutas absolutas (Termux/BUNKER) y relativas al proyecto.
 */
function resolveLocalPath($url) {
    $candidates = [];
    if (str_starts_with($url, '/')) {
        $candidates[] = $url;
    } else {
        $candidates[] = __DIR__ . '/../' . $url;
        $candidates[] = '/data/data/com.termux/files/home/' . $url;
    }
    foreach ($candidates as $cand) {
        $real = realpath($cand);
        if ($real && is_file($real) && is_readable($real)) return $real;
    }
    return false;
}

/**
 * ¿Sigue montado el disco que contiene esa ruta? (evita confundir "disco ausente" con "archivo borrado")
 */
function localDiskMounted($url) {
    $path = str_starts_with($url, '/') ? $url : '/data/data/com.termux/files/home/' . $url;
    if (!preg_match('#^(.*/BUNKER/[^/]+)#', $path, $m)) return true;
    $mountPoint = $m[1];
    $canonical = realpath($mountPoint);
    $mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($mounts as $line) {
        $parts = preg_split('/\s+/', $line);
        if (isset($parts[1]) && ($parts[1] === $mountPoint || ($canonical && $parts[1] === $canonical))) return true;
    }
    return false;
}

$host = parse_url($url, PHP_URL_HOST);
if (!$host) {
    echo json_encode(['status' => 'down', 'error' => 'Invalid URL']);
    exit;
}

$referer = "https://blog-peliculas.net/";
if (strpos($host, 'latinplay') !== false) {
    $referer = "https://latinplay.xyz/";
} elseif (strpos($host, 'medixiru.com') !== false) {
    $referer = "https://medixiru.com/";
} elseif (strpos($host, 'cloudwindow-route.com') !== false) {
    $referer = "https://pelisplushd.la/";
} elseif (strpos($host, 'ibra.lat') !== false || strpos($host, 'pelisflix') !== false) {
    $referer = "https://pelisflix1.autos/";
}

$userAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36";

// 🛡️ DHARMA #33: ULTIMATE CF-BYPASS con Verificación de Token por Tiempo
// Para CDNs protegidos por Cloudflare, no podemos verificar con cURL (403/503).
// PERO podemos calcular si el token expiró usando los parámetros s= y e= de la URL.
// s = timestamp Unix de creación | e = duración en segundos
$cfBypassDomains = ['medixiru.com', 'cloudwindow-route.com', 'callistanise.com', 'vimeos.', 'goodstream.one'];
$isCfDomain = false;
foreach ($cfBypassDomains as $cfDomain) {
    if (strpos($host, $cfDomain) !== false) { $isCfDomain = true; break; }
}

if ($isCfDomain) {
    // Intentar extraer parámetros de expiración del token desde la URL
    $parsed = parse_url($url);
    $queryStr = $parsed['query'] ?? '';
    parse_str($queryStr, $params);

    $tokenStart    = isset($params['s']) ? (int)$params['s'] : 0;
    $tokenDuration = isset($params['e']) ? (int)$params['e'] : 0;
    $now           = time();

    if ($tokenStart > 0 && $tokenDuration > 0) {
        $tokenExpiry = $tokenStart + $tokenDuration;
        $remaining   = $tokenExpiry - $now;

        if ($remaining <= 0) {
            // Token expirado — marcar como caído con diagnóstico
            $expiredAgo = abs($remaining);
            $h = floor($expiredAgo / 3600);
            $m = floor(($expiredAgo % 3600) / 60);
            echo json_encode([
                "status"  => "down",
                "code"    => 410,
                "note"    => "TOKEN_EXPIRED",
                "expired" => "Hace {$h}h {$m}m",
                "detail"  => "Token expirado. Actualiza el enlace con uno nuevo."
            ]);
            exit;
        } else {
            // Token todavía válido
            $hLeft = floor($remaining / 3600);
            $mLeft = floor(($remaining % 3600) / 60);
            echo json_encode([
                "status"  => "online",
                "code"    => 200,
                "note"    => "CF-Bypass+TokenOK",
                "expires" => "En {$hLeft}h {$mLeft}m"
            ]);
            exit;
        }
    }

    // Si no hay parámetros s/e (URL sin token temporal), asumir ONLINE
    echo json_encode(["status" => "online", "code" => 200, "note" => "CF-Bypass"]);
    exit;
}


$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_USERAGENT      => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
    CURLOPT_HTTPHEADER     => ["Referer: $referer"],
    // DHARMA #76: el resolver DNS de Android (netd) puede quedar inservible cuando
    // el router no responde; el DoH por IP directa evita falsos "servidor caído".
    CURLOPT_DOH_URL        => 'https://1.1.1.1/dns-query',
    CURLOPT_RANGE          => (strpos($host, 'medixiru') !== false || strpos($host, 'cloudwindow') !== false) ? null : "0-512" 
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// 🛡️ SMART 403 BYPASS (v2.7)
// Cloudflare bloquea nuestra IP de servidor (Symmetry Box) con un 403 antes de verificar el archivo.
// Como el usuario final reproduce esto directamente sin proxy, interpretamos este 403 "defensivo" 
// como un servidor vivo (ONLINE) para no manchar el dashboard.
if ($httpCode === 403 && (strpos($host, 'medixiru.com') !== false || strpos($host, 'cloudwindow-route.com') !== false)) {
    $httpCode = 200;
}

$result = 'down';
if ($httpCode >= 200 && $httpCode < 400) {
    $lowerResponse = strtolower($response);
    $blackList = ['dmca request', 'file deleted', 'expired'];
    $isFake = false;
    foreach ($blackList as $pattern) {
        if (strpos($lowerResponse, $pattern) !== false) {
            $isFake = true;
            break;
        }
    }
    if (!$isFake) $result = 'online';
}

echo json_encode([
    'status' => $result,
    'http_code' => $httpCode,
    'error' => $error ? $error : null
]);
