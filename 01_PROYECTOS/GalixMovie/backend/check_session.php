<?php
require 'auth.php';

if (isAdmin()) {
    echo json_encode(['status' => 'success', 'logged_in' => true]);
} else {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'logged_in' => false]);
}
?>
