<?php
define('DB_NAME',   'galix_movie');
define('DB_USER',   'root');
define('DB_SOCKET', '/data/data/com.termux/files/usr/var/run/mysqld.sock');

try {
    $pdo = new PDO(
        'mysql:unix_socket=' . DB_SOCKET . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, '',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]
    );
} catch (PDOException $e) {
    http_response_code(503);
    header('Content-Type: application/json');
    die(json_encode(['status' => 'error', 'message' => 'DB: ' . $e->getMessage()]));
}
