<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);
echo "allow_url_fopen: " . ini_get("allow_url_fopen") . "<br>";
$raw = file_get_contents("https://api.themoviedb.org/3/movie/2034?api_key=aa99c189865340e6421390ff192384b6&language=es-MX");
echo "Result length: " . strlen($raw) . "<br>";
$err = error_get_last();
if ($err) print_r($err);
?>
