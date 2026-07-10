<?php
header('Content-Type: text/plain');
putenv('PATH=/data/data/com.termux/files/usr/bin:/system/bin:/system/xbin');
$ffmpeg = '/data/data/com.termux/files/usr/bin/ffmpeg';

// Test: convert a short segment to verify AC3→AAC works
$base = '/data/data/com.termux/files/home/BUNKER/HDD_500GB/PELICULAS2';
$input = "$base/Avatar The Way of Water (2022).mp4";
$output = "$base/_test_aac.mp4";
$cmd = "$ffmpeg -y -loglevel error -ss 0 -t 10 -i " . escapeshellarg($input) . " -c:v copy -c:a aac -ac 2 -b:a 128k -movflags +faststart " . escapeshellarg($output) . " 2>&1";
echo "CMD: $cmd\n\n";
echo shell_exec($cmd);
$probe = shell_exec("/data/data/com.termux/files/usr/bin/ffprobe -v error -select_streams a:0 -show_entries stream=codec_name,channels -of default=noprint_wrappers=1 " . escapeshellarg($output) . " 2>&1");
echo "RESULT: $probe\n";
echo "SIZE: " . round(filesize($output)/1024) . " KB\n";
unlink($output);
