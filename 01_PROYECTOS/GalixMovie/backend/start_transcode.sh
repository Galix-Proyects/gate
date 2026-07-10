#!/bin/bash
# GalixMovie Transcode Manager v1.0
# Transcodes MPEG-2 IPTV streams to H.264 for web playback

TRANSCODE_DIR="$HOME/galix_transcode"
PID_FILE="$TRANSCODE_DIR/ffmpeg.pid"

start_transcode() {
    local NAME="$1"
    local INPUT_URL="$2"
    local OUTPUT_DIR="$TRANSCODE_DIR/$NAME"
    local PLAYLIST="$OUTPUT_DIR/playlist.m3u8"

    # Check if already running
    if [ -f "$PID_FILE" ]; then
        local OLD_PID=$(cat "$PID_FILE")
        if kill -0 "$OLD_PID" 2>/dev/null; then
            echo "ALREADY_RUNNING:$OLD_PID"
            return 0
        fi
    fi

    # Create output directory
    mkdir -p "$OUTPUT_DIR"

    # Start FFmpeg in background
    ffmpeg -hide_banner -loglevel warning \
        -re -i "$INPUT_URL" \
        -c:v libx264 -preset ultrafast -tune zerolatency -crf 28 \
        -c:a aac -b:a 96k -ac 2 \
        -f hls \
        -hls_time 4 \
        -hls_list_size 10 \
        -hls_flags delete_segments+append_list \
        -hls_segment_filename "$OUTPUT_DIR/seg_%03d.ts" \
        "$PLAYLIST" &

    local FF_PID=$!
    echo "$FF_PID" > "$PID_FILE"
    echo "STARTED:$FF_PID:$PLAYLIST"
}

stop_transcode() {
    if [ -f "$PID_FILE" ]; then
        local PID=$(cat "$PID_FILE")
        if kill -0 "$PID" 2>/dev/null; then
            kill "$PID" 2>/dev/null
            rm -f "$PID_FILE"
            echo "STOPPED:$PID"
        else
            rm -f "$PID_FILE"
            echo "ALREADY_STOPPED"
        fi
    else
        echo "NOT_RUNNING"
    fi
}

status_transcode() {
    if [ -f "$PID_FILE" ]; then
        local PID=$(cat "$PID_FILE")
        if kill -0 "$PID" 2>/dev/null; then
            echo "RUNNING:$PID"
        else
            echo "DEAD:$PID"
        fi
    else
        echo "NOT_RUNNING"
    fi
}

case "$1" in
    start)
        start_transcode "$2" "$3"
        ;;
    stop)
        stop_transcode
        ;;
    status)
        status_transcode
        ;;
    *)
        echo "Usage: $0 {start|stop|status} [name] [url]"
        exit 1
        ;;
esac
