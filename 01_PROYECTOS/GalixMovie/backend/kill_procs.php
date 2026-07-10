<?php
header('Content-Type: text/plain');
putenv('PATH=/data/data/com.termux/files/usr/bin:/data/data/com.termux/files/usr/bin/applets:/system/bin:/system/xbin');
putenv('HOME=/data/data/com.termux/files/home');
putenv('PREFIX=/data/data/com.termux/files/usr');
// Kill all ffmpeg processes by PID
$ps = shell_exec('ps -eo pid,comm | grep -i ffmpeg 2>/dev/null');
if (trim($ps)) {
    echo "Found:\n$ps\n";
    $lines = explode("\n", trim($ps));
    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) >= 1 && is_numeric($parts[0])) {
            $pid = (int)$parts[0];
            exec("kill -9 $pid 2>/dev/null");
            echo "Killed PID $pid\n";
        }
    }
} else {
    echo "No ffmpeg processes found\n";
}
// Kill parent script (run_fix_audio.sh)
$ps2 = shell_exec('ps -eo pid,comm | grep -i "run_fix_audio\|fix_audio" 2>/dev/null');
if (trim($ps2)) {
    echo "Parent scripts:\n$ps2\n";
    $lines2 = explode("\n", trim($ps2));
    foreach ($lines2 as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) >= 1 && is_numeric($parts[0])) {
            exec("kill -9 {$parts[0]} 2>/dev/null");
            echo "Killed parent PID {$parts[0]}\n";
        }
    }
}
// Also try killall as fallback
exec('/data/data/com.termux/files/usr/bin/killall -9 ffmpeg 2>/dev/null');
echo "Done\n";
