<?php
require 'auth.php';

$password = $_POST['password'] ?? '';
$master_key = $_ENV['MASTER_KEY'] ?? '';

if ($password === $master_key && !empty($master_key)) {
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['login_time'] = time();
    echo json_encode(['status' => 'success', 'message' => 'Login successful', 'sid' => session_id()]);
} else {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid credentials']);
}
?>
