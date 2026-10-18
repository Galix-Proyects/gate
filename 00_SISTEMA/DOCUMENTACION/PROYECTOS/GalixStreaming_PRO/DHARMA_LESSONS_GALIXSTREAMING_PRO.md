# 🧘 DHARMA LESSONS — GalixStreaming PRO (Telegram Daemon)

## GS-001: Videos en topics sin caption no son buscables vía SearchRequest
La API de Telegram (`search_messages`/`SearchRequest`) solo busca en el texto del mensaje. Los videos enviados como archivo sin caption (solo filename) son invisibles para la búsqueda nativa. **Solución:** Índice SQL `forum_video_index` que almacena filename + caption.

## GS-002: GetRepliesRequest flood wait en foros con muchos topics
`GetRepliesRequest` en foros con ~100 topics genera flood waits de 20-25s por request. Iterar todos los topics es inviable (>30 min). **Solución:** Usar `client.get_messages(reply_to=t.id, limit=100)` con manejo explícito de `FloodWaitError` (esperar `e.seconds + 2` y reintentar hasta 3 veces).

## GS-003: Delay entre topics en build del índice
Delay de 30s original reducido a 5s después de implementar manejo de `FloodWaitError`. El build pasó de ~50 min a ~8-10 min con reintentos inteligentes que no pierden datos.

## GS-004: Collation utf8mb4_uca1400_ai_ci para búsqueda insensible a acentos
La colación `utf8mb4_uca1400_ai_ci` en MariaDB maneja búsquedas accent-insensitive y case-insensitive a nivel SQL. El `LIKE %query%` funciona sin normalizar el query. Para el frontend, `normalizeText()` con `NFD + strip diacríticos` se usa solo en el filtro local de fuentes.

## GS-005: Daemon reinicio limpia descargas en memoria
El daemon almacena `active_downloads` en un dict en memoria. Al reiniciar el proceso, las descargas activas se pierden. No hay tabla `active_downloads` en MariaDB para persistencia. **Lección:** Si se necesita persistencia entre reinicios, migrar a tabla SQL.

## GS-006: Layout 3 columnas con fieldset y divisores
El panel de búsqueda rediseñado como `<fieldset>` con grid de 3 columnas (`1fr 1px 1fr 1px 1fr`) y divisores verticales mejora la organización visual. Los labels en cada columna (BUSCAR / FILTRAR / DESCARGAR) guían al usuario.

## GS-007: await en loadTelegram para recarga completa secuencial
Las llamadas a `loadAuthStatus()`, `loadSources()`, `loadActiveDownloads()` deben ejecutarse con `await` para que el usuario vea la recarga completa antes de que la función retorne. Sin `await`, las promesas se disparan en paralelo y pueden no completarse visualmente.

## GS-008: Paginación en build_forum_index para capturar todos los videos del foro
`client.get_messages(reply_to=t.id, limit=100)` solo obtiene los últimos 100 mensajes de cada topic. Los topics con más de 100 mensajes perdían videos históricos. **Solución:** Bucle `while True` con `offset_id = msgs[-1].id` para paginar hasta que Telegram devuelva < 100 mensajes. Timeout 20s por página via `asyncio.wait_for()` para evitar cuelgues eternos.

## GS-009: Cache de resultados search.php con archivo único por query
`backend/search.php` cacheaba resultados en `cache/` usando `fileatime()` para expiración. Dos bugs: (1) `fileatime()` no es confiable en sistemas de archivos montados con `noatime` (HDD externo BUNKER), (2) el array de resultados mezclaba fuentes (PirateBay + TorrentGalaxy) pero el cache era por query+fuente combinada. **Solución:** Usar `filemtime()` en lugar de `fileatime()`, guardar resultados separados por fuente en `cache/{md5(query)}_{source}.json`, expiración 6h, y mergear al servir.

## GS-010: Auth centralizado Bearer Token + session_write_close
GalixStreaming_PRO y todos los proyectos PHP dependen de session_start() para auth via cookie GALIXSID. Dos problemas críticos: (1) session_start() mantiene lock exclusivo del archivo de sesión durante todo el request, (2) descargas Telegram vía api.php con CURLOPT_TIMEOUT=600 segundos retienen el lock bloqueando todos los demás requests hasta agotar PHP-FPM max_children=8. **Solución:** Implementar galix_auth_init() en auth_lib.php con soporte Bearer Token (Authorization header) prioritario sobre cookie, y session_write_close() inmedia­tamente después del auth check en api.php para liberar el lock antes de ejecutar la lógica del endpoint. OPTIONS (CORS preflight) debe manejarse ANTES del auth check para evitar 401 innecesarios en navegador.

## GS-011: asyncio.gather en descargas satura ancho de banda — secuencial da full speed
`asyncio.gather(download_message(m) for m in messages)` ejecuta N descargas en paralelo. Cada descarga con FastTelethon usa ~8 conexiones paralelas a Telegram. Si se lanzan 2 simultáneas, las 16 conexiones compiten por el mismo ancho de banda de red (~97 Mbps), resultando en ~1.5 MB/s c/u en vez de 2.5-3.5 MB/s. **Solución:** `for msg in messages: await download_message(...)` — una descarga a la vez usa el ancho de banda completo.

## GS-012: FastTelethon fast_download_file vs Telethon client.download_media
`client.download_media()` usa una sola conexión TCP a Telegram (~0.3 MB/s por conexión, límite del servidor). `fast_download_file()` de FastTelethon usa 8 conexiones paralelas, resultando en ~2.5 MB/s total. **Ratio:** FastTelethon es ~3x más rápido. **Lección:** Siempre usar FastTelethon para descargas de archivos grandes de Telegram.

## GS-013: No meter DB ops en progress callback
El progress callback de `fast_download_file` se ejecuta cada ~0.5s. Meter `ensure_connection()` + `update_download_progress()` ahí dentro saturaba el pool de MariaDB y bloqueaba el event loop de asyncio. **Solución:** Solo actualizar dict en RAM (`dl[msg_id]['downloaded'] = current`). Speed tracking reducido a cada 2s. La DB se actualiza al finalizar la descarga, no durante.

## GS-014: Rename modal antes de descargar (custom_filename)
El usuario necesita nombrar el archivo antes de que comience la descarga, no después. Implementar modal `#renameModal` en frontend que captura el nombre personalizado y lo envía como `custom_filename` al daemon. El daemon pasa el nombre a `fast_download_file` omitiendo generación automática. **Lección:** `sanitize_filename()` no debe aplicar `.replace(' ', '_')` — los espacios elegidos por el usuario deben preservarse.

## GS-015: ANDROID_DATA/ANDROID_ROOT necesarias en Termux para restart Python
En Termux (Android), Python lanza procesos hijo que requieren `ANDROID_DATA` y `ANDROID_ROOT` en el environment. Sin ellas, `subprocess` falla silenciosamente. Al reiniciar el daemon vía `fuser -k 5050/tcp` + `pkill`, el nuevo proceso Python no hereda estas variables porque el shell de PHP (vía `exec`/`shell_exec`) no las tiene. **Solución:** Exportarlas explícitamente en `start.sh` antes de lanzar el daemon.

## GS-016: Paginación backfill — loop infinito cuando max_id=0
`client.get_messages(entity, limit=100, max_id=max_id)` con `max_id=0` significa "sin límite superior" → devuelve los mensajes más recientes. Al calcular `max_id = msgs[-1].id - 1`, si el mensaje más antiguo tiene ID 1, `max_id = 0` y el siguiente request devuelve LOS MISMOS mensajes, creando un loop infinito. **Solución:** `if len(msgs) < 100: break` después de procesar cada página + `MAX_PAGES = 500` por canal + `prev_max_id` para detectar si max_id no disminuye.

## GS-017: Resolución de entidades canal requiere -100 prefix
`client.get_entity(peer_id)` con el peer_id desnudo (ej. 3805568326) puede resolver a un canal incorrecto o fallar. Los canales broadcast de Telegram se identifican internamente con el prefijo `-100` (ej. `-1003805568326`). **Solución:** En `resolve()`, probar `int(f'-100{peer_id}')` ANTES que `peer_id` desnudo. Además cachear por `peer_id` original para evitar get_entity() repetido.

## GS-018: Búsqueda FULLTEXT 4 fases — frase exacta antes que términos sueltos
Con `innodb_ft_min_token_size=2`, palabras de 2 letras como "yo", "mi", "el" son indexadas por FULLTEXT. Si la búsqueda usa OR implícito (por defecto en BOOLEAN MODE), una query "irene yo y mi otro yo" matchea TODAS las filas que contengan "yo" o "mi", resultando en miles de resultados irrelevantes. **Solución:** 4 fases en orden: (1) frase exacta con comillas dobles, (2) AND con `+palabra` para todas las palabras, (3) LIKE de la frase completa, (4) LIKE de términos individuales SOLO como fallback si hay pocos resultados.

## GS-019: Wikipedia exige User-Agent y el fallback ES→EN resuelve títulos sin año
"LA VIDA DE PI.mp4" no lo encontraba TMDB en es-MX (sin año en el nombre). Al consultar Wikipedia en español para traducir el título, la API devolvía **403 Forbidden** — Wikipedia bloquea el User-Agent default de urllib. **Lección:** todo `urlopen` contra APIs externas debe llevar `User-Agent` propio (`GalixStreaming-PRO/1.0`). La cadena de fallback ganadora fue: TMDB es-MX → Wikipedia ES (`list=search`) → interwiki `langlinks` a en.wikipedia → TMDB en-US → poster correcto de "Life of Pi".

## GS-020: Bono de año en el scoring TMDB — no borres el año del query
Nombres como "Chernobyl (2019) S01E01.mp4" o "Hombre en Llamas 2022.mkv": TMDB devuelve muchos candidatos por título sin contexto. Si el año se elimina del query, la coincidencia pierde precisión. **Solución:** extraer el año del nombre, quitarlo del texto de búsqueda (para no romper la búsqueda) pero SUMARLO como bono de puntaje (+8) cuando el año del resultado coincide. Combinado con `prefer_tv` (+1) para series (detectadas por tokens SxxExx/Temporada/1x01), el scoring ≥ 8 acierta consistentemente.

## GS-021: Inserta la fila pending ANTES del semáforo — la cola debe mostrar todo
Con `async with self.semaphore` (max_concurrent=2) envolviendo el INSERT, agregar 5 descargas mostraba solo 2 en la cola: las otras 3 bloqueaban esperando slot antes de insertar su fila. **Lección:** separar "registrar intención" (insertar fila `pending`, visible al usuario al instante) de "ejecutar descarga" (adquirir semáforo en un worker en background con `asyncio.create_task`). Tracking de tasks en un set `_bg_tasks` para evitar garbage collection y poder diagnosticar.

## GS-022: No auto-resumir descargas colgadas al reiniciar — marcar error y dejar decidir
Tras un reinicio del daemon, filas quedaban en `downloading` para siempre (zombies). Auto-reanudarlas arriesga duplicar descargas o reanudar archivos a medias sin conocimiento del usuario. **Solución:** `fail_stale_downloads()` al arranque marca `source='telegram'` (o magnet `tg://%`) colgadas como `error`; el usuario decide con el botón de reintento 🔄. La cola así siempre refleja la realidad.

## GS-023: Retirar un disco deja filas huérfanas y un scan que aborta por un solo dir sin permiso
Al desconectar el **HDD_320GB** (2026-10-04) quedaron 322 filas en `galix_streaming.local_content` apuntando a un disco ausente, y `backend/local.php` (`indexDirectory`) abortaba el scan COMPLETO con `UnexpectedValueException` al topar con el directorio `HDD_1TB_B/Montado` (sin permiso de lectura): un único subdirectorio ilegible tumbaba la reindexación entera. **Solución:** (1) borrar las 322 filas del 320GB y reindexar → `local_content` = 1525 filas (2026-10-09, `max(indexed_at)=2026-10-09 23:59:34`); (2) `indexDirectory` usa `RecursiveCallbackFilterIterator` + `@is_readable` para saltar entradas ilegibles sin abortar; (3) quitar `HDD_320GB` de `backend/config.php` (`LOCAL_HDD`) y `backend/folders.php`. **Blindaje:** al retirar un disco, purgar DB + código + catálogo el mismo día, y hacer los recorridos de directorios tolerantes a permisos. Distribución post-reindex: HDD_1TB 1283 · HDD_1TB_B 202 · HDD_500GB 3 · DEBRID 36 · BACKUP 1.

## GS-024: Sesión Telethon corrupta → AuthKeyDuplicated → re-login obligatorio; NUNCA clonar la sesión
La sesión `telegram_daemon/session/galix.session` (SQLite de Telethon) quedó **corrupta** (`sqlite3.DatabaseError: database disk image is malformed`) y el daemon crasheaba al arrancar (la excepción se lanza dentro de `TelegramClient(...)` en `start_client()`, en el event loop → traceback en `asyncio.run(main())`). Se recuperó con `sqlite3 .recover` (integridad `ok`, `auth_key` 256 B) **pero** al reconectar Telegram devolvió **`AuthKeyDuplicatedError`**: la key quedó **quemada** por haber usado la MISMA `galix.session` desde **IPs distintas** (mismo patrón del incidente 2026-10-11). Hallazgo: **TODAS** las copias de `galix.session` comparten la MISMA `auth_key` (md5 `429c1c465f7d`) → no hay sesión reutilizable → **re-login obligatorio** (código + 2FA). **Blindaje:** (1) **NUNCA clonar/copiar `galix.session` a otra máquina/IP** — es la causa raíz del `AuthKeyDuplicated`; (2) instancia ÚNICA del daemon (no correr `login_helper.py` junto al daemon: dos clientes sobre la misma sesión → misma excepción); (3) `start_client()` ahora envuelve la creación del `TelegramClient` en try/except y, si el error es `malformed`/`database`, renombra la sesión a `.corrupt-<ts>` y recrea (auto-recuperación en vez de crash). El código de verificación se entrega como **`SentCodeTypeApp`** (dentro de la app, no SMS); cada reenvío reinicia el rate-limit de entrega — enviar UNO solo tras el cooldown.

## GS-025: Handler de 2FA sin `return` — la petición nunca responde
`api_server.py::submit_2fa()` ejecutaba la lógica del 2FA pero **no retornaba** `jsonify(result)`; Flask devolvía una respuesta vacía/colgada y el re-login no podía completarse aunque el 2FA fuera correcto. **Solución:** agregar `return jsonify(result), status_code` al final del handler. **Blindaje:** todo endpoint Flask debe terminar con un `return` explícito (respuesta o `jsonify`); un handler que no retorna deja al cliente sin respuesta.

## GS-026: GetFileRequest de Telegram exige offset alineado a 1 MiB y `limit` = 1 MiB exacto
Para verificar un archivo **sin descargarlo** (sondeo cabeza+cola de solo ~3 MB + 8 MB) se usa `GetFileRequest` para bajar rangos binarios. Telegram **rechaza offsets no alineados al tamaño de parte (1 MiB)**: se intentó alinear a 1 KB y a 4 KB sin éxito → `"The given offset was invalid, it must be divisible by 1KB"` y `"An invalid limit was provided"`. **Solución:** alinear el offset **hacia abajo a 1 MiB** (`PART=1048576`) y enviar `limit` = **exactamente 1 MiB por request** (el server recorta el último chunk en EOF); iterar `while position < end` con `GetFileRequest(offset=position, limit=PART)` y cortar cuando `len(data) < PART`. **Extra MP4:** con `moov` al final, un `head` fijo de 3 MB puede quedarse corto (el `moov` pesó ~4.6 MB en un caso real); conviene recorrer las cajas del MP4 y calcular el fin de `mdat` (`_mp4_mdat_end`) para traer justo la región del `moov`. **Blindaje:** en `GetFileRequest` nunca asumas alineaciones < 1 MiB ni "un límite de hasta N MB": el offset debe ser múltiplo de 1 MiB y cada request pide exactamente 1 MiB.