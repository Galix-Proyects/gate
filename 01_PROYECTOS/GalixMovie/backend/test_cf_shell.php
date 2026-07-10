<?php
$url = "https://callistanise.com/stream/sKQ-I5NzJZkOgdmP4EMWOw/hjkrhuihghfvu/1779014097/40929651/master.m3u8";
$cmd = "curl -s -I -H 'Referer: https://callistanise.com/' '$url'";
$output = shell_exec($cmd);
echo "SHELL_EXEC CURL:\n$output\n";
