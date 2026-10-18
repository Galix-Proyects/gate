<?php
header('Content-Type: application/json');
require_once __DIR__ . '/auth_lib.php';
galix_auth_init();
galix_logout();
echo json_encode(['success' => true]);
