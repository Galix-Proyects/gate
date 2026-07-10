# 🧘 Filosofía DHARMA: Lecciones de Evolución GalixMovie PRO
## 🔥 Integrado en el [Protocolo FENIX](../_Cerebro_Israel/99_SISTEMA/FENIX_MANIFESTO.md)

Este documento registra los errores recurrentes y su transmutación en sabiduría técnica para evitar regresiones y potenciar el aprendizaje continuo del sistema. Como parte del ecosistema FENIX, estas lecciones alimentan el núcleo de inteligencia central.

---

## 🛑 Lección 001: El Paradoja del Proxy y los Fragmentos Relativos

**Error:** `404 Not Found` en fragmentos de video (ej. `index-f1-v1.txt`).
**Causa Raíz:** 
Cuando un manifiesto HLS (M3U8 o TXT) se carga a través del `proxy.php`, la URL base en el navegador cambia a la ubicación del proxy (local). Si el manifiesto utiliza rutas relativas para sus segmentos de video, el reproductor intenta descargarlos desde nuestro servidor local en lugar del servidor original.

**Solución DHARMA:**
Identificar proactivamente los CDNs que utilizan este esquema y forzar la **Carga Directa** (CORS permitiendo) para mantener la integridad de la ruta base.

**Servidores Catalogados (Direct Load):**
- `vimeos.zip`
- `cloudwindow-route.com`
- `hlswish.com`
- `latinplay.xyz`
- `seekplayer.vip`
- `rpmstream.live`

---

## 🛑 Lección 002: La Dualidad del Controlador HLS

**Error:** Audio residual al cerrar el reproductor.
**Causa Raíz:** 
Existencia de múltiples variables globales (`hls` vs `currentHls`) gestionando el mismo flujo. Al cerrar el modal, solo se destruía una instancia, dejando la otra activa en segundo plano.

**Solución DHARMA:**
Unificación absoluta de la identidad del reproductor bajo una única variable maestra `window.hls` y protocolo de "Tierra Quemada" en el cierre (reset de `src` y vaciado de contenedores).

---

## 🛑 Lección 003: La Muñeca Rusa de los Iframes (Inception)

**Error:** El interceptor de red no detecta el M3U8.
**Causa Raíz:** 
Aislamiento de contexto. Los navegadores bloquean la visibilidad de red entre ventanas de diferentes niveles de Iframe.

**Solución DHARMA:**
Protocolo de "Pelado de Cebolla": Usar la sonda de extracción recursivamente hasta llegar al reproductor final en una pestaña independiente donde el interceptor tenga acceso directo al tráfico de red.

---

## 🛑 Lección 004: El Codec Fantasma (La Trampa del HEVC)

**Error:** `manifestIncompatibleCodecsError` (Buffer llenándose pero video congelado en 0:00).
**Causa Raíz:** 
El servidor (ej. Wellness) envía un manifiesto declarando codecs de alta eficiencia (HEVC/H.265) que el navegador Chrome (vía MSE) rechaza por defecto. Hls.js detiene la reproducción preventivamente aunque el hardware pueda ser capaz de decodificarlo.

**Solución DHARMA (v2):**
Implementar un **Saneador de Manifiestos** en el `proxy.php`. El proxy **elimina** el atributo `CODECS` por completo en lugar de reemplazarlo. Esto evita el `bufferAppendError` que ocurre cuando forzamos un codec específico (como Baseline) pero el stream real es de mayor perfil (como High Profile). Sin el atributo, el navegador auto-detecta el codec real desde los segmentos.

---

## 🛑 Lección 005: El Escudo del Iframe (X-Frame-Options)

**Error:** Iframe de reproductor externo en blanco.
**Causa Raíz:** El servidor externo prohíbe ser embebido en iframes de otros dominios.
**Solución:** `embed_proxy.php` actúa como intermediario descargando el HTML y sirviéndolo desde nuestro dominio sin headers restrictivos.

---

## 🛑 Lección 006: El Código de la Base Perdida (Tag `<base>`)

**Error:** El reproductor carga pero queda en negro o sin estilos (Megapelis).
**Causa Raíz:** El HTML del embed usa rutas relativas (ej: `static/player.js`). Al cargarlo desde nuestro proxy, el navegador busca esos archivos en nuestro servidor local en lugar del original.
**Solución DHARMA:** Inyectar el tag `<base href="URL_ORIGINAL">` en el `<head>` vía el proxy. Esto redirige automáticamente todas las peticiones relativas al dominio correcto sin tocar el código original.

---

## 🛑 Lección 007: La Especialización del Flujo (Blindaje)

**Error:** Una solución que arregla un servidor rompe a otro (ej: Proxy vs URLs con #hash).
**Causa Raíz:** Intentar aplicar una solución universal a servidores con naturalezas distintas (HLS vs Embeds vs SPAs).
**Solución DHARMA:** Implementar una estructura de `switch` o `if/else` por servidor en `app.js`. Lo que funciona para uno se "blinda" y no afecta al resto.

---

## 🛑 Lección 008: El Fantasma en la Máquina (HLS Ghost)

**Error:** Al cambiar de servidor, el reproductor "salta" automáticamente al siguiente sin permiso.
**Causa Raíz:** Una instancia de `Hls.js` previa sigue viva en memoria. Al no encontrar el video donde inyectar datos, lanza un error interno que dispara el disparador de "reintento/siguiente servidor", saltándose el servidor actual.
**Solución DHARMA:** Purgar y destruir globalmente (`hls.destroy()`) cualquier instancia previa de HLS al inicio absoluto de la función `loadSource()`.

---

## 🛑 Lección 010: La Máscara del GIF (MIME-Type Obfuscation)

**Error:** El buffer carga pero el video no se reproduce (Latinplay).
**Causa Raíz:** El servidor envía segmentos TS con extensión `.gif` y header `Content-Type: image/gif`. El navegador los descarga pero el MediaSource Engine los ignora por no ser datos de video válidos.
**Solución DHARMA:** Forzar `header("Content-Type: video/mp2t")` en el proxy para cualquier extensión de imagen que venga de un CDN de video.

---

## 🛑 Lección 011: La Dieta de la URL (Giant Tokens)

**Error:** Error 500 (Internal Server Error) en el proxy (Latinplay).
**Causa Raíz:** URLs con tokens extremadamente largos que superan límites de Apache o fallan al ser re-codificados con `urlencode` (que usa `+` para espacios).
**Solución DHARMA:** Usar `rawurlencode` (`%20`) para mayor compatibilidad y forzar el `Referer` original del CDN para evitar bloqueos de seguridad por "Hotlinking".

---

## 🛑 Lección 012: La Parálisis por Concurrencia (Race Condition)

**Error:** El botón de "Siguiente Servidor" se bloquea o el sistema se queda "trabado" tras clics rápidos.
**Causa Raíz:** Falta de un semáforo de estado (isSwitchingSource). Múltiples procesos de carga de HLS y consultas de estado se disparaban en paralelo.
**Solución DHARMA:** Implementar un **Guardia de Cambio** que bloquee la entrada del usuario y proporcione feedback visual hasta que la nueva fuente esté cargada.

---

## 🛑 Lección 013: La Incompatibilidad de Capas (Plyr vs Embed)

**Error:** Al cargar un servidor externo (Iframe), la pantalla se ve negra o los controles no responden.
**Causa Raíz:** El contenedor de Plyr (reproductor nativo) permanece visible con un z-index superior, cubriendo físicamente el Iframe.
**Solución DHARMA:** Protocolo de **Alternancia de Visibilidad**. Al detectar un Embed, se debe ocultar el nodo .plyr para liberar el espacio visual al 100%.

---

## 🛑 Lección 014: El Muro del Desbordamiento (Hidden Tooltips)

**Error:** Los popups de información no aparecen al pasar el cursor sobre las tarjetas.
**Causa Raíz:** Uso de overflow: hidden en la tarjeta padre. Esto actúa como una guillotina para cualquier elemento absoluto que intente salir de los límites.
**Solución DHARMA:** Evolucionar hacia un **Overlay Interno**. Diseñar la información para que se deslice dentro de los límites de la tarjeta con Glassmorphism.

---

## 🛑 Lección 015: La Sonda de Tiempo Excedido (Auditoría Incremental)

**Error:** Error 500 o Timeout al intentar auditar la salud de toda la biblioteca desde el backend.
**Causa Raíz:** Procesar cientos de peticiones cURL en un solo hilo excede los límites de tiempo del servidor.
**Solución DHARMA:** **Auditoría Incremental**. Delegar la lógica de control al frontend, procesando un título a la vez.

---

## 🛑 Lección 016: La Trampa de la Sobreescritura de Contingencias (isForcedEmbed Reset)

**Error:** Semillas pesadas o protegidas (como Inkapelis) fallando tras un Sniper timeout, rompiéndose con error 403 en HLS en vez de cargar el reproductor embed.
**Causa Raíz:** 
Una carrera lógica en el árbol condicional de `loadSource()`. La contingencia del Sniper activa correctamente la bandera global `window.isForcedEmbed = true`, pero inmediatamente después, el bloque `else` de la condición `source.startsWith('extract:')` reestablece destructivamente la variable a `false`, saboteando el fallback de Iframe y enviando una página HTML cruda al motor de reproducción HLS.

**Solución DHARMA:**
Eliminar el bloque `else` redundante que sobreescribía la variable global. Como `window.isForcedEmbed` ya se inicializa de forma segura al inicio absoluto de la función `loadSource()`, esta purga previene que fallas temporales de red o bloqueos de Cloudflare rompan el flujo, asegurando que las contingencias inyecten con total precisión un iframe funcional (SPA-safe) en pantalla.

---

## 🛑 Lección 017: El Milagro del Caché Read-Through Relacional

**Error:** Tiempos de espera excesivos (de hasta 25 segundos) cada vez que el usuario reproduce un servidor dinámico lento (Fénix/Extractor/Sniper), e interrupciones constantes por pantallas de CAPTCHAs/Turnstile de Cloudflare en los dominios de origen.
**Causa Raíz:** 
Hacer recargas en vivo (On-the-fly) sin persistencia de tokens. Cada reproducción forzaba una llamada pesada a scrapers basados en cURL o hilos fantasma de navegador que eran bloqueados defensivamente por los cortafuegos de los CDNs de origen.

**Solución DHARMA:**
Crear una **Caché Relacional Read-Through** (`resolved_streams_cache`). Cada vez que el reproductor o el Sniper resuelve con éxito una URL dinámica lenta, registra el mirror directo en la base de datos MySQL con metadatos asociados de idioma, calidad y servidor. Al volver a abrir la película, el frontend lee la caché de inmediato, cargando el video en **0 segundos** y eliminando por completo la necesidad de volver a interactuar con Cloudflare o resolver retos humanos de forma recurrente.

---

## 🛑 Lección 018: El Escudo Anti-Caducidad SSL (Invalid Certificate Hijack)

**Error:** `net::ERR_CERT_DATE_INVALID` en el navegador al intentar realizar "Carga Directa" (ej. en `s11.vimeos.net`).
**Causa Raíz:**
Los dominios del CDN de servidores como Vimeus experimentan ocasionalmente periodos donde sus certificados SSL expiran o no son renovados a tiempo. Como el navegador del cliente es estricto en HTTPS, bloquea por completo la conexión directa con un error crítico a nivel de red, impidiendo descargar el manifiesto y tirando la reproducción.

**Solución DHARMA:**
Eliminar los dominios caídos del bypass de proxy en el frontend (`app.js`) y forzarlos a enrutarse a través de `proxy.php`. Como el backend PHP tiene configurado cURL con `CURLOPT_SSL_VERIFYPEER => false` y `CURLOPT_SSL_VERIFYHOST => 0`, el servidor proxy puede descargar el contenido saltándose la advertencia del certificado, reescribir los segmentos y servirlos de vuelta al navegador del usuario bajo una conexión HTTPS de confianza y validada del propio dominio del player.

---

## 🛑 Lección 019: La Paradoja de IP-Coincidencia y el Spoofer del Token (XFF Exclusión)

**Error:** Error 403 Forbidden devuelto por cURL en `proxy.php` para URLs válidas de Vimeus/Goodstream, a pesar de que el mismo enlace abre con éxito directamente en el navegador del usuario.
**Causa Raíz:**
Muchos CDNs avanzados asocian los tokens de sesión de streaming (`t`) de forma estricta a la dirección IP pública que solicitó originalmente el token (en este caso, la IP de la Box Symmetry que ejecuta Fénix/Extractor). Al pasar por `proxy.php`, inyectábamos automáticamente las cabeceras `X-Forwarded-For` y `X-Real-IP` apuntando a la IP real del cliente (generalmente IPv6). El CDN detectaba la discrepancia entre la IP que solicitó el token (IPv4 del servidor) y la IP declarada en las cabeceras de reenvío (IPv6 del cliente), rechazando la descarga. Adicionalmente, el envío de metadatos de navegador sospechosos (`Sec-Fetch-*` en un contexto cURL) y el UA Windows hardcodeado en clientes macOS delataba la naturaleza del bot.

**Solución DHARMA:**
Implementar exclusión de cabeceras de reenvío y UA adaptativo. 1) Excluir a los dominios del CDN de Vimeus de enviar cabeceras `X-Forwarded-For` y `X-Real-IP` en `proxy.php`, forzando al CDN a ver únicamente el socket de conexión nativo del servidor (que coincide 100% con la IP generadora del token). 2) Heredar dinámicamente el `HTTP_USER_AGENT` del navegador para coincidir con la firma TLS nativa del sistema del cliente (macOS, Windows, iOS). 3) Remover cabeceras `Sec-Fetch-*` de metadatos del navegador para peticiones cURL del CDN. 4) Fortalecer la extensión GalixSniper (v1.3) añadiendo una regla `declarativeNetRequest` dedicada (`ID 2`) para modificar peticiones directas sobre la marcha, habilitando una topología de doble protección transparente.

---

## 🛑 Lección 020: La Inmunidad DNS y Mitigación de Cuelgues por Timeout de Carga (DoH por IP & Eager Strategy)

**Error:** `net::ERR_NAME_NOT_RESOLVED` o cuelgues eternos del backend por más de 120s (`HTTPConnectionPool Read timed out`) en el servidor Symmetry Box (Termux) al ejecutar el motor Server-Side Sniper.
**Causa Raíz:**
1) El entorno de red local o la VPN Tailscale (en modo userspace) bloquean de raíz todas las consultas salientes del puerto **53 UDP**, imposibilitando la resolución DNS convencional.
2) Selenium por defecto espera a que todo el DOM y todos sus trackers y banners carguen por completo (`readyState == "complete"`). En páginas de streaming repletas de anuncios rotos o bloqueados, el script se quedaba colgado indefinidamente.
3) Ruta física inválida hacia el binario de Chromium: en Termux, el ejecutable no es `chromium` (inexistente), sino `chromium-browser` (lanzador oficial).

**Solución DHARMA:**
1) **DNS-over-HTTPS (DoH) por IP Directa**: Configurar la plantilla DoH nativa de Google utilizando directamente su IP (`--dns-over-https-templates=https://8.8.8.8/dns-query`). Al no depender de la resolución de un dominio DNS como `dns.google` en el puerto 53 UDP, Chromium realiza las consultas directo en el puerto 443 HTTPS, volviéndose 100% inmune a bloqueos de red locales.
2) **Estrategia Eager & Timeout de Carga**:
   - Ajustar `page_load_strategy = 'eager'` en las opciones del driver para devolver el control al script tan pronto como el HTML principal sea interactivo.
   - Definir un tiempo de espera límite estricto (`driver.set_page_load_timeout(10)`) envuelto en un bloque `try-except` contra `TimeoutException`. Esto previene cuelgues por trackers de anuncios y permite al script continuar inmediatamente analizando las solicitudes ya capturadas.
3) **Binario Termux Correcto**: Apuntar físicamente al wrapper `/data/data/com.termux/files/usr/bin/chromium-browser`.

---

## 🛑 Lección 021: La Travesía de Sub-Iframes y la Sonda de Rendimiento Aislada

**Error:** `"No HLS stream intercepted"` devuelto por el Server-Side Sniper al procesar reproductores de terceros (como PelisCalidad) que encapsulan el reproductor real en contextos anidados profundos.
**Causa Raíz:**
Los reproductores de streaming modernos anidan iframes en múltiples niveles (ej. PelisCalidad -> Vimeus -> Vimeos.net) e implementan políticas estrictas de origen cruzado (CORS / Frame ancestors). Como el navegador restringe la propagación de eventos y solicitudes entre dominios no emparentados, un rastreador superficial en la ventana principal (`window`) o en el primer nivel de iframe es incapaz de interceptar o ver el manifiesto HLS cargado en el nivel de iframe más profundo.
**Solución DHARMA:**
**Travesía Recursiva y Análisis de Contexto Aislado**.
1. Se implementó una rutina recursiva en `sniper.py` que recorre dinámicamente todo el árbol de frames anidados del navegador (`driver.switch_to.frame`).
2. En cada nivel del frame, simula clics físicos simulados sobre elementos interactivos (como overlays de anuncios o botones de Play invisibles) para forzar la inicialización del reproductor interno.
3. Inyecta y ejecuta una consulta nativa a la API de Rendimiento del navegador (`window.performance.getEntriesByType('resource')`) dentro del contexto exacto de cada sub-iframe. Esto permite capturar directamente las solicitudes de red locales del reproductor interno, logrando la intercepción exitosa del enlace maestro `.m3u8` firmado a pesar de la barrera de origen cruzado de los CDNs.

---

## 🛑 Lección 022: La Arquitectura Pentafásica y la Hibridación de Semillas con Auto-Expansión

**Error:** Complejidad en la administración del catálogo de servidores (5 campos redundantes) y fatiga al tener que registrar múltiples alternativas para prever caídas de reproductores externos.
**Causa Raíz:**
Mantener múltiples servidores alternativos para cada película (Goodstream, Vimeos, Voe, Filemoon) requiere que el administrador capture manualmente 5 URLs diferentes en la base de datos, lo cual es tedioso y propenso a errores humanos.
**Solución DHARMA:**
**Hibridación Pentafásica con Auto-Expansión Dual**:
1. **Preservar los 5 Campos**: Mantener las 5 ranuras físicas de base de datos como una topología híbrida flexible que permite mezclar archivos locales directos (premium) con semillas web dinámicas.
2. **Auto-Expansión Dual y Cosecha Automática**:
   - En el panel administrativo, el usuario solo ingresa una única semilla maestra en el primer campo (ej. `extract:https://vimeus.com/...`).
   - Al reproducir, el script del cliente (`app.js`) clona dinámicamente el enlace en dos alternativas automáticas en la cola de reproducción (Versión Sniper del cliente + Versión Fénix del servidor).
   - El motor Fénix (`extract.php`) rompe la semilla de forma limpia y cosecha al vuelo hasta 4 mirrors válidos de alta velocidad (Goodstream, Vimeos.zip, Voe, Filemoon) protegidos por proxy.
   - El reproductor del cliente multiplica ese único campo pegado por el administrador en **hasta 8 opciones de reproducción reales y funcionales** en pantalla, logrando compatibilidad multi-dispositivo (SmartTV, iPad, PC) con cero esfuerzo de administración.

---

## 🛑 Lección 023: La Paradoja de Inyección del Entorno Termux en Procesos Web

**Error:** `Segmentation fault` (Result Code 139) o fallos de comando no encontrado (`Result Code 127`) al ejecutar scripts externos complejos como el Server-Side Sniper (`sniper.py`) desde el backend de PHP a través de peticiones web.
**Causa Raíz:**
Las solicitudes procesadas por Nginx o Apache en Termux se ejecutan bajo un entorno de usuario altamente esterilizado y restringido (`u0_aXX`). Este entorno carece por completo de las variables de entorno nativas que Termux inyecta de forma interactiva en la terminal (como `ANDROID_DATA`, `ANDROID_ROOT`, `LD_PRELOAD`, `PATH`, etc.). Sin ellas, el intérprete de Python3 y el motor de Chromium no logran iniciar sus dependencias biónicas internas y segfaulean al instante.
**Solución DHARMA:**
**Inyección de Entorno de Ejecución Termux**:
Antes de realizar cualquier llamada a `exec()` sobre comandos del sistema, inyectar proactivamente a nivel de proceso PHP las variables de entorno detectadas de la sesión activa (`ANDROID_DATA`, `ANDROID_ROOT`, `PREFIX`, `HOME`, `TMPDIR`, `LD_PRELOAD` y `PATH`) mediante `putenv()`. Esto otorga inmunidad total contra sandboxing y permite que el servidor web ejecute scrapers headless con un 100% de fiabilidad y en menos de 8 segundos.

---

## 🛑 Lección 024: El Bucle Zombi y el Refresh de Fuerza Bruta (DHARMA Fix #45)

**Error:** Las modificaciones de diseño (CSS/JS) o los nuevos botones agregados al panel de administración (`admin.html`) son invisibles para dispositivos móviles (iOS Safari, Chrome) a pesar de presionar actualizar, obligando al usuario a limpiar los datos del navegador manualmente desde la configuración del sistema operativo.
**Causa Raíz:**
Los Service Workers y la agresiva API de `caches` del navegador anclan los recursos críticos (HTML, CSS, JS) para permitir funcionamiento PWA y carga offline. Un simple "pull to refresh" o el uso de un parámetro `?v=X` a veces no es suficiente si el navegador intercepta la navegación desde la base.
**Solución DHARMA:**
Implementar un botón "nuclear" (`Hard Refresh`) directamente en el DOM de la aplicación que ejecute una limpieza profunda mediante código JS nativo. Al ser invocado, el botón itera por toda la `caches.keys()`, borra de forma destructiva cada caché anclada en el dispositivo del cliente y luego fuerza una redirección total concatenando un `?v=Date.now()`. Esto destruye cualquier loop zombi PWA y obliga a la aplicación a resincronizar la versión más actual desde el servidor local al instante.

---

## 🛑 Lección 025: La Pertenencia del Token de IP y la Donación Manual (Bypass del Proxy)

**Error:** `403 Forbidden` al reproducir semillas M3U8 o flujos HLS extraídos manualmente por el cliente (como Goodstream o Vimeos) y pegados en los campos de redundancia.
**Causa Raíz:**
Muchos CDNs modernos de video implementan **IP-Binding** en sus tokens de acceso (el token `t=` generado está estrictamente cifrado y amarrado a la dirección IP pública que solicitó la extracción). Al forzar todo el tráfico de video a través del `proxy.php` local, la IP de la Box Symmetry (Servidor) realizaba la descarga final en lugar de la IP de la MacBook (Cliente que generó el token), lo cual provocaba un rechazo automático del CDN por discrepancia de IPs de conexión.
**Solución DHARMA:**
Implementar un validador inteligente de pertenencia de token. Se introdujo la bandera global `window.isFenixExtractedToken` en `js/app.js`. 
- Si el token fue extraído por el motor Fénix (Headless Chrome en el Servidor), el reproductor **fuerza** el paso por `proxy.php` para que la IP de la solicitud del video coincida con la IP del Servidor.
- Si el token fue extraído por el cliente (donaciones manuales o extracción local del Sniper), el reproductor realiza un **Bypass absoluto de Proxy**, cargando el M3U8 de forma directa en el navegador del cliente para que la IP de la MacBook coincida 100% con la IP autorizada por el CDN.

---

## 🛑 Lección 026: La Ilusión de la Auditoría Síncrona y la Latencia de Cambio de Servidor

**Error:** Cuelgues y congelamientos de red de hasta 15-20 segundos al saltar de servidor (botón "Siguiente Servidor") o pantalla en negro eterna (25 segundos) en dispositivos móviles sin extensión (iOS/Safari) al intentar reproducir un video inactivo antes de cambiar a Fénix.
**Causa Raíz:**
1. **Falta de Redundancia Local en Cliente:** Al alcanzar el fin de la cola de servidores, el reproductor forzaba un bucle síncrono pesado (`Promise.all`) con peticiones a `check_status.php` para auditar *en caliente* el estado de salud de todos los servidores. Si varios servidores estaban offline, cURL colapsaba la cola de Termux por timeouts de conexión TCP acumulados, bloqueando la interfaz por más de 15 segundos.
2. **Timeout Rígido Excesivo:** El timeout de resolución local de la extensión estaba configurado en 25 segundos, forzando esperas insoportables en plataformas que no tienen extensiones nativas (como iPad/móviles).
3. **Falsos Negativos en Auditor:** `check_status.php` fallaba arrojando advertencias de "Invalid URL" y catalogando erróneamente mirrors legítimos como caídos si el administrador inyectaba esquemas virtuales (`extract:` / `sniper:`).

**Solución DHARMA:**
1. **Contador de Fallos Local e Instantáneo:** Eliminar por completo las consultas síncronas a nivel de red para verificar salud al ciclar. Se delegó el rastreo a un contador en el DOM del cliente (`consecutiveFailuresCount`) que se incrementa ante fallos automáticos reales (atrapados por HLS.js o HTML5) y se resetea a 0 al cargar contenido o reproducir exitosamente. Si el contador iguala la longitud de la cola, se despliega instantáneamente la alerta de Apagón de Redundancia. De lo contrario (p. ej. saltos del usuario), el ciclo ocurre en **0.0 segundos**.
2. **Bifurcación del Cambio de Canal:** Parametrizar `window.tryNextSource(isManual)`. Si el salto de canal es manual, se resetean los fallos para que el usuario pueda explorar libremente los servidores sin activar alarmas de caída general.
3. **Timeout Quirúrgico de Sonda:** Reducir el timeout del Sniper en el cliente a **10 segundos** (óptimo y veloz).
4. **Backend Sanitizado:** Inyectar una rutina de limpieza (`preg_replace('/^(extract:|sniper:)/', '', $url)`) en `check_status.php` para purgar esquemas dinámicos antes de que `parse_url` los procese, asegurando respuestas limpias e instantáneas.

---

## 🛑 Lección 027: Arquitectura de Adaptación Responsiva de Tablas Compactas con Scroll de Cristal (Mobile Tradicional)

**Error:** Interfaz del Panel Administrativo `admin.html` inutilizable en dispositivos móviles (tablas de 11 columnas complejas que colapsan destructivamente o exigen conversiones molestas en tarjetas que rompen el flujo de lectura tabular fluido del administrador).
**Causa Raíz:**
1. **Dificultad de Lectura Horizontal:** Intentar forzar 11 columnas a caber en un ancho de pantalla móvil de 360px-480px provoca el colapso de texto y la superposición de datos.
2. **Alternativas Incómodas (Cards):** Aunque la transformación a tarjetas verticales (cards) distribuye la información, rompe la visión de "fila" de base de datos que prefiere un administrador experimentado para realizar comparativas y Drag & Drop ágiles.
3. **Ausencia de Scroll Dedicado de Alto Nivel:** Los navegadores móviles por defecto no aplican estilos de barra de desplazamiento estilizados en contenedores con desbordamiento horizontal, lo cual hace que el desplazamiento se sienta burdo.

**Solución DHARMA:**
1. **Cápsula Flotante Glassmorphic (Mobile Navbar):** Rediseñar la barra de navegación móvil `.nav-links` en `style.css` para suspenderse a 15px de la base táctil del celular, con un ancho adaptativo del 90%, esquinas ultra-redondeadas (`border-radius: 30px`), bordes cristalinos finos, y un desenfoque biónico por hardware de fondo (`backdrop-filter: blur(25px)`). Incrementamos el padding de cuerpo a `95px`.
2. **Contenedor Responsivo de Desplazamiento Suave:** En `@media (max-width: 768px)`, encapsular la tabla en una envoltura con propiedad `overflow-x: auto` y un ancho mínimo forzado de `760px` para la tabla en móviles, permitiendo un swipe horizontal de alta fluidez.
3. **Scrollbar de Cristal Estilizado:** Desarrollar barras de scroll horizontales neón translúcidas ultra-finas de `4px` con `-webkit-scrollbar` específicas para móviles para un look futurista premium.
4. **Formato Ultra-Compacto y Truncado Inteligente:**
   - Reducir el tamaño de fuente global a un tamaño micro de `0.65rem` en `th` y `td`.
   - Disminuir el padding de celdas a un mínimo absoluto de `3px 2px` para maximizar el espacio útil y minimizar el alto de filas.
   - Limitar el ancho máximo del título de la película a `100px` con elipsis (`overflow: hidden; text-overflow: ellipsis; white-space: nowrap;`), previniendo que textos largos deformen horizontalmente la estructura.
5. **Cuadrícula de Botones en Rejilla 3x2:** Agrupar los 5 botones del panel superior en una cuadrícula CSS Grid de **3 columnas por 2 filas** en móviles, acortando proactivamente sus nombres a denominaciones comprimidas (*Escanear*, *Autopilot*, *Verif. Serv.*, *Sinc. Gate*, *Aud. Index*) para lograr un formato ergonómico libre de envolturas que ocupa una fracción del espacio anterior.
6. **Píldoras de Servidores S1-S5 Enriquecidas:** Sustituir los iconos planos por cápsulas de estado compactas con brillo translúcido de neón coordinado (rayo morado para Sniper, cubo cian para Fénix, base de datos verde para local) que visualizan dinámicamente la procedencia y salud del flujo, permitiendo una rápida monitorización táctil y Drag & Drop ergonómico.

---

## 🛑 Lección 028: Minimización de Espaciados Verticales y Resets de Margen del Navegador en Catálogos Móviles

**Error:** Brecha vertical excesiva e inestética entre el bloque inferior del Hero (especialmente los botones de control como "Mi Lista") y la primera sección de carrusel de películas ("Continuar Viendo" / "Tu Biblioteca") en teléfonos inteligentes.
**Causa Raíz:**
1. **Márgenes por Defecto del Navegador:** Los elementos HTML de cabecera como `h2` (`.row-title`) arrastran por defecto directivas `margin-top: 0.83em` del User-Agent del navegador. Esto añade más de 16px de espacio incontrolado arriba de cada título de fila.
2. **Padding Excesivo de Elementos del Hero:** En móvil, la cabecera `.hero` mantenía un `padding-bottom: 20px` para holgura genérica, lo cual, combinado con el `align-items: flex-end`, empujaba los botones "Mi Lista" hacia arriba dejando una franja vacía.
3. **Paddings Generales de Sección:** Cada bloque `.content-row` aplicaba rellenos superiores innecesarios en móviles (`padding-top: 0.5rem`), lo que sumaba 8px adicionales al espaciado.

**Solución DHARMA:**
1. **Reset Total de Margen en Títulos (`margin-top: 0`):** Establecer de forma global y móvil `margin-top: 0` en `.row-title`, forzando que los títulos de secciones solo dependan del padding del contenedor.
2. **Ajuste Quirúrgico del Relleno del Hero:** Reducir el padding inferior de `.hero` de `20px` a `4px` en móviles. Esto permite que los controles floten perfectamente ajustados al límite basal sin interferencias.
3. **Fila Adyacente Unificada (`padding-top: 0`):** Modificar la directiva `.content-row` en móviles para remover el relleno superior (`padding: 0 4% 0.5rem 4%`), permitiendo que el catálogo comience inmediatamente debajo del final físico del Hero, optimizando la tasa de visualización del catálogo en el viewport inicial.

---

## 🛑 Lección 029: Super-Compactación del Hero Móvil y Priorización Dinámica de Póster Vertical como Background

**Error:** El catálogo principal de la PWA ("Continuar Viendo") quedaba oculto bajo el pliegue del viewport inicial en dispositivos móviles debido a un Hero excesivamente alto (`75vh` / `min-height: 450px`). Al usar la imagen panorámica horizontal (`backdrop_path`), el fondo se estiraba desproporcionadamente dejando grandes espacios vacíos verticales sobre el texto.

**Causa Raíz:**
1. **Proporciones Incompatibles:** Los viewports de smartphones son esbeltos y verticales (ej. relación 9:16). Usar una imagen panorámica horizontal (`backdrop_path`) obliga a recortar drásticamente los lados o ensanchar la sección hacia abajo para evitar bandas negras, ocupando demasiado espacio.
2. **Altura Rígida:** Una directiva de `75vh` consume el 75% del viewport inicial, empujando la biblioteca interactiva fuera del campo visual.

**Solución DHARMA:**
1. **Conmutación Inteligente de Imagen (`poster_path` en Móvil):** Implementar en `app.js` un selector dinámico por ancho de pantalla (`window.innerWidth <= 768`). Si el usuario navega en un dispositivo móvil, se carga proactivamente la imagen del póster vertical (`movie.poster_path`) en lugar de la panorámica (`movie.backdrop_path`). Esto adapta armoniosamente las proporciones del arte cinematográfico a las dimensiones naturales del celular.
2. **Compactación Quirúrgica a 42vh:** Rediseñar la sección `.hero` en la `@media` móvil para operar en una altura ultra-compacta de **`42vh`** (con un `min-height: 260px` y `padding-top: 60px`), logrando que todo el catálogo se visualice inmediatamente sin necesidad de scroll.
3. **Micro-tipografía y Restricción de Texto:** Limitar quirúrgicamente el tamaño del título del Hero a `1.4rem` y truncar estrictamente la sinopsis a un máximo de **2 líneas** (`-webkit-line-clamp: 2` y `line-clamp: 2`). Aplicar una opacidad atenuada a `opacity: 0.45` en el póster de fondo con alineación `object-position: center 20%` para una legibilidad estelar de los metadatos y botones compactados.

---

## 🛑 Lección 030: Fragmentación en Filas de 10 Películas (Chunking) y Acotado de Carga para Rendimiento Excepcional

**Error:** Rendimiento degradado del navegador (lags, saltos y consumo de GPU) al cargar cientos de tarjetas de películas simultáneamente en un único carrusel infinito de la biblioteca. Además, la persistencia de demasiados registros en "Continuar Viendo" obstaculizaba la navegación ágil de reproducción.

**Causa Raíz:**
1. **Coste Excesivo de Pintado (Repaint DOM Cost):** Renderizar más de 100 películas en un solo nodo `.movie-grid` horizontal fuerza al navegador a recalcular layouts masivos continuamente al desplazar.
2. **Pérdida de Interés Visual:** Un carrusel interminable reduce la tasa de clics porque el usuario experimenta fatiga de elección.

**Solución DHARMA:**
1. **Secciones de 10 en 10 (Chunking):** Sustituir el `#movie-grid` estático por un contenedor receptor `<div id="dynamicRowsContainer"></div>`. Reprogramar `renderGrid()` para segmentar el catálogo en subgrupos contiguos de **exactamente 10 películas** utilizando `movies.slice(i, i + 10)`. Para cada grupo, genera dinámicamente un `<section class="content-row">` numerado secuencialmente (`Tu Biblioteca`, `Tu Biblioteca - Sec. 2`, etc.), optimizando el renderizado gráfico a nivel de CPU y GPU.
2. **Limitación de Continuar Viendo a 8:** Cortar el array de progreso a un límite estricto de `.slice(0, 8)` en `loadContinueWatching()`.
3. **Controlador de Scroll Dinámico Centralizado:** Desarrollar `window.initGridScrolling(grid)` en `index.html` para adjuntar los listeners de redirección de rueda y drag por arrastre de mouse a las filas añadidas en tiempo de ejecución, erradicando fallos de listeners huérfanos.

---

## 🛑 Lección 031: El Bloqueo Silencioso de TMDB / Cloudflare y la fragilidad de cURL en NAS PHP 8

**Error:** `SyntaxError: Unexpected token '<'` en la consola de `admin.html` al intentar inyectar o actualizar películas mediante `manual_index.php` o `update_movie.php`.
**Causa Raíz:**
1. **Corte por User-Agent Vacío:** TMDB y sus capas de protección Cloudflare bloquean con código HTTP 403 Forbidden las peticiones HTTP que no declaran un User-Agent legítimo o que provienen de firmas automatizadas sospechosas (ej. cURL por defecto), devolviendo una página HTML de error de red.
2. **Fragilidad de cURL en NAS:** El módulo de cURL en ciertos entornos locales/NAS con PHP 8 emite advertencias o colapsa con errores de red/certificados salientes, inyectando basura en la salida estandar antes de que se devuelva el JSON.
3. **Fallo de Parseo en Cliente:** El script JS `fetch` en el panel de administración espera JSON. Al recibir el HTML de error de Cloudflare con `<br />` o `<!DOCTYPE html>`, falla el parseo arrojando `SyntaxError: Unexpected token '<'`.

**Solución DHARMA:**
1. **Migración a file_get_contents con Stream Context:** Se sustituyó `cURL` por un motor ligero basado en `file_get_contents` y un contexto de flujo (`stream_context`).
2. **Spoofing de Firma de Navegador:** Se inyectó explícitamente `User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)` y `Accept-Encoding: gzip` en las cabeceras del contexto HTTP.
3. **Descompresión Dinámica:** Se implementó una comprobación sobre el response para decodificar dinámicamente con `gzdecode` si el contenido viene comprimido en gzip, garantizando la recuperación de JSON 100% íntegro.

---

## 🛑 Lección 032: Priorización Heurística de Enlaces y Semillas mediante Clasificación por Estrellas en GalixSniper

**Error:** Complejidad y pérdida de tiempo del administrador al analizar y seleccionar de forma ciega las múltiples URLs capturadas por el radar de `GalixSniper`, sin saber cuáles enlaces eran temporales, cuáles estaban limitados por IP (IP-lock) y cuáles representaban semillas de extracción permanentes y universales.

**Causa Raíz:**
1. **Heterogeneidad de CDNs:** Sitios como Goodstream y Vimeus encriptan streams ligados a la IP del extractor (IP-lock) con expiración rápida, mientras que otros como Voe.sx o HLSWish usan tokens temporales libres de IP-lock, y otros representan embeds reutilizables e inmutables.
2. **Falta de Indicación Visual:** La interfaz del popup listaba de forma genérica todos los enlaces capturados bajo el mismo diseño, obligando a realizar pruebas manuales de ensayo y error para detectar las semillas de máxima estabilidad.

**Solución DHARMA:**
1. **Motor Evaluador de Enlaces (`getUrlRating`):** Se integró un motor de clasificación heurística en `popup.js` que categoriza las URLs capturadas en 5 niveles de estrellas:
   - ⭐⭐⭐⭐⭐ (5 Estrellas): Semillas dinámicas en servidor (`extract:`) y embeds permanentes (Vimeus/Goodstream auto-embeds). Libres de IP-lock en cliente y con permanencia universal.
   - ⭐⭐⭐⭐ (4 Estrellas): Semillas que requieren resolución en navegador (`sniper:`) y auto-embeds de Sniper.
   - ⭐⭐⭐ (3 Estrellas): Transmisiones directas sin IP-lock con token de larga duración (ej. Voe.sx, HLSWish).
   - ⭐⭐ (2 Estrellas): Transmisiones con IP-lock estricto y expiración rápida (ej. Vimeos.zip, Goodstream directos).
   - ⭐ (1 Estrella): Enlaces volátiles de anuncios o CDNs desconocidos.
2. **Enriquecimiento del UI del Popup:** Se rediseñó el renderizado del popup de la extensión para inyectar los badges coloridos de estrellas (`⭐⭐⭐⭐⭐`), etiquetas informativas sobre IP-lock y descripciones detalladas de estabilidad al lado de cada enlace capturado, permitiendo copiar instantáneamente el mirror ideal de forma visual y segura.
3. **Depuración de Ruidos de Redirección e Inspección de Embeds:** Se eliminaron por completo los botones `sniper_page` que apuntaban a la página completa contenedora (`pageUrl`), dejando únicamente los botones de reproducción. Para los iframes de reproductores detectados, se añadieron los botones **`🔗 Link Embed`** (para copiar la URL limpia del iframe) y **`🌐 Abrir`** (que abre el reproductor directamente en una pestaña nueva del navegador), simplificando drásticamente el proceso de inspección manual para el operador.

---

## 🛑 Lección 033: Auto-Detección y Normalización Multicanal de Dominios Efímeros y Clones Streamwish/Vimeos en GalixSniper

**Error:** Imposibilidad de `GalixSniper` de auto-extraer y construir enlaces de reproducción válidos (`embedUrl`) para m3u8s provenientes de dominios efímeros de corta duración (ej. `languageexchangeclub.sbs` o `kathyinformationwhether.com`) o aquellos con agrupadores de calidad complejos (ej. `_,l,n,h,.urlset`). Adicionalmente, la generación de falsos embeds inválidos como `embed-engine` para URLs de CDN como `cloudwindow-route.com` que contienen subdirectorios fijos de engine (`/engine/hls2/...`), rompiendo la inyección automática. Asimismo, las URLs de reproductores iframe (tales como `https://www.cloudwindow-route.com/embed-9cqovji4h9vy`) no eran convertidas al vuelo a `vimeos.net` al copiarlas o procesarlas por el puente.

**Causa Raíz:**
1. **Reglas de Calidad Estrictas:** El regex original en `background.js` solo reconocía sub-resoluciones numéricas o estándares fijos (`(?:n|h|360|480|720|1080)`), excluyendo descriptores como `l` (low quality) que rompían el emparejamiento.
2. **Definición Estática de Proveedores:** El normalizador requería que el host contuviera cadenas explícitas como `.vimeos.` o `goodstream` para activar la generación del URL de reproducción embed.
3. **Falsos Positivos de Ruteo:** El extractor de ID en ruta asumía a ciegas que la carpeta inmediatamente previa a la palabra clave `/hls/` era un ID de video dinámico. Al toparse con la estructura `/engine/hls2/`, extraía `"engine"` como si fuese el ID del video.
4. **Falta de Reconocimiento del ID de Embed:** No existía un extractor capaz de obtener el `videoId` directamente desde URLs de reproductores iframe (`/embed-ID` o `/e/ID`), impidiendo su normalización.

**Solución DHARMA:**
1. **Flexibilización de Regex de Calidades:** Se actualizó la expresión regular para emparejar de forma genérica cualquier lista de calidades separadas por comas (ej. `_,[a-z0-9,]+\.urlset`).
2. **Patrón de ID de Embed e Iframe:** Se añadió soporte para extraer el `videoId` directamente desde URLs de embed (`/embed-([a-zA-Z0-9]{8,20})` o `/e/([a-zA-Z0-9]{8,20})`), permitiendo normalizar el reproductor iframe en sí.
3. **Extracción Dinámica del ID de Ruteo y Exclusión de Estáticos:** Se implementó una lógica de escaneo en la ruta que segmenta el path por niveles; si se detecta un patrón de HLS (ej. `/hls3/`), se asume que el segmento previo es el ID del video (`ocmb8ssa8kyo`), a menos que coincida exactamente con la carpeta estática `"engine"` (en cuyo caso se ignora y se recurre al ID genérico de calidad de archivo).
4. **Redirección de CDNs al Reproductor Original:** Se enrutan automáticamente todas las peticiones de `cloudwindow-route.com` a su reproductor correspondiente `vimeos.net` (ej. `https://vimeos.net/embed-${videoId}.html`). Dado que este CDN carece de interfaz de visualización propia, el desvío directo garantiza la inyección de embeds de máxima estabilidad.
5. **Mapeo de Dominios Dinámicos y Fallback de CDN Universal:** Se programó un generador dinámico que toma el hostname de origen (ej. `languageexchangeclub.sbs`) y reconstruye automáticamente el enlace `/e/[ID]` (tipo StreamWish). Para cualquier otro CDN puro desconocido (donde no haya ID de ruta), el algoritmo enruta automáticamente y de forma universal al reproductor `vimeos.net/embed-[ID].html`, blindando el sistema a nivel preventivo ante futuros dominios de streaming.
6. **Normalización Preventiva en Almacenamiento y Puente:** Se modificó la extensión para interceptar y normalizar los iframes al vuelo en la acción `players_found` antes de guardarlos en el almacenamiento, y se implementó un bypass en `sniper_refresh` que convierte automáticamente la URL solicitada a `vimeos.net` y remueve el prefijo `sniper:` para evitar fallos de protocolo en el navegador.

## 🛑 Lección 034: Filtro Antiduplicados por Video ID en el Radar en Tiempo Real

**Error:** Duplicidad persistente de enlaces en el live report de "Masters Capturados" en `popup.html` para una sola película. Ocurría cuando los CDNs realizaban peticiones paralelas desde subdominios diferentes (ej. `hls1.goodstream.one` y `hls2.goodstream.one`) o refrescaban el token del manifest con minutos de diferencia, contaminando el panel con copias redundantes del mismo video.

**Causa Raíz:**
El interceptor de red en `background.js` solo verificaba si la URL exacta existía en el historial (`l.url === url`). Al cambiar subdominios o tokens de autenticación dinámica, las URLs diferían y se insertaban de nuevo, a pesar de apuntar exactamente al mismo `videoId`.

**Solución DHARMA:**
Se inyectó un validador cruzado por identificador de video en el backend de la extensión (`background.js`): tras normalizar el enlace capturado, si cuenta con un `videoId` válido, se escanea el historial de la pestaña activa en busca de un objeto que posea ese mismo `videoId`. Si se localiza una coincidencia, la petición se ignora en caliente, previniendo duplicados masivos y garantizando que cada película se reporte una única vez.

## 🛑 Lección 035: Filtro de Ruido de Clips Cortos y Previsualizaciones (Trailers) en GalixSniper

**Error:** Inclusión y reporte no deseado de videos promocionales, intros o trailers muy cortos (tales como videos de 20 segundos alojados en `player.viloud.tv` y BunnyCDN) en el popup de la extensión, saturando la vista e induciendo al error al intentar extraer semillas de películas de larga duración.

**Causa Raíz:**
El escáner del DOM y el interceptor de peticiones master de red capturaban de manera ciega cualquier iframe y flujo `.m3u8` que no proviniera de redes publicitarias estándar o redes sociales (excluyendo solo `youtube` y `google`), permitiendo la entrada de plataformas de previsualización o streaming secundario de trailers como `viloud.tv`.

**Solución DHARMA:**
Se integró una regla de exclusión proactiva en cascada:
1. En **content.js**: Se añadió `viloud.tv` a la lista de `trashDomains` para detener en seco la extracción e indexación de iframes de previsualización desde el navegador del cliente.
2. En **background.js**: Se agregó `viloud.tv` al filtro de red para bloquear cualquier intento de interceptar manifiestos y masters relacionados con la plataforma de trailers.
3. En **popup.js**: Se filtró la carga del storage impidiendo la renderización en el panel de cualquier URL asociada a `viloud.tv`. Esto enfoca el radar exclusivamente en streams de largometraje y servidores de mirror reales.

---

## 🛑 Lección 036: La Paradoja del Status 206 y el Silencio de cURL (Archive.org CORS)

**Error:** `FFmpegDemuxer: open context failed` y `MEDIA_ELEMENT_ERROR: Format error` al intentar reproducir archivos MP4 de Archive.org a través de `stream_remote.php`, a pesar de que el proxy respondía correctamente con `Content-Range` y `Content-Length`.

**Causa Raíz:**
cURL con `CURLOPT_RETURNTRANSFER false` (streaming directo) **no forwarda automáticamente el status line HTTP** del servidor remoto al cliente. Archive.org responde `206 Partial Content` para Range Requests, pero el navegador del cliente recibía `200 OK` con cabecera `Content-Range`. Esta combinación viola el protocolo HTTP/1.1 (un response 200 no debe incluir Content-Range), y el motor de video del navegador (FFmpeg/Chromium) rechaza el stream por inconsistencia de protocolo.

**Solución DHARMA:**
Implementar interceptación explícita del status line en `CURLOPT_HEADERFUNCTION`:
```php
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $headerLine) {
    if (preg_match('/^HTTP\/[\d.]+ (\d+)/i', $headerLine, $matches)) {
        $code = (int)$matches[1];
        if ($code === 206) {
            http_response_code(206); // Forwardar 206 al navegador
        }
    }
    if (preg_match('/^(Content-Type|Content-Length|Content-Range|Accept-Ranges):/i', $headerLine)) {
        header($headerLine); // Forwardar headers multimedia
    }
    return strlen($headerLine);
});
```
Esto garantiza que el navegador reciba `206 Partial Content` correctamente, alineando el status code con las cabeceras Content-Range y permitiendo la reproducción sin errores de protocolo.

**Lección Adicional — Doble Codificación:**
`encodeURIComponent` en JavaScript genera `%2520` (doble codificación: `%` → `%25`, luego `20` queda literal). Si se aplica `urldecode()` sin filtro, `%20` se convierte en espacios reales → Archive.org responde HTTP 400. La solución es detectar solo `%25` antes de decodificar:
```php
if (strpos($url, '%25') !== false) { $url = urldecode($url); }
```
Esto decodifica `%2520` → `%20` (correcto) pero preserva `%20` intacto (no lo convierte en espacios).

---

## 🛑 Lección 037: El Tanque de Guerra — Blindaje de 6 Niveles para Proxies de Streaming Remoto

**Error:** Un proxy de streaming abierto (`stream_remote.php?url=...`) puede ser explotado como open-proxy para acceder a cualquier recurso de internet, violando políticas de seguridad y consumiendo ancho de banda del servidor.

**Causa Raíz:**
Sin validación de dominio, extensión, o tipo de contenido, cualquier atacante puede usar el proxy para acceder a recursos internos, descargar archivos maliciosos, o realizar ataques de amplificación.

**Solución DHARMA — 6 Niveles de Blindaje:**
1. **Anti-Open-Proxy (Whitelist de Dominios):** Solo dominios explícitamente autorizados (`archive.org`, `ia*.us.archive.org`) pueden ser proxyados. Todo lo demás recibe HTTP 403.
2. **Validación de Host:** `parse_url()` verifica que la URL tenga un host válido. URLs malformadas o sin host son rechazadas.
3. **Solo Extensiones Autorizadas:** Permite exclusivamente extensiones de video/streaming (mp4, webm, mkv, avi, mov, m3u8, ts). Scripts, HTML, o binarios son bloqueados.
4. **Desactivación Segura de Compresión:** `apache_setenv('no-gzip', 1)` + `zlib.output_compression Off` sin usar `ob_end_clean()` o `output_buffering Off` que rompen el gestor de buffers global de Apache/PHP-FPM.
5. **Timeout Inteligente:** `connectTimeout 15s` (tiempo para establecer conexión) + `transferTimeout 300s` (tiempo total de transferencia). Evita bloqueos indefinidos con servidores lentos.
6. **Validación de Content-Type + Logging:** `CURLOPT_HEADERFUNCTION` intercepta y valida Content-Type del response. Errores cURL se loggean con `error_log()` para diagnóstico sin exponer detalles al cliente.

**Principio DHARMA:** Todo proxy de streaming remoto debe ser un "tanque de guerra" — cerrado por defecto, abierto solo para lo autorizado, con timeouts definidos y logging de errores.

---

## 🛑 Lección 039: La Paradoja del Preview Ciego — Archivos sin Match Invisibles y TMDB ID No Editable

**Error:** En el modal de preview del escaneo, los archivos sin coincidencia en TMDB se mostraban como texto inerte sin checkbox ni campo de entrada, obligando al usuario a salir del modal para indexarlos manualmente. Los archivos ya indexados correctamente no aparecían en absoluto. No había forma de sobrescribir un TMDB ID desde el preview.

**Causa Raíz:**
1. El filtro `noMatch` en `admin.html` renderizaba solo un `<div>` con texto, sin checkbox ni input.
2. El filtro `noChange` (archivos ya indexados correctamente) se ignoraba completamente.
3. `runApply()` en `scrapper.php` re-buscaba TMDB siempre por nombre, sin aceptar IDs manuales del frontend.
4. No existía función `tmdbSearchById()` para buscar películas por ID numérico directo.

**Solución DHARMA (Planificada):**
1. **Backend**: Nueva función `tmdbSearchById(int $id, string $tipo)` para fetch directo por ID.
2. **Backend**: `runApply()` modificado para aceptar `$customTmdb = [index => tmdb_id]`. Si existe ID manual, usa `tmdbSearchById()` en vez de búsqueda por nombre.
3. **Frontend**: Modal rediseñado con 4 secciones (incluyendo "✅ Ya indexados" colapsable y "⚠️ Sin match" con input TMDB ID + botón 🔍 Buscar).
4. **Frontend**: Checkboxes en TODAS las filas, botones "Seleccionar Todo / Ninguno".
5. **Frontend**: Apply envía `{"approve": [...], "custom_tmdb": {index: id}}`.

**Principio DHARMA:** Todo archivo escaneado debe ser visible y editable en el preview. El usuario debe poder corregir cualquier match fallido sin salir del modal.

---

## 🛑 Lección 038: El Espejismo del Worker Externo — exec() y el Abismo del PATH Perdido

**Error:** El procesamiento completo de Autopilot (100% de semillas) no se ejecutaba. El worker lanzado vía `exec("php worker.php ... > /dev/null 2>&1 &")` nunca arrancaba, dejando el progreso pegado en `0 / 0` con el toast congelado.

**Causa Raíz:**
1. **PHP_BINARY Fantasma:** La constante `PHP_BINARY` en la SAPI del servidor web (PHP-FPM/mod_php) puede apuntar a un binario que no existe, está vacío, o no tiene permisos de ejecución para el usuario del proceso web (`www-data`, `nobody`, `u0_aXX`).
2. **/tmp/ Inaccesible:** El usuario del servidor web frecuentemente carece de permisos de escritura en `/tmp/`. Cualquier intento de crear archivos de progreso o logs ahí fallaba silenciosamente.
3. **exec() con Ampersand (&):** Al usar `exec($cmd . " &")`, el código de salida (`exitCode`) siempre es `0` aunque el worker muera microsegundos después — el sistema operativo reporta éxito al *iniciar* el proceso, no al *completarlo*. Las validaciones tradicionales de `$exitCode !== 0` nunca detectaban fallos.
4. **Silencio Absoluto:** La redirección `> /dev/null 2>&1 &` enviaba tanto stdout como stderr al vacío. Si el worker moría con un `Segmentation fault` (code 139) o `PHP Fatal error`, no quedaba ningún rastro.

**Solución DHARMA — Worker Inline con Connection-Close Trick:**
Se eliminó por completo la dependencia de `exec()` y procesos externos. En su lugar, se implementó un **worker inline** dentro del mismo proceso HTTP:

```php
// 1. Escribir progreso inicial
file_put_contents($progressFile, json_encode($initial), LOCK_EX);

// 2. Cerrar conexión HTTP temprano
ignore_user_abort(true);
set_time_limit(0);
while (ob_get_level() > 0) ob_end_clean();
header("Connection: close\r\n");
header("Content-Type: application/json");
echo json_encode(["status" => "started"]);
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
flush();

// 3. Todo el procesamiento aquí, en el mismo proceso
$movies = $pdo->query("SELECT ...")->fetchAll();
foreach ($movies as $item) {
    // ... curación, poda, auto-fill ...
    writeProgressToFile($progressFile, $progress);
}
```

**Principio DHARMA:** Nunca asumas que `exec()` funciona en el servidor de producción. El PATH del servidor web es un desierto estéril. La técnica de connection-close (`fastcgi_finish_request` + `flush`) permite que el cliente reciba la respuesta al instante mientras el servidor completa el trabajo pesado en el mismo proceso, sin depender de binarios externos, permisos de `/tmp/`, ni procesos huérfanos.

---

## 🛑 Lección 039: El Silencio del Deprecado — PHP 8.5 y el Content-Type Fantasma

**Error:** `asset_proxy.php` retorna HTTP 200 con los datos binarios JPEG correctos (~130KB) pero `Content-Type: text/html; charset=UTF-8` en vez de `image/jpeg`. Las imágenes TMDB no cargan en el navegador.

**Causa Raíz:**
PHP 8.5.1 deprecó `curl_close()` como no-op. Al ejecutarse con `error_reporting(E_ALL)` activo, PHP emite un warning HTML (`Deprecated: Function curl_close() is deprecated...`). Este warning se OUTPUTEA antes de cualquier `header()` call, activando el envío implícito de headers. Cualquier `header('Content-Type: ...')` posterior es ignorado porque PHP no puede modificar headers después de output. PHP-FPM sirve su Content-Type por defecto (`text/html; charset=UTF-8`). El navegador, con strict MIME checking, rechaza la imagen aunque los datos binarios sean válidos.

**Por qué fue tan difícil de diagnosticar:**
1. `curl_close()` es ubicuo en ejemplos y tutoriales PHP; nadie lo cuestiona
2. PHP no produce error fatal — solo un warning que no interrumpe la ejecución
3. El resto del script (curl_exec, echo del contenido) funciona perfectamente
4. HTTP 200 con 130KB de datos JPEG válidos — desde el servidor todo parece correcto
5. No hay error 500, no hay excepción, no hay log visible de error
6. La función funcionó por años en PHP 8.4 y anteriores sin problema
7. El deprecado es silencioso: no aparece en changelog de migración de PHP 8.5
8. Solo se descubre al inspeccionar manualmente los headers de respuesta con `curl -v`

**Solución DHARMA:**
1. Eliminar `curl_close()` de TODO el código (es un no-op confirmado en PHP 8.5+)
2. Agregar `error_reporting(0)` al inicio de TODOS los endpoints PHP que sirvan contenido binario o JSON (no solo imágenes)
3. Para detección MIME, usar `pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)` con un mapa estático en vez de regex sobre la URL completa
4. No confiar en `curl_getinfo($ch, CURLINFO_CONTENT_TYPE)` como única fuente — forzar MIME por extensión de archivo
5. Verificar headers de respuesta con `curl -D -` o `curl -w "%{content_type}"` como paso obligatorio en debugging de proxys

**Prevención:**
- `error_reporting(0)` debe ser la PRIMERA línea de todo endpoint que modifique headers HTTP
- `header('Content-Type: ...')` debe ocurrir antes de cualquier operación que pueda emitir output
- En PHP 8+, funciones deprecadas no lanzan excepción; emiten warnings que rompen headers silenciosamente
- Los datos binarios pueden servirse correctamente aunque el Content-Type esté mal — el bug es invisible desde el servidor
- Usar siempre `php -l` y probar con `curl -D -` antes de desplegar

**Diagnóstico Rápido:**
```bash
# Si TYPE no es image/jpeg, hay contaminación de headers
curl -s -w "HTTP:%{http_code} TYPE:%{content_type}" -o /dev/null \
  "http://localhost/asset_proxy.php?url=https://...jpg"

# Verificar si hay output ANTES de los headers
curl -s "http://localhost/asset_proxy.php?url=..." | head -c 100
# Si ves "<br />\n<b>Deprecated</b>:" → warning corrompiendo headers
```

**Principio DHARMA:** *"Lo que no falla no significa que funcione"* — Un proxy puede descargar y servir datos impecablemente pero ser completamente inútil si el header que describe esos datos está corrupto. Los headers HTTP son el contrato entre servidor y cliente; violarlos invalida la respuesta aunque el body sea perfecto.

---

---

## 🛑 Lección 040: El Secuestro del Scroll (Wheel Event Hijack)

**Error:** El scroll vertical del trackpad/rueda del mouse quedaba paralizado al pasar sobre los carruseles de películas, forzando un desplazamiento horizontal errático que el usuario no intentaba realizar.

**Causa Raíz:**
El listener `wheel` en `initGridScrolling()` capturaba el evento deltaY (scroll vertical), lo traducía a un desplazamiento horizontal del carrusel mediante `scrollLeft += event.deltaY`, y ejecutaba `event.preventDefault()`. Aunque la intención era mejorar UX en trackpad, en la práctica secuestraba el scroll del navegador y generaba una experiencia antinatural donde el trackpad no podía hacer scroll vertical mientras el puntero estuviera sobre una tarjeta.

**Solución DHARMA:**
Eliminar por completo el listener `wheel` de `initGridScrolling()`. Conservar solo el drag-to-scroll (arrastre con mouse/táctil) como la única forma de desplazamiento horizontal manual. El scroll vertical natural del navegador se restaura al 100%.

**Principio DHARMA:** *"El trackpad del usuario no es tuyo"* — Interceptar el scroll vertical para convertirlo en horizontal puede parecer una mejora de UX, pero en realidad es un secuestro del control del navegador. Los carruseles deben desplazarse horizontalmente SOLO con arrastre táctil o click-and-drag, no reasignando la rueda del ratón por defecto del sistema.

---

## 🛑 Lección 041: La Violación del Volumen de Sistema (osascript set volume)

**Error:** El reporte de voz sonora (`speak_sonic.py`) se escuchaba extremadamente alto después de ejecutar `say`, sin importar el volumen actual del sistema del usuario. El script subía el volumen al 100% antes de hablar y no lo restauraba correctamente.

**Causa Raíz:**
El script contenía `osascript -e "set volume output volume 100"` para garantizar audibilidad. Sin embargo, esta manipulación directa del volumen del sistema es invasiva: ignora la preferencia actual del usuario, puede causar picos de sonido en audífonos, y no hay garantía de que el valor original se restaure (especialmente si el script se interrumpe a medio camino).

**Solución DHARMA:**
Eliminar TODA manipulación de volumen del sistema del script. `say` se ejecuta al volumen actual del usuario. Si el usuario desea escuchar, que suba su volumen manualmente. El reporte de voz es obligatorio, pero no al costo de violar el control de hardware del usuario.

**Principio DHARMA:** *"El volumen del usuario es sagrado"* — Ningún script debe tocar `osascript set volume` o equivalente en ningún sistema operativo. El software debe operar dentro del volumen que el usuario ha configurado, no modificarlo para garantizar su propia audibilidad.

---

## 🛑 Lección 042: La Guerra de z-index entre Pseudo-elementos y Overlays (Shimmer)

**Error:** Al hover sobre la tarjeta de una película, el efecto shimmer (brillo vítreo) no se veía o se veía detrás del overlay oscuro del icono play.

**Causa Raíz:**
El overlay oscuro (`.movie-overlay`) tenía `z-index: 20` (dentro de `.movie-card`), mientras que el pseudo-elemento `::before` para el shimmer no tenía `z-index` explícito, heredando el valor por defecto `auto` que lo posicionaba en el mismo nivel del `.movie-card` padre (z-index implícito 1). Sin `z-index: X` en `::before`, el overlay (z-index 20) se renderizaba encima, ocultando el brillo.

**Solución DHARMA:**
Asignar `z-index: 25` al `::before` del shimmer, asegurando que el brillo cruce **sobre** el overlay oscuro (z-index 20) e ilumine también el icono play. El orden correcto: `::before (25) > .movie-overlay (20) > .movie-info (10)`.

**Principio DHARMA:** *"En CSS, el orden de apilamiento importa tanto como el diseño"* — Un pseudo-elemento con `position: absolute` y sin `z-index` se apila dentro del contexto del padre. Si otros hijos tienen `z-index` superiores, el pseudo-elemento queda oculto. Siempre declarar `z-index` en pseudo-elementos animados que compiten con overlays.

---

## 🛑 Lección 043: El Fantasma Apple Double (._ Files)

**Error:** El escáner de biblioteca (`scanner.php`) creaba entradas en la base de datos para archivos invisibles con prefijo `._` (Apple Double), como `._MiPelicula.mp4`, causando entradas fantasma en el catálogo de administración que no podían reproducirse.

**Causa Raíz:**
macOS (y sistemas de archivos montados desde Mac) genera bifurcaciones de recursos (Apple Double) para cada archivo. Estos archivos `._` contienen metadatos extendidos, NO contenido multimedia. El escáner recorría todos los archivos del directorio `media/` y, al no filtrar por prefijo, procesaba estos archivos invisibles como si fueran películas válidas.

**Solución DHARMA:**
Agregar `if (str_starts_with($basename, '._')) continue;` como primera validación dentro del bucle de escaneo, antes del filtro de extensión y cualquier otra lógica. Esto descarta los archivos Apple Double inmediatamente sin consumir recursos de FFprobe o TMDB.

**Principio DHARMA:** *"Lo invisible también contamina"* — Los sistemas operativos generan archivos auxiliares invisibles al usuario (`.DS_Store`, `._*`, `Thumbs.db`, etc.). Todo escáner de archivos debe filtrar proactivamente estos artefactos antes de procesar, o terminará indexando basura en la base de datos.

---

## 🛑 Lección 044: La Cárcel del Caché Fallback — Embed Fallback Impide Re-Extracción

**Error:** Autopilot Engine terminaba en ~5 segundos reportando "procesamiento completo" sin haber curado ninguna semilla, a pesar de que la mayoría del contenido necesitaba re-extracción.

**Causa Raíz:**
El caché de semillas (`resolved_streams_cache`) almacenaba entradas con `servidor_nombre = 'Embed Fallback'` cuando la extracción original fallaba y se inyectaba el embed como fallback. En ejecuciones posteriores, Autopilot encontraba el caché y lo consideraba "saludable" (healthy), saltándose la re-extracción. Como el enlace resuelto era la misma URL semilla (`resolved_url === seed_url`), nunca se había obtenido un stream real. El sistema estaba atrapado en un bucle: fallaba la extracción → guardaba fallback → en la siguiente ejecución veía el caché y no re-extractaba → el stream seguía siendo un embed sin resolver.

**Tres bugs en uno:**

1. **Embed Fallback no forzaba re-extracción:** El caché con `servidor_nombre === 'Embed Fallback'` era tratado como healthy, bloqueando la resolución real.
2. **Falta else para URLs no-seed:** Las URLs directas HTTP o locales (no semillas) nunca se verificaban — se asumían como saludables sin comprobación, dejando muertos no detectados.
3. **WHERE is_online filtraba offline:** La query SQL `WHERE c.is_online = 1` excluía automáticamente contenido offline, que es precisamente el que necesita curación.

**Solución DHARMA (Triple Fix):**
1. **Detección de Fallback:** Si `resolved_url === seed_url` (el extractor nunca encontró un stream real) o `servidor_nombre === 'Embed Fallback'`, el caché NO se considera healthy. Se fuerza re-extracción. Nuevo contador `fallback_cached`.
2. **Else branch:** Para URLs no-seed, HTTP → `checkUrlStatus()`, archivos locales → `file_exists()`. Dead añadidas a `static_dead_detected[]`.
3. **WHERE is_online eliminado:** La query ahora procesa 100% de las filas sin filtrar por estado.

**Principio DHARMA:** *"Un caché que oculta fallos es peor que no tener caché"* — El sistema de caché no debe enmascarar errores de extracción. Un resolved_url idéntico a la seed_url indica que nunca hubo resolución real. El sistema de caché necesita una distinción clara entre "caché de éxito" y "caché de fallback" para no perpetuar errores.

---

## 🛑 Lección 045: El Muro del CDN Blindado — Pornhub y sus 3 Capas de Bloqueo

**Error:** Los embeds de Pornhub (`https://es.pornhub.com/embed/...`) cargan el HTML del reproductor en el iframe pero el video nunca arranca — la pantalla se queda en negro o cargando.

**Causa Raíz:**
Pornhub implementa 3 capas de defensa que bloquean la reproducción fuera de su dominio:

1. **X-Frame-Options DENY** (Capa 1 — ✅ Resuelta): El servidor de Pornhub envía la cabecera `X-Frame-Options: DENY` que impide incrustar su página en iframes de terceros. Se resuelve sirviendo el HTML localmente a través de `embed_proxy.php`.

2. **API de Video Bloqueada por Origin** (Capa 2 — ❌): El reproductor embed carga HTML, CSS y JS, pero al solicitar metadatos del video a la API interna `https://es.pornhub.com/video/get_media?...`, Pornhub valida estrictamente el `Origin` HTTP. Aunque el HTML se sirva desde nuestro proxy, el JavaScript del embed ejecuta `fetch()` desde el contexto del iframe con un Origin que no coincide con `pornhub.com`. El servidor responde `503 Service Unavailable` o `403 Forbidden`, dejando al reproductor sin datos de video.

3. **CDN con IP-Lock** (Capa 3 — ❌): `extract.php` resuelve la URL embed a su CDN directo (`phncdn.com`) con tipo `hls`. Estos CDNs generan tokens de acceso ligados a la IP que solicitó el manifiesto. Los segments `.ts` del HLS devuelven `404 Not Found` desde dispositivos fuera del rango de IP autorizado.

**Por qué `extract.php` no debe tocar Pornhub:**
El flujo normal de GalixMovie con semillas `extract:` funciona para Vimeus, Goodstream, etc., porque esos CDNs permiten reutilización de tokens. Pornhub, en cambio, genera tokens IP-bound ultra-estrictos con expiración en segundos. Intentar resolver el CDN de Pornhub como si fuera un HLS estándar produce falsos positivos (el manifiesto se descarga, pero los segments fallan 404).

**Solución PH-DHARMA aplicada:**
No mezclar a Pornhub con `extract.php` ni con la lógica HLS. En su lugar:
1. Detectar `phncdn.com`/`pornhub.com` en `js/app.js` tras el paso de `extract.php` y descartar el CDN resuelto, conservando la URL embed original.
2. Redirigir la URL embed a `backend/embed_proxy.php?url=...` en lugar de pasarla por `proxy.php` o `extract.php`.
3. `embed_proxy.php` debe: (a) inyectar tag `<base>` para rutas relativas, (b) congelar `top`/`parent`/`self` con `Object.defineProperty` para evadir anti-frame-busting JS, (c) suprimir popups y redirecciones de ventana.
4. `error_reporting(0)` en el proxy para evitar corrupción de headers por deprecaciones de PHP 8.5.

**Diagnóstico Rápido:**
```bash
# Verificar si embed_proxy.php sirve HTML correcto
curl -s "http://localhost/backend/embed_proxy.php?url=https://es.pornhub.com/embed/ph5e47c91c20c8f" | head -50
# Buscar /video/get_media en consola del navegador
# Si hay 503 en esa API → Pornhub bloquea por Origin (Capa 2)
```

**Principio DHARMA:** *"No todo lo que brilla es HLS"* — Un CDN puede devolver un manifiesto .m3u8 funcional en el servidor pero sus segments pueden tener IP-lock estricto. La existencia de un manifiesto no garantiza reproducibilidad. Para plataformas con 3+ capas de bloqueo (Pornhub, OnlyFans, etc.), la única aproximación viable es servir el embed limpio y aceptar las limitaciones de la API interna. No forzar resolución CDN donde hay IP-lock.

---

> *"Un error que se documenta se convierte en un peldaño; un error que se olvida se convierte en un abismo."* - **Filosofía DHARMA de GalixMovie**

## 🛑 Lección 046: La Columna Fantasma y el Principio de Resiliencia por SHOW COLUMNS

**Error:** `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'c.oculta' in 'field list'` al consultar `get_content.php`. El grid entero del catálogo dejó de renderizar — solo se veía "Continuar Viendo".

**Causa Raíz:**
Se agregó `c.oculta` directamente en el `SELECT` de `get_content.php` sin verificar si la columna existía en la base de datos. La migración (`migrate.php`) no se había ejecutado aún en el servidor de producción (Box Symmetry offline por SSH). MySQL lanza un error fatal en tiempo de consulta si el campo no existe en el esquema, independientemente de que el backend capture la excepción con `try-catch`.

**Solución DHARMA — Patrón SHOW COLUMNS + alias fallback:**
Antes de construir el SELECT, verificar la existencia de la columna con una consulta de metadatos:
```php
$checkOculta = $pdo->query("SHOW COLUMNS FROM `contenido` LIKE 'oculta'")->fetch();
$ocultaCol = $checkOculta ? ", c.oculta" : ", 0 as oculta";
```
Si la columna existe → se incluye en el SELECT. Si no → se usa un literal `0 as oculta` para que el cliente JS siempre reciba el campo, aunque sea con valor 0.

**Lección secundaria — String "0" es truthy en JavaScript:**
Cuando la columna no existe, `0 as oculta` llega como string `"0"` en JSON. En JavaScript:
```javascript
!"0" // → false (string no vacío es truthy)
```
Esto causó que **todas** las películas se filtraran como ocultas. Solución: normalizar a booleano real en `loadContent()`:
```javascript
m.oculta = !!(m.oculta == 1 || HIDDEN_TITLES.some(...));
```

**Principio DHARMA:** *"Nunca asumas que una columna de BD existe en producción"* — Siempre usar `SHOW COLUMNS` + fallback para columnas agregadas después del despliegue inicial. Y en JavaScript, normalizar cualquier valor de BD a booleano puro con `!!()` para evitar falsos positivos por type coercion.

---

### DHARMA #47: curl_close() PHP 8.5 Deprecation
**Problema:** stream.php retornaba `text/html` en vez de `video/mp4`, causando error -1 en Roku.
**Causa Raíz:** `curl_close()` en PHP 8.5 emite warning que se outputs antes de headers, rompiendo la respuesta.
**Solución:** Eliminar `curl_close()` — PHP cierra automáticamente al final del script.
**Blindaje:** Verificar compatibilidad PHP 8.5+ antes de agregar funciones deprecated.

### DHARMA #48: PHP !empty("0") Bug
**Problema:** Range requests con `bytes=0-` fallaban, causando timeout en Roku (error -2).
**Causa Raíz:** `!empty("0")` retorna true en PHP — empty considera "0" como empty.
**Solución:** Cambiar a `$matches[2] !== ""` para verificación explícita.
**Blindaje:** Nunca usar `empty()` para validar strings que pueden ser "0".

### DHARMA #49: WRITEFUNCTION Abort Pattern
**Problema:** Cada request a stream.php tomaba 5 segundos.
**Causa Raíz:** `return $chunkLen` en WRITEFUNCTION le decía a curl que siga descargando todo el archivo.
**Solución:** `return -1` después de enviar los bytes solicitados aborta la transferencia. Tiempo: 5s → 10ms.
**Blindaje:** Siempre abortar transferencias parciales con `return -1` en WRITEFUNCTION.

### DHARMA #50: rclone VFS Cache Mode
**Problema:** `--vfs-cache-mode full` descargaba archivos completos al cache local (2.6GB).
**Causa Raíz:** `full` cachea todo lo que se lee; con archivos de 1GB+, llenaba el disco rápidamente.
**Solución:** `--vfs-cache-mode writes` — lecturas van directo a Google Drive sin cache local.
**Blindaje:** Evaluar impacto de cache mode antes de configurar; `writes` es mejor para streaming read-only.

### DHARMA #51: Episode Prebuffer Pattern
**Problema:** Click en episodio tomaba 3-5s de carga inicial.
**Causa Raíz:** No había pre-carga del stream antes del click.
**Solución:** Timer 1.5s en mouseenter → `<video>` hidden con `preload=auto`. Click reutiliza buffer.
**Blindaje:** Aplicar patrón de prebuffer por hover para contenido de alta latencia.

### DHARMA #52: Vertical Carousel Viewport
**Problema:** Lista de episodios desbordaba el modal mostrando todos los items.
**Causa Raíz:** Sin clipping/máscara, todos los hijos del Group eran visibles.
**Solución:** Rectangle con `clip="true"` como viewport + updateEpisodeViewport() reposiciona items.
**Blindaje:** Siempre usar clipping para listas largas en interfaces con espacio limitado.


### #53: Prebuffer flooding en episodios de series
**Fecha:** 2026-06-13
**Problema:** Prebuffer instantáneo en cada hover de episodio causaba 7+ requests HTTP simultáneos (uno por cada episode row). Cada request era un curl completo a stream.php, desperdiciando ancho de banda.
**Causa Raíz:** `onEpisodePrebufferFire()` se llamaba directamente en `moveEpisodeFocus` sin debounce. Cada flecha UP/DOWN disparaba un prebuffer nuevo.
**Solución:** Debounce de 500ms con `setTimeout` + `mouseleave` cancel. Solo prebuffera cuando el usuario PARA en un episodio 500ms. Click sigue siendo inmediato.
**Blindaje:** Siempre usar debounce en prebuffer de contenido pesado (gdrive: MP4). El debounce debe ser cancelable con `mouseleave`.

### #54: Restaurar último episodio reproducido en series
**Fecha:** 2026-06-13
**Problema:** Al volver a abrir el modal de una serie, el cursor volvía al primer episodio. El usuario tenía que navegar manualmente al que había dejado.
**Causa Raíz:** No se guardaba el último episodio seleccionado.
**Solución:** `localStorage.setItem(last_episode_ + movie.id, epKey)` al hacer click. Al abrir modal, busca en `localStorage`, scrollea a la posición + prebuffera ese episodio.
**Blindaje:** Para apps con estado persistente, usar `localStorage` (web) o `roRegistrySection` (Roku) con clave compuesta `prefix_id`.

### #55: Prebuffer al abrir modal de serie
**Fecha:** 2026-06-13
**Problema:** Al restaurar posición del último episodio, el cursor se movía pero no se hacía prebuffer hasta que el usuario navegara.
**Causa Raíz:** `buildEpisodeList` restauraba posición pero no iniciaba el timer de prebuffer.
**Solución:** Después de restaurar posición y pintar focus, iniciar `episodePrebufferTimer` si el episodio es gdrive:. Esto asegura que al abrir el modal, el último episodio reproducido esté prebufferando.
**Blindaje:** Al restaurar estado persistente, siempre iniciar la operación pesada correspondiente (prebuffer, carga, etc.) no solo la posición visual.

---

### #56: Unificación de Labels (S1-S5)
**Fecha:** 2026-06-15
**Problema:** Labels S1-S5 tenían texto plano sin contexto visual en inyector, tabla, data-labels y modal edición. Era confuso identificar qué representa cada slot.
**Causa Raíz:** Los slots tenían nombres genéricos (S1, S2...) sin iconografía que indicara su naturaleza (local/cloud/seed).
**Solución:** Asignar iconos fijos por slot: S1📁 (local), S2☁️/S3☁️ (cloud), S4🌱/S5🌱 (seed). Aplicados en inyector, tabla, data-labels y modal edición.
**Blindaje:** Todo slot numérico debe tener icono + tooltip que describa su función. No asumir que el usuario recuerda qué es cada S[N].

---

### #57: Filename Editable con Rename Físico
**Fecha:** 2026-06-15
**Problema:** El filename en el modal de edición era un texto estático. Si el archivo físico se renombraba, había que hacerlo manualmente por SSH.
**Causa Raíz:** update_movie.php no contemplaba renombrar archivos en disco, solo actualizaba la BD.
**Solución:** Campo filename editable en modal. Botón guardar envía nuevo nombre. update_movie.php: si filename cambió, ejecuta rename() en disco + subtítulos (srt, vtt, ass). Reporta flag `renamed: true/false`. El frontend actualiza la tabla con el nuevo nombre.
**Blindaje:** Siempre que el filename sea editable, sincronizar con disco. No separar estado lógico (BD) de estado físico (filesystem). Incluir subtítulos en el rename.

---

### #58: Responsive Admin para iPhone
**Fecha:** 2026-06-15
**Problema:** Los botones del inyector se salían del viewport en iPhone. La tabla de gestión no se podía scrollear horizontalmente. Columna Título quedaba detrás del scroll.
**Causa Raíz:** Botones con padding fijo (12px 20px) que sumaban >375px. Tabla sin overflow-x. Título sin posición sticky.
**Solución:** Botones reducidos (8px 12px). Título 0.95rem. Tabla wrapper con overflow-x: auto. Columna Título con position: sticky; left: 0 + z-index + background.
**Blindaje:** Probar todo cambio UI en viewport de 375px (iPhone SE/12 mini). Usar overflow-x: auto + sticky columns para tablas con muchas columnas.

---

### #59: 504 Gateway Time-out en Procesos Largos (PHP + Nginx)
**Fecha:** 2026-06-15
**Problema:** Scrapper.php daba 504 Gateway Time-out después de 60s. Nginx mataba la conexión antes de que PHP terminara de escanear + consultar TMDB.
**Causa Raíz:** Tres timeouts independientes: nginx fastcgi_read_timeout (60s → 300s), nginx fastcgi_send_timeout (60s → 300s), PHP-FPM request_terminate_timeout (120s → 600s). Cualquiera de los tres mata el proceso.
**Solución:** Aumentar los tres timeouts. Usar set_time_limit(0) en el script. Para streaming, connection-close pattern (fastcgi_finish_request + flush) para workers largos.
**Blindaje:** Siempre que hay un proceso largo (scanner, autopilot, extract), verificar los 3 timeouts (nginx upstream, nginx fastcgi, PHP-FPM). connection-close no es suficiente si el cliente no cierra — monitorear CPU en workers inline.

---

### #60: Editable Input para Nombre Propuesto en Scanner
**Fecha:** 2026-06-15
**Problema:** El nombre propuesto por el scrapper era texto estático. Para cambiarlo, el usuario debía aceptar el scan y luego editar manualmente el archivo.
**Causa Raíz:** proposed_name se renderizaba como <span> sin capacidad de edición.
**Solución:** Reemplazar <span> por <input type="text"> con clase scan-rename y data-index. preConfirm recolecta custom_names de todos los inputs. runApply(): si custom_name existe, rename file + subtítulos + busca TMDB con el nuevo nombre.
**Blindaje:** Cualquier valor propuesto por el sistema debe ser editable por el usuario si implica cambios físicos (rename en disco). Punto único de edición antes de aplicar.

---

### #61: Skip Indexed + Ocultar Indexados
**Fecha:** 2026-06-15
**Problema:** Cada escaneo mostraba todos los archivos incluyendo los ya indexados correctamente. El usuario se abrumaba con cientos de "✅ Ya indexados" y perdía los que necesitaban acción.
**Causa Raíz:** No había filtro ni en backend ni en frontend para omitir archivos sin cambios.
**Solución:** (1) Checkbox "Solo nuevos" junto al botón Escanear → envía &skip_indexed=1 → backend omite archivos ya indexados sin cambios. (2) Checkbox "Ocultar ya indexados" en el modal de resultados → toggle visual de la sección ✅. (3) Mensaje "Sin novedades" personalizado según modo.
**Blindaje:** Escaneos deben tener modo "solo nuevos" por defecto. El usuario debe poder optar a "reescanear todo" cuando quiera. El modal debe permitir filtrar visualmente por categoría.

---

### #62: Trailing Dot en ProposedName por Año Falso
**Fecha:** 2026-06-15
**Problema:** El nombre propuesto terminaba en punto ("Movie.") en vez de "Movie.mp4" para algunos archivos.
**Causa Raíz:** $ext = pathinfo($name, PATHINFO_EXTENSION) retorna vacío cuando el filename termina en punto o no tiene extensión. $proposedName = $safeTitle . '.' . $ext → "Movie.".
**Solución:** trim(".", " \t\n\r\0\x0B.") en safeTitle. Separar yearPart. Solo concatenar '.' si $ext no está vacío: ($ext ? '.' . $ext : '').
**Blindaje:** pathinfo puede retornar extensión vacía. Siempre validar que $ext no esté vacío antes de concatenar punto. Filenames con trailing dots son inválidos en Windows pero comunes en descargas web.

---

### #63: Rutas Rotas por Movimiento de Archivos (media/ → HDD_500GB/)
**Fecha:** 2026-06-15
**Problema:** Catálogo mostraba solo 112 de 284 películas (60% oculto). Usuarios web y Roku veían catálogo incompleto.
**Causa Raíz:** Los archivos .mp4 fueron movidos de media/ a media/HDD_500GB/ en algún momento. Las rutas en peliculas_metadata seguían apuntando a media/X.mp4. file_exists() fallaba y get_content.php filtraba 173 películas.
**Solución:** UPDATE masivo de 157 rutas en BD. Detección de 17 archivos adicionales que tenían año en el filename (ej. "Peli (2024).mp4" vs "Peli.mp4"). Corrección caso-por-caso con find -iname.
**Blindaje:** Cuando el almacenamiento físico cambia (nuevo disco, reorganización), ejecutar sync de rutas en BD. Usar rutas relativas si es posible o un symlink estable (media/HDD_500GB) que pueda ser recreado. get_content.php debería tener un fallback: si file_exists falla en la ruta exacta, buscar por basename en todo media/.

---

### #64: Acentos en Filenames (Día → Dia)
**Fecha:** 2026-06-15
**Problema:** find -iname '*dia*entrenamiento*' no encontraba "Día de entrenamiento (2001).mp4" en Linux.
**Causa Raíz:** -iname en Linux/Unix hace match case-insensitive solo para ASCII. Caracteres acentuados (í, é, á, etc.) NO son tratados como equivalentes a sus versiones sin acento.
**Solución:** Usar grep con locale apropiado o buscar con el nombre exacto incluyendo acentos.
**Blindaje:** En Linux, para buscar archivos con acentos, usar ls | grep -i o name exacto. find -iname no funciona con Unicode acentuado. Las queries PHP SQL deben escapar correctamente los acentos pero LIKE con % funciona.

---

### #65: shell_exec sin timeout (rclone cuelga el scrapper)
**Fecha:** 2026-06-27
**Problema:** Botón Scrapper en admin se quedaba en "Iniciando escaneo inteligente..." sin respuesta. Todo el script se bloqueaba.
**Causa Raíz:** `shell_exec('rclone ls gdrive:...')` sin timeout. Si rclone no respondía (GDrive inaccesible/lento por Tunnel), el script se colgaba en `scanGoogleDrive()` antes del primer echo NDJSON.
**Solución:** Envolver shell_exec en `rcloneExecSafe()` con comando `timeout` de Linux/Termux (15s default). Agregar pre-flight `rclone version` con timeout 5s → si falla, omitir GDrive. Blindar buffer con `Content-Encoding: identity` + `ob_start()`.
**Blindaje:** TODO shell_exec a procesos externos debe tener timeout. Usar `timeout` de Linux (o `timeout` en Termux). Verificar salud del proceso (health check) antes de la operación principal. NDJSON requiere `ob_start()` antes del bucle para evitar headers ya enviados.

---

### #66: series_metadata no consultado en stream.php
**Fecha:** 2026-06-27
**Problema:** Ningún capítulo de series (HotD, GoT, EJC) se reproducía en Roku ni web. stream.php respondía 404 "Episodio no encontrado".
**Causa Raíz:** stream.php consultaba solo `peliculas_metadata` para episodios (`WHERE season IS NOT NULL`). Pero los episodios de series se almacenan en `series_metadata` desde scrapper v2. `peliculas_metadata` solo tiene el row hero (season=NULL).
**Solución:** stream.php ahora consulta `series_metadata` primero, fallback a `peliculas_metadata` si no encuentra.
**Blindaje:** Siempre que haya tablas paralelas (oficial + legacy/histórico), consultar la tabla oficial primero. No asumir que una tabla contiene todos los datos — verificar el schema actual.

---

### #67: Ruta HLS hardcodeada (HLS_TEST/) ignorando path real de BD
**Fecha:** 2026-06-27
**Problema:** El juego del calamar no reproducía en Roku ni web. HLS.js mostraba `fragParsingError`. Roku no mostraba la serie en cartelera.
**Causa Raíz:** Dos códigos paralelos (Roku `resolveVideoUrl` en BrightScript y web `resolveGDriveUrl` en JS) hardcodeaban `HLS_TEST/SxxExx/playlist.m3u8` en vez de usar la ruta real almacenada en series_metadata.archivo_path. Para EJC, la ruta real es `gdrive:/El juego del calamar/S01E01/playlist.m3u8`, NO `gdrive:HLS_TEST/S01E01/playlist.m3u8`.
**Solución:** Extraer path real después del prefijo `gdrive:` (eliminando `/` inicial) y pasarlo directamente a `stream.php?hls=1&path=`. Mismo fix en Roku (MainScene.brs) y web (app.js).
**Blindaje:** No hardcodear rutas HLS basadas en season/episode. Usar SIEMPRE la ruta real almacenada en BD. El prefijo `gdrive:` indica contenido en Google Drive, el resto del string ES el path real. Duplicar fixes entre web y Roku cuando comparten lógica.

---

### #68: Skip list por filename bloquea episodios de series
**Fecha:** 2026-06-28
**Problema:** El scrapper no indexaba episodios nuevos de series (S03E01 HotD, etc.) porque `scanner_skip.json` contenía entradas genéricas como `"S01E01": 1399` que se verificaban ANTES de detectar el contexto de carpeta de la serie.
**Causa Raíz:** `skipList[$video['name']]` se revisaba antes de `detectSeriesFromPath()`. Un archivo `S01E01.mp4` dentro de `media/series/House Of The Dragon/HOD 3/` se ignoraba porque el skip list tenía `"S01E01": 1399` (Game of Thrones), sin considerar que está en la carpeta de otra serie.
**Solución:** Mover `detectSeriesFromPath()` ANTES del skip list check. El skip list solo aplica a películas sueltas (no detectadas como serie por estructura de carpetas).
**Blindaje:** El skip list debe usarse solo para archivos sin contexto de carpeta (películas en raíz de media/). Para series, la estructura `/series/[Nombre]/[Temporada]/SxxExx` es más confiable que cualquier skip list. Verificar el nombre del archivo en su contexto de carpeta antes de decidir si ignorarlo.

---

### #69: Roku 3840R no decodifica AC3 — Passthrough HDMI
**Fecha:** 2026-07-05
**Problema:** Los archivos MP4 con audio AC3 (Dolby Digital) no tenían audio en Roku Streaming Stick 3840R. AAC siempre funcionaba. El web player con stream_ac3fix.php saturaba PHP-FPM (max_children=8, timeout 600s).
**Causa Raíz:** Roku 3840R no tiene decodificador AC3 interno — solo hace passthrough HDMI. Si el TV no soporta AC3 (Dolby Digital), no hay audio. AAC lo decodifica nativamente en el Roku, por eso siempre funciona. El intento de transcodificar AC3→AAC on-the-fly con stream_ac3fix.php creaba dos procesos ffmpeg simultáneos por request, agotando los 8 workers PHP-FPM.
**Solución (doble):**
1. **Roku**: Cambiar Settings → Audio → Digital output format de "Dolby Digital" (Auto/AC3) a **"Dolby Digital Plus"**. El TV decodifica DD+, por lo que AC3 se escucha correctamente como DD+. AAC también funciona en este modo.
2. **Web player**: Revertir de stream_ac3fix.php a stream.php. AC3 en web no se escuchará hasta que el usuario convierta manualmente con ffmpeg a AAC. stream.php usa X-Accel-Redirect (nginx sirve archivo directo, no ocupa worker PHP-FPM).
**Blindaje:** Roku 3840R = passthrough HDMI only. No confiar en decodificación AC3 nativa en ningún modelo Roku de gama media. Siempre verificar el formato de salida de audio en Settings → Audio. Para web, no usar transcoding on-the-fly con PHP-FPM limitado. Preferir conversión manual batch con ffmpeg.

### #70: PHP-FPM Pool Worker Blocking por Dual FFmpeg
**Fecha:** 2026-07-05
**Problema:** stream_ac3fix.php ejecutaba dos ffmpeg simultáneos por cada request de video (ffprobe + ffmpeg real), ocupando workers PHP-FPM durante 5-15 minutos por archivo. max_children=8 significaba que 4 usuarios bloqueaban todo el pool.
**Causa Raíz:** PHP-FPM con request_terminate_timeout=600 mata workers después de 10 minutos, pero durante ese tiempo el worker está ocupado. Con set_time_limit(0), el timeout de FPM no se respeta realmente y el worker queda bloqueado hasta que ffmpeg termina. Workers ocupados no pueden servir otros endpoints (get_content.php, admin, etc.).
**Solución:** Eliminar stream_ac3fix.php del flujo activo. Usar stream.php (X-Accel-Redirect) que no ocupa workers PHP — nginx sirve el archivo directamente. El usuario convierte AC3→AAC manualmente con ffmpeg batch en background.
**Blindaje:** Nunca ejecutar procesos largos (ffmpeg > 30s) dentro del pool PHP-FPM. Usar workers CLI separados o X-Accel-Redirect. Monitorear pm.max_children y request_terminate_timeout. Para transcoding real, implementar cola de workers externa con proc_open + connection-close.
