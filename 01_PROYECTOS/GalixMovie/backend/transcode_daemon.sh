#!/bin/bash
# GalixMovie Transcode Daemon v1.0
# Monitors FFmpeg process and restarts if dead

TRANSCODE_DIR="$HOME/galix_transcode"
PID_FILE="$TRANSCODE_DIR/ffmpeg.pid"
LOG_FILE="$TRANSCODE_DIR/ffmpeg.log"

INPUT_URL="http://45.189.62.33:8002/play/a0fm/index.m3u8"
OUTPUT_DIR="$TRANSCODE_DIR/azteca7"
PLAYLIST="$OUTPUT_DIR/playlist.m3u8"

start_ffmpeg() {
    mkdir -p "$OUTPUT_DIR"
    
    # Clean old segments
    rm -f "$OUTPUT_DIR"/seg_*.ts
    
    nohup ffmpeg -hide_banner -loglevel warning \
        -err_detect ignore_err \
        -fflags +genpts+igndts \
        -i "$INPUT_URL" \
        -c:v libx264 -preset ultrafast -tune zerolatency -crf 28 \
        -c:a aac -b:a 96k -ac 2 \
        -f hls \
        -hls_time 4 \
        -hls_list_size 10 \
        -hls_flags delete_segments+append_list \
        -hls_segment_filename "$OUTPUT_DIR/seg_%03d.ts" \
        "$PLAYLIST" \
        >> "$LOG_FILE" 2>&1 &
    
    echo "$!" > "$PID_FILE"
    echo "STARTED:$(cat $PID_FILE)"
}

check_and_restart() {
    if [ -f "$PID_FILE" ]; then
        local PID=$(cat "$PID_FILE")
        if kill -0 "$PID" 2>/dev/null; then
            echo "RUNNING:$PID"
            return 0
        else
            echo "DEAD:$PID - restarting..."
        fi
    else
        echo "NO_PID - starting..."
    fi
    
    start_ffmpeg
}

case "$1" in
    check)
        check_and_restart
        ;;
    start)
        start_ffmpeg
        ;;
    stop)
        if [ -f "$PID_FILE" ]; then
            kill $(cat "$PID_FILE") 2>/dev/null
            rm -f "$PID_FILE"
            echo "STOPPED"
        fi
        ;;
    *)
        echo "Usage: $0 {check|start|stop}"
        ;;
esac
