<?php
/**
 * GalixMovie - Parche de Base de Datos v1.1
 * Aplica índices a la tabla de caché para acelerar el Autopiloto.
 * ─────────────────────────────────────────────────────────────────
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

require 'db.php';

$response = ['status' => 'success', 'messages' => []];

try {
    // 1. status index
    try {
        $pdo->exec("ALTER TABLE resolved_streams_cache ADD INDEX idx_res_status (status)");
        $response['messages'][] = "Index idx_res_status added successfully.";
    } catch (Exception $e) {
        $response['messages'][] = "Status index note: " . $e->getMessage();
    }

    // 2. expires_at index
    try {
        $pdo->exec("ALTER TABLE resolved_streams_cache ADD INDEX idx_res_expires (expires_at)");
        $response['messages'][] = "Index idx_res_expires added successfully.";
    } catch (Exception $e) {
        $response['messages'][] = "Expires index note: " . $e->getMessage();
    }
} catch (Exception $e) {
    $response['status'] = 'error';
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>
