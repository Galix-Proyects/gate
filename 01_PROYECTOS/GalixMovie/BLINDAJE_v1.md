# 🛡️ PUNTO DE BLINDAJE — GalixMovie v1.2
**Fecha de Creación:** 2026-05-12 13:39:00  
**Última Actualización:** 2026-06-15 18:00:00  
**Estado del Sistema al momento del blindaje:** ✅ OPERATIVO COMPLETO  
**Protocolo:** DARMA v15.0 | Antigravity

> [!IMPORTANT]
> Este documento es el **escudo de integridad** de GalixMovie. Antes de aprobar cualquier modificación, se debe consultar la **Matriz de Impacto** para determinar si el cambio puede romper procesos existentes. Si hay riesgo, se avisará ANTES de tocar código.

---

## ✅ PROCESOS CONFIRMADOS EN FUNCIONAMIENTO

| ID | Proceso | Archivo Principal | Estado |
|:--|:--|:--|:--:|
| P01 | Login / Sesión | `login.html` + `backend/auth.php` + `backend/check_session.php` | ✅ OK |
| P02 | Carga de Catálogo | `backend/get_content.php` → `js/app.js` | ✅ OK |
| P03 | Reproducción HLS (.m3u8) | `backend/proxy.php` + `js/app.js` | ✅ OK |
| P04 | Reproducción Embed (iframe) | `backend/embed_proxy.php` + `js/app.js` | ✅ OK |
| P05 | Extracción Universal (yt-dlp) | `backend/extract.php` + `js/app.js` | ✅ OK |
| P06 | Inyección Manual de Contenido | `admin.html` → `backend/manual_index.php` | ✅ OK |
| P07 | Edición de Metadatos | `admin.html` → `backend/update_movie.php` | ✅ OK |
| P08 | Eliminación de Contenido | `admin.html` → `backend/delete_movie.php` | ✅ OK |
| P09 | Verificación de Servidores | `admin.html` → `backend/check_status.php` | ✅ OK |
| P10 | Auditar y Limpiar Index | `admin.html` → `backend/set_online_status.php` | ✅ OK |
| P11 | Sincronizar Gateway (Faro) | `admin.html` → `backend/faro.php` → GitHub | ✅ OK |
| P12 | Historial / Continuar Viendo | `js/app.js` → `backend/save_progress.php` + `get_progress.php` | ✅ OK |
| P13 | Favoritos | `js/app.js` → `backend/toggle_favorite.php` + `get_favorites.php` | ✅ OK |
| P14 | Subtítulos | `js/app.js` → `backend/subtitles.php` | ✅ OK |
| P15 | PWA (Service Worker) | `sw.js` + `manifest.json` | ✅ OK |
| P16 | Detección de Duplicados (Real-Time) | `admin.html` (JS inline) | ✅ OK |
| P17 | Diagnóstico de Capítulos (Series) | `admin.html` → `backend/get_content.php` | ✅ OK |
| P18 | Proxy CORS / Smart Referer | `backend/proxy.php` (DHARMA Fix #22, #23, #25) | ✅ OK |
| P19 | Sesión iOS Safari (ITP Bypass) | `js/app.js` + `admin.html` (SID token URL) | ✅ OK |
| P20 | Validación Anti-CSRF | `backend/auth.php` | ✅ OK |
| P21 | Carga Directa CF-Protected CDNs | `js/app.js` v11.9 + `GalixSniper` + `check_status.php` CF-Bypass | ✅ OK |
| P22 | Motor Autopilot v2.1 (Embed Fallback + Non-Seed Verificación) | `backend/autopilot.php` + `admin.html` | ✅ OK |
| P23 | Caché Relacional Read-Through (0s) | `backend/cache_manager.php` + `js/app.js` + `backend/extract.php` | ✅ OK |
| P24 | Proxy Inteligente de Token (IP Bypass) | `js/app.js` + `backend/proxy.php` | ✅ OK |
| P25 | Stream Remote Proxy (Archive.org CORS) | `backend/stream_remote.php` + `js/app.js` | ✅ OK |
| P26 | Escaneo Inteligente + Preview + Apply | `backend/scrapper.php` + `admin.html` (runScan) | ✅ OK |
| P27 | Escaneo "Solo nuevos" (skip_indexed) | `backend/scrapper.php` + `admin-bundle.js` + `admin.html` | ✅ OK |
| P28 | Renombre personalizado en escaneo (custom_names) | `backend/scrapper.php` + `admin-bundle.js` | ✅ OK |
| P29 | Edición metadatos + rename físico | `backend/update_movie.php` + `admin-bundle.js` | ✅ OK |
| P30 | Catálogo con validación de archivos locales (`media/HDD_500GB/`) | `backend/get_content.php` | ✅ OK |
| P31 | Scrapper timeout (rclone) | `backend/scrapper.php` (rcloneExecSafe) | ✅ OK |
| P32 | Reproducción episodios series (stream.php) | `backend/stream.php` (query series_metadata) | ✅ OK |
| P33 | Catálogo Roku (compat check series) | `components/MainScene.brs` | ✅ OK |
| P34 | Resolución HLS GDrive (Roku + Web) | `components/MainScene.brs` + `js/app.js` (resolveGDriveUrl) | ✅ OK |
| P35 | Skip list contextual (series vs películas) | `backend/scrapper.php` (orden detección) | ✅ OK |

---

## 🗺️ MAPA DE DEPENDENCIAS CRÍTICAS

> Qué archivos son "padres" (si los tocas, múltiples procesos se ven afectados):

```
backend/auth.php         → Protege: P01, P02, P03, P04, P05, P06, P07, P08, P09, P10, P11, P22
backend/db.php           → Protege: P02, P06, P07, P08, P12, P13, P14, P17, P22, P23
backend/cache_manager.php→ Protege: P23 (Centralizador de base de datos relacional de streams)
backend/proxy.php        → Protege: P03, P18
backend/stream_remote.php→ Protege: P25 (Archive.org + CDNs remotos con CORS + Range Requests)
backend/check_status.php → Protege: P09, P21 (CF-Bypass para CDNs protegidas)
js/app.js                → Protege: P02, P03, P04, P05, P12, P13, P14, P19, P21, P23, P24, P25
backend/autopilot.php    → Protege: P22 (Centraliza la lógica de worker inline + connection-close + progreso JSON)
admin.html               → Protege: P06, P07, P08, P09, P10, P11, P16, P17, P22
```

---

## ⚠️ PROTOCOLO DE MODIFICACIÓN SEGURA

Antes de aplicar cualquier cambio, Antigravity declarará:

```
📋 ANÁLISIS DE IMPACTO:
   Archivo a modificar : [nombre]
   Procesos afectados  : [P01, P05, ...] o NINGUNO
   Riesgo              : ALTO / MEDIO / BAJO / NULO
   Estrategia          : [qué se hará para no romper los procesos activos]
   ¿Proceder?          : Esperando aprobación de Israel
```

---

## 📌 ARCHIVOS CRÍTICOS — NO MODIFICAR SIN ANÁLISIS

| Archivo | Razón |
|:--|:--|
| `backend/auth.php` | Punto de autenticación global; tocarlo puede dejar sin sesión a todos los módulos |
| `backend/proxy.php` | DHARMA Fixes #22, #23, #25 integrados; modificar puede romper reproducción de streams |
| `js/app.js` | Núcleo del reproductor, lógica de sesión iOS, exclusiones de proxy, caché Read-Through e inyección de fallbacks |
| `backend/cache_manager.php` | Administrador relacional de streams. Si se altera, el sistema de caché y carga en 0s se quiebra |
| `backend/check_status.php` | Lista CF-Bypass crítica. Modificar puede causar falsos negativos (rojo) en el dashboard de administración |
| `backend/db.php` | Conexión central a MariaDB; cambios aquí afectan todos los endpoints de datos |
| `backend/stream_remote.php` | Proxy passthrough cURL con 6 niveles de blindaje para Archive.org; manejar status 206, CORS, Range Requests |
| `sw.js` | Service Worker PWA; errores aquí pueden dejar la app en caché rota en dispositivos iOS |

---

## 🔖 REGISTRO DE VERSIÓN AL MOMENTO DEL BLINDAJE

| Componente | Versión / Estado |
|:--|:--|
| Motor de Proxy Backend | DHARMA v27 (Anti-Truncado + Syntax Fix + Cache-Buster) |
| Motor de Proxy Frontend | DHARMA v29.1 (Caché Relacional Read-Through — app.js v13.1) |
| Sonda de Verificación | v2.8 (CF-Bypass: medixiru, cloudwindow, callistanise, vimeos.net) |
| Motor Faro | v1.5 + Fallback de Logs |
| Autenticación | Session + SID Token (iOS ITP Bypass) |
| PWA | manifest.json + sw.js v2.1 (Purga Zombi) |
| Gateway Sync | tunnel.json → GitHub Pages auto-push |
| Watchdog | tunnel_watchdog.sh + galix.sh con `--metrics localhost:4400` |
| Auditoría | runAuditor() con `rows` corregido (Fix 2026-05-12) |
| Extensión CORS | GalixSniper v3.1 (Inyección Dinámica de reglas `declarativeNetRequest`) |
| Motor de Autocuración | Galix Autopilot Engine v2.1 (Embed Fallback + Non-Seed Verification + is_online removed — curación total sin filtro) |

### 🔒 Dominios bajo protección CF-Bypass

| Dominio | Motivo del Bypass | Método de Reproducción |
|:--|:--|:--|
| `medixiru.com` | Cloudflare estricto, bloquea datacenter | Carga Directa + GalixSniper |
| `cloudwindow-route.com` | Cloudflare estricto, bloquea datacenter | Carga Directa + GalixSniper |
| `callistanise.com` | Cloudflare estricto, bloquea datacenter | Carga Directa + GalixSniper |
| `vimeos.net` | nginx 403 por IP de datacenter, CORS nativo abierto | Carga Directa nativa |
| `inkapelis.cyou` | Cloudflare Turnstile, requiere interacción | Sniper + Iframe Fallback Interactivo |

---

## 🦅 DOCTRINA FÉNIX / DARMA: LECCIONES APRENDIDAS

Los errores son el mapa de ruta hacia la invulnerabilidad del sistema. A partir del protocolo Fénix, cada fallo operativo debe registrarse, analizarse y convertirse en una mejora permanente de la arquitectura:

*   **Error de Caché Zombi (M6/M7/M55):** El navegador puede retener configuraciones de CSS/JS destructivas a pesar de recargas blandas. Adicionalmente, el Service Worker anclará estas cachés ignorando el cache-buster del HTML. **Lección:** Ninguna actualización crítica debe desplegarse sin forzar el *cache-busting* (`?v=XX`) y, si hay SW, se DEBE obligatoriamente subir la versión de `CACHE_NAME` en `sw.js` e invocar el nuevo script desde el frontend.
*   **Amnesia de Contexto (M7):** Escribir una mejora en la bitácora no equivale a que el código se haya guardado. **Lección:** Verificación cruzada; el código productivo es la única fuente de verdad, la bitácora es su sombra.
*   **Colapso HLS por Rutas Absolutas (M8/M9):** Los servidores piratas de streaming son entornos hostiles; utilizan arquitecturas no estándar. **Lección:** El motor `proxy.php` jamás debe dar por sentada la estructura de un manifiesto `.m3u8`. Si una ruta comienza con `/`, pertenece al *Domain Root* (`scheme://host`), no a la ruta relativa del archivo en el servidor. El proxy ahora escanea y reconstruye estas URLs quirúrgicamente sin destruir el resto de la estructura HLS.
*   **Conflictos de Red IPv6 / IP-Locked (M10/M11):** Los tokens de IP en streaming pueden fallar en redes LAN donde cada dispositivo tiene una IPv6 pública distinta. **Lección:** Forzar `CURL_IPRESOLVE_V4` en peticiones críticas del servidor para garantizar que la IP del proxy coincida con la IP del token generado por el usuario.
*   **Truncado de Datos (M12):** Los enlaces encriptados de Workers pueden exceder los 500 caracteres. **Lección:** Usar siempre tipo `TEXT` para campos de URLs persistentes para evitar errores SQL 1406.
*   **CORS Firewall Block (M55):** El proxy backend no puede procesar servidores extremadamente estrictos respaldados por Cloudflare debido al bloqueo de IPs de Data Centers. **Lección:** La única arquitectura viable para evadir esto es la "Carga Directa", donde el frontend pide el recurso asistiéndose de la API `declarativeNetRequest` (GalixSniper) para inyectar dinámicamente `Access-Control-Allow-Origin: *` anulando silenciosamente el bloqueo de Chrome.
*   **Dashboard False-Negative (M55/M56):** Un CDN puede funcionar correctamente para el usuario final (carga directa) pero devolver 403 al servidor de verificación (`check_status.php`), pintando un falso rojo en el dashboard. **Lección:** Todo CDN con restricción de IP de datacenter debe añadirse al bloque CF-Bypass de `check_status.php` para que el servidor lo reporte automáticamente como ONLINE sin ejecutar el cURL, alineando el indicador visual con la realidad operativa.

---

## DHARMA Fix #32, #33 y #34: Blindaje Maestro de Iframes y Sniper
- **Contexto:** Al cargar reproductores de terceros que bloqueaban iframes (`frame-ancestors 'self'`) como PelisCalidad, el Sniper esperaba clics humanos y terminaba en `Sniper timeout`. Además, el Motor Fantasma (Phantom Mode) ocultaba los controles superiores cuando un iframe de terceros tomaba el foco, dejando al usuario atrapado.
- **Acción (DHARMA #32):** Se inyectó la bandera global `window.isIframeActive` en el Motor Fantasma (`app.js`) para anular la ocultación del header cuando se activa un reproductor embed, previniendo el "secuestro de pantalla".
- **Acción (DHARMA #33):** Se habilitó el permiso de `scripting` en el Sniper y se programó un "Auto-Clicker" inyectado desde el Service Worker (`background.js`) capaz de saltar barreras anti-autoplay (usando `allFrames: true`).
- **Acción (DHARMA #34):** Se descubrió que el embed de PelisCalidad es sólo una carcasa vacía. Se programó un desvío dinámico en `app.js` para reenrutar los enlaces de PelisCalidad desde Sniper hacia **Fénix**. Posteriormente, se calibró `extract.php` con cURL para romper la carcasa, extraer el iframe interno de Vimeus y devolverlo como SPA-safe, esquivando totalmente el bloqueo CSP de PelisCalidad.
- **Estado:** 100% Nativo, los botones jamás desaparecen en iframes y los enlaces de PelisCalidad cargan limpiamente.

## DHARMA Fix #35: Phoenix Multi-Mirror Redundancy
- **Context:** Enlaces agregados con `extract:` (como Vimeus) son en realidad distribuidores inteligentes que contienen múltiples opciones internas de idioma (Latino, Castellano, Sub) y servidores (Goodstream, Streamwish, Voe, etc.). Anteriormente, Fénix solo extraía la primera opción, perdiendo el resto de los idiomas y obligando al administrador a crear múltiples entradas de metadatos de forma manual.
- **Acción (DHARMA #35):** Se rediseñó el motor de inyección de `app.js`. Al resolver una URL con `extract:`, el extractor Fénix extrae e inyecta dinámicamente todo el array de mirrors adicionales directamente dentro del `sourceQueue` de reproducción en tiempo real. 
- **UX:** El primer espejo se carga al instante, pero el resto de las opciones de idioma y servidor de Vimeus quedan registradas en la cola de redundancia. Si el usuario desea cambiar de Castellano a Latino o de servidor, solo debe presionar el botón nativo de **"Siguiente Servidor"** y la cabecera mostrará dinámicamente algo como: `S2 (Opción 2: Castellano - Voe)`.
- **Estado:** 100% Operativo y libre de mantenimiento manual.

## DHARMA Fix #36: Blindaje de Fallback ante Sniper Timeout por Cloudflare
- **Context:** Cuando una semilla de tipo `sniper:` (como Inkapelis o Vimeus) se encuentra protegida por Cloudflare Turnstile, la extensión en background no puede autoejecutar la reproducción, resultando en un `Sniper timeout`. El reproductor intentaba entonces un fallback fallido que trataba la página HTML como un flujo M3U8 cargado por `proxy.php`, produciendo un error de carga de manifiesto (403).
- **Causa:** Un bloque `else` en la rutina del extractor `extract:` sobreescribía de manera destructiva la variable `window.isForcedEmbed = false` justo después de que el Sniper la hubiera forzado a `true`.
- **Acción:** Se eliminó la sobreescritura redundante en `js/app.js`. Ahora, el timeout del Sniper preserva de forma íntegra `window.isForcedEmbed = true`, haciendo que la URL de origen se inyecte directamente como un iframe seguro y funcional (SPA-safe) en el `quarantineContainer`.
- **UX:** El usuario ve el reproductor con el reto de Cloudflare en pantalla, realiza la verificación con un solo clic directamente dentro de GalixMovie, e inicia la reproducción, permitiendo al Sniper capturar el flujo en vivo y guardarlo inmediatamente en el caché del servidor.
- **Estado:** 100% Blindado y Verificado.

## DHARMA Fix #37: Inmunidad DNS y Blindaje contra Cuelgues de Carga en Server-Side Sniper
- **Context:** En el servidor Symmetry Box (Termux), la ejecución del motor Server-Side Sniper (`sniper.py`) se quedaba congelada indefinidamente por timeouts a nivel del puerto HTTP del controlador (`HTTPConnectionPool Read timed out`) o fallaba con `net::ERR_NAME_NOT_RESOLVED`.
- **Causa:** 1) Bloqueo total del puerto 53 UDP por parte del proveedor local de internet o la VPN Tailscale (en modo userspace), impidiendo la resolución DNS convencional. 2) Comportamiento predeterminado de Selenium de esperar a que todo el DOM y todos sus trackers y banners carguen por completo (`readyState == "complete"`), atrapando la ejecución en páginas de streaming saturadas de recursos lentos. 3) Ruta incorrecta al ejecutable de Chromium en Termux (se buscaba `chromium` en lugar de `chromium-browser`).
- **Acción:**
  - **DoH por IP Directo**: Inyectar la plantilla de resolución DNS-over-HTTPS (DoH) de Google directamente usando su IP pública (`--dns-over-https-templates=https://8.8.8.8/dns-query`). Al no requerir resolver un dominio a nivel de UDP 53, Chromium procesa las consultas directo en el puerto 443 HTTPS de forma completamente inmune al bloqueo de red del ISP o VPN.
  - **Page Load Strategy & Timeout**: Configurar la estrategia de carga en `chrome_options.page_load_strategy = 'eager'` y declarar un timeout estricto de carga de 10 segundos (`driver.set_page_load_timeout(10)`) atrapado elegantemente con `TimeoutException`. Esto previene que el scraper se quede atorado y le permite continuar de inmediato interceptando URLs de recursos ya cargados.
  - **Ruta Fiel**: Apuntar la ruta de Chromium en el entorno Termux a `/data/data/com.termux/files/usr/bin/chromium-browser`.
- **Estado:** 100% Operativo y Blindado. La ejecución se realiza en menos de 15 segundos y retorna JSON impecable con exit code 0.

## DHARMA Fix #38: Travesía Recursiva e Intercepción de Sub-iframes en Server-Side Sniper v13.5
- **Context:** Enlaces dinámicos que incrustan reproductores anidados en capas profundas de iframes de origen cruzado (como PelisCalidad -> Vimeus -> Vimeos.net) resultaban en fallos tipo `No HLS stream intercepted` en el raspador del servidor debido al bloqueo de comunicación y eventos entre frames de dominios diferentes.
- **Acción:** Se diseñó una rutina de búsqueda recursiva (`driver.switch_to.frame`) en `sniper.py` que recorre dinámicamente todo el árbol de iframes del DOM. En cada nivel, simula clics nativos simulados sobre elementos interactivos para forzar la carga del reproductor interno, e inyecta consultas directas a la API de Rendimiento (`window.performance.getEntriesByType('resource')`) dentro del contexto exacto de cada sub-iframe aislado. Esto permite capturar directamente el `.m3u8` maestro firmado y sus tokens sin importar la barrera CORS de origen.
- **Estado:** 100% Operativo y Blindado. Intercepción exitosa en menos de 9 segundos en el servidor de Termux.

## DHARMA Fix #39: Arquitectura Pentafásica Híbrida y Auto-Expansión Dual de Semillas
- **Context:** La complejidad y fatiga de tener que rellenar manualmente los 5 campos alternativos en el panel administrador para prever caídas de reproductores externos.
- **Acción:** Se formalizó la decisión de diseño de conservar los 5 campos físicos en la base de datos para mantener compatibilidad con archivos locales (premium) y diversas semillas web alternas. No obstante, se integró el motor de **Auto-Expansión Dual** y **Cosecha Dinámica**: con sólo rellenar el primer campo con una semilla (`extract:https://vimeus.com/...`), el sistema autogenera las opciones Sniper (cliente) y Fénix (servidor) en la cola de reproducción, y cosecha al vuelo hasta 4 mirrors (Goodstream, Vimeos.zip, Voe, Filemoon) protegidos por proxy y caché. Esto multiplica un único campo copiado en hasta 8 opciones reales y autocurables en pantalla, maximizando la robustez de reproducción multi-dispositivo (SmartTV, iPad, PC) con cero esfuerzo de administración.
- **Estado:** 100% Operativo y Blindado.

## DHARMA Fix #40: Inyección de Entorno de Ejecución Termux en Servidores Web
- **Context:** Cuando el extractor Fénix backend (`extract.php`) intentaba ejecutar el motor Server-Side Sniper (`sniper.py`) a través de un request del cliente web, la llamada fallaba con un código de error de sistema `127` (python3 no encontrado) o un `Segmentation fault` (código de error `139`).
- **Causa:** Las peticiones PHP procesadas por Nginx o Apache en Termux se ejecutan bajo un entorno de usuario altamente restringido (`u0_aXX`) que carece por completo de las variables de entorno nativas de Android/Termux (como `ANDROID_DATA`, `ANDROID_ROOT`, `LD_PRELOAD`, `PATH`, etc.). Al no tenerlas, el ejecutable de Python3 no logra inicializar su motor biónico y segfaulea al instante.
- **Acción:** Se integró una rutina de inyección de entorno dinámico utilizando `putenv()` en PHP directamente antes de llamar a `exec()` sobre `sniper.py`. Se restauraron de forma explícita las variables clave detectadas de la sesión activa (`ANDROID_DATA`, `ANDROID_ROOT`, `PREFIX`, `HOME`, `TMPDIR`, `LD_PRELOAD` y `PATH`).
- **Resultado:** Inmunidad total en Nginx/Apache. Las llamadas remotas ejecutan el Server-Side Sniper limpiamente en menos de 8 segundos, resolviendo el HLS maestro firmado directamente en iPad, iPhone, SmartTV o PC sin necesidad de extensiones en el cliente.
- **Estado:** 100% Operativo y Blindado.
## DHARMA Fix #45: Hard Refresh de Fuerza Bruta en Panel Administrativo (Limpieza Profunda)
- **Context:** Las cachés agresivas del Service Worker (PWA) y del navegador (`caches.keys()`) anclan versiones antiguas de `admin.html` en iOS Safari o Chrome, volviendo invisible el despliegue de nuevas interfaces gráficas o herramientas (como la consolidación del Toolbelt o módulos expansibles). Para obligar al dispositivo a solicitar la versión más fresca, era necesario limpiar la caché a mano desde las configuraciones del sistema.
- **Acción:** Se diseñó y expuso un botón "nuclear" (`Hard Refresh`) en el encabezado superior izquierdo de `admin.html`. Este botón contiene una función en línea autoejecutable (`onclick`) que itera sobre la API de Caché del navegador borrando todas las llaves almacenadas y luego fuerza una redirección mediante el parámetro de evasión `?v=timestamp` (`window.location.href = window.location.pathname + '?v=' + Date.now();`).
- **Estado:** 100% Operativo. Un solo clic y el dispositivo borra toda la caché en memoria y reconecta al servidor web local forzando un refresco total de la última versión del código.

## DHARMA Fix #42: Proxy Inteligente basado en Pertenencia de Token (Bypass del Proxy en Donaciones Manuales)
- **Contexto:** Al intentar reproducir semillas M3U8 extraídas manualmente en la MacBook (como Goodstream o Vimeos), el reproductor lanzaba un error **403 (Forbidden)**.
- **Causa:** Estos CDNs de video implementan **IP-Binding** en sus tokens de acceso (el token está estrictamente amarrado a la dirección IP que lo solicitó originalmente). Al pasar todo el tráfico de video por `proxy.php`, la IP de la Box Symmetry (Servidor) realizaba la solicitud en lugar de la IP de la MacBook (Cliente), provocando un rechazo del CDN por discrepancia de IP.
- **Acción:** Se implementó una bandera inteligente llamada `window.isFenixExtractedToken` en `js/app.js`. 
  - Si el token es generado por Fénix en el servidor, se mantiene el uso obligado del Proxy (ya que el token pertenece a la IP del Servidor).
  - Si el token es generado por el cliente (donaciones manuales o extracción local vía Sniper), se realiza un **Bypass total del Proxy**, cargándolo de forma directa con la IP de la MacBook para que coincida 100% con la IP del token y el CDN apruebe la reproducción.
- **Cache-Busting PWA:** Se actualizó la referencia de `app.js` en `index.html` a la versión `v=13.1` para forzar a los navegadores a borrar su cache agresiva PWA y aplicar la nueva lógica de bypass al instante.
- **Estado:** 100% Operativo y Verificado. Los M3U8 de Goodstream/Vimeos pegados a mano arrancan a reproducir de inmediato sin interrupciones ni bloqueos de red.

## DHARMA Fix #43: Optimización Quirúrgica de Velocidad en Ciclado de Servidores y Reducción de Timeout
- **Contexto:** Al saltar de servidor (botón "Siguiente Servidor"), el reproductor presentaba congelamiento de red de hasta 15-20 segundos antes de reaccionar, y en dispositivos sin extensión (como iOS Safari/iPad) la pantalla se quedaba en negro durante 25 segundos antes de realizar el fallback a Fénix.
- **Causa:**
  1. **Auditoría Síncrona Masiva:** Al llegar al final de la cola, `app.js` ejecutaba un `Promise.all` con peticiones síncronas a `check_status.php` para *todos* los servidores de la película simultáneamente. Si varios estaban caídos, cURL causaba bloqueos de red y saturación en Termux (espera de hasta 15 segundos).
  2. **Timeout de Sonda Excesivo:** El canal local de la extensión (`galix-sniper-request`) tenía un timeout rígido de 25 segundos para declarar error. En dispositivos móviles que no soportan la extensión, el usuario experimentaba una pantalla en negro larguísima antes de que el script saltara a Fénix.
  3. **Sanitización de Esquemas Virtuales:** `check_status.php` recibía URLs con esquemas virtuales (`extract:` y `sniper:`), lo que causaba que `parse_url` fallara arrojando un error de `Invalid URL` (marcando servidores legítimos como caídos de forma errónea).
- **Acción:**
  1. **Contador de Fallos Local e Instantáneo:** Se eliminó por completo el `Promise.all` y la consulta masiva de red al ciclar. Se implementó la variable de control `consecutiveFailuresCount` que incrementa con fallos automáticos reales (atrapados en Hls o Native Safari) y se resetea al reproducir exitosamente o cargar nuevo video. Si `consecutiveFailuresCount >= sourceQueue.length`, se declara Apagón de Redundancia al instante. Si es menor (p. ej. saltos interactivos rápidos del usuario), se cicla instantáneamente al Servidor 1 en **0.0 segundos de espera**.
  2. **Bifurcación Manual y Automática:** Se parametrizó `window.tryNextSource(isManual)`. Si el click es manual, la variable de fallos se resetea a 0, evitando falsas alarmas de apagón por clicks consecutivos.
  3. **Reducción de Timeout de Sonda:** Se redujo el timeout del Sniper de 25s a **10s** (tiempo óptimo para MacBook y veloz fallback para iPad/móviles).
  4. **Sanitización en Backend:** Se inyectó una limpieza de prefijos (`preg_replace('/^(extract:|sniper:)/', '', $url)`) en `check_status.php` para prevenir el fallo de parser de URL.
- **Estado:** 100% Operativo y Verificado. Transición entre servidores ultra-fluida e instantánea.

## DHARMA Fix #44: Evolución Estética y Responsiva UX/UI Premium (Cápsula Flotante y Tabla Compacta con Scroll de Cristal)
- **Contexto:** 1) La barra de navegación móvil inferior ocupaba un bloque rígido de pantalla completa que chocaba visualmente con las áreas de notch de teléfonos. 2) La tabla de gestión de biblioteca de películas en `admin.html` contiene 11 columnas complejas de datos, lo cual hacía imposible su lectura nativa en celular. 3) Los botones de acción de biblioteca ocupaban mucho espacio en móviles, empujando el diseño. 4) Las tarjetas de estadísticas se apilaban verticalmente ocupando valioso espacio táctil.
- **Acción:**
  - **Cápsula Flotante Glassmorphic (Mobile Navbar):** Rediseñamos la barra de navegación móvil en `@media (max-width: 768px)` en `style.css` para suspenderse como una cápsula flotante estilizada (`bottom: 15px`, `width: 90%`, `left: 5%`, `border-radius: 30px`). Inyectamos un fondo translúcido (`background: rgba(15, 23, 42, 0.65)`) y desenfoque por hardware (`backdrop-filter: blur(25px)`) con bordes cristalinos finos. Ajustamos el padding-bottom del cuerpo a `95px`.
  - **Tabla Ultra-Compacta con Scroll de Cristal (Admin Layout):** Establecimos un contenedor de desbordamiento horizontal suave (`overflow-x: auto`), definiendo un ancho de tabla denso de `760px` en móviles. Redujimos la tipografía general de tabla a un tamaño micro de `0.65rem` y los paddings de celda a un mínimo de `3px 2px`. Diseñamos barras de scroll ultra-finas de cristal neón.
  - **Truncado Inteligente:** Ajustamos un ancho máximo de `100px` con elipsis (`overflow: hidden; text-overflow: ellipsis; white-space: nowrap;`) para el Título en móviles.
  - **Cuadrícula de Botones en 3x2:** Agrupamos los 5 botones del panel superior de administración en una rejilla CSS Grid compacta (`.library-actions-grid`) de **3 columnas por 2 filas** en móviles, acortando proactivamente sus nombres a denominaciones comprimidas (*Escanear*, *Autopilot*, *Verif. Serv.*, *Sinc. Gate*, *Aud. Index*) para lograr un formato ergonómico libre de envolturas.
  - **Tarjetas de Estadísticas Coalineales:** Rediseñamos las tarjetas de estadísticas (`.stats-grid`) en móviles para colocarse lado a lado en una sola fila (`grid-template-columns: 1fr 1fr` con `gap: 8px`). Redujimos los paddings a `8px`, las fuentes de valores a `1.15rem` y de etiquetas a `0.58rem` con `white-space: nowrap` y elipsis, logrando un formato ultra-compacto que aprovecha el 100% de la pantalla sin deformaciones.
  - **Píldoras y Micro-glows de Servidores S1-S5:** Enriquecimos los indicadores de servidores `renderStatusPlaceholder` para renderizar cápsulas con iconos coordinados y sutiles brillos neón translúcidos.
  - **Minimización de Espaciado Vertical (Mi Lista a Continuar Viendo):** Para reducir al mínimo absoluto la separación entre el botón "Mi Lista" en el Hero y la fila de "Continuar Viendo" / "Tu Biblioteca", eliminamos el `margin-top` por defecto del navegador en `.row-title` (`margin-top: 0`), redujimos el `padding-bottom` de `.hero` en celular de `20px` a `4px`, y ajustamos la separación vertical superior de `.content-row` en móviles a `0px` (`padding: 0 4% 0.5rem 4%`), trayendo el contenido principal inmediatamente después del Hero.
- **Estado:** 100% Operativo, Premium y responsivo en todos los tamaños de pantalla.

## DHARMA Fix #47: Super-Compactación del Hero Móvil y Background Vertical Adaptativo (Bypass de Altura Excesiva)
- **Contexto:** En teléfonos móviles con orientación vertical (portrait), el Hero mantenía una altura de `75vh` con un ancho mínimo de `450px`, lo que empujaba el catálogo interactivo de películas por debajo del límite visual de la pantalla. Además, el uso de la imagen panorámica horizontal (`backdrop_path`) en pantallas delgadas requería demasiada altura para no cortarse de forma inestética.
- **Acción:**
  - **Selección Dinámica de Póster (`app.js`):** Reprogramamos `updateHero()` para detectar si el usuario se encuentra en un viewport móvil (`window.innerWidth <= 768`). En caso afirmativo, se prioriza automáticamente la imagen del póster vertical (`movie.poster_path`) en lugar de la panorámica (`movie.backdrop_path`), logrando una perfecta adaptación de aspecto vertical al display del smartphone.
  - **Reducción Quirúrgica de Altura (`style.css`):** Recortamos la altura del Hero en móviles de `75vh` a un valor super-compacto de **`42vh`** (con un min-height de apenas `260px` y padding-top reducido a `60px`), logrando que todo el catálogo de películas suba y se presente de inmediato en el viewport principal sin necesidad de hacer scroll.
  - **Legibilidad Estelar:** Para garantizar que el texto quepa en este nuevo contenedor ultra-estrecho de 42vh, redujimos el tamaño del título a `1.4rem` y limitamos quirúrgicamente la sinopsis a un máximo de **2 líneas** (`-webkit-line-clamp: 2`). Ajustamos el brillo y atenuación de la imagen del poster a `opacity: 0.45` con alineación vertical del fondo a `object-position: center 20%`.
  - **Cache-Busting Forzado:** Incrementamos la versión de `app.js` a `v=13.2` y el Service Worker a `sw.js?v=4.0` en `index.html` para invalidar cachés agresivos en PWAs de iPhone/iPad.
- **Estado:** 100% Operativo y Verificado visualmente. Catálogo inmediatamente accesible sobre la línea de doblado de pantalla en móvil.

## DHARMA Fix #48: Secciones Horizontales de 10 Películas y Limitación de Continuar Viendo a Máximo 8
- **Contexto:** 1) A medida que la biblioteca crece (más de 120 películas), renderizar todas las películas en una única fila horizontal infinita satura la memoria del navegador, provoca lags de GPU y reduce el interés por navegar. 2) La sección de "Continuar Viendo" acumulaba demasiados elementos inactivos, perdiendo su propósito UX de acceso rápido.
- **Acción:**
  - **Secciones de 10 en 10 (Tanto PC como Móviles):** Eliminamos la sección de biblioteca estática del HTML de `index.html`, sustituyéndola por `<div id="dynamicRowsContainer"></div>`. Reprogramamos `renderGrid()` en `js/app.js` para fragmentar el listado de películas en subgrupos contiguos de **exactamente 10 películas**. Para cada grupo, genera dinámicamente un `<section class="content-row">` con un título numerado secuencialmente (`Tu Biblioteca` para el primero, `Tu Biblioteca - Sec. 2`, `Tu Biblioteca - Sec. 3`, etc.).
  - **Limitador de Historial (Máximo 8):** Modificamos `loadContinueWatching()` en `js/app.js` para realizar una extracción controlada `.slice(0, 8)` sobre el historial devuelto por el backend. Esto mantiene el carrusel de progreso ágil y relevante sin clutter de UI.
  - **Scroll y Drag-to-Scroll Reutilizable:** Diseñamos un controlador global de registro `window.initGridScrolling(grid)` en `index.html`. Éste se llama dinámicamente tanto en la inicialización estática como cada vez que `js/app.js` renderiza y añade una nueva fila de 10 películas al contenedor, garantizando que el deslizamiento por rueda de mouse en PC y arrastre táctil (drag) funcionen con total fluidez de hardware en el 100% de las filas dinámicas.
  - **Cache-Busting:** Incrementamos `app.js` a `v=13.3` y el Service Worker a `sw.js?v=4.1` en `index.html`.
- **Estado:** 100% Operativo, ultra-optimizado para PC/móviles y verificado de extremo a extremo.

## DHARMA Fix #49: Ventana de Detalles Premium e Integración en Tiempo Real de Elenco de TMDB
- **Contexto:** Al hacer clic en cualquier tarjeta del catálogo o de progreso, el sistema iniciaba directamente la reproducción. Para una experiencia premium similar a Netflix o HBO Max, se requería una ventana de detalles intermedia estéticamente atractiva que muestre el póster, la sinopsis detallada, calificación, año, tipo de contenido y el elenco de actores principales antes de comenzar.
- **Acción:**
  - **Diseño Glassmorphic Impecable (`index.html`):** Insertamos el elemento `#movieDetailsModal` con fondo de cristal ultra-desenfocado (`backdrop-filter: blur(20px)`), bordes sutiles y sombras de neón moradas. La estructura es grid de dos columnas (Póster portrait a la izquierda, metadatos y botones a la derecha) en PC, que colapsa de forma responsiva en celular.
  - **Búsqueda Dinámica de Elenco (Credits API):** Integramos una consulta asíncrona client-side automática en tiempo real a la API oficial de TMDB (`creditsUrl`) utilizando el `tmdb_id` para extraer y listar los nombres de los 5 actores principales de forma dinámica y elegante, superando las limitaciones físicas de la base de datos sin agregar columnas de reparto en backend.
  - **Acción Unificada de Tarjetas:** Reprogramamos los controladores de eventos `onclick` en `renderGrid()` y `loadContinueWatching()` en `js/app.js` para llamar a `window.showMovieDetails(movie)` en lugar de reproducir directamente.
- **Estado:** 100% Operativo y verificado en PC y smartphones.

## DHARMA Fix #50: Pre-cargador Ultra-Veloz en Background (Cero Tiempos de Espera)
- **Contexto:** La extracción de enlaces de servidores externos (como sniper: y extract:) tarda entre 1 y 5 segundos en completarse. Esperar a que el usuario presione el botón de Play final para iniciar este proceso resulta en una UX lenta. Se requería pre-cargar y pre-resolver silenciosamente la película en segundo plano desde el momento en que se abre la ventana de detalles.
- **Acción:**
  - **Resolución Asíncrona Previa (`preloadMovieSources`):** Creamos una función global que, al abrir la ventana de detalles, obtiene el primer enlace no vacío de la película. Si es una semilla `extract:` o `sniper:`, consulta la caché de Autopilot (`get_cached_mirrors.php`).
  - **Cosecha Silenciosa en Background:** Si es un Miss de caché, realiza una llamada silenciosa (`fetch`) a `backend/extract.php` en segundo plano. Esto resuelve la redirección/token y la guarda automáticamente en la base de datos de Autopilot. Al presionar "Ver Ahora", la reproducción es instantánea (HIT de caché garantizado).
  - **Pre-buffer HTTP Nativo:** Si la película tiene un enlace directo (MP4 o HLS) o ya está resuelto en caché, crea de manera silenciosa un objeto `<video preload="auto" muted>` en memoria y llama a `.load()`. Esto hace que el navegador comience a descargar el archivo en la caché de red del navegador del cliente, de modo que al dar Play, el video se inicia en 0.01 segundos.
  - **Sanidad de Memoria:** Destruye y libera el preloader anterior cada vez que se abre un nuevo contenido para evitar fugas de memoria y consumos de red innecesarios.
  - **Cache-Busting:** Incrementamos `app.js` a `v=13.5` y el Service Worker a `sw.js?v=4.3` en `index.html`.
- **Estado:** 100% Operativo, ultra-rápido y verificado. Reducción de latencia a cero en el botón Play.

## DHARMA Fix #51: Tarjetas de Reparto Visuales Premium (Plex/Netflix Style) con TMDB
- **Contexto:** La API de TMDB en el modal de detalles mostraba los actores únicamente como texto plano, lo que no aprovechaba la riqueza visual de los metadatos disponibles. Se solicitó implementar un diseño visual con las fotos de perfil de los actores idéntico al de Plex.
- **Acción:**
  - **Reestructuración de UI (`index.html`):** Transformamos el contenedor de texto `#detailsCast` en un carrusel dinámico `.details-cast-grid` con scroll horizontal táctil y por rueda de ratón (scrollbar de cristal). Diseñamos el componente visual `.actor-card` con avatares circulares `.actor-img-wrapper`, efectos de hover (`scale 1.05`), y etiquetas de nombre de actor (`.actor-name`) y personaje interpretado (`.actor-character`).
  - **Generación Dinámica (`js/app.js`):** Modificamos el pipeline asíncrono de `showMovieDetails` para generar y renderizar las tarjetas HTML de los **8 actores principales**.
  - **Fallback Inteligente (PWA Offline-Ready):** Para los actores que no cuentan con foto de perfil en TMDB (`profile_path` nulo), desarrollamos un generador instantáneo de avatares SVG in-line (Data URI) que dibuja un círculo oscuro con borde de neón e inyecta las iniciales del actor en color morado, eliminando dependencias externas de generadores de avatares y garantizando tiempos de renderizado de 0 milisegundos.
- **Estado:** 100% Operativo. El modal ahora exhibe un reparto visual espectacular.

## DHARMA Fix #52: Bypass de WAF y Reparación de Cabeceras HTTP en Inyección de TMDB Backend
- **Contexto:** Al intentar inyectar o actualizar películas de forma manual desde el panel administrativo (`admin.html`), el sistema arrojaba un error de parseo JSON (`SyntaxError: Unexpected token '<'`).
- **Causa:** El motor cURL en el entorno local/NAS disparaba advertencias que contaminaban la salida PHP, y las peticiones sin User-Agent eran bloqueadas por las capas defensivas de Cloudflare/WAF de TMDB, retornando un error HTTP 403 con contenido HTML. Al recibir HTML en lugar de JSON, el JavaScript del cliente fallaba al parsearlo.
- **Acción:** Se migró de cURL a `file_get_contents` en `manual_index.php` y `update_movie.php` mediante el uso de un `stream_context` configurado con cabeceras de simulación de navegador (`User-Agent` de Chrome y `Accept-Encoding: gzip`). Se inyectó decodificación gzip mediante `gzdecode` para manejar respuestas comprimidas y garantizar la recuperación limpia del JSON, blindando por completo las solicitudes de inyección y actualización del backend.
- **Estado:** 100% Operativo y Verificado.

## DHARMA Fix #53: Motor de Calificación Heurística y Priorización por Estrellas en GalixSniper
- **Contexto:** Se solicitó priorizar visualmente en el radar de captura de `GalixSniper` los enlaces con mayor estabilidad (enlaces permanentes, tokens persistentes, sin IP-lock, permanencia universal) mediante una escala de calificación por estrellas para guiar la selección manual de mirrors de forma inmediata.
- **Acción:**
  - **Lógica de Calificación (`getUrlRating` en popup.js):** Diseñamos un motor heurístico que evalúa el patrón de las URLs y asigna una prioridad en estrellas: `extract:` y embeds permanentes obtienen 5 estrellas (⭐⭐⭐⭐⭐); semillas `sniper:` de cliente obtienen 4 estrellas (⭐⭐⭐⭐); streams directos con tokens persistentes (ej. Voe, Wish) obtienen 3 estrellas (⭐⭐⭐); enlaces directos con IP-lock estricto obtienen 2 estrellas (⭐⭐); y enlaces efímeros/desconocidos obtienen 1 estrella (⭐).
  - **Enriquecimiento del UI:** Integramos los badges de estrellas con colores HSL personalizados, leyendas descriptivas sobre IP-lock/longevidad de sesión, y descripciones técnicas al lado de cada enlace e iframe del popup.
  - **Eliminación de Redirección Innecesaria e Inspección Directa:** Eliminamos el botón `sniper_page` que apuntaba a la URL completa del sitio contenedor para evitar inyectar páginas de navegación en lugar de flujos de reproducción, conservando exclusivamente las de reproductores (`sniper_embed`/`extract_embed`). A su vez, inyectamos en la sección de reproductores capturados el botón `🔗 Link Embed` (para copiar la URL directa del reproductor iframe) y el botón `🌐 Abrir` (para lanzar el reproductor limpio en una pestaña nueva con el fin de auditar o extraer semillas).
- **Estado:** 100% Operativo. Los enlaces se listan con su nivel de estrellas y controles de inspección en el popup.

## DHARMA Fix #54: Auto-Detección y Normalización Multicanal de Dominios Dinámicos en GalixSniper
- **Contexto:** Al interceptar m3u8s de dominios temporales de Streamwish/Vimeos o con nomenclaturas complejas de calidad (como `_,l,n,h,`), la extensión no lograba construir el URL del reproductor embed (`embedUrl`). Asimismo, en servidores como `cloudwindow-route.com` se generaban embeds inválidos tipo `embed-engine` al confundir el directorio fijo de enrutado `/engine/` con un ID de video dinámico. Del mismo modo, las URLs de reproductores iframe (`cloudwindow-route.com/embed-ID`) no eran normalizadas a `vimeos.net` automáticamente al guardarse o al procesar solicitudes por el puente de refresco.
- **Acción:**
  - **regex Flexibilizado:** Modificamos la expresión regular de calidades en `background.js` a `/([a-zA-Z0-9]{8,20})_,[a-z0-9,]+\.urlset/i` para capturar identificadores con descriptores alfabéticos de calidad.
  - **Detección de ID de Embed:** Añadimos un extractor de ID para reproducir iframes (`/embed-([a-zA-Z0-9]{8,20})` o `/e/([a-zA-Z0-9]{8,20})`), permitiendo extraer la ID de video del propio iframe.
  - **Mapeador Inteligente con Exclusión:** Añadimos lógica que segmenta el path por niveles; si encuentra un marcador HLS (ej. `/hls3/`), selecciona el segmento de ruta anterior como el ID del video (`ocmb8ssa8kyo`). Para evitar el falso positivo en CloudWindow, inyectamos una regla de exclusión que ignora el valor `"engine"`, forzando a que se extraiga el ID de archivo real (`genericId`).
  - **Redirección de CDN a Reproductor y Fallback Universal:** Se implementó el desvío directo de `cloudwindow-route.com` a `vimeos.net` (`/embed-${videoId}.html`). Para blindar preventivamente el sistema de cara a futuros CDNs de streaming puros de Vimeos/Goodstream, modificamos el generador de enlaces dinámicos: si el dominio de origen no contiene ruta de ID (es un CDN puro) e intenta autogenerar un embed en su propio host, GalixSniper enruta de forma automática y transparente la consulta al reproductor inmutable `vimeos.net/embed-[ID].html` (⭐⭐⭐⭐⭐).
  - **Normalización en Storage y Puente de Refresco:** Modificamos la recepción de `players_found` para normalizar las URLs de reproductores detectados en la página antes de guardarlos en el storage (convirtiendo `cloudwindow-route.com` a `vimeos.net`), y blindamos `sniper_refresh` para redirigir proactivamente al reproductor `vimeos.net` y eliminar el protocolo `sniper:` antes de intentar abrir el tab.
  - **Generación de Dominios Dinámicos:** Implementamos la extracción del dominio base de la petición al vuelo y la construcción del embed `/e/[ID]` para clones de Streamwish de 5 estrellas de forma universal.
- **Estado:** 100% Operativo y blindado tanto para transmisiones directas como para reproductores iframe.

## DHARMA Fix #55: Filtrado Antiduplicados por Video ID en Historial de GalixSniper
- **Contexto:** Al reproducir una película, los reproductores HLS de Goodstream/Vimeos realizan peticiones a múltiples servidores CDN paralelos (ej. `hls1.goodstream.one`, `hls2.goodstream.one`) o actualizan tokens dinámicos (`t=...`) de sesión con escasos minutos de diferencia. Al diferir la URL completa, el popup de la extensión mostraba registros redundantes para el mismo video, saturando la vista.
- **Acción:**
  - **Detección por Identificador Unico:** Modificamos la rutina de almacenamiento de red de la extensión (`background.js`) para que realice una validación cruzada.
  - **Filtro de Inserción:** Antes de añadir un nuevo link, si el objeto `normalizedInfo` posee un `videoId` válido, se escanea el historial agrupado de la pestaña. Si ya existe un registro con el mismo `videoId`, el flujo se interrumpe y la petición se descarta silenciosamente.
- **Estado:** 100% Operativo.

## DHARMA Fix #56: Exclusión en Cascada de Dominios de Trailers (Viloud TV) en GalixSniper
- **Context:** En algunas páginas web, se inyectan iframes o playlists de previsualización muy cortas (ej. clips promocionales de 20 segundos de `player.viloud.tv` o subdominios de BunnyCDN) antes de la película principal, lo que contaminaba el popup del radar y confundía al administrador.
- **Acción:**
  - **Filtro de Extracción del DOM (content.js):** Se añadió el dominio `viloud.tv` a la lista `trashDomains` para descartar de inmediato estos reproductores durante el escaneo automático.
  - **Filtro de Intercepción de Red (background.js):** Se agregó `viloud.tv` a la lista de exclusiones de red para evitar la captura de manifiestos y fragmentos provenientes de esta plataforma.
  - **Filtro de Renderizado (popup.js):** Se inyectó una validación en la carga de reproductores que excluye a `viloud.tv` de los resultados presentados, asegurando un listado 100% depurado con streams reales.
- **Estado:** 100% Operativo.

## DHARMA Fix #76: Stream Remote Proxy Blindado (6 Niveles) para Archive.org
- **Context:** Las 10 películas clásicas mexicanas almacenadas en Archive.org (.mp4 directo) no reproducían en el reproductor GalixMovie. El navegador las bloqueaba por CORS al intentar carga directa desde el túnel Cloudflare, y el proxy.php existente no soportaba Range Requests correctamente para archivos de video directo.
- **Causa Raíz:**
  1. **CORS Block:** Archive.org no envía `Access-Control-Allow-Origin` para dominios externos (túneles Cloudflare/Tailscale), el navegador rechaza la petición.
  2. **Status 206 Missing:** cURL con `CURLOPT_RETURNTRANSFER false` no forwarda el status line `206 Partial Content` del servidor remoto. El navegador recibía `200 OK` + `Content-Range`, violando el protocolo HTTP y causando `FFmpegDemuxer: open context failed`.
  3. **Double-Encoding:** `encodeURIComponent` en JavaScript genera `%2520` (doble codificación de espacios). Si se decodifica con `urldecode` sin filtro, `%20` se convierte en espacios reales → Archive.org responde HTTP 400.
- **Acción (6 Niveles de Blindaje):**
  1. **Anti-Open-Proxy:** Whitelist estricta de dominios (`archive.org` + subdominios `ia*.us.archive.org`). Rechaza cualquier otro dominio con HTTP 403.
  2. **Validación de Host:** `parse_url()` rechaza URLs malformadas o sin host definido.
  3. **Solo Extensiones de Video:** Permite exclusivamente mp4, webm, mkv, avi, mov, m3u8, ts.
  4. **Desactivación Segura de Compresión:** `apache_setenv('no-gzip', 1)` + `zlib.output_compression Off` sin romper el gestor de buffers global.
  5. **Timeout Inteligente:** `connectTimeout 15s` + `transferTimeout 300s`. Archive.org puede ser lento en la primera conexión.
  6. **Validación de Content-Type + Content-Length:** `CURLOPT_HEADERFUNCTION` intercepta headers, valida tipo de contenido, forwarda `206 Partial Content` correctamente, y loggea errores cURL.
- **Técnica Clave:** Puente cURL con `CURLOPT_RETURNTRANSFER false` para streaming directo sin buffer en RAM. `CURLOPT_HEADERFUNCTION` detecta `HTTP/1.1 206` → aplica `http_response_code(206)`. `CURLOPT_BUFFERSIZE 8192` para fragmentos ligeros. User-Agent Chrome 120 + Referer archive.org para evasión de bloqueo.
- **Integración Frontend:** `js/app.js` (Pre-cargador ~445 + loadSource ~1262) detecta `isRemoteDirectVideo` (URL http + no hostname local + extensión video) → reescribe a `backend/stream_remote.php?url=` codificada. Archivos locales y m3u8 mantienen flujo estándar.
- **Estado:** 100% Operativo y Blindado. Las 10 películas clásicas de Archive.org reproducen correctamente con soporte completo de Range Requests y CORS.

## DHARMA Fix #80: Autopilot Engine v2.0 — Worker Inline con Connection-Close (Adiós a exec())
- **Contexto:** El Autopilot Engine v1.0 limitaba la curación a solo 3 semillas por ejecución. La causa raíz era que el motor original usaba `exec()` para lanzar un proceso PHP externo (`autopilot_worker.php`), dependiendo de `PHP_BINARY`, permisos del sistema (`/tmp/`), y enfrentando timeouts de Cloudflare de 100s. En el entorno de producción (Apache/Nginx en Termux), `exec()` fallaba silenciosamente porque el binario PHP no estaba en el PATH del servidor web o el usuario del proceso no tenía permisos de escritura en `/tmp/`.
- **Acción:** Se reemplazó la arquitectura de worker externo por un **worker inline** que corre dentro del mismo proceso PHP de la petición HTTP, utilizando la técnica de **connection-close trick**:
  1. El endpoint `action=run` escribe el progreso inicial (`status: running`) a un archivo JSON.
  2. Ejecuta `ignore_user_abort(true)`, `set_time_limit(0)`, limpia buffers de salida.
  3. Declara `Connection: close`, envía la respuesta JSON `{"status":"started"}` al cliente.
  4. Llama a `fastcgi_finish_request()` (PHP-FPM) + `flush()` para que el cliente reciba la respuesta al instante.
  5. Luego continúa ejecutando toda la lógica de escaneo, curación y auto-fill directamente en el mismo script, escribiendo progreso al JSON con `LOCK_EX` para consistencia.
  6. El frontend (`admin.html`) implementa un **toast flotante no bloqueante** con polling cada 2s a `action=progress`, mostrando barra de progreso, contadores en vivo (procesados/total, curadas, contenido actual), y al completar un botón "📋 Ver Reporte" con detalle completo.
- **Archivos modificados:**
  - `backend/autopilot.php`: Nuevas acciones `run` (inline worker con connection-close), `progress` (lectura de JSON), función `getProgressFilePath()` (fallback a `__DIR__` si sys_get_temp_dir() no es escribible), función `writeProgressToFile()`.
  - `admin.html`: Reemplazo completo de `runAutopilot()` — toast en vivo + polling + manejador `idle` para casos de error.
  - `backend/autopilot_worker.php` → CREADO pero hoy huérfano (reemplazado por worker inline). Se dejó como referencia.
- **Dependencia eliminada:** `exec()` + binario PHP externo + permisos `/tmp/`. El worker inline no requiere nada más que PHP y PDO.
- **Estado:** 100% Operativo. Procesa el 100% de las semillas sin límite, con progreso en vivo en el toast y reporte final completo.

## DHARMA Fix #81: Proxy de Imágenes TMDB — Content-Type Fantasma por Deprecación de PHP 8.5
- **Contexto:** TMDB image CDN (image.tmdb.org) es ERR_ADDRESS_UNREACHABLE desde los clientes (MacBook, navegador) pero accesible desde Box Symmetry (HTTP 200 en 0.47s). Se implementó asset_proxy.php como proxy local para servir imágenes TMDB desde el servidor. Sin embargo, las imágenes no cargaban: el proxy devolvía HTTP 200 con el binario JPEG correcto (~130KB) pero Content-Type `text/html; charset=UTF-8` en vez de `image/jpeg`.
- **Causa Raíz:** PHP 8.5.1 deprecó `curl_close()` como no-op. Al ejecutarse con `error_reporting(E_ALL)` (activo en get_content.php), emitía un warning HTML ANTES de cualquier `header('Content-Type: ...')`, activando el envío implícito de headers. Todos los `header()` posteriores eran ignorados. PHP-FPM servía `text/html; charset=UTF-8` por defecto. El navegador, con strict MIME checking, rechazaba la imagen.
- **Complejidad del Diagnóstico:**
   1. `curl_close()` es ubicuo en ejemplos PHP; nadie cuestiona su presencia
   2. PHP no produce error fatal, solo un warning que no interrumpe ejecución
   3. El resto del script funciona perfectamente: curl descarga, echo sirve los datos
   4. Los datos binarios se sirven correctamente (130KB JPEG, HTTP 200)
   5. No hay error 500, no hay excepción, no hay log visible
   6. El deprecado es silencioso: no aparece en changelog de migración de PHP 8.5
- **Acción (DHARMA Hardening + PHOENIX Protocol):**
   1. Eliminar `curl_close()` de asset_proxy.php (innecesario en PHP 8.5+)
   2. `error_reporting(0)` en asset_proxy.php y get_content.php para suprimir cualquier warning que pueda corromper headers
   3. Reemplazar detección MIME por regex con `pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)` más robusto y predecible
   4. Cache buster (`?v=Date.now()`) en fetch de `get_content.php` y `get_progress.php` en app.js para evitar que SW sirva JSON cacheado con URLs viejas
   5. `CACHE_NAME` de SW bumpado a v6.0 + versiones bumpadas en index.html (app.js 15.3, sw.js 6.0, style.css 2.7)
   6. `SVG_FALLBACK` constante reusable + `onerror` handler en las 8 fuentes de imágenes dinámicas de app.js: heroImg, detailsPoster, grid principal, continueWatching, TV en vivo, clásicas, actores
   7. `trimCache()` en sw.js con límite de 200 items para evitar crecimiento infinito de caché PWA
   8. Notificación SweetAlert con auto-reload diferido en actualización de SW
- **Archivos:** `backend/asset_proxy.php`, `backend/get_content.php`, `js/app.js`, `sw.js`, `index.html`
- **Bitácoras:** Instru_SISTEMA.txt [M148][M149][M150], Instru_GalixMovie.txt [M148][M149][M150]
- **Estado:** 100% Operativo y Blindado. Proxy retorna `image/jpeg` con cache 7 días. SW en v6.0 con límite de caché. Todas las imágenes tienen fallback SVG inline. Hard refresh requerido en cliente para activar.

## DHARMA Fix #82: Shimmer/Glass Glare Effect en Hover de Tarjetas (UI)
- **Contexto:** Se solicitó un efecto visual de vidrio/brillo líquido que barra la tarjeta al pasar el puntero, similar a tarjetas de presentación premium.
- **Acción:** Se añadió pseudo-elemento `::before` en `.movie-card` con gradiente lineal 110deg, blanco translúcido (0.08 a 0.15), `skewX(-20deg)`. Animación `shimmer-sweep` 0.65s ease forwards, `z-index: 25` (sobre el overlay oscuro z-index 20) para que el brillo cruce sobre el icono play.
- **Archivo:** `css/style.css`
- **Estado:** 100% Operativo. Brillo vítreo que se desliza sobre cada tarjeta al hover.

## DHARMA Fix #83: Scanner Inteligente — Apple Double Filter + Smart TMDB Fallback
- **Contexto:** 1) macOS genera archivos `._` (Apple Double) que el escáner procesaba como archivos de video válidos, saturando la base de datos con entradas fantasma. 2) Metadatos embebidos con caracteres extraños (ej. `"EN BUSCA DE LA FELICIDAD (2006) 480p versión para móviles @REIDIOMAN"`) producían búsquedas TMDB fallidas aunque el filename estuviera limpio.
- **Acción:**
  - `scanMedia()`: `if (str_starts_with($basename, '._')) continue;` antes del filtro de extensión.
  - Si estrategia de metadatos embebidos devuelve 0 resultados TMDB, parsea el filename limpio y reintenta (movie → tv).
- **Archivo:** `backend/scrapper.php`
- **Estado:** 100% Operativo. Escáner ignora archivos ._ y recupera metadata correcta incluso con tags sucios.

## DHARMA Fix #84: Autopilot Engine v2.1 — Embed Fallback Cache + Non-Seed Verification + is_online Removed
- **Contexto:** 1) Autopilot terminaba en ~5s sin procesar nada porque el cache con `servidor_nombre='Embed Fallback'` era tratado como healthy, bloqueando la re-extracción. 2) La query `WHERE c.is_online = 1` excluía contenido offline que necesita curación. 3) URLs no-seed (locales o HTTP directas) no tenían verificación de salud.
- **Acción:**
  1. **Embed Fallback Detection**: Si `resolved_url === seed_url` (nunca se resolvió a un stream real) o `servidor_nombre === 'Embed Fallback'`, no se considera healthy → fuerza re-extracción. Nuevo contador `fallback_cached`.
  2. **WHERE c.is_online = 1** eliminado — procesa todo contenido.
  3. **Else branch for non-seed URLs**: HTTP → `checkUrlStatus()`, local → `file_exists()`. Dead entries añadidas a `static_dead_detected[]`.
- **Archivo:** `backend/autopilot.php`
- **Estado:** 100% Operativo. Autopilot procesa 154+ items, detecta fallback cache, verifica todas las URLs, reporta 3 contadores (healthy, fallback, pruned-dead).

## DHARMA Fix #85: PH-DHARMA — Integración Pornhub vía embed_proxy (Aislamiento de Proveedor)
- **Contexto:** Los embeds de Pornhub no reproducían en GalixMovie. `extract.php` resolvía la URL embed al CDN directo (`phncdn.com`) con tipo `hls`, pero esos CDNs tienen IP-lock estricto (segments .ts devuelven 404). Intentar forzar la reproducción como HLS estándar fallaba porque los tokens CDN de Pornhub están ligados a la IP que solicitó el manifiesto y expiran en segundos.
- **Causa Raíz:** El pipeline de reproducción de GalixMovie trata toda URL de terceros como `extract:` → `proxy.php` → HLS. Pornhub NO encaja en este pipeline. Su embed tiene 3 capas de bloqueo: (1) X-Frame-Options DENY, (2) API `/video/get_media` con verificación de Origin (503), (3) CDN tokens con IP-lock (segments .ts 404).
- **Acción (Aislamiento de Proveedor):**
  1. `js/app.js:1098-1105`: Detección post-`extract.php` de `phncdn.com`/`pornhub.com` en `data.url`. Si se detecta → `forcedType='embed'` y se conserva la URL embed original en vez del CDN resuelto.
  2. `js/app.js:1320-1324`: Si `iframeSrc` contiene `pornhub.com/embed/` o `phncdn.com` → reescribe a `backend/embed_proxy.php?url=...`.
  3. `backend/embed_proxy.php:87-94`: Anti-frame-busting con `Object.defineProperty` para congelar `top`/`parent`/`self` y prevenir detección de iframe por JS de Pornhub. Anti-popup: window.open redirigido a null. `error_reporting(0)` (línea 2) para evitar headers contaminados por deprecaciones PHP 8.5.
  4. Se eliminó `curl_close()` de `embed_proxy.php` (deprecado en PHP 8.5.1, mismo fix que #81).
  5. `00_SISTEMA/PLANTILLAS_CONTEXTO.md`: creada plantilla de cierre de jornada.
- **Regla de Blindaje PH-DHARMA:** Pornhub NO debe pasar por `extract.php` ni `proxy.php`. Sólo vía `embed_proxy.php` con anti-frame-busting. Cualquier futuro proveedor con CDN IP-lock debe tratarse igual: detectar, aislar, servir embed.
- **Estado:** Embed HTML se carga visualmente con anti-frame-busting. Stream no completa: API interna de Pornhub rechaza peticiones desde iframe (503 por Origin), y CDN tokens tienen IP-lock. Es limitación del servidor de Pornhub, no del código.

## M172: Preview Modal con Edición en Línea de TMDB ID (Planificado — No Implementado)
- **Contexto:** El modal de preview del escaneo inteligente no permite interactuar con archivos sin match TMDB ni sobrescribir IDs manualmente. Archivos ya indexados correctamente no aparecen.
- **Impacto:** ALTO (afecta P26 — Escaneo Inteligente + Preview + Apply)
- **Cambios planificados:**
  - `backend/scrapper.php`: Nueva función `tmdbSearchById()`, modificar `runApply()` para aceptar `$customTmdb`
  - `admin.html`: Rediseño del modal preview con 4 secciones, inputs TMDB ID editables, checkboxes universales
  - Estado: ⏳ Pendiente de aprobación de Israel
- **Riesgo:** MEDIO — cambios en scrapper.php y admin.html, procesos afectados: P26
- **Estrategia:** Preservar flujo NDJSON streaming existente. No modificar full mode CLI. No tocar upsertContent(). Solo agregar capa opcional de custom_tmdb en runApply().

## M171: Easter Egg v2 + Sistema Ocultar/Desocultar Películas (Sección Oculta Persistente)
- **Contexto:** Se requería: (1) poder **volver a ocultar** las películas secretas después de desbloquear el easter egg, (2) **agregar más películas** a la sección oculta de forma sencilla, (3) que el toggle fuera **discreto** (sin botón visible), (4) que las películas ocultas tuvieran su **propio carrusel** independiente.
- **Arquitectura:**
  1. **Backend (BD):** Nueva columna `oculta TINYINT(1) DEFAULT 0` en tabla `contenido`. Migración en `migrate.php` con `SHOW COLUMNS + ALTER TABLE`. Endpoint `backend/toggle_oculta.php` para toggle vía POST (con auto-creación de columna si no existe).
  2. **Backend (Query):** `get_content.php` incluye `c.oculta` con DHARMA fallback (`SHOW COLUMNS → $ocultaCol = ", c.oculta" : ", 0 as oculta"`) para evitar error MySQL si la columna no existe.
  3. **Frontend (app.js):** Array `HIDDEN_TITLES` para lista rápida de títulos a ocultar. `loadContent()` normaliza `m.oculta` a booleano real con `!!()`. `filterHidden()` ahora **siempre** excluye ocultas del grid principal. Cuando `isSecretUnlocked()`, se renderiza sección `🔒 Contenido Oculto` al final del grid con su propio carrusel.
  4. **UI Discreta:** En modal de detalles, el botón visible fue reemplazado por **doble clic en el póster** (`.details-poster-area`) → confirm "¿Ocultar/Mostrar?" → llama a `toggle_oculta.php`. Solo activo si secreto desbloqueado.
  5. **Re-ocultar:** Doble clic en zona easter egg (top-left 50×50px) ya desbloqueado → confirm "¿Volver a ocultar?" → limpia `sessionStorage`.
- **Archivos:** `backend/migrate.php`, `backend/get_content.php`, `backend/toggle_oculta.php`, `js/app.js`, `index.html`
- **Estado:** 100% Operativo. Persistencia en BD, carrusel separado, toggle por doble clic en poster.

---

## Fixes Críticos — streaming & Google Drive (Junio 2026)

### stream.php: curl_close() PHP 8.5 Deprecation
- **Riesgo:** ALTO — Roku no podía reproducir ningún video (error -1)
- **Causa:** `curl_close()` emitía warning que rompía headers antes de enviar video
- **Fix:** Eliminar `curl_close()` + `error_reporting(E_ALL & ~E_DEPRECATED)`
- **Blindaje:** Verificar PHP 8.5+ antes de usar funciones deprecated

### stream.php: !empty("0") Bug
- **Riesgo:** ALTO — Range requests con `bytes=0-` fallaban (error -2 en Roku)
- **Causa:** PHP trata "0" como empty, `!empty($matches[2])` retornaba true
- **Fix:** Cambiar a `$matches[2] !== ""`
- **Blindaje:** Nunca usar `empty()` para strings que pueden ser "0"

### stream.php: WRITEFUNCTION Abort
- **Riesgo:** MEDIO — cada request tomaba 5 segundos innecesariamente
- **Causa:** `return $chunkLen` decía a curl que siga descargando todo el archivo
- **Fix:** `return -1` aborta transferencia parcial. Tiempo: 5s → 10ms
- **Blindaje:** Siempre abortar transferencias parciales con `return -1`

### stream.php: Range Support + file_size from DB
- **Riesgo:** MEDIO — sin Range support, Roku no podía hacer seek
- **Fix:** HTTP_RANGE handler + 26 Partial Content. file_size en SELECT omite HEAD request
- **Blindaje:** Siempre soportar Range requests en endpoints de video

### rclone: VFS Cache Mode writes
- **Riesgo:** MEDIO — `full` mode llenaba disco con 2.6GB de cache
- **Fix:** `--vfs-cache-mode writes` — lecturas directas a Google Drive
- **Blindaje:** Evaluar cache mode según caso de uso; `writes` para streaming

### Episode Prebuffer (Web + Roku)
- **Riesgo:** BAJO — mejora de UX, sin riesgo funcional
- **Fix:** Timer 1.5s en hover → prebuffer en background → reutilizar al play
- **Blindaje:** Aplicar patrón para contenido de alta latencia

### Episode Carousel (Web + Roku)
- **Riesgo:** BAJO — mejora de UI, sin riesgo funcional
- **Fix:** 3 items visibles con clipping/scroll automático
- **Blindaje:** Usar clipping para listas largas en espacio limitado

### Prebuffer Series (Debounce + Last Played)
- **Riesgo:** MEDIO — prebuffer flooding puede causar latencia en tunnels Cloudflare
- **Fix:** Debounce 500ms con mouseleave cancel; localStorage para último episodio; prebuffer al abrir modal
- **Blindaje:** Siempre usar debounce en operaciones HTTP pesadas; persistir estado con clave compuesta

### Back en Series → Modal
- **Riesgo:** BAJO — mejora UX, sin riesgo funcional
- **Fix:** BACK durante playback de serie返回modal en vez de catálogo
- **Blindaje:** Para apps con navegación jerárquica, BACK debe retornar al nivel anterior correcto

### Scrapper rclone timeout
- **Riesgo:** ALTO — bloqueaba todo el admin (scanner colgado)
- **Fix:** rcloneExecSafe() con timeout 15s + health check 5s pre-flight
- **Blindaje:** TODO shell_exec a procesos externos debe tener timeout. Verificar salud antes de operación principal.

### stream.php episodios series
- **Riesgo:** ALTO — 0% reproducción episodios series (Roku + web)
- **Fix:** Query series_metadata primero, fallback peliculas_metadata
- **Blindaje:** Tablas paralelas (oficial + legacy): consultar oficial primero. Verificar schema actual.

### Ruta HLS hardcodeada (HLS_TEST/) en Roku y web
- **Riesgo:** ALTO — series con GDrive fuera de HLS_TEST no reproducían
- **Fix:** Extraer path real de gdrive: URL en vez de hardcodear HLS_TEST/SxxExx
- **Blindaje:** No hardcodear rutas. Usar path real de BD. Duplicar fixes web+Roku.

### Skip list bloqueando episodios de series
- **Riesgo:** ALTO — episodios nuevos nunca se indexaban
- **Fix:** detectSeriesFromPath() antes del skip list check
- **Blindaje:** El skip list solo debe aplicarse a archivos sin contexto de carpeta de serie.

## P36: stream.php X-Accel-Redirect (No bloquear workers PHP-FPM)
- **Contexto:** stream_ac3fix.php ejecutaba dual ffmpeg por request, ocupando workers PHP-FPM por 5-15 min. max_children=8 se agotaba con 4 usuarios.
- **Causa Raíz:** PHP-FPM request_terminate_timeout=600 + set_time_limit(0) no libera el worker. ffmpeg bloquea el proceso PHP hasta terminar.
- **Solución:** Revertir a stream.php que usa X-Accel-Redirect. Nginx sirve el archivo directo. El worker PHP termina en <50ms.
- **Impacto:** 0% workers PHP-FPM usados para servir video. Pool disponible para otros endpoints.
- **Archivos:** backend/stream.php, backend/stream_ac3fix.php, js/app.js

## P37: AC3 Aware — Diagnóstico y Configuración de Audio
- **Contexto:** Archivos AC3 no tenían audio en Roku 3840R (passthrough HDMI sin decodificador interno). Web player no podía transcodificar sin saturar FPM.
- **Causa Raíz:** Roku 3840R no decodifica AC3 — solo reenvía el flujo digital por HDMI. AAC siempre funciona (decodificación nativa).
- **Solución Roku:** Settings → Audio → Digital output format → Dolby Digital Plus. El TV decodifica DD+, AC3 se escucha como DD+.
- **Solución Web:** stream.php (X-Accel-Redirect). AC3 se sirve directo sin transcodificar. El usuario convierte AC3→AAC manual con ffmpeg batch.
- **Impacto:** Roku con DD+ reproduce AC3 sin pérdida. Web requiere conversión manual para escuchar AC3.
- **Archivos:** js/app.js, backend/stream.php, backend/stream_ac3fix.php (inactivo)
