<?php
require 'db.php';
header('Content-Type: text/plain');
$stmt = $pdo->query("SELECT * FROM series_metadata ORDER BY id DESC LIMIT 20");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
?>
