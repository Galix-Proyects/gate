<?php
header('Content-Type: text/plain');
$ps = shell_exec('ps -eo pid,ppid,stat,comm | grep -i ffmpeg 2>/dev/null');
echo "FFMPEG processes:\n$ps\n";
echo "---\n";
// Find parent of zombie PIDs: 5780, 5849, 6001
foreach ([5780, 5849, 6001] as $zpid) {
    $parent = shell_exec("ps -p $zpid -o ppid= 2>/dev/null");
    $parentName = shell_exec("ps -p " . (int)$parent . " -o comm= 2>/dev/null");
    echo "Zombie $zpid -> parent PID " . (int)$parent . " ($parentName)\n";
}
