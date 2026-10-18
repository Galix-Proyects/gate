# 🛡️ BLINDAJE — GalixStreaming PRO

> Matriz de procesos críticos, riesgos y blindaje del ecosistema integrado (PHP frontend + Telegram Daemon + MariaDB).

**Fecha de Creación:** 2026-07-28
**Última Actualización:** 2026-10-18
**Estado del Sistema:** ✅ OPERATIVO

---

## ⚠️ Procesos Críticos

| Proceso | Riesgo | Blindaje Implementado |
|---------|--------|----------------------|
| Proxy PHP → Flask daemon | Daemon caído → frontend sin datos | telegram.php detecta error curl y responde 503 + mensaje claro |
| Descarga Telegram (downloader.py) | Download stall / timeout | Stall detection 60s, FastTelethon directo sin wait_for wrapper |
| Progreso en tiempo real | DB sync en callback satura pool | Solo RAM en callback, speed cada 2s, DB al finalizar |
| Rename antes de descargar | Nombre generico sin contexto | Modal #renameModal con input personalizado, custom_filename via API |
| Session lock PHP | Descargas largas bloquean workers | session_write_close() post-auth check en api.php |
| Auth cross-origin | Tunnel cambia, cookie no funciona | Bearer Token (Authorization header) prioritario sobre cookie |
| Daemon restart | Puerto ocupado, env vars faltantes | fuser -k + pkill + ANDROID_DATA/ANDROID_ROOT exports en start.sh |
| Cache SW obsoleto | Frontend no refleja cambios | Cache version bump (v1→v6), app.js?v= query string |
| Ancho de banda saturación | asyncio.gather comparte throughput | Secuencial (for loop), max_concurrent_downloads=2 |
| FastTelethon vs Telethon | Usar client.download_media = 3x lento | Forzado fast_download_file (8 conexiones paralelas) |
| sanitize_filename | Replace espacios con _ rompe nombre | Preservar espacios, sin .replace(' ', '_') |
| Backfill canal grande | Loop infinito paginación max_id=0 | `MAX_PAGES=500` + `if len(msgs) < 100: break` + `prev_max_id` loop detection |
| Búsqueda FULLTEXT palabras cortas | "yo"/"mi"/"el" saturan resultados con OR implícito | 4 fases: frase exacta → AND → LIKE frase → LIKE términos (fallback) |
| MariaDB restart | Daemon pierde conexión, queries fallan | `ensure_connection()` con `conn.ping()` + reconnect automático |
| Poster TMDB en cola | TMDB 403 / no encuentra título → slot sin poster | User-Agent `GalixStreaming-PRO/1.0` + fallback Wikipedia ES→EN + `prefer_tv` para series |
| Cola de descargas (pending) | Semáforo bloquea insert → solo 2 de 5 visibles | `_prepare_download()` inserta `pending` ANTES del semáforo; worker en background con `_bg_tasks` |
| Descargas colgadas tras reinicio | Filas zombie en `downloading` para siempre | `fail_stale_downloads()` al init → `error` + reintento manual 🔄 (sin auto-resume) |
| Filas viejas sin poster | Emojis de estado en el slot del poster | Backfill de posters + fallback neutral 🎬 en cola/historial (pendiente) |
| Scan local abortado | Dir `HDD_1TB_B/Montado` sin permiso lanzaba `UnexpectedValueException` y cortaba todo el index (0 filas) | `indexDirectory` (`backend/local.php`) usa `RecursiveCallbackFilterIterator` + `@is_readable`: salta entradas ilegibles sin abortar |
| Disco retirado deja filas huérfanas | HDD_320GB desconectado (2026-10-04) → filas apuntando a un disco ausente | Borradas las 322 filas del 320GB + reindex a 1525; `LOCAL_HDD`/`folders.php` sin `320` |
| Sesión Telethon corrupta | `galix.session` (SQLite) malformed → crash del daemon al crear `TelegramClient` | `start_client()` con try/except: si error `malformed`/`database` → renombra a `.corrupt-<ts>` y recrea (auto-recuperación). Respaldo `galix.session.good-<ts>` |
| `AuthKeyDuplicated` (key quemada) | Sesión inutilizable (misma key usada desde 2 IPs) → daemon sin conexión | **NUNCA clonar `galix.session` a otra máquina/IP**; instancia ÚNICA del daemon; re-login (código + 2FA) con `login_helper.py` |
| `submit_2fa` sin `return` | El endpoint de 2FA no responde → el re-login no completa | Handler devuelve `return jsonify(result), status_code` |
| Verificación Roku sin descargar | Bajar el archivo completo solo para validar codec = horas perdidas | 4º botón 📺 → sondeo cabeza (3 MB) + cola (8 MB) con `download_range` (`GetFileRequest` alineado a 1 MiB, limit 1 MiB/request); `_mp4_mdat_end` localiza el moov; decide `roku_compat_check.sh`; cache 600s |

---

## 🔒 Reglas de Seguridad

1. **Flask solo en 127.0.0.1** — nunca exponer el puerto 5050 al exterior
2. **Bearer Token** en todas las requests fetch (inyectado via override en app.js)
3. **session_write_close()** inmediatamente después de auth check en api.php
4. **OPTIONS (CORS preflight)** manejado ANTES del auth check
5. **api_id / api_hash** en config.json — no compartir, no subir a git
6. **Sanitizar** nombres de archivo antes de escribir a disco (caracteres peligrosos)

---

## 🚨 Protocolos de Rescate

### Si el daemon no responde:
```bash
php _start_daemon.php  # fuser -k 5050/tcp + pkill + start.sh
```

### Si la sesión Telethon expira o se corrompe:
```bash
# 1) El daemon ya NO crashea: aparta la sesión corrupta sola (.corrupt-<ts>) y recrea.
# 2) Re-login por CLI (REGLA: no correrlo junto al daemon, instancia única):
python3 telegram_daemon/login_helper.py status   # ver estado
python3 telegram_daemon/login_helper.py send     # enviar código (SentCodeTypeApp)
python3 telegram_daemon/login_helper.py sign <code>
python3 telegram_daemon/login_helper.py 2fa <password>
# 3) NUNCA copiar galix.session a otro host/IP -> AuthKeyDuplicated (key quemada).
```

### Si MariaDB está caído:
```bash
mariadbd-safe &
```

### Si el SW cachea versión vieja:
Abrir DevTools → Application → Clear storage → Clear site data

---

## 📊 Métricas de Salud

- **Daemon activo:** PID check via GET /status cada 30s
- **Stall detection:** 60s sin progreso → corta descarga
- **Download speed:** ~2.5-3.5 MB/s por archivo (FastTelethon, 8 conexiones)
- **Auth:** Bearer Token + cookie GALIXSID (fallback)
- **Cache SW:** Version bump manual al cambiar assets

---

## 🔄 Dependencias Externas

| Dependencia | Uso |
|------------|-----|
| telethon + fast-telethon | Conexión Telegram, descarga media |
| flask | API HTTP :5050 |
| MariaDB | forum_video_index, fuentes, descargas |
| PHP 8.1+ | Proxy frontend (api.php, telegram.php, queue.php) |
| nginx | Servidor web :8080 |
| Tailscale | Acceso remoto a Box Symmetry |
