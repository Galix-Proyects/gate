<?php
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Extractor FÉNIX v3.2 (Anti-Ads / Embed Fallback)
// Blindado para ibra.lat y bloqueos de IP
// Integrado con Galix Autopilot Cache v1.0

require_once 'db.php';
require_once 'cache_manager.php';

$contenido_id = $_GET['contenido_id'] ?? '';
$episodio_id  = $_GET['episodio_id'] ?? '';
if ($episodio_id === '') $episodio_id = null;

$url = $_GET['url'] ?? '';
if (!$url) {
    echo json_encode(["status" => "error", "message" => "No URL provided"]);
    exit;
}

// Detectar IP real del cliente (a través de Cloudflare tunnel / proxy)
$clientIp = $_SERVER['HTTP_CF_CONNECTING_IP']    // Cloudflare header
         ?? $_SERVER['HTTP_X_FORWARDED_FOR']      // Proxy estándar
         ?? $_SERVER['HTTP_X_REAL_IP']            // Nginx proxy
         ?? $_SERVER['REMOTE_ADDR']               // Fallback directo
         ?? null;
// Si viene multi-IP (X-Forwarded-For: ip1, ip2), tomar solo la primera
if ($clientIp && strpos($clientIp, ',') !== false) {
    $clientIp = trim(explode(',', $clientIp)[0]);
}

$host = parse_url($url, PHP_URL_HOST);
$yt_dlp = "yt-dlp";

// 🎯 MÓDULO PELISCALIDAD EXTRACTOR (DHARMA Fix #34)
// PelisCalidad bloquea iframes (frame-ancestors 'self') y esconde el reproductor real
// dentro de su propio wrapper HTML. Lo extraemos con cURL y lo pasamos al flujo normal.
if (strpos($host, 'peliscalidad.com') !== false) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    if ($body && preg_match('/<iframe[^>]+src=["\']([^"\']+)["\']/i', $body, $match)) {
        $innerUrl = $match[1];
        // Si el iframe interno es de vimeus, lo redirigimos para que se resuelva abajo
        $url = $innerUrl;
        $host = parse_url($url, PHP_URL_HOST);
    } else {
        // Fallback
        echo json_encode(["status" => "error", "message" => "No inner iframe found in PelisCalidad"]);
        exit;
    }
}

// 🎯 MÓDULO VIMEUS API RESOLVER (DHARMA Fix #30)
// vimeus.com expone una API JSON que devuelve embeds frescos con tokens válidos.
// En lugar de guardar el m3u8 firmado (que expira en 12h), guardamos la URL de vimeus.com
// y la resolvemos aquí al momento de reproducir, obteniendo siempre un token nuevo.
if (strpos($host, 'vimeus.com') !== false) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept: application/json, text/html, */*', 'Referer: https://vimeus.com/']
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    if ($body) {
        // Extraer el objeto JSON de la respuesta (puede estar mezclado con JS)
        if (preg_match('/(\{["\']backdrop["\']:.+?\})\s*[\r\n]/s', $body, $jm)) {
            $data = json_decode($jm[1], true);
        } else {
            $data = json_decode($body, true);
        }

        if (!empty($data['embeds'])) {
            $embeds = [];
            foreach ($data['embeds'] as $e) {
                if (!empty($e['url'])) {
                    $embeds[] = [
                        'url'     => $e['url'],
                        'quality' => $e['quality'] ?? 'HD',
                        'lang'    => $e['lang'] ?? 'Latino',
                        'server'  => $e['server'] ?? 'Online'
                    ];
                }
            }
            if (!empty($embeds)) {
                // Auto-cosechar mirrors en el caché relacional
                if (!empty($contenido_id)) {
                    foreach ($embeds as $e) {
                        saveResolvedCache($pdo, $contenido_id, $episodio_id, $url, $e['url'], 'embed', $e['lang'], $e['server'], $e['quality'], 12, $clientIp);
                    }
                }

                // 🚀 MEJORA MAESTRA M65: Resolver el primer mirror a HLS directo si es escaneable (vimeos o goodstream)
                $firstMirrorUrl = $embeds[0]['url'];
                $resolvedHls = null;
                $isScrapable = (strpos($firstMirrorUrl, 'vimeos.zip') !== false || strpos($firstMirrorUrl, 'goodstream.one') !== false || strpos($firstMirrorUrl, 'vimeos.net') !== false);
                
                if ($isScrapable) {
                    $pythonSniper = __DIR__ . "/sniper.py";
                    if (file_exists($pythonSniper)) {
                        // 🚀 DHARMA #26 ENVIRONMENT FIX: Inyectar variables de Termux para evitar Segfaults en el servidor Nginx/Apache
                        putenv("ANDROID_DATA=/data");
                        putenv("ANDROID_ROOT=/system");
                        putenv("PREFIX=/data/data/com.termux/files/usr");
                        putenv("HOME=/data/data/com.termux/files/home");
                        putenv("TMPDIR=/data/data/com.termux/files/usr/tmp");
                        putenv("LD_PRELOAD=/data/data/com.termux/files/usr/lib/libtermux-exec-ld-preload.so");
                        putenv("PATH=/data/data/com.termux/files/usr/bin");

                        $pyCommand = "python3 " . escapeshellarg($pythonSniper) . " " . escapeshellarg($firstMirrorUrl) . " 2>&1";
                        $pyOutput = [];
                        $pyResultCode = 0;
                        exec($pyCommand, $pyOutput, $pyResultCode);
                        
                        if ($pyResultCode === 0 && !empty($pyOutput)) {
                            $jsonResult = json_decode(implode("", $pyOutput), true);
                            if ($jsonResult && $jsonResult['status'] === 'success' && !empty($jsonResult['url'])) {
                                $resolvedHls = $jsonResult['url'];
                            }
                        }
                    }
                }

                if ($resolvedHls) {
                    // Si se resolvió a HLS exitosamente, sobrescribimos en el caché como HLS y lo devolvemos como HLS
                    if (!empty($contenido_id)) {
                        saveResolvedCache($pdo, $contenido_id, $episodio_id, $url, $resolvedHls, 'hls', $embeds[0]['lang'], $embeds[0]['server'], $embeds[0]['quality'], 12, $clientIp);
                    }
                    echo json_encode([
                        "status"  => "success",
                        "type"    => "hls",
                        "url"     => $resolvedHls,
                        "mirrors" => $embeds,
                        "title"   => $data['title'] ?? '',
                        "source"  => "vimeus_api_resolved"
                    ]);
                } else {
                    echo json_encode([
                        "status"  => "success",
                        "type"    => "embed",
                        "url"     => $embeds[0]['url'],   // Primer embed (prioridad)
                        "mirrors" => $embeds,              // Todos los disponibles
                        "title"   => $data['title'] ?? '',
                        "source"  => "vimeus_api"
                    ]);
                }
                exit;
            }
        }
    }
    // Si falla la API, devolver como embed directo (fallback)
    if (!empty($contenido_id)) {
        saveResolvedCache($pdo, $contenido_id, $episodio_id, $url, $url, 'embed', 'Latino', 'Vimeus Fallback', 'HD', 12, $clientIp);
    }
    echo json_encode(["status" => "success", "type" => "embed", "url" => $url, "message" => "Vimeus API fallback"]);
    exit;
}

// 🛡️ REGLA MAESTRA PARA IBRA.LAT (Priorizar Embed Blindado)
if (strpos($host, 'ibra.lat') !== false || strpos($host, 'pelisflix') !== false) {
    // Si es ibra.lat, devolvemos el embed directamente para evitar el bloqueo de IP de yt-dlp
    // Ibra suele usar un formato de embed estándar basado en el ID
    echo json_encode([
        "status" => "success",
        "type"   => "embed",
        "url"    => $url, // La URL original suele actuar como embed en estos sitios
        "shield" => "sandbox_active"
    ]);
    exit;
}

// Para otros servidores, intentamos extracción directa con yt-dlp
$command = escapeshellcmd($yt_dlp) . " -g --no-check-certificates --geo-bypass " . escapeshellarg($url);
$output = [];
$return_var = 0;
exec($command, $output, $return_var);

if ($return_var === 0 && !empty($output)) {
    $directUrl = trim(end($output));
    // Guardar en cache
    if (!empty($contenido_id)) {
        saveResolvedCache($pdo, $contenido_id, $episodio_id, $url, $directUrl, 'hls', 'Latino', 'Directo (yt-dlp)', 'HD', 12, $clientIp);
    }
    echo json_encode([
        "status" => "success",
        "type" => "hls",
        "url" => $directUrl
    ]);
    exit;
}

// 🎯 MÓDULO SERVER-SIDE BROWSER SNIPER (DHARMA CORE)
// El servidor abre un navegador invisible de forma autónoma para interceptar el stream.
// Evita que los dispositivos cliente (iPad, Móviles, TVs) tengan que instalar extensiones.
$pythonSniper = __DIR__ . "/sniper.py";
if (file_exists($pythonSniper)) {
    $pyCommand = "python3 " . escapeshellarg($pythonSniper) . " " . escapeshellarg($url);
    $pyOutput = [];
    $pyResultCode = 0;
    exec($pyCommand, $pyOutput, $pyResultCode);
    
    if ($pyResultCode === 0 && !empty($pyOutput)) {
        $jsonResult = json_decode(implode("", $pyOutput), true);
        if ($jsonResult && $jsonResult['status'] === 'success' && !empty($jsonResult['url'])) {
            $sniperUrl = $jsonResult['url'];
            if (!empty($contenido_id)) {
                saveResolvedCache($pdo, $contenido_id, $episodio_id, $url, $sniperUrl, 'hls', 'Latino', 'Directo (Server Sniper)', 'HD', 12, $clientIp);
            }
            echo json_encode([
                "status" => "success",
                "type" => "hls",
                "url" => $sniperUrl,
                "server_sniper" => true
            ]);
            exit;
        }
    }
}

// 🎯 MODO GALIX SNIPER TRADICIONAL: Si yt-dlp y Server-Side Sniper fallan, escaneamos manualmente
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$html = curl_exec($ch);
curl_close($ch);

if ($html) {
    // 🔍 INVESTIGACIÓN DE IFRAMES (Multi-Hop)
    // Si el video no está en el HTML principal, buscamos iframes y escaneamos su contenido
    // 🛡️ FILTRO ANTI-RUIDO: Ignorar widgets de publicidad, redes sociales y trackers
    $noisePatterns = [
        'addtoany', 'google-analytics', 'googletagmanager', 'facebook.com/plugins',
        'twitter.com/widgets', 'disqus.com', 'histats.com', 'doubleclick', 'amazon-adsystem',
        'adnxs', 'smartadserver', 'quantserve'
    ];

    if (preg_match_all('/<iframe[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
        foreach ($matches[1] as $iframeUrl) {
            // Saltarse el ruido
            $isNoise = false;
            foreach ($noisePatterns as $noise) {
                if (strpos(strtolower($iframeUrl), $noise) !== false) {
                    $isNoise = true;
                    break;
                }
            }
            if ($isNoise) continue;

            if (strpos($iframeUrl, 'http') === 0) {
                $ch2 = curl_init($iframeUrl);
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch2, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
                $iframeHtml = curl_exec($ch2);
                curl_close($ch2);
                if ($iframeHtml) $html .= $iframeHtml; // Acumular para el escaneo final
            }
        }
    }

    // Buscar patrones comunes de video (m3u8, master, mp4)
    $patterns = [
        '/(https?:\/\/[^"\'>]+\.m3u8[^"\'>]*)/i',
        '/(https?:\/\/[^"\'>]+master[^"\'>]*)/i',
        '/(https?:\/\/[^"\'>]+\.mp4[^"\'>]*)/i',
        '/["\']([^"\'>\s]+\.m3u8[^"\'>\s]*)["\']/i' // Búsqueda de relativos
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $videoMatches)) {
            $sniperUrl = str_replace('\\', '', $videoMatches[1]);
            
            // Reconstrucción de URLs Relativas
            if (strpos($sniperUrl, 'http') !== 0) {
                $parsed = parse_url($url);
                $base = $parsed['scheme'] . "://" . $parsed['host'];
                $sniperUrl = $base . (strpos($sniperUrl, '/') === 0 ? '' : '/') . $sniperUrl;
            }

            // Guardar en cache
            if (!empty($contenido_id)) {
                saveResolvedCache($pdo, $contenido_id, $episodio_id, $url, $sniperUrl, 'hls', 'Latino', 'Directo (Sniper)', 'HD', 12, $clientIp);
            }

            echo json_encode([
                "status" => "success",
                "type" => "hls",
                "url" => $sniperUrl,
                "sniper_hit" => true
            ]);
            exit;
        }
    }
}

// Fallback a Embed si todo lo anterior falla
// DHARMA FIX: Sitios que bloquean Iframes (X-Frame-Options) no deben hacer fallback a embed para evitar que el player se quede atascado.
$iframeBlockers = ['inkapelis', 'peliscalidad'];
foreach ($iframeBlockers as $blocker) {
    if (strpos($url, $blocker) !== false) {
        echo json_encode([
            "status" => "error",
            "message" => "El sitio bloquea iframes y la extracción profunda falló."
        ]);
        exit;
    }
}

if (!empty($contenido_id)) {
    saveResolvedCache($pdo, $contenido_id, $episodio_id, $url, $url, 'embed', 'Latino', 'Embed Fallback', 'HD', 12, $clientIp);
}

echo json_encode([
    "status" => "success",
    "type" => "embed",
    "url" => $url,
    "fallback" => true,
    "message" => "Fallback a Embed: Sniper no encontró rastro de video directo"
]);
