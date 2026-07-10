<?php
/**
 * GalixMovie AUTOMATED AUDITOR v2.0
 * Protocolo FENIX - Auto-Sanación de Biblioteca
 * DHARMA v29 - Bypass CF + cURL Multi (verificación paralela)
 */
require 'auth.php';
checkAuth();
require 'db.php';
set_time_limit(600); // 10 minutos max
header('Content-Type: application/json');

// 🛡️ Dominios CF-Protected: se asumen ONLINE sin hacer cURL
// (bloquean IPs de datacenter pero funcionan via Carga Directa)
$CF_BYPASS_DOMAINS = ['medixiru.com', 'cloudwindow-route.com', 'callistanise.com', 'vimeos.'];

function isCFProtected($url, $bypassList) {
    if (!$url) return false;
    $host = parse_url($url, PHP_URL_HOST) ?? '';
    foreach ($bypassList as $domain) {
        if (strpos($host, $domain) !== false) return true;
    }
    return false;
}

// Verifica múltiples URLs en paralelo con cURL Multi
function checkUrlsParallel(array $urls) {
    $results = [];
    $handles = [];
    $mh = curl_multi_init();

    foreach ($urls as $key => $url) {
        if (!$url || strpos($url, 'http') === false) {
            $results[$key] = ($url && strpos($url, 'http') === false); // local = true
            continue;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_NOBODY         => true,
            CURLOPT_USERAGENT      => "Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    // Ejecutar todas las peticiones en paralelo
    $running = null;
    do { curl_multi_exec($mh, $running); } while ($running);

    foreach ($handles as $key => $ch) {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $results[$key] = ($code >= 200 && $code < 400) || $code === 403; // 403 CF = alive
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $results;
}

try {
    $stmt = $pdo->query("SELECT c.id, m.archivo_path, m.server2, m.server3, m.server4, m.server5 FROM contenido c LEFT JOIN peliculas_metadata m ON c.id = m.contenido_id");
    $items = $stmt->fetchAll();
    
    $results = [];
    foreach ($items as $item) {
        $mirrors = [
            's1' => $item['archivo_path'] ?? null,
            's2' => $item['server2'] ?? null,
            's3' => $item['server3'] ?? null,
            's4' => $item['server4'] ?? null,
            's5' => $item['server5'] ?? null,
        ];

        // 🛡️ CF-Bypass: marcar como online sin cURL
        $anyOnline = false;
        $toCheck = [];
        foreach ($mirrors as $key => $url) {
            if (isCFProtected($url, $CF_BYPASS_DOMAINS)) {
                $anyOnline = true; // CF-protected = asumido online
            } elseif ($url) {
                $toCheck[$key] = $url;
            }
        }

        // Verificar los que no son CF-protected en paralelo
        if (!$anyOnline && !empty($toCheck)) {
            $checkResults = checkUrlsParallel($toCheck);
            foreach ($checkResults as $isOnline) {
                if ($isOnline) { $anyOnline = true; break; }
            }
        }

        $newStatus = $anyOnline ? 1 : 0;
        $update = $pdo->prepare("UPDATE contenido SET is_online = ? WHERE id = ?");
        $update->execute([$newStatus, $item['id']]);
        
        $results[] = ["id" => $item['id'], "status" => $anyOnline ? "online" : "offline"];
    }
    
    echo json_encode([
        "status"        => "success",
        "audited_count" => count($results),
        "details"       => $results
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
