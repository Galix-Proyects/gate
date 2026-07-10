<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require 'db.php';
$stmt = $pdo->query("DESCRIBE peliculas_metadata");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "P_META: " . $row['Field'] . " - " . $row['Type'] . "<br>";
}
$stmt = $pdo->query("DESCRIBE series_metadata");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "S_META: " . $row['Field'] . " - " . $row['Type'] . "<br>";
}
?>
