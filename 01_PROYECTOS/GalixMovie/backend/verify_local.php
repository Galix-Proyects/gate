<?php
/**
 * GalixMovie - Verificador / Reparador de Archivos Locales v1.0
 * ────────────────────────────────────────────────────────────
 * Comprueba si los archivos locales registrados en la BD existen
 * realmente en disco y detecta si el disco está desmontado.
 *
 * Uso CLI:
 *   php backend/verify_local.php            -> reporte (solo lectura)
 *   php backend/verify_local.php --fix      -> reactiva is_online=1 los que SÍ existen
 *
 * Uso web (requiere sesión admin):
 *   backend/verify_local.php                -> reporte
 *   backend/verify_local.php?fix=1          -> repara
 *
 * DHARMA #74: nunca confiar en el estado guardado; validar contra disco.
 */
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    require 'auth.php';
    checkAuth();
}
require 'db.php';

if (!$isCli) header('Content-Type: application/json');

$fix = $isCli
    ? in_array('--fix', $argv ?? [], true)
    : (($_GET['fix'] ?? '') === '1');

define('RATIO_ALERT', 0.10); // si >10% falta sin disco caído, avisar

/** Resuelve una ruta local (absoluta Termux o relativa al proyecto). */
function resolveLocalPath(string $url) {
    $candidates = [];
    if (str_starts_with($url, '/')) {
        $candidates[] = $url;
    } else {
        $candidates[] = __DIR__ . '/../' . $url;
        $candidates[] = '/data/data/com.termux/files/home/' . $url;
    }
    foreach ($candidates as $cand) {
        $real = realpath($cand);
        if ($real && is_file($real) && is_readable($real)) return $real;
    }
    return false;
}

/** ¿Está montado el disco BUNKER que contiene la ruta? */
function diskMountedFor(string $path): bool {
    if (!preg_match('#^(.*/BUNKER/[^/]+)#', $path, $m)) return true;
    $mountPoint = $m[1];
    $canonical = realpath($mountPoint);
    $mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($mounts as $line) {
        $parts = preg_split('/\s+/', $line);
        if (!isset($parts[1])) continue;
        if ($parts[1] === $mountPoint || ($canonical && $parts[1] === $canonical)) return true;
    }
    return false;
}

try {
    $rows = $pdo->query("SELECT c.id, c.titulo, c.is_online, m.archivo_path
                         FROM contenido c
                         JOIN peliculas_metadata m ON m.contenido_id = c.id
                         WHERE m.archivo_path IS NOT NULL AND m.archivo_path != ''
                           AND m.archivo_path NOT LIKE 'http%'
                           AND m.archivo_path NOT LIKE 'extract:%'
                           AND m.archivo_path NOT LIKE 'sniper:%'
                           AND m.archivo_path NOT LIKE 'gdrive:%'
                           AND m.archivo_path NOT LIKE 'backend/%'")->fetchAll(PDO::FETCH_ASSOC);

    $exists = 0;
    $missing = 0;
    $unmounted = 0;
    $alreadyOnline = 0;
    $reactivated = 0;
    $missingList = [];
    $unmountedList = [];

    $upd = $pdo->prepare("UPDATE contenido SET is_online = 1 WHERE id = ?");

    foreach ($rows as $row) {
        $path = trim($row['archivo_path']);
        $resolved = resolveLocalPath($path);

        if (!$resolved) {
            if (!diskMountedFor($path)) {
                $unmounted++;
                $unmountedList[] = ['id' => $row['id'], 'titulo' => $row['titulo'], 'path' => $path];
                continue;
            }
            $missing++;
            $missingList[] = ['id' => $row['id'], 'titulo' => $row['titulo'], 'path' => $path];
            continue;
        }

        $exists++;
        if ($row['is_online'] == 1) {
            $alreadyOnline++;
        } elseif ($fix) {
            $upd->execute([$row['id']]);
            $reactivated++;
        }
    }

    $total = count($rows);
    $pctMissing = $total > 0 ? round($missing / $total * 100, 1) : 0;

    $report = [
        'status'            => 'success',
        'mode'              => $fix ? 'fix' : 'report',
        'total_locales'     => $total,
        'existen'           => $exists,
        'ya_online'         => $alreadyOnline,
        'reactivados'       => $reactivated,
        'faltantes'         => $missing,
        'porcentaje_faltante' => $pctMissing,
        'omitidos_disco_ausente' => $unmounted,
        'alerta'            => $pctMissing > (RATIO_ALERT * 100)
                                ? 'POSIBLE DISCO DESMONTADO O RUTAS ROTAS'
                                : null,
        'faltantes_list'    => $missingList,
        'disco_ausente_list'=> $unmountedList,
    ];

    if ($isCli) {
        echo "=== GalixMovie verify_local ({$report['mode']}) ===\n";
        echo "Locales: {$total} | Existen: {$exists} | Ya online: {$alreadyOnline} | Reactivados: {$reactivated}\n";
        echo "Faltantes: {$missing} ({$pctMissing}%) | Disco ausente: {$unmounted}\n";
        if ($report['alerta']) echo "⚠️  {$report['alerta']}\n";
        foreach ($missingList as $x) echo "  ❌ {$x['id']} {$x['titulo']} -> {$x['path']}\n";
        foreach ($unmountedList as $x) echo "  💤 {$x['id']} {$x['titulo']} (disco desmontado)\n";
    } else {
        echo json_encode($report, JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    if ($isCli) {
        echo "ERROR: " . $e->getMessage() . "\n";
    } else {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
