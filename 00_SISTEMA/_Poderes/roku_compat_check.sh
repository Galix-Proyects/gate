#!/data/data/com.termux/files/usr/bin/bash
# roku_compat_check.sh — evalúa compatibilidad RokuStick de un archivo.
# Compatible = MP4 (moov presence de contenedor mp4) + video H264 + audio AAC.
# Salida: JSON de una línea. rc: 0=analizado · 1=sin arg · 2=no existe.
# Env opcional ROKU_PROBESIZE (bytes) para sondeo temprano de archivos parciales.

export HOME=/data/data/com.termux/files/home
export PREFIX=/data/data/com.termux/files/usr
export PATH=$PREFIX/bin:$PREFIX/bin/applets:/system/bin:$PATH
export ANDROID_DATA=/data ANDROID_ROOT=/system TERMUX_APP__PACKAGE_NAME=com.termux

F="$1"
[ -n "$F" ] || { echo '{"compatible":"error","error":"no_args"}'; exit 1; }
[ -f "$F" ] || { echo '{"compatible":"error","error":"not_found"}'; exit 2; }

ARGS=(-v error -print_format json -show_entries format=format_name:format=duration:stream=codec_name,codec_type,profile,pix_fmt)
if [ -n "$ROKU_PROBESIZE" ]; then
    ARGS+=(-probesize "$ROKU_PROBESIZE" -analyzeduration "$ROKU_PROBESIZE")
fi
PROBE=$(ffprobe "${ARGS[@]}" "$F" 2>/dev/null)
rc=$?
if [ $rc -ne 0 ]; then
    echo '{"compatible":"unknown","error":"unreadable"}'
    exit 0
fi

P="$F" PROBE_DATA="$PROBE" python3 - <<'PY'
import os, json
F = os.environ["P"]
try:
    j = json.loads(os.environ["PROBE_DATA"])
except Exception:
    print('{"compatible":"unknown","error":"badprobe"}'); raise SystemExit
fmt = j.get("format", {}).get("format_name", "") or ""
dur = j.get("format", {}).get("duration", "")
streams = j.get("streams", []) or []
v = [s for s in streams if s.get("codec_type") == "video"]
a = [s for s in streams if s.get("codec_type") == "audio"]
v0 = v[0] if v else {}
vcodec = v0.get("codec_name", "") if v else ""
acodec = (a[0].get("codec_name", "") if a else "")
reasons = []
if any(k in fmt for k in ("matroska", "webm")):
    reasons.append("container:mkv")
if vcodec and vcodec != "h264":
    reasons.append("video:" + vcodec)
profile = str(v0.get("profile", "")).lower()
pix = str(v0.get("pix_fmt", "")).lower()
if profile == "high 10":
    reasons.append("profile:high10")
if pix == "yuv420p10le":
    reasons.append("pixfmt:10bit")
if acodec and acodec != "aac":
    reasons.append("audio:" + acodec)
out = {"file": F, "compatible": not reasons, "reasons": reasons, "container": fmt,
       "vcodec": vcodec, "acodec": acodec, "duration": float(dur) if dur not in ("", None) else 0,
       "size": os.path.getsize(F), "profile": v0.get("profile",""), "pix_fmt": v0.get("pix_fmt","")}
print(json.dumps(out, ensure_ascii=False))
PY