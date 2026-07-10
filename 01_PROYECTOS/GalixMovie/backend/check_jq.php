<?php
header('Content-Type: application/json');
$output = shell_exec("jq --version 2>&1");
$exists = (strpos($output, 'jq-') !== false);
echo json_encode([
    'jq_installed' => $exists,
    'output' => trim($output),
    'os' => PHP_OS
]);
?>
