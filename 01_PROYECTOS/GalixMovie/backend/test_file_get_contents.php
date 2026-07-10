<?php
$url = "https://callistanise.com/stream/sKQ-I5NzJZkOgdmP4EMWOw/hjkrhuihghfvu/1779014097/40929651/master.m3u8";

$options = [
    'http' => [
        'method' => "GET",
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36\r\n" .
                    "Accept: */*\r\n" .
                    "Referer: https://callistanise.com/\r\n"
    ]
];

$context = stream_context_create($options);
$result = @file_get_contents($url, false, $context);
if ($result === false) {
    echo "FAILED\n";
    print_r($http_response_header);
} else {
    echo "SUCCESS\n";
    echo substr($result, 0, 100);
}
