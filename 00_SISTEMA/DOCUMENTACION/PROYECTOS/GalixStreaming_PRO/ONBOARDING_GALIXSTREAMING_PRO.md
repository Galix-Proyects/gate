# 📦 ONBOARDING — GalixStreaming PRO (Telegram Daemon)
**Actualización:** 2026-10-18
**Versión:** 3.7 (recuperación de sesión Telethon + re-login CLI + auto-recovery al arranque)

## 1. Identidad

Sistema de búsqueda y descarga de videos desde foros de Telegram (GEEKFLIX). Backend Python/Telethon con API Flask, proxy PHP hacia nginx, y frontend web dark glassmorphic. Resuelve el problema de videos en topics de foros sin caption que la API de Telegram no puede buscar.

## 2. Stack Técnico

| Componente | Tecnología |
|---|---|---|
| Daemon Telegram | Python + Telethon + FastTelethon, Flask (:5050) |
| Proxy PHP | `api.php` → `backend/telegram.php` → daemon :5050 |
| Base de datos | MariaDB — tabla `forum_video_index` (utf8mb4_uca1400_ai_ci) |
| Frontend | HTML/CSS/JS — `index.html`, `style.css`, `js/app.js` |
| Nginx | `:8080` → `01_PROYECTOS/GalixStreaming_PRO/` |
| Auth | `00_SISTEMA/auth_lib.php` (Bearer Token + cookie GALIXSID) |

## 3. Autenticación

GalixStreaming_PRO usa el sistema de auth centralizado en `00_SISTEMA/auth_lib.php`.

### Mecanismo
1. Usuario ingresa Master Key en `login.php` → recibe `{success, token, user}`
2. Frontend guarda `token` en `localStorage('galix_token')`
3. Cada request vía fetch override inyecta `Authorization: Bearer <token>`
4. `api.php` llama `galix_check_auth()` → reanuda sesión vía token o cookie GALIXSID
5. Después del auth check: `session_write_close()` libera lock de sesión
6. OPTIONS (CORS preflight) manejado ANTES del auth check

### Session Locking (crítico)
`session_start()` retiene un lock exclusivo sobre el archivo de sesión. Si un request (ej. descarga Telegram con `CURLOPT_TIMEOUT=600`) no cierra la sesión, todos los demás requests quedan bloqueados.

**Fix:** `session_write_close()` inmedia­tamente después de `galix_check_auth()` en `api.php`.

### Fix loadQueue
`initQueue()` solo registra el listener del botón refresh. `loadQueue()` + `setInterval()` se activan exclusivamente después de auth exitoso (en ambas rutas: login submit y verificación de sesión existente). Esto elimina el spam de 401 antes del login.

### Logout (BUG conocido)
`logout.php` llama `galix_logout()` sin `galix_auth_init()` previo → `session_start()` nunca se ejecuta → `session_destroy()` falla → la sesión sigue activa.

**Fix pendiente:** agregar `galix_auth_init()` al inicio de `logout.php`.

## 3B. Sesión Telethon — recuperación y re-login (2026-10-18)

- **Archivo de sesión:** `telegram_daemon/session/galix.session` (SQLite de Telethon).
- **Auto-recuperación al arrancar:** `galix_telegram_daemon.py::start_client()` envuelve la creación del `TelegramClient` en try/except. Si el error es `malformed`/`database` (sesión corrupta), renombra la sesión a `.corrupt-<ts>` y la recrea. Ya no crashea el daemon.
- **`AuthKeyDuplicatedError`:** ocurre si la **misma** `galix.session` se usa desde **IPs distintas** (clonada). Deja la key **quemada** (todas las copias comparten el mismo `auth_key`) → **re-login obligatorio**. **REGLA: NUNCA clonar `galix.session` a otra máquina/IP.**
- **Re-login por CLI:** `telegram_daemon/login_helper.py` con `status|send|sign <code>|2fa <password>`. **REGLA: no correrlo junto al daemon** (dos clientes sobre la misma sesión → `AuthKeyDuplicated`).
- **Entrega del código:** `SentCodeTypeApp` (dentro de la app de Telegram, **no SMS**); cada reenvío reinicia el rate-limit de entrega — enviar UNO solo tras el cooldown.
- **Health:** `GET /health` ahora incluye el campo **`auth_status`** (lee el flag `.auth_required`); `GET /status` reporta la autenticación.
- **Respaldo:** conservar una copia `galix.session.good-<ts>` (integridad `ok`) tras un login exitoso.

## 4. Arquitectura

```
Navegador → nginx :8080 → api.php → telegram.php (proxy) → Daemon Flask :5050
                                                                   ↓
                                                              Telethon client → Telegram API
                                                                   ↓
                                                              MariaDB (forum_video_index)
```

### Daemon Telegram
- **Puerto:** 5050 (localhost)
- **Archivo:** `telegram_daemon/galix_telegram_daemon.py`
- **API Server:** `api_server.py` (Flask, rutas `/forum/*`, `/search`, `/downloads`)
- **Downloader:** `downloader.py` (descarga media, build índice)
- **DB Handler:** `db_handler.py` (MariaDB conexión + consultas)
- **Inicio:** `start_daemon.sh` (arranque endurecido instancia-única; exporta `ANDROID_DATA`/`ANDROID_ROOT`). `_start_daemon.php` (L7) ahora invoca este script, no `start.sh`.
- **Reinicio:** `_start_daemon.php` ejecuta `fuser -k 5050/tcp` + `pkill -f galix_telegram_daemon` + `start_daemon.sh`

### Proxy PHP
- `api.php` — router principal (recibe todas las acciones vía `?action=telegram&tg=...`)
- `backend/telegram.php` — proxy HTTP POST/GET hacia daemon :5050

### Verificación de compatibilidad Roku (v3.7, 2026-10-18)
- 4º botón 📺 en cada resultado de video (búsqueda rápida y profunda) → badge inline ✅ compatible /
  ❌ no compatible / ⚠️ indeterminado. **Solo avisa**: no bloquea la descarga.
- **No descarga el archivo completo**: sondea cabeza (3 MB) + cola (8 MB) con
  `FastTelethon.download_range()` (offset alineado a 1 MiB, `limit` = 1 MiB por request).
  `downloader._mp4_mdat_end()` localiza el fin de `mdat` para traer la región del `moov` cuando está
  al final del MP4.
- Decide con el script oficial `00_SISTEMA/_Poderes/roku_compat_check.sh` (regla MP4/H.264/AAC).
- Endpoint `api.php?action=telegram&tg=roku_check` → `POST /check_roku` del daemon. Cache 600s.

## 4. Tabla forum_video_index

```sql
CREATE TABLE forum_video_index (
  peer_id      BIGINT NOT NULL,
  topic_id     INT NOT NULL,
  topic_title  VARCHAR(300) DEFAULT '',
  message_id   INT NOT NULL,
  filename     VARCHAR(500) DEFAULT '',
  caption      TEXT,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (peer_id, message_id),
  KEY idx_filename (filename(255)),
  KEY idx_caption (caption(255)),
  FULLTEXT KEY ft_search (filename, caption)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
```

- Colación `utf8mb4_uca1400_ai_ci`: búsquedas **accent-insensitive** y **case-insensitive**
- `filename`: nombre del archivo de video (ej. `Hombre en Llamas 2022 1080p.mkv`)
- `caption`: caption del mensaje (NULL si no tiene)
- Mensajes duplicados se actualizan por `PRIMARY KEY (peer_id, message_id)`
- `FULLTEXT KEY ft_search (filename, caption)`: índice FULLTEXT para búsqueda eficiente en texto
- `innodb_ft_min_token_size = 2`: indexa palabras desde 2 letras

## 5. Build del Índice (build_forum_index)

Itera todos los topics de un foro vía `GetForumTopicsRequest` y extrae metadatos de videos. Implementa **paginación completa** por topic para capturar TODOS los mensajes (no solo los últimos 100).

### Flujo
1. Obtener entity del peer_id (prueba `peer_id` y `-100{peer_id}`)
2. `GetForumTopicsRequest(entity, limit=100)` → lista de topics
3. Por cada topic:
   - Bucle `while True` con paginación vía `offset_id`
   - `client.get_messages(entity, reply_to=t.id, limit=100, offset_id=offset_id)` → 100 mensajes por página
   - Si `< 100` mensajes recibidos → último topic, break
   - Sino → `offset_id = msgs[-1].id` para siguiente página
   - Filtra solo videos (`is_video()`)
   - Extrae `filename` + `caption` → `insert_forum_video()`
   - Delay 5s entre topics

### Manejo de FloodWaitError + Timeout
```python
from telethon.errors import FloodWaitError
offset_id = 0
page = 0
while True:
    page += 1
    for attempt in range(3):
        try:
            msgs = await asyncio.wait_for(
                client.get_messages(entity, reply_to=t.id, limit=100, offset_id=offset_id),
                timeout=20
            )
            break
        except asyncio.TimeoutError:
            if attempt == 2: stats['errors'] += 1
            else: await asyncio.sleep(5)
        except FloodWaitError as e:
            await asyncio.sleep(e.seconds + 2)
        except Exception:
            if attempt == 2: stats['errors'] += 1
            else: await asyncio.sleep(5)
    else:
        stats['errors'] += 1
        break
    for m in msgs:
        # procesar videos...
    if len(msgs) < 100:
        break
    offset_id = msgs[-1].id
```

### Endpoints del Build + Backfill
| Endpoint | Método | Descripción |
|---|---|---|
| `/forum/index` | POST | Inicia build en background para `peer_id` |
| `/forum/index/build` | GET | Estado: `running` / `done` / error |
| `/forum/index/status` | GET | Estadísticas: `total_videos`, `forums_count`, `last_updated` |
| `/forum/search?q=...` | GET | Busca en índice SQL — 4 fases (frase exacta → AND → LIKE frase → LIKE términos) |
| `/backfill/channels` | POST | Backfill masivo: indexa TODOS los canales habilitados en background |
| `/backfill/status` | GET | Estado del backfill: running, completed, total, indexed, errors, current_channel |

### Detalles Técnicos
- **Timeout por página:** 20s (`asyncio.wait_for`) — evita cuelgues eternos
- **Reintentos:** 3 intentos por página con delay 5s entre intentos
- **FloodWait:** Espera `e.seconds + 2` y reintenta automáticamente
- **Paginación:** Va página por página hasta que Telegram devuelve < 100 mensajes
- **Upsert:** `ON DUPLICATE KEY UPDATE` — rebuild es seguro, no duplica registros

### Backfill Masivo (canales)
`backfill_all_channels()` en `downloader.py` itera los canales habilitados y hace paginación completa de mensajes vía `client.get_messages(entity, limit=100, max_id=...)`. El índice creció de 124 fuentes/98,342 videos (2026-07-29) a **167 foros / 246,131 videos (2026-10-10)**.

| Parámetro | Valor |
|---|---|
| Canales/Fuentes procesadas | 167 foros indexados (2026-10-10) |
| Videos indexados | 246,131 (`forum_video_index`, verificado 2026-10-10) |
| Paginación | `max_id` decremental, `len(msgs) < 100: break` + `MAX_PAGES=500` |
| Delay | 3s por página, 10s entre canales |
| FloodWait | Espera `e.seconds + 2`, `continue` |
| Loop detection | `prev_max_id` — si max_id no disminuye, break |

### Búsqueda FULLTEXT 4 Fases
`search_forum_index()` en `db_handler.py` ejecuta 4 fases en orden de precisión:

| Fase | Método | Descripción | SQL |
|------|--------|-------------|-----|
| 1 | Frase exacta FULLTEXT | `"query"` en BOOLEAN MODE | `MATCH AGAINST('"query"' IN BOOLEAN MODE)` |
| 2 | AND FULLTEXT | Todas las palabras con `+palabra` | `MATCH AGAINST('+palabra1 +palabra2' IN BOOLEAN MODE)` |
| 3 | LIKE frase completa | Substring exacto de la frase | `filename LIKE '%query%' OR caption LIKE '%query%'` |
| 4 | LIKE términos individuales | Fallback si `len(combined) < limit` | `OR` de términos individuales |

Resultados combinados y deduplicados por `(peer_id, message_id)`. Orden final por `updated_at`.

## 5B. Download Flow (Descargas)

### Motor
- **FastTelethon** `fast_download_file()` con 8 conexiones paralelas (~2.5-3.5 MB/s)
- NO usar `client.download_media()` de Telethon (~1 MB/s, 3x más lento)

### Anti-Stall
- Stall detection: si downloaded no cambia en 60s → corta la descarga
- Sin `asyncio.wait_for` wrapper — FastTelethon directo
- Sin retry loop (3 intentos) — FastTelethon maneja sus propios reintentos
- Sin `asyncio.sleep(5)` en finally

### Progreso (Callback optimizado)
- Solo actualiza dict en RAM (`dl[msg_id]['downloaded'] = current`)
- Speed tracking cada 2s (no 0.5s)
- Sin DB ops (`ensure_connection` + `update_download_progress`)
- Sin `dict.update()` — asignación directa

### Secuencial (no concurrente)
- `scan_and_download()` usa `for msg in messages: await download_message(...)`
- `asyncio.gather` compartía ancho de banda entre descargas
- Secuencial = full bandwidth por archivo

### Rename antes de descargar
- Modal `#renameModal` en frontend captura nombre personalizado
- Parámetro `custom_filename` en POST /download
- `sanitize_filename()` preserva espacios (sin `.replace(' ', '_')`)

### Posters TMDB al encolar (v3.4)
- El poster se resuelve en el daemon al ENCOLAR (no al completar): fila `downloads.poster` llena desde el inicio
- Resolver en `downloader.py`: `_resolve_tmdb_poster(filename)`
- Limpieza de tokens de serie (`S01E01`, `S01E01E02`, `S01E01-S01E03`, `1x01`, `Temporada 1 Capitulo 1`, `EP01`) y tags (`DUAL`, `MULTI`, `LATINO`, `SUB`, `VOSE`, `VOS`, `COMPLETA`, `4K`, `UHD`, `HDR`, `WEB`, `WEBRIP`, `WEB-DL`, `BLURAY`, `BRRIP`, `REMUX`, `1080p`, `720p`, `2160p`)
- Scoring `_pick_tmdb_result()`: umbral ≥ 8 (exacto 20, subcadena 12, solape de palabras 8, bono de año +8, `prefer_tv` +1)
- Búsqueda: TMDB `multi` → `movie` → `tv`; fallback Wikipedia ES→EN (interwiki) para títulos que TMDB es-MX no encuentra
- User-Agent obligatorio: `GalixStreaming-PRO/1.0` (Wikipedia devuelve 403 al UA default de urllib)
- El nombre del archivo NUNCA se modifica; solo se limpia el query a TMDB

### Cola: pending antes del semáforo (v3.4)
- `download_message()` → `_prepare_download()` inserta fila `status='pending'` ANTES de adquirir el semáforo (`max_concurrent_downloads=2`) → todas las descargas son visibles de inmediato
- `asyncio.create_task(_download_worker(...))` + set `_bg_tasks` para tracking
- Excepción → `fail_download()` marca `error` (visible con ❌ y botón de reintento 🔄)
- Al arrancar, `fail_stale_downloads()` marca como `error` las descargas Telegram colgadas del reinicio anterior (sin auto-resume; el usuario decide con 🔄)

### Indexado manual de un archivo (v3.6, 2026-10-11)
**El indexado al catálogo GalixMovie NUNCA es automático** (`config.json → auto_scrape=false`; sin cron de scrapper). Solo hay 2 disparadores manuales:
1. **Telegram:** al completar una descarga, el bot avisa con botón manual (`ix|go` indexar, `ix|ed` editar título/TMDB, `ix|sk` omitir) + botón URL **🌐 Abrir en la web** → `{web_url}/?tab=queue&dl={download_id}`.
2. **Cola web:** botón 🎯 en cada descarga `completed` (requiere `downloads.final_path`) → panel con campo **Nombre**, campo **TMDB ID (opcional)**, **🔍 Buscar** (link TMDB), **🔍 Preview** (modal) y **✅ Aplicar e indexar**.

- Deep link: `js/app.js → applyDeepLink()` lee `?tab=` y `?dl=` tras el login; abre la pestaña, resalta (`data-dlid` + clase `.highlight`) y expande el panel de esa descarga. `web_url` se define en `telegram_daemon/config.json`.

- Endpoint: `api.php?action=index_file` (POST `{download_id|path, title, tmdb_id}`) → `backend/index_file.php`.
  - Valida path dentro de BUNKER + extensión de video; bloquea concurrencia (`pgrep php.*scrapper.php`).
  - Auto-detecta: archivo ya en `galix_movie` (`peliculas_metadata`/`series_metadata.archivo_path`) → **FIX** por ID (`GALIX_FIX_ID`); archivo nuevo → **indexado dirigido** (`GALIX_ONLY_PATHS` + `GALIX_CUSTOM_TITLE`/`GALIX_TMDB_ID`, `GALIX_NO_FALLBACK` si no hay ID). Ejecuta `scrapper.php` (nice 19, timeout 180s).
  - `backend/tmdb_preview.php` acepta `&id=&tipo=` (lookup exacto) además de `&q=` (search/multi).
- `db_handler.insert_download()` + `downloader._prepare_download()` guardan **`downloads.final_path`** (antes NULL) para que la Cola sepa qué archivo indexar.
- **Red de seguridad del aviso (v3.6):** `downloads.index_notified_at` marca cuándo se envió el aviso. Al arrancar el daemon, `resend_missing_index_notifications()` reenvía el aviso de descargas `completed` (origen telegram) con `final_path` aún sin notificar; salta y marca las que ya no existen (renombradas/indexadas) o ya están en `galix_movie`. Así el aviso no se pierde si el daemon se reinicia justo al terminar una descarga.
- **Aviso ya resuelto (v3.6):** no hay temporizador; el daemon purga en cada envío las entradas de `TELEMETRY/telegram_pending.json` cuyo archivo ya no existe. Para no dejar un mensaje mudo ("expirado"), el poller y la web escriben `TELEMETRY/telegram_index_log.json` (mapa `download_id → {action, title, path, origin, at}`); al pulsar un botón obsoleto el mensaje muestra QUÉ pasó (indexado/renombrado/ignorado) y la 📁 ruta final. El scrapper emite `[SAVED 📁] <ruta>` con la ruta real (los `upsert*` renombran internamente → se expone por `$GLOBALS['GALIX_LAST_SAVED_PATH']`).

### Semáforo de escaneo de fuentes (v3.4)
- `scan_status` en el daemon: `{running, done, total, current, pct, started_at, finished_at}`
- `GET /status` devuelve `scan`; frontend `renderScanStatus()` → punto ámbar pulsante + barra de progreso (verde al terminar, gris en reposo)
- Polling 10s solo mientras el escaneo está activo (324 fuentes, ~6 min)

## 5C. Almacenamiento local y reindex (`local_content`)

### Discos (código/DB, 2026-10-10)
`backend/config.php` → `LOCAL_HDD` = `BUNKER/STORAGE, HDD_500GB, HDD_1TB, HDD_1TB_B`.
`backend/folders.php` → mismos 4 discos (roots + default `HDD_500GB`).
**HDD_320GB fue RETIRADO** el 2026-10-04 y quitado del código/DBs/catálogo (2026-10-09/10). Sus series se migraron a `HDD_1TB/SERIES3`.

### Reindex `local_content` (2026-10-09)
- Se borraron las **322 filas** que apuntaban al HDD_320GB.
- Reindex completo → `local_content` = **1525 filas**.
- Distribución real por disco (2026-10-10): HDD_1TB 1283 · HDD_1TB_B 202 · HDD_500GB 3 · BUNKER/DEBRID 36 · BUNKER/BACKUP 1.
- `max(indexed_at)` = `2026-10-09 23:59:34`.

### Fix de bug en `backend/local.php` (`indexDirectory`)
- El directorio `HDD_1TB_B/Montado` (sin permiso de lectura) abortaba el scan completo con `UnexpectedValueException`.
- Solución: `RecursiveCallbackFilterIterator` + `@is_readable` para saltar entradas ilegibles sin cortar el recorrido.

## 6. Frontend

### Panel "🔍 Búsqueda y descarga"
`<fieldset>` con 3 columnas separadas por divisores verticales:

```
┌─ 🔍 BÚSQUEDA Y DESCARGA ───────────────────────────────┐
│  BUSCAR             │  FILTRAR          │  DESCARGAR     │
│ [input + Buscar ✕]  │ [input filtrar]   │ [input + Desc]  │
│ 📡 Todos 📺 Canales 🎬 Películas 📺 Series                │
└──────────────────────────────────────────────────────────┘
```

### Header
```
📱 Telegram [+ Agregar fuente] ·············· [🔄 Actualizar]
```
- Botón "+" toggle: muestra/oculta formulario de agregar fuente
- "🔄 Actualizar" recarga: status + auth + fuentes + descargas activas (secuencial con `await`)

### Funcionalidades UX
- `normalizeText()`: elimina acentos y lowercase para búsqueda insensitive
- Spinner animado en lugar de texto plano durante búsqueda
- Resultados aparecen en misma posición que grid de fuentes (toolbar siempre visible)
- Búsquedas: paralelo canales + foros + índice SQL

## 7. API Endpoints

### vía api.php (proxy PHP → daemon)
| Parámetro | Descripción |
|---|---|
| `action=telegram&tg=status` | Estado daemon + Telegram |
| `action=telegram&tg=sources` | Lista fuentes |
| `action=telegram&tg=add_source` | Agregar fuente (POST) |
| `action=telegram&tg=remove_source` | Eliminar fuente |
| `action=telegram&tg=download` | Descargar video por URL |
| `action=telegram&tg=downloads` | Listar descargas activas |
| `action=telegram&tg=search_videos&q=...&mode=quick/deep` | Buscar videos |
| `action=telegram&tg=auth_status` | Estado autenticación |
| `action=telegram&tg=auth_code` | Enviar código 2FA |
| `action=telegram&tg=auth_2fa` | Verificar 2FA |
| `action=telegram&tg=preview` | Preview de videos en fuente |
| `action=telegram&tg=info` | Info de mensaje |
| `action=telegram&tg=import_forum` | Importar foro |
| `action=telegram&tg=scan` | Escanear fuente |

### vía daemon directo (:5050)
| Ruta | Descripción |
|---|---|
| `GET /status` | Estado daemon |
| `POST /forum/index` | Iniciar build índice |
| `GET /forum/index/build` | Estado del build |
| `GET /forum/index/status` | Estadísticas del índice |
| `GET /forum/search?q=...` | Buscar en índice |
| `GET /downloads` | Descargas activas |

## 8. Foros Indexados

| Foro | Peer ID | Topics | Estado |
|---|---|---|---|
| GEEKFLIX MOVIES | `2196080366` | ~101 | ✅ Indexado (build puede ~8 min) |
| GEEKFLIX SERIES | `2246141105` | ~106 | ❌ Pendiente de indexar |

## 9. Próximos Pasos / Pendientes

- [x] Backfill masivo de canales (98,342 videos en 2026-07-29 → 246,131 en 2026-10-10)
- [x] Retiro HDD_320GB (2026-10-04) + limpieza de `local_content` (322 filas) + reindex a 1525 filas (2026-10-09)
- [x] Búsqueda FULLTEXT 4 fases (frase exacta → AND → LIKE frase → LIKE términos)
- [x] MariaDB innodb_ft_min_token_size=2 (palabras de 2 letras indexadas)
- [x] Verificar GalixMovieRoku con la nueva API de búsqueda
- [ ] Checkpoint en build (retomar desde último topic procesado)
- [ ] Búsqueda profunda automática si quick no encuentra resultados
- [ ] Backfill de posters para filas viejas sin poster (endpoint `/backfill_posters`) + fallback neutral 🎬 en cola/historial