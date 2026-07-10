# 🎬 ONBOARDING — GalixMovie
**Actualización:** 2026-06-28

## 1. Identidad

Plataforma de streaming propia. **Target:** Todo dispositivo — Smart TV, Roku TV, iPad, iPhone, Android, PC, Mac. Diseñada con Glassmorphism, PWA, y filosofía mobile-first. El backend corre en la **Box Symmetry**, el frontend se sirve vía Cloudflare Tunnel y GitHub Pages.

## 2. Stack Técnico

| Componente | Tecnología |
|------------|------------|
| Backend | PHP 8.1+ PDO (MariaDB) — `/backend/` |
| Frontend | HTML + CSS Glassmorphism + JS Vanilla |
| Base de datos | `galix_movie` (MariaDB en Box Symmetry) |
| API externa | TMDB (TheMovieDB) — metadatos de películas/series |
| Roku App | BrightScript — `components/MainScene.brs` (catálogo, modal, reproductor) |
| Proxy | `proxy.php` — Rewrite de manifiestos HLS + CORS bypass |
| Cache | `cache_manager.php` — Caché relacional Read-Through |
| Extractor | `extract.php` — Fénix (yt-dlp + cURL + Chromium headless) |
| Sniper | `sniper.py` — Server-Side Sniper (Chromium headless) |
| Extensión Chrome | GalixSniper — captura de streams HLS desde el navegador |
| PWA | `sw.js` + `manifest.json` — offline cache + instalable |
| Autopilot | `autopilot.php` — Worker inline de auto-curración |
| Reproducción | HLS.js + Plyr + Embed Proxy + Stream Remote Proxy |

## 3. Archivos Críticos (NO TOCAR SIN ANÁLISIS)

| Archivo | Riesgo |
|---------|--------|
| `backend/db.php` | Conexión a MariaDB — todo depende de esto |
| `backend/proxy.php` | DHARMA Fixes #22, #23, #25 integrados — rompe reproducción |
| `backend/cache_manager.php` | Caché relacional — si se altera, el sistema de 0s se quiebra |
| `backend/autopilot.php` | Worker inline con connection-close — curación automática |
| `backend/check_status.php` | Lista CF-Bypass — falsos negativos si se modifica |
| `backend/stream_remote.php` | 6 niveles de blindaje para Archive.org |
| `backend/stream.php` | Proxy rclone + Range Requests + HLS — consulta series_metadata para episodios |
| `backend/scrapper.php` | Población del catálogo — rcloneExecSafe (timeout), detectSeriesFromPath(), skip list contextual |
| `js/app.js` | Núcleo del reproductor, sesión iOS, proxy bypass, pre-cargador, resolveGDriveUrl() |
| `components/MainScene.brs` | App Roku — catálogo, carruseles, modal episodios, resolveVideoUrl() |
| `sw.js` | Service Worker — errores aquí dejan la app en caché rota |

## 4. Target Devices

- **Smart TV / Roku:** Interfaz adaptativa, navegación por control remoto, tarjetas grandes, Hero compacto.
- **iPad / Tablet:** Diseño táctil, drag-to-scroll, sin dependencia de extensión.
- **iPhone:** PWA instalable, iOS Safari ITP Bypass (SID Token URL).
- **Android:** PWA + GalixSniper (Chrome Ext).
- **PC / Mac:** Experiencia completa con teclado, Sniper y extensiones.

## 5. Último Hito Completado

**M235-M239 — Fix Scrapper + Reproducción Series + Compatibilidad Roku**

- **Scrapper colgado (CRÍTICO):** shell_exec('rclone ls') sin timeout colgaba todo el admin. Fix: rcloneExecSafe() con timeout 15s + health check 5s pre-flight. Buffer hardening. `backend/scrapper.php`.
- **Reproducción series (CRÍTICO):** stream.php consultaba solo peliculas_metadata para episodios → 404. Fix: query series_metadata primero, fallback peliculas_metadata. `backend/stream.php`.
- **HLS GDrive hardcodeado (CRÍTICO):** Roku y web hardcodeaban HLS_TEST/SxxExx ignorando ruta real de BD. Fix: extraer path real de gdrive: URL. `components/MainScene.brs` + `js/app.js`.
- **Compatibilidad Roku series:** Series con episodios se consideran compatibles aunque archivo_path base no tenga extensión de video. `components/MainScene.brs`.
- **Skip list bloqueando series:** Skip list se verificaba antes de detectSeriesFromPath(). Episodios de series se procesan por estructura de carpetas, no por nombre de archivo. `backend/scrapper.php`.

**Próximo:** SideloClear Roku app para que fixes tomen efecto. Verificar reproducción EJC en web tras fix resolveGDriveUrl.

- **Problema:** Embeds de Pornhub no reproducían. `extract.php` resolvía al CDN directo (`phncdn.com`) con tipo `hls`, pero CDNs de Pornhub tienen IP-lock estricto (segments .ts 404).
- **3 Capas de Bloqueo:** (1) X-Frame-Options DENY ✅ resuelto vía embed_proxy.php, (2) API `/video/get_media` 503 por Origin ❌, (3) CDN tokens IP-lock 404 ❌.
- **Aislamiento:** Pornhub no pasa por `extract.php` ni `proxy.php`. Sólo vía `embed_proxy.php` con anti-frame-busting.
- **Archivos modificados:** `js/app.js` (líneas 1098-1105, 1320-1324), `backend/embed_proxy.php` (anti-frame-busting, error_reporting(0), curl_close eliminado), `00_SISTEMA/PLANTILLAS_CONTEXTO.md` (plantilla cierre de jornada).
- **Estado:** Embed HTML se carga con anti-frame-busting. Stream no completa por bloqueos del servidor de Pornhub (API Origin + CDN IP-lock). No es limitación del código.

**Anterior: M153-M159 — 4 Sub-hitios**

**(A) M153-M154: Hover Cinemático Premium (Rediseño de Tarjetas)**
- Movie card hover: `scale(1.05)`, doble capa de glow azul eléctrico (`#3b82f6`), `border-color` azul.
- Nuevo `.movie-overlay` con fondo oscuro y `play-circle-outline` centrado con animación `play-pulse`.
- Efecto Shimmer/Glass Glare: pseudo-elemento `::before` con gradiente diagonal que barre de izquierda a derecha al hacer hover.
- Eliminadas todas las instancias de `.movie-popup` y sus subclases.
- 3 loops de renderizado actualizados: continue-watching, clásicas, grid.

**(B) M155-M156: Scanner Inteligente (Scrapper)**
- Filtro `._` Apple Double: `str_starts_with($basename, '._')` → continue.
- Fallback inteligente TMDB: si metadatos embebidos dan basura → parsea filename limpio y reintenta (movie → tv).

**(C) M157: Contadores (REVERTIDO)**
- Fix implementado y revertido por instrucción de Israel.

**(D) M158-M159: Autopilot Triple Fix**
- `WHERE c.is_online = 1` removido — procesa todo contenido.
- Cache `Embed Fallback` ya no bloquea re-extracción.
- Nuevo contador `fallback_cached` en reporte.
- URLs no-seed verificadas: `checkUrlStatus()` (HTTP), `file_exists()` (local), dead añadidas a `static_dead_detected[]`.
- Archivos: `backend/autopilot.php`, `backend/scrapper.php`, `css/style.css`, `js/app.js`, `index.html`

## 6. Procesos de Series (Flujo Completo)

### Scrapper → BD
1. `scanMedia(MEDIA_DIR)` escanea recursivo → encuentra `media/series/[Nombre]/[Temp]/SxxExx.mp4`
2. `detectSeriesFromPath()` extrae: nombre, temporada (del folder), episodio (del filename)
3. TMDB search con `tipo='tv'` → metadatos (poster, sinopsis, backdrop)
4. `upsertContent()`: upsert en `contenido` (tipo='series'), upsert en `series_metadata` (temporada, episodio, archivo_path)
5. Skip list (`scanner_skip.json`) NO aplica a archivos dentro de `/series/` (fix M238)

### BD → API
6. `get_content.php`: LEFT JOIN `peliculas_metadata` (row hero) + merge episodios desde `series_metadata` (y fallback `peliculas_metadata`)
7. Para Roku: filtra `visible_roku = 1` e `is_online = 1`

### API → Roku
8. `buildCarousels()`: compatibility check — series con `episodes[] > 0` se muestran automáticamente
9. Modal episodios: `buildEpisodeList()` → `resolveVideoUrl()` extrae path real de `gdrive:` (no hardcodea `HLS_TEST/`)
10. Play: `playSelectedEpisode()` → `playFromGrid()` → `stream.php?hls=1&path=` (path real) o `stream.php?id=X&season=Y&episode=Z`

### API → Web
11. `resolveGDriveUrl()`: extrae path real de `gdrive:` (fix M239, mirror del fix Roku)
12. HLS.js inicia con URL resuelta → `stream.php` HLS handler → rclone proxy

### Reglas CRÍTICAS
- `series_metadata` = tabla OFICIAL de episodios. `peliculas_metadata` = solo row hero (season=NULL).
- `stream.php` consulta `series_metadata` primero (fix M236).
- NO hardcodear `HLS_TEST/SxxExx` — usar path real de BD (fix M236 Roku, M239 web).
- Skip list no bloquea episodios de serie (fix M238).
- Debug: verificar `visible_roku=1`, `is_online=1`, `series_metadata` poblada.

## 7. Pendientes Activos

- [✅ P01] Archive.org MP4 verificado operativo tras Fix #76
- [🟡 P02] HDD 1TB pendiente de formateo (Israel haciendo respaldo)
- [✅ P03] FFmpeg en Termux para scrapper — COMPLETADO M145
- [🟡 P04] 17 películas sin archivo físico en HDD_500GB — descargar o purgar registros. Lista: Armados y peligrosos, Día de entrenamiento, El abismo secreto, El diablo viste a la moda 2, El hombre bicentenario, El hoyo, El hoyo 2, El mito, Exterminio, Harold y su crayón mágico, Michael, Mortal Kombat II, Sin piedad, Super Mario Galaxy: La película, Supercool, The Punisher: La última muerte, Zootopia 2 (NOTA: algunas YA corregidas con años — verificar)
- [🟡 P05] Script "drop & index" — simplificar ingesta de nuevos .mp4 (scanner automático sin modal)
- [🟡 PH-DHARMA] Pornhub embed no reproduce completamente — API interna 503 + CDN IP-lock (bloqueo del servidor, no del código)

## 8. Decisiones Arquitectónicas Clave

1. **Connection-Close Worker:** No usar `exec()` — el PATH del servidor web no es confiable. Usar `ignore_user_abort` + `fastcgi_finish_request` para worker inline.
2. **Caché Relacional:** `resolved_streams_cache` evita re-extraer URLs dinámicas. Lectura en 0s.
3. **Proxy Bypass:** URLs con IP-lock (Goodstream, Vimeos) se cargan directo desde el cliente, no vía proxy.
4. **Auto-Expansión Pentafásica:** 1 semilla `extract:` → hasta 8 opciones de reproducción.
5. **CF-Bypass:** Dominios protegidos por Cloudflare se verifican por tiempo de expiración de token, no por cURL.

6. **Shell_exec Timeout:** Todo proceso externo (rclone, ffmpeg) debe tener timeout. Usar `timeout` de Linux. Health check antes de operación principal.
7. **series_metadata > peliculas_metadata:** La tabla oficial de episodios es `series_metadata`. `peliculas_metadata` es legacy. stream.php debe consultar la tabla oficial primero.
8. **Path real vs hardcodeado:** NO hardcodear rutas HLS (`HLS_TEST/SxxExx`). Usar siempre el path real almacenado en BD. Este bug apareció idéntico en Roku (BrightScript) y web (JavaScript).
9. **Skip list contextual:** El skip list por nombre de archivo solo aplica a películas sueltas. Series se identifican por estructura de carpetas, no por nombre.
10. **Compatibilidad Roku:** Series con episodios son siempre compatibles (el archivo_path base puede ser un directorio sin extensión de video).

## 9. Enlaces Rápidos

- Bitácora: `Instru_GalixMovie.txt`
- Blindaje: `BLINDAJE_v1.md`
- Lecciones: `DHARMA_LESSONS.md`
- Backend: `backend/autopilot.php`
- Admin: `admin.html`
- **Prebuffer series** → Debounce 500ms con mouseleave cancel; localStorage último episodio; prebuffer al abrir modal
- **Back series** → BACK durante playback返回modal de episodios, no catálogo
