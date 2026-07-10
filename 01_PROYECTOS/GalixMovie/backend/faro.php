<?php
/**
 * FARO GALIX MULTI-PROYECTO v1.5
 * Sincronizador Maestro de Ecosistema
 */
require 'auth.php';
// checkAuth(); // Descomentar para proteger el faro si es necesario

$cf_metrics = 'http://localhost:4040/api/tunnels';
$data = @file_get_contents($cf_metrics);

header('Content-Type: application/json');

if ($data) {
    $json = json_decode($data, true);
    $tunnel_url = $json['tunnels'][0]['public_url'] ?? null;
} else {
    // 🛡️ PROTOCOLO FÉNIX: Fallback a lectura de Logs locales
    $log_path = __DIR__ . '/../../../../logs/tunnel.log'; // Ruta en la Box
    if (file_exists($log_path)) {
        $log_content = file_get_contents($log_path);
        preg_match('/https:\/\/[a-z0-9-]+\.trycloudflare\.com/', $log_content, $matches);
        $tunnel_url = end($matches) ?: null;
    }
}

header('Content-Type: application/json');

if ($tunnel_url) {
    $signal = [
        'url' => $tunnel_url,
        'updated' => date('Y-m-d H:i:s'),
        'status' => 'online',
        'host' => PHP_OS
    ];
    
    // Guardar el faro en la raíz del repositorio Proyectos
    $faro_path = __DIR__ . '/../../../tunnel.json';
    file_put_contents($faro_path, json_encode($signal, JSON_PRETTY_PRINT));
    
    // Intento de Auto-Push si Git está configurado
    $git_log = shell_exec("cd ../../../ && git add tunnel.json && git commit -m '🚀 FARO: Sincronización de Túnel' && git push origin main 2>&1");

    echo json_encode([
        'status' => 'success',
        'url' => $tunnel_url,
        'git' => $git_log ? trim($git_log) : 'No git output'
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'No se pudo detectar el túnel activo ni en el API ni en los logs.']);
}
?>
