<?php
error_reporting(0);
/**
 * GalixMovie EMBED PROXY v3.0 — DHARMA FIX FINAL
 * ─────────────────────────────────────────────────────────────────
 * ESTRATEGIA: Proxy limpio + Señuelo window.open + Cirugía de JS
 * Sin sandbox (para que el reproductor cargue correctamente).
 * La cirugía JS en asset_proxy.php elimina los popups del código fuente.
 */

$url      = $_GET['url']  ?? '';
$fragment = $_GET['hash'] ?? '';

if (!$url || !preg_match('/^https?:\/\//i', $url)) die('URL inválida');

$host   = parse_url($url, PHP_URL_HOST)   ?: '';
$scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
$path   = parse_url($url, PHP_URL_PATH)   ?: '/';
$pathDir = rtrim(dirname($path), '/') . '/';
$baseUrl = $scheme . '://' . $host . '/'; // Raíz del dominio (más seguro para React SPA)

// ─── Descargar el HTML ────────────────────────────────────────────────────────
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HEADER         => false,
    CURLOPT_HTTPHEADER     => [
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Accept-Language: es-MX,es;q=0.9,en;q=0.8",
        "Referer: {$scheme}://{$host}/",
        "Origin: {$scheme}://{$host}",
    ]
]);
$html     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
// curl_close() omitido intencionalmente — PHP 8.5+ lo deprecó y emite warning que corrompe headers.

if ($html === false || $httpCode >= 400) {
    http_response_code($httpCode ?: 502);
    die("Error HTTP $httpCode al obtener: $url");
}

// ─── ESTRATEGIA DEFINITIVA: Base tag + Strip CORS attributes ─────────────────
// PROBLEMA RAÍZ: Los import() dinámicos dentro del JS van a nuestro servidor (404).
// No podemos reescribir lo que está dentro del JS minificado.
// SOLUCIÓN: Apuntar <base> al dominio original + quitar atributos que fuerzan CORS.

// 1. Inyectar <base> apuntando al dominio original (resuelve rutas relativas Y dinámicas)
$baseTag = '<base href="' . $scheme . '://' . $host . '/">';

// 2. Quitar atributo "crossorigin" de scripts y links (evita que el browser fuerce CORS)
$html = preg_replace('/\s+crossorigin(?:=["\'][^"\']*["\'])?/i', '', $html);

// 3. Convertir <script type="module"> a <script> clásico
//    (Los módulos siempre usan CORS, los scripts clásicos no)
$html = preg_replace('/<script\s+type=["\']module["\']([^>]*)>/i', '<script$1>', $html);
$html = preg_replace('/\s+type=["\']module["\']/i', '', $html);

// ─── Señuelo Anti-Sandbox + Bloqueo de Popups ────────────────────────────────
$safeHash = addslashes(htmlspecialchars($fragment, ENT_QUOTES));
$shield = <<<JS
<script>
(function(){
    // 1. Restaurar hash para reproductores que lo necesitan
    if ('$safeHash' && !window.location.hash) {
        window.location.hash = '$safeHash';
    }
    // 2. SEÑUELO: Fingir que window.open funciona para engañar la detección de sandbox.
    //    El player llamará window.open() y recibirá un objeto falso convincente.
    //    Así no muestra "Opss! Sandboxed" porque cree que tiene permisos.
    //    En realidad ninguna ventana se abre — devolvemos un objeto vacío.
    const fakeWin = {
        focus:()=>{}, blur:()=>{}, close:()=>{}, closed:false,
        document:{write:()=>{},writeln:()=>{},close:()=>{}},
        location:{href:'about:blank',replace:()=>{},assign:()=>{}},
        history:{}, screen:{}, navigator:{},
        addEventListener:()=>{}, removeEventListener:()=>{}
    };
    window.open = (u,n,s) => {
        console.log('🛡️ GalixShield: window.open() interceptado →', u);
        return fakeWin;
    };
    // 3. 🛡️ PH-DHARMA: Anti-frame-busting (Pornhub, etc.)
    //    Proveedores como Pornhub detectan iframe con top!==self y redirigen.
    //    Congelamos top/parent para que el reproductor crea que es ventana raíz.
    try {
        Object.defineProperty(window, 'top', { value: window, writable: false, configurable: false });
        Object.defineProperty(window, 'parent', { value: window, writable: false, configurable: false });
        Object.defineProperty(window, 'self', { value: window, writable: false, configurable: false });
    } catch(e) {}
    // 4. Bloquear otras formas de redirección
    window.alert   = ()=>{};
    window.confirm = ()=>true;
    // 5. Interceptar links _blank en clicks
    document.addEventListener('click', e => {
        const a = e.target.closest('a');
        if (a && a.target === '_blank') {
            e.preventDefault(); e.stopPropagation();
        }
    }, true);
    console.log('🛡️ GalixProxy v3.0: Anti-Ad Shield + Anti-Frame activo.');
})();
</script>
JS;

// Inyectar <base> + señuelo como primeros elementos del <head>
$inject = $baseTag . $shield;
if (preg_match('/<head[^>]*>/i', $html)) {
    $html = preg_replace('/(<head[^>]*>)/i', '$1' . $inject, $html, 1);
} elseif (preg_match('/<html[^>]*>/i', $html)) {
    $html = preg_replace('/(<html[^>]*>)/i', '$1<head>' . $inject . '</head>', $html, 1);
} else {
    $html = $inject . $html;
}

// ─── Servir ───────────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: ALLOWALL');
echo $html;
