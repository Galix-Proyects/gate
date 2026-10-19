#!/data/data/com.termux/files/usr/bin/bash
# roku_recode_daemon.sh — daemon de recodificación Roku-Ready (ventana nocturna).
# CLI: start|stop|status|restart|dry-run|only <regex>|audio|revisar [dir]
# Env: ROKU_FORCE=1 omite ventana/presupuesto (modo --only/manual).
# supervisor persistente: lanzado por cron @reboot + watchdog */5 (self-reexec).

export HOME=/data/data/com.termux/files/home
export PREFIX=/data/data/com.termux/files/usr
export PATH=$PREFIX/bin:$PREFIX/bin/applets:/system/bin:$PATH
export ANDROID_DATA=/data ANDROID_ROOT=/system TERMUX_APP__PACKAGE_NAME=com.termux

DIR=$(dirname "$(readlink -f "$0")")
source "$DIR/roku_lib.sh" || exit 1

PIDF=$LOGS/roku_recode.pid
STOPMARK=$LOGS/.roku_recode.stopped
STATE=$LOGS/roku_recode.state
RATIO=1.47
CRF=21
PRESET=ultrafast
THREADS=3
THREADS_SOAK=2
T_GATE_ON=83
T_GATE_OFF=75
GATE_GRACE=60
HEAT_TH=78
RESPIRO=60
POLL=15
FIRST_TS=0
NIGHT=""
RK_LIB_HASH=$(md5sum "$DIR/roku_lib.sh" 2>/dev/null | cut -d' ' -f1)
RK_DAEMON_HASH=$(md5sum "$DIR/roku_recode_daemon.sh" 2>/dev/null | cut -d' ' -f1)

_state_set() { : > "$STATE"; printf 'pid=%s\nstart=%s\nhb=%s\nhash=%s\nnight=%s\nheat=%s\nmaxtemp=%s\nenc=%s\npgid=%s\n' "$1" "$2" "$3" "$4" "$5" "${6:-0}" "${7:-0}" "${8:-0}" "${9:-0}" > "$STATE"; }
_state_patch() { python3 - "$STATE" "$2" "$3" <<'PY'
import sys
p, prev, cur = sys.argv[1], sys.argv[2], sys.argv[3]
try:
    lines = open(p).read().splitlines()
except Exception:
    lines = []
out, ok = [], False
for l in lines:
    if l.startswith(prev + "="):
        out.append(cur); ok = True
    else:
        out.append(l)
if not ok:
    out.append(cur)
open(p, "w").write("\n".join(out) + "\n")
PY
}
_state_get() { grep "^$1=" "$STATE" 2>/dev/null | head -1 | cut -d= -f2-; }
_hb() { _state_patch "$STATE" "hb" "hb=$(date +%s)"; }

# Mata cualquier ffmpeg de codificación en curso. Se usa como trap de salida
# del daemon para que NUNCA queden codificaciones huérfanas (SIGTERM y, si el
# encoder se atasca, SIGKILL). Solo mata el pid exacto (x264 no tiene hijos).
enc_cleanup() {
    local e
    e=$(_state_get enc 2>/dev/null)
    _state_patch "$STATE" "enc" "enc=0" 2>/dev/null
    if [ -n "$e" ] && [ "$e" != "0" ] && kill -0 "$e" 2>/dev/null; then
        kill -TERM "$e" 2>/dev/null; sleep 1; kill -9 "$e" 2>/dev/null
    fi
}

_night_bounds() {
python3 - <<'PY'
import time, datetime as dt
now = time.time()
t = dt.datetime.fromtimestamp(now)
def h(y, m, d, hh, mi): return dt.datetime(y, m, d, hh, mi).timestamp()
today2300 = h(t.year, t.month, t.day, 23, 0)
today0615 = h(t.year, t.month, t.day, 6, 15)
today0600 = h(t.year, t.month, t.day, 6, 0)
tom = t + dt.timedelta(days=1)
tom0615  = h(tom.year, tom.month, tom.day, 6, 15)
tom0600  = h(tom.year, tom.month, tom.day, 6, 0)
if now < today0615:
    start, end, food = today2300 - 86400, today0615, today0600
else:
    start, end, food = today2300, tom0615, tom0600
in_night = start <= now < end
budget = max(0, (end - now) / 1.47) if in_night else 0
can_start = in_night and now <= food
print("%d %d %.1f %d %d" % (end, in_night, budget, can_start, now))
PY
}

_q_read() { python3 - <<'PY'
import json
try:
    j = json.load(open("/data/data/com.termux/files/home/Proyectos/01_PROYECTOS/GalixMovie/backend/roku_recode_queue.json"))
except Exception:
    j = {"entries": []}
for e in j.get("entries", []):
    if e.get("status") not in ("done",):
        print("%s\t%s\t%s\t%s\t%s" % (e.get("path",""), e.get("status",""), e.get("attempts",0), e.get("duration",0), e.get("reason","")))
PY
}
_q_counts() { python3 - "$STATE" <<'PY'
import sys, json, time, os
st = {}
try:
    for l in open(sys.argv[1]).read().splitlines():
        if "=" in l:
            k, v = l.split("=", 1); st[k] = v
except Exception:
    pass
try:
    j = json.load(open("/data/data/com.termux/files/home/Proyectos/01_PROYECTOS/GalixMovie/backend/roku_recode_queue.json"))
except Exception:
    j = {"entries": []}
pend = fail = done = inprog = 0
done_today = 0
today = time.strftime("%Y-%m-%d")
for e in j.get("entries", []):
    s = e.get("status")
    if s == "pending": pend += 1
    elif s == "failed": fail += 1
    elif s == "done":
        done += 1
        if str(e.get("done_at", ""))[:10] == today: done_today += 1
    elif s == "inprogress": inprog += 1
print("pend=%d done=%d fail=%d inprog=%d done_today=%d heat=%s maxtemp=%s night=%s" % (
    pend, done, fail, inprog, done_today,
    st.get("heat", 0), st.get("maxtemp", 0), st.get("night", "")))
PY
}

# Versión "humana" del estado, para Telegram (sin campos técnicos).
_q_text() { python3 - "$STATE" <<'PY'
import sys, json, time
st = {}
try:
    for l in open(sys.argv[1]).read().splitlines():
        if "=" in l:
            k, v = l.split("=", 1); st[k] = v
except Exception:
    pass
try:
    j = json.load(open("/data/data/com.termux/files/home/Proyectos/01_PROYECTOS/GalixMovie/backend/roku_recode_queue.json"))
except Exception:
    j = {"entries": []}
pend = fail = inprog = done_today = 0
today = time.strftime("%Y-%m-%d")
for e in j.get("entries", []):
    s = e.get("status")
    if s == "pending": pend += 1
    elif s == "failed": fail += 1
    elif s == "inprogress": inprog += 1
    elif s == "done" and str(e.get("done_at", ""))[:10] == today: done_today += 1
partes = []
if inprog: partes.append("convirtiendo uno ahora")
partes.append("%d en espera" % pend if pend else "nada en espera")
if done_today: partes.append("%d ya listos hoy" % done_today)
if fail: partes.append("%d con problemas" % fail)
print(" · ".join(partes))
PY
}

_thermal_ok() { [ "$(roku_temp_mC)" -lt $((T_GATE_ON * 1000)) ]; }

_ffmpeg_cmd() {
    local f="$1" tmp="$2" vc="$3" ac="$4" th="$5"
    local A=(ffmpeg -nostdin -hide_banner -loglevel error -y -i "$f")
    if [ -n "$vc" ]; then
        A+=(-map 0:v:0 -c:v libx264 -preset "$PRESET" -crf "$CRF" -pix_fmt yuv420p -profile:v high -level 4.0 -threads "$th")
    else
        A+=(-map 0:v:0? -c:v copy)
    fi
    A+=(-map 0:a:0)
    if [ "$ac" = "aac" ]; then
        A+=(-c:a copy)
    else
        A+=(-c:a aac -ac 2 -b:a 160k)
    fi
    A+=(-c:s none -movflags +faststart "$tmp")
    printf '%q ' "${A[@]}"
}

_probe_codes() {
    local f="$1" pe
    pe=$(ROKU_PROBESIZE=4194304 bash "$CHECK" "$f" 2>/dev/null)
    [ -n "$pe" ] || { echo "::0"; return; }
    pe=$(printf '%s' "$pe" | python3 -c 'import sys,json; j=json.load(sys.stdin); v=(j.get("vcodec") or "").lower(); print(("" if v=="h264" else v)+":"+(j.get("acodec") or "")+":"+str(j.get("duration",0)))' 2>/dev/null)
    echo "${pe:-::0}"
}

_verify_out() {
    local tmp="$1" needvc="$2" cj
    cj=$(ROKU_PROBESIZE=4194304 bash "$CHECK" "$tmp" 2>/dev/null)
    cj="$cj" NEEDVC="$needvc" python3 - <<'PY'
import os, json
try:
    j = json.loads(os.environ.get("cj", ""))
except Exception:
    print("NO"); raise SystemExit
d = j.get("duration", 0)
need = os.environ.get("NEEDVC", "")
if d and d > 0 and j.get("compatible") is True:
    print("OK")
elif j.get("compatible") is False and j.get("vcodec", "__") == need and j.get("acodec") == "aac":
    print("OK")
else:
    print("NO")
PY
}

encode_one() {
    local f="$1" reason="$2" vc="$3" ac="$4" dur="$5" force="$6"
    local base tmp bak rc th fp mclock=0 sclock=0 now run_max=0 cut=0 hang=0 MAXENC=0
    base=$(basename "$f")
    tmp="$f.roku.tmp.mp4"
    bak="$f.roku.bak"
    if [ -f "$bak" ]; then rm -f "$bak"; fi
    if [ -e "$f" ]; then
        cp -p -- "$f" "$bak" 2>/dev/null || cp -a "$f" "$bak" 2>/dev/null || { roku_log "$DAEMON_LOG" "ERROR backup: $f"; roku_set_status "$f" failed; return 2; }
    else
        roku_log "$DAEMON_LOG" "ERROR backup: no existe $f"; roku_set_status "$f" failed; return 2
    fi
    th="$THREADS"
    if [ "$(_state_get heat | head -1)" = "1" ] && [ -z "$force" ]; then
        th="$THREADS_SOAK"
        roku_log "$DAEMON_LOG" "heat-soak activo -> threads $th"
    fi
    rm -f "$tmp"
    roku_set_status "$f" inprogress
    for t in 1 2 3; do
        [ $t -gt 1 ] && roku_log "$DAEMON_LOG" "reintento $((t-1)) de $f"
        if command -v ionice >/dev/null; then
            eval "ionice -c3 nice -n 19 $(_ffmpeg_cmd "$f" "$tmp" "$vc" "$ac" "$th") &"
        else
            eval "nice -n 19 $(_ffmpeg_cmd "$f" "$tmp" "$vc" "$ac" "$th") &"
        fi
        fp=$!
        _state_patch "$STATE" "enc" "enc=$fp"
        MAXENC=$(printf '%s' "$dur" | awk '{d=$1+0; t=d*1.47*3+600; if(t<1800)t=1800; if(t>21600)t=21600; printf "%d",t}')
        [ "$MAXENC" -gt 0 ] 2>/dev/null || MAXENC=7200
        felt=""; [ "$force" = "1" ] && felt=" (prueba manual)"
        roku_log "$DAEMON_LOG" "encode start [$reason] thr=$th pid=$fp max=${MAXENC}s: $f"
        roku_tg "🎬 Preparando <b>$(basename "$f")</b> para tu Roku…$felt"
        _hb; mclock=0; sclock=0; rc=1; cut=0
        while kill -0 "$fp" 2>/dev/null; do
            sleep $POLL
            _hb; mclock=$((mclock + POLL)); sclock=$((sclock + POLL))
            now=$(roku_temp_mC)
            [ "$now" -gt "$run_max" ] && run_max="$now"
            if [ "$now" -ge $((T_GATE_ON * 1000)) ]; then
                kill -STOP "$fp" 2>/dev/null
                tC=$((now / 1000))
                roku_log "$TEMP_LOG" "PAUSA térmica ${tC}C (>=${T_GATE_ON}C)"
                while [ "$(roku_temp_mC)" -ge $((T_GATE_OFF * 1000)) ] 2>/dev/null; do
                    sleep 10; _hb
                done
                kill -CONT "$fp" 2>/dev/null
                roku_log "$TEMP_LOG" "REANUDO ${T_GATE_OFF}C"
            fi
            [ $sclock -ge 30 ] && { sclock=0; roku_log "$TEMP_LOG" "temp=$(roku_temp_mC) pid=$fp encode=$(basename "$f") ${mclock}s"; }
            if [ "$force" != "1" ] && [ "$now" -ge "$(_night_end)" ] 2>/dev/null; then
                kill -TERM "$fp" 2>/dev/null; sleep 2; kill -9 "$fp" 2>/dev/null
                cut=1
                roku_log "$DAEMON_LOG" "ventana cerrada, cortando $f (TERM+KILL)"
                break
            fi
            if [ "$MAXENC" -gt 0 ] 2>/dev/null && [ "$mclock" -ge "$MAXENC" ] 2>/dev/null; then
                kill -TERM "$fp" 2>/dev/null; sleep 2; kill -9 "$fp" 2>/dev/null
                hang=1
                roku_log "$DAEMON_LOG" "TIMEOUT encode (${MAXENC}s) -> mato $f"
                roku_tg "⏱️ <b>$(basename "$f")</b> tardó demasiado y detuve la conversión.\nLo intentaré otra vez más tarde."
                break
            fi
        done
        wait "$fp" 2>/dev/null; rc=$?
        _state_patch "$STATE" "enc" "enc=0"
        if [ "$cut" = "1" ]; then
            rm -f "$tmp" "$bak"
            roku_set_status "$f" pending
            roku_log "$DAEMON_LOG" "cortado por ventana (sin intento): $f"
            return 3
        fi
        if [ $rc -eq 0 ] && [ -s "$tmp" ] && [ "$(_verify_out "$tmp" "$vc")" = "OK" ]; then
            if mv -f "$tmp" "$f" 2>/dev/null && [ "$(_verify_out "$f" "$vc")" = "OK" ]; then
                rm -f "$bak"
                roku_set_status "$f" done
                _state_patch "$STATE" "heat" "heat=$([ "$run_max" -ge $((HEAT_TH * 1000)) ] && echo 1 || echo 0)"
                roku_log "$DAEMON_LOG" "OK $base (${mclock}s) -> $f"
                roku_tg "✅ <b>$(basename "$f")</b> ya se reproduce en tu Roku."
                return 0
            fi
        fi
        [ -f "$tmp" ] && rm -f "$tmp"
        roku_set_status "$f" failed
        if [ "$(_q_attempts "$f")" -ge 3 ] 2>/dev/null; then
            roku_log "$DAEMON_LOG" "FALLO definitivo (3 intentos) -> manual: $f"
            roku_tg "❌ No pude convertir <b>$(basename "$f")</b> después de varios intentos.\nNecesita revisión manual."
            return 1
        fi
        roku_set_status "$f" pending
    done
    return 1
}

_q_attempts() { _q_read | awk -F'\t' -v f="$1" '$1==f {print $3}' | head -1; }

pick_next() {
    local force="${1:-0}"
    read -r end in_night budget can_start now <<< "$(_night_bounds)"
    [ "$force" = "1" ] && can_start=1
    [ "$can_start" = "1" ] || return 1
    _q_read | while IFS=$'\t' read -r p st at dur rs; do
        [ "$st" = "pending" ] || continue
        [ "$at" -lt 3 ] 2>/dev/null || continue
        [ -f "$p" ] || continue
        IFS=: read -r vc ac dur2 <<< "$(_probe_codes "$p")"
        # solo archivos realmente incompatibles
        if ! ROKU_PROBESIZE=4194304 bash "$CHECK" "$p" 2>/dev/null | grep -q '"compatible": false'; then
            continue
        fi
        [ "$dur2" = "0" ] && continue
        if [ "$force" != "1" ] && [ "$(printf '%s' "$dur2" | awk -v b="$budget" '{print ($1>b)?"1":"0"}')" = "1" ]; then
            roku_log "$DAEMON_LOG" "sin presupuesto para: $(basename "$p")"
            continue
        fi
        [ -z "$vc" ] && vx=0 || vx=1
        printf '%s\t%s\t%s\t%s\t%s\n' "$vx" "$dur2" "$p" "$vc" "$ac"
        break
    done
}

run_night() {
    local force="${1:-0}"
    while true; do
        _hb
        _state_patch "$STATE" "night" "night=$(date '+%F')"
        read -r end in_night budget can_start now <<< "$(_night_bounds)"
        [ "$in_night" = "1" ] || return 0
        pick_next "$force" > "$LOGS/.roku_pick"
        IFS=$'\t' read -r vx d p vc ac <<< "$(sed -n '1p' "$LOGS/.roku_pick" 2>/dev/null)"
        [ -n "$p" ] || { sleep 30; continue; }
        encode_one "$p" "$(_q_reason "$p")" "$vc" "$ac" "$d" "$force"
        _hb
        sleep "$RESPIRO"
        [ "$force" = "1" ] && return 0
    done
}

_q_reason() { _q_read | awk -F'\t' -v f="$1" '$1==f {print $5}' | head -1; }

_night_end() { _night_bounds | cut -d' ' -f1; }

_purge_orphans() {
    local f
    find "$BUNKER" -maxdepth 5 -type f -name '*.roku.tmp.mp4' -mmin +180 -print 2>/dev/null |
    while read -r f; do
        if ! _q_read | grep -Fq "$f.roku.tmp.mp4"; then
            rm -f "$f" 2>/dev/null
            roku_log "$DAEMON_LOG" "huérfano purgado: $f"
        fi
    done
}

# Sanea el estado tras un arranque: un daemon muerto pudo dejar entradas en
# "inprogress" (nunca se reintentarían) y backups .roku.bak huérfanos.
_recover_stale() {
    local p st at dur rs bak orig
    _q_read | while IFS=$'\t' read -r p st at dur rs; do
        [ "$st" = "inprogress" ] || continue
        roku_set_status "$p" pending
        roku_log "$DAEMON_LOG" "recuperado inprogress->pending: $(basename "$p")"
    done
    find "$BUNKER" -maxdepth 5 -type f -name '*.roku.bak' -cmin +30 -print 2>/dev/null |
    while read -r bak; do
        orig="${bak%.roku.bak}"
        if [ -f "$orig" ]; then
            rm -f "$bak" 2>/dev/null
            roku_log "$DAEMON_LOG" "bak huérfano purgado: $bak"
        else
            roku_log "$DAEMON_LOG" "AVISO bak sin original, conservo: $bak"
        fi
    done
}

_summary() {
    local c
    c=$(_q_text)
    roku_tg "🌅 <b>Resumen de la noche</b>\n$c"
    roku_log "$DAEMON_LOG" "resumen: $(_q_counts)"
}

loop() {
    rm -f "$STOPMARK"
    _state_set "$$" "$(date +%s)" "" "$RK_DAEMON_HASH" "$(date '+%F')" 0 0 0 "$(ps -o pgid= -p $$ 2>/dev/null | tr -d ' ')"
    _hb
    roku_tg "🟢 <b>Servicio de conversión Roku activo</b>\n$(_q_text)"
    roku_log "$DAEMON_LOG" "daemon arranca pid=$$ hash=$RK_DAEMON_HASH"
    _purge_orphans
    _recover_stale
    LAST_NIGHT=""
    while true; do
        _hb
        read -r end in_night budget can_start now <<< "$(_night_bounds)"
        TODAY=$(date '+%F')
        if [ "$in_night" = "1" ]; then
            if [ -z "$LAST_NIGHT" ]; then
                LAST_NIGHT=$TODAY
                _state_patch "$STATE" "maxtemp" "maxtemp=0"
                roku_log "$DAEMON_LOG" "ventana abierta ($TODAY)"
            fi
            run_night
        else
            if [ -n "$LAST_NIGHT" ] && [ "$TODAY" != "$LAST_NIGHT" ]; then
                _summary
                LAST_NIGHT=$TODAY
            fi
            sleep 120
        fi
    done
}

# ── CLI ──
case "${1:-}" in
    start)
        if [ -f "$PIDF" ] && kill -0 "$(cat "$PIDF")" 2>/dev/null; then
            echo "ya corriendo pid=$(cat "$PIDF")"; exit 0
        fi
        rm -f "$STOPMARK"
        rm -f "$PIDF"
        nohup setsid bash "$0" __loop >>"$DAEMON_LOG" 2>&1 &
        disown
        for i in 1 2 3 4 5; do
            [ -f "$PIDF" ] && break
            sleep 0.5
        done
        [ -f "$PIDF" ] && cat "$PIDF" || echo "pid pendiente"
        ;;
    __loop)
        echo "$$" > "$PIDF"
        trap 'rm -f "$PIDF"' EXIT
        trap 'enc_cleanup; rm -f "$PIDF"; exit 0' TERM INT HUP
        loop
        ;;
    stop)
        touch "$STOPMARK"
        if [ -f "$PIDF" ] && kill -0 "$(cat "$PIDF")" 2>/dev/null; then
            P=$(cat "$PIDF")
            pg=$(grep '^pgid=' "$STATE" 2>/dev/null | cut -d= -f2)
            kill "$P" 2>/dev/null            # TERM: dispara trap enc_cleanup
            for i in 1 2 3 4 5 6; do kill -0 "$P" 2>/dev/null || break; sleep 0.5; done
            if kill -0 "$P" 2>/dev/null; then
                kill -9 "$P" 2>/dev/null
                if [ -n "$pg" ] && [ "$pg" -gt 1 ] 2>/dev/null; then kill -9 -"$pg" 2>/dev/null; fi
                echo "detenido (forzado) pid=$P"
            else
                echo "detenido pid=$P"
            fi
            rm -f "$PIDF"
        else
            echo "no corriendo"
        fi
        ;;
    status)
        if [ -f "$PIDF" ] && kill -0 "$(cat "$PIDF")" 2>/dev/null; then
            echo "pid=$(cat "$PIDF") (vivo)"
            _q_counts
        else
            echo "no corriendo"; _q_counts
        fi
        ;;
    restart)
        bash "$0" stop
        sleep 1
        bash "$0" start
        ;;
    dry-run)
        _night_bounds | python3 -c 'import sys; e,i,b,c,n=sys.stdin.read().split(); print("end=%s in_night=%s budget_content=%ss can_start_new=%s" % (e,i,b,c))'
        _q_read | while IFS=$'\t' read -r p st at dur rs; do
            printf '%-8s intentos=%s dur=%.0fs  %s\n' "$st" "$at" "$dur" "$(basename "$p")"
        done
        echo "── totales ──"; _q_counts
        ;;
    only)
        # bash roku_recode_daemon.sh only <regex>  [ROKU_FORCE=1]
        shift
        PAT="${1:-.}"
        F=$( _q_read | awk -F'\t' -v p="$PAT" '$1 ~ p {print $1}' | head -1)
        [ -n "$F" ] || { echo "sin coincidencias para '$PAT'"; exit 1; }
        echo "encode --only: $F"
        trap 'enc_cleanup; exit 0' TERM INT HUP
        IFS=: read -r vc ac d <<< "$(_probe_codes "$F")"
        encode_one "$F" "$(_q_reason "$F")" "$vc" "$ac" "$d" "${ROKU_FORCE:-0}"
        ;;
    audio)
        # Convierte AHORA (fuera de la ventana) todos los pendientes que solo
        # necesitan audio: video ya compatible (H.264) -> se copia, no se recodifica.
        # Escribe su propio PIDF para que el watchdog lo trate como daemon sano
        # (evita que su _reap_ffmpeg mate nuestro encode por "huérfano").
        echo "$$" > "$PIDF"
        trap 'rm -f "$PIDF"' EXIT
        trap 'enc_cleanup; rm -f "$PIDF"; exit 0' TERM INT HUP
        n=0; fails=0
        while :; do
            F=""
            while IFS=$'\t' read -r p st at dur rs; do
                [ "$st" = "pending" ] || continue
                [ "$at" -lt 3 ] 2>/dev/null || continue
                [ -f "$p" ] || continue
                IFS=: read -r vc ac dur2 <<< "$(_probe_codes "$p")"
                [ -z "$vc" ] || continue
                ROKU_PROBESIZE=4194304 bash "$CHECK" "$p" 2>/dev/null | grep -q '"compatible": false' || continue
                F="$p"; break
            done < <(_q_read)
            [ -n "$F" ] || break
            IFS=: read -r vc ac d <<< "$(_probe_codes "$F")"
            echo "audio-now [$((n+1))]: $F"
            encode_one "$F" "$(_q_reason "$F")" "$vc" "$ac" "$d" 1
            rc=$?; [ $rc -eq 0 ] && n=$((n+1)) || fails=$((fails+1))
            _hb
            [ $fails -ge 20 ] && break
        done
        echo "audio-now: convertidos=$n fallidos=$fails"
        ;;
    revisar)
        # bash roku_recode_daemon.sh revisar [dir]  -> ¿qué ya es Roku-Ready y qué falta?
        RD="${2:-$BUNKER/HDD_1TB/REVISION}"
        [ -d "$RD" ] || { echo "no existe: $RD"; exit 1; }
        RPT=$LOGS/.roku_revisar.txt
        python3 - "$RD" "$CHECK" "$RPT" <<'PY'
import sys, os, json, glob, subprocess, html
rd, check, rpt = sys.argv[1], sys.argv[2], sys.argv[3]
exts = (".mp4", ".mkv", ".avi", ".mov", ".webm")
files = sorted(f for f in glob.glob(os.path.join(rd, "*")) if f.lower().endswith(exts))
ok, todo, err = [], [], []
for f in files:
    base = html.escape(os.path.basename(f))
    gb = (os.path.getsize(f) / 1073741824) if os.path.exists(f) else 0
    try:
        out = subprocess.run(["bash", check, f], capture_output=True, text=True, timeout=300).stdout.strip()
        j = json.loads(out)
    except Exception as e:
        j = {"compatible": "error", "error": str(e)}
    c = j.get("compatible")
    if c is True:
        ok.append("✅ %s (%.2f GB)" % (base, gb))
    elif c is False:
        r = html.escape(", ".join(j.get("reasons") or []) or "no cumple")
        todo.append("\U0001F3AC %s (%.2f GB) — %s" % (base, gb, r))
    else:
        err.append("\u26A0\uFE0F %s (%.2f GB) — %s" % (base, gb, html.escape(str(j.get("error") or c))))
head = "\U0001F4FC <b>REVISION</b> — %d archivos: %d \U0001F3AC a convertir · %d ✅ ya Roku · %d \u26A0\uFE0F error" % (len(files), len(todo), len(ok), len(err))
body = "\n".join([head] + todo + ok + err) or (head + "\n(vacío)")
open(rpt, "w").write(body)
print(body)
PY
        roku_tg "$(cat "$RPT")"
        ;;
    *)
        echo "uso: $0 start|stop|status|restart|dry-run|only <regex>|audio|revisar [dir]"
        exit 1
        ;;
esac