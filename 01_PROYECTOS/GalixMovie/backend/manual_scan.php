<?php
require 'auth.php';
checkAuth();
header('Content-Type: application/json');

$phpPath = file_exists('/data/data/com.termux/files/usr/bin/php') ? '/data/data/com.termux/files/usr/bin/php' : 'php';
$scrapperPath = __DIR__ . "/scrapper.php";

$output = shell_exec("$phpPath \"$scrapperPath\" 2>&1");

echo json_encode([
    'status' => 'success',
    'output' => $output
]);
