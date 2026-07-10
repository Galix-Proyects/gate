<?php
$url = "https://ugc-cdn-caching-n3lwlvsywuuq2ifiqn.cloudwindow-route.com/engine/hls2-c/01/17232/dtt9jgtm8vfr_,n,.urlset/master.m3u8?t=yPa_hrqWzC4nxTZOzfCbHjH52Ws7Y-5ubdiTCuH2-HI&s=1778969716&e=14400&f=86247341&node=CcG+qOjROgZUTd3yvKUVtqHPJW1rxtjJmbg4P9ekU20=&i=187.142&sp=2500&asn=8151&q=n&rq=Ziiy0kZneayzRtW5LEfQRSv7VWQX9ko7z202M9zE";

$headers = [
    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
    "Accept: */*",
    "Accept-Language: es-MX,es;q=0.9,en-US;q=0.8,en;q=0.7",
    "Referer: https://pelisplushd.la",
    "Sec-Fetch-Site: cross-site",
    "Sec-Fetch-Mode: cors",
    "Sec-Fetch-Dest: empty",
    "Origin: https://pelisplushd.la"
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_HEADER         => true,
    CURLOPT_ENCODING       => "",
    CURLOPT_TIMEOUT        => 45
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP Code: $httpCode\n";
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
echo substr($response, 0, $headerSize);
