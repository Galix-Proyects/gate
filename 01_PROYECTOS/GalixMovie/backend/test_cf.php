<?php
$url = "https://corsproxy.io/?https%3A%2F%2Fcallistanise.com%2Fstream%2FsKQ-I5NzJZkOgdmP4EMWOw%2Fhjkrhuihghfvu%2F1779014097%2F40929651%2Fmaster.m3u8";

$options = [
    'http' => [
        'method' => "GET",
        'header' => "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36\r\n"
    ]
];
$context = stream_context_create($options);
$res2 = @file_get_contents($url, false, $context);
$code2 = $http_response_header[0] ?? 'NO HEADER';

echo "Test CORS Proxy: Code $code2\n";
