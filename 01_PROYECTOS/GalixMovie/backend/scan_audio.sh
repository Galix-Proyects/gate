#!/data/data/com.termux/files/usr/bin/bash
# Scan all MP4 files in BUNKER and output "relpath:codec_name" for each
# This is faster than calling ffprobe from PHP for each file

MEDIA_DIR="/data/data/com.termux/files/home/BUNKER"
find "$MEDIA_DIR" -name "*.mp4" -type f 2>/dev/null | while IFS= read -r f; do
    rel="${f#$MEDIA_DIR/}"
    # Use -analyzeduration to limit header reading
    codec=$(ffprobe -v error -analyzeduration 200000 -probesize 100000 -select_streams a:0 -show_entries stream=codec_name -of default=noprint_wrappers=1 "$f" 2>/dev/null | grep "^codec_name=" | head -1)
    echo "$rel|${codec#codec_name=}"
done
