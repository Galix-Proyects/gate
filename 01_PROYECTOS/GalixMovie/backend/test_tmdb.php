<?php
$url = "https://api.themoviedb.org/3/movie/550?api_key=aa99c189865340e6421390ff192384b6&language=es-MX";
$context = stream_context_create([
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    'http' => ['ignore_errors' => true, 'header' => "Accept-Encoding: gzip\r\nUser-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n"]
]);
$res = @file_get_contents($url, false, $context);
if ($res === false) {
    echo "FAILED: " . print_r(error_get_last(), true);
} else {
    echo "SUCCESS: " . substr($res, 0, 100);
}
