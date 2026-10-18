# RESUMEN — GalixStreaming_PRO (+ GalixDownStreaming + GalixTelegramDaemon)

**Última actualización:** 2026-10-18

- **Ruta Box:** /data/data/com.termux/files/home/Proyectos/01_PROYECTOS/GalixStreaming_PRO/
- **Ruta web:** http://100.102.226.89:8080/GalixStreaming_PRO/ (y /GalixStreaming_PRO/GalixDownStreaming/)
- **Stack:** PHP 8.1 + MariaDB + yt-dlp + Daemon Python (FastTelethon). (Transmission/torrents ya no corre: sin :9093)
- **Auth:** 00_SISTEMA/auth_lib.php (Bearer Token + cookie GALIXSID) — api.php con session_write_close()
- **Endpoints:** api.php?action=search|resolve|download|queue|progress|stream|telegram|...|index_file
- **Indexado (NO automático):** `auto_scrape=false` y sin cron de scrapper. Se indexa solo a mano: (1) botón en Telegram tras cada descarga, y (2) botón 🎯 en la Cola web (`api.php?action=index_file`, indexa/fix el archivo de esa descarga). `downloads.final_path` guarda la ruta del archivo por descarga. El aviso de Telegram incluye botón "🌐 Abrir en la web" (deep link a la Cola). `downloads.index_notified_at` + `resend_missing_index_notifications()` evitan perder el aviso si el daemon se reinicia. Si un aviso YA se resolvió (o su archivo se renombró/movió), al pulsar el botón el mensaje ahora indica QUÉ pasó (indexado/renombrado/ignorado) y la 📁 ruta final, vía `00_SISTEMA/_Poderes/TELEMETRY/telegram_index_log.json` (lo escriben el poller y la web; el scrapper emite `[SAVED 📁] <ruta>`).
- **Verificación Roku (v3.7, 2026-10-18):** 4º botón 📺 en los resultados de video → badge ✅/❌/⚠️. Sondea cabeza+cola (3+8 MB) SIN descargar (rango `GetFileRequest` alineado a 1 MiB) y decide con `00_SISTEMA/_Poderes/roku_compat_check.sh`. Endpoint `api.php?action=telegram&tg=roku_check` → `POST /check_roku` (cache 600s)
- **GalixDownStreaming:** gestor de descargas PHP (submódulo) — gate redirect v2.3 con wrapper (mismo fix que GalixDownloader)
- **GalixTelegramDaemon:** daemon Python :5050 (reiniciado 2026-10-18 tras RE-LOGIN: sesión corrupta + `AuthKeyDuplicated`), descargas secuenciales 2.5-3.5 MB/s, posters TMDB, semáforo de escaneo, búsqueda FULLTEXT (<100ms). Sesión `session/galix.session` con auto-recuperación (`start_client()` aparta la corrupta a `.corrupt-<ts>`); re-login CLI `login_helper.py` (`status|send|sign|2fa`). **NUNCA clonar la sesión** (otra IP → key quemada).
- **DB `galix_streaming` (2026-10-18):** `local_content` = **1571 filas** (HDD_1TB 1293 · HDD_1TB_B 226 · DEBRID 36 · HDD_500GB 15 · BACKUP 1) · `forum_video_index` = **251,832 videos** · `telegram_sources` = 379 · `downloads` = 2
- **Discos BUNKER:** HDD_1TB (930G, 70%, PELICULAS3/SERIES3) · HDD_1TB_B (916G, 34%, películas planas) · HDD_500GB (457G, 1%, GALIX_HDD/PELICULAS2/SERIES2) · STORAGE (SSD vacío). **HDD_320GB RETIRADO** (2026-10-04)
- **Ops:** reinicio daemon = `_start_daemon.php` (L7 → `start_daemon.sh`; `fuser -k 5050/tcp` + `pkill`) — max_concurrent_downloads=2, scan_interval=120min, download_path=HDD_500GB
- **Bitácora:** Instru_GalixStreaming_PRO.txt (en la carpeta del proyecto) · Instru_GalixTelegramDaemon.txt · ONBOARDING_GALIXSTREAMING_PRO.md · BLINDAJE_GALIXSTREAMING_PRO.md · DHARMA_LESSONS_GALIXSTREAMING_PRO.md
