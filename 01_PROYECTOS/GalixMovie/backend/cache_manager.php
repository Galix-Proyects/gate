<?php
/**
 * Galix Autopilot Cache Manager v1.0
 * Gestiona el almacenamiento, consulta y ciclo de vida de los streams cacheados.
 * ─────────────────────────────────────────────────────────────────
 */
require_once 'db.php';

/**
 * Verifica si una URL es estable (sin tokens IP-bound de CDN).
 * URLs con ?t=TOKEN&s=TIMESTAMP&e=EXPIRY son generadas para la IP del extractor.
 */
function isStableUrlCM($url) {
    if (empty($url)) return false;
    $query = parse_url($url, PHP_URL_QUERY);
    if (!$query) return true;
    parse_str($query, $params);
    if (isset($params['t']) && isset($params['s']) && isset($params['e'])) return false;
    if (isset($params['token']) && isset($params['expires'])) return false;
    if (isset($params['s']) && isset($params['e']) && strlen((string)$params['s']) >= 9) return false;

    // Tokens firmados en PATH (ej: callistanise.com/stream/TOKEN/TOKEN/1779014097/ID/master.m3u8)
    $path = parse_url($url, PHP_URL_PATH) ?? '';
    if (preg_match('/\/\d{10}\//', $path)) return false;

    return true;
}

/**
 * Obtiene el mirror válido para una película/episodio.
 * Busca primero por client_ip (per-dispositivo), luego el cache compartido.
 *
 * @param PDO $pdo
 * @param int $contenido_id
 * @param int|null $episodio_id
 * @param string $seed_url
 * @param string|null $clientIp IP del dispositivo cliente (opcional)
 * @return array
 */
function getResolvedCache($pdo, $contenido_id, $episodio_id, $seed_url, $clientIp = null) {
    try {
        $epCond = "AND episodio_id IS NULL";
        $baseParams = ['contenido_id' => $contenido_id, 'seed_url' => $seed_url];
        if ($episodio_id !== null && $episodio_id !== '') {
            $epCond = "AND episodio_id = :episodio_id";
            $baseParams['episodio_id'] = $episodio_id;
        }

        // 1. Buscar cache específico para este dispositivo (IP)
        if ($clientIp) {
            $params = $baseParams + ['client_ip' => $clientIp];
            $stmt = $pdo->prepare(
                "SELECT * FROM resolved_streams_cache
                  WHERE contenido_id = :contenido_id {$epCond}
                    AND seed_url = :seed_url
                    AND client_ip = :client_ip
                    AND status = 'online'
                    AND expires_at > CURRENT_TIMESTAMP()
                  ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute($params);
            $ipResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($ipResult)) return $ipResult; // Cache propio del dispositivo
        }

        // 2. Fallback: cache compartido (sin IP específica)
        $stmt = $pdo->prepare(
            "SELECT * FROM resolved_streams_cache
              WHERE contenido_id = :contenido_id {$epCond}
                AND seed_url = :seed_url
                AND (client_ip IS NULL OR client_ip = '')
                AND status = 'online'
                AND expires_at > CURRENT_TIMESTAMP()
              ORDER BY id ASC"
        );
        $stmt->execute($baseParams);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log("[Autopilot Cache Error] getResolvedCache failed: " . $e->getMessage());
        return [];
    }
}

/**
 * Guarda o actualiza un mirror resuelto en el caché de la base de datos.
 * Evita duplicados comparando la resolved_url.
 * 
 * @param PDO $pdo
 * @param int $contenido_id
 * @param int|null $episodio_id
 * @param string $seed_url
 * @param string $resolved_url
 * @param string $tipo_resolucion 'hls' o 'embed'
 * @param string $idioma 'Latino', 'Castellano', 'Subtítulos', etc.
 * @param string $servidor_nombre 'Goodstream', 'Streamwish', etc.
 * @param string $calidad 'HD', 'FHD', 'SD', etc.
 * @param int $duration_hours Tiempo de expiración por defecto (12 horas)
 * @return bool
 */
function saveResolvedCache($pdo, $contenido_id, $episodio_id, $seed_url, $resolved_url, $tipo_resolucion, $idioma = 'Latino', $servidor_nombre = 'Desconocido', $calidad = 'HD', $duration_hours = 12, $clientIp = null) {
    try {
        if (empty($idioma)) $idioma = 'Latino';
        if (empty($servidor_nombre)) $servidor_nombre = 'Desconocido';
        if (empty($calidad)) $calidad = 'HD';

        $epCond = "AND episodio_id IS NULL";
        $epVal  = null;
        if ($episodio_id !== null && $episodio_id !== '') {
            $epCond = "AND episodio_id = :episodio_id";
            $epVal  = $episodio_id;
        }

        $expires_at = date('Y-m-d H:i:s', time() + ($duration_hours * 3600));

        // Detectar si la URL es IP-bound (CDN con tokens firmados)
        $isIpBound = !isStableUrlCM($resolved_url);

        // Para URLs IP-bound, buscar/actualizar por client_ip (per-dispositivo)
        // Para URLs estables, buscar por resolved_url compartido
        if ($isIpBound && $clientIp) {
            // Cache per-IP: cada dispositivo tiene su propio entry
            $checkStmt = $pdo->prepare(
                "SELECT id FROM resolved_streams_cache
                  WHERE contenido_id = :cid {$epCond} AND seed_url = :seed AND client_ip = :ip"
            );
            $checkParams = ['cid' => $contenido_id, 'seed' => $seed_url, 'ip' => $clientIp];
            if ($epVal !== null) $checkParams['episodio_id'] = $epVal;
            $checkStmt->execute($checkParams);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        } else {
            // Cache compartido: buscar por resolved_url
            $checkParams = ['contenido_id' => $contenido_id, 'resolved_url' => $resolved_url];
            if ($epVal !== null) { $checkParams['episodio_id'] = $epVal; }
            $checkStmt = $pdo->prepare(
                "SELECT id FROM resolved_streams_cache
                  WHERE contenido_id = :contenido_id {$epCond} AND resolved_url = :resolved_url"
            );
            $checkStmt->execute($checkParams);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($existing) {
            $stmt = $pdo->prepare(
                "UPDATE resolved_streams_cache
                    SET seed_url = :seed_url, status = 'online', expires_at = :expires_at,
                        idioma = :idioma, servidor_nombre = :servidor_nombre, calidad = :calidad,
                        resolved_url = :resolved_url, client_ip = :client_ip,
                        last_verified = CURRENT_TIMESTAMP()
                  WHERE id = :id"
            );
            $result = $stmt->execute([
                'id' => $existing['id'], 'seed_url' => $seed_url, 'expires_at' => $expires_at,
                'idioma' => $idioma, 'servidor_nombre' => $servidor_nombre, 'calidad' => $calidad,
                'resolved_url' => $resolved_url, 'client_ip' => $isIpBound ? $clientIp : null
            ]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO resolved_streams_cache
                    (contenido_id, episodio_id, seed_url, resolved_url, tipo_resolucion,
                     idioma, servidor_nombre, calidad, status, expires_at, client_ip)
                  VALUES
                    (:contenido_id, :episodio_id, :seed_url, :resolved_url, :tipo_resolucion,
                     :idioma, :servidor_nombre, :calidad, 'online', :expires_at, :client_ip)"
            );
            $result = $stmt->execute([
                'contenido_id' => $contenido_id, 'episodio_id' => $epVal,
                'seed_url' => $seed_url, 'resolved_url' => $resolved_url,
                'tipo_resolucion' => $tipo_resolucion, 'idioma' => $idioma,
                'servidor_nombre' => $servidor_nombre, 'calidad' => $calidad,
                'expires_at' => $expires_at,
                'client_ip' => $isIpBound ? $clientIp : null
            ]);
        }

        // REGLA DEFINITIVA S1/S2 AUTO-FILL:
        // S1 (archivo_path) = HLS/MP4 estables sin token IP-bound
        // S2 (server2)      = Embed estables sin token IP-bound
        // Caché per-IP      = URLs con tokens firmados (Goodstream/Vimeos)
        $isDirectStream = ($tipo_resolucion === 'hls' || $tipo_resolucion === 'mp4' || $tipo_resolucion === 'embed');
        $isRealExtract  = (!empty($contenido_id) && $resolved_url !== $seed_url);
        $isStable       = isStableUrlCM($resolved_url);

        if ($isDirectStream && $isRealExtract && $isStable) {
            try {
                $table    = ($episodio_id !== null && $episodio_id !== '') ? 'series_metadata'   : 'peliculas_metadata';
                $idCol    = ($episodio_id !== null && $episodio_id !== '') ? 'id'                 : 'contenido_id';
                $targetId = ($episodio_id !== null && $episodio_id !== '') ? $episodio_id         : $contenido_id;

                $stmtMeta = $pdo->prepare("SELECT archivo_path, server2, server3, server4, server5 FROM {$table} WHERE {$idCol} = ?");
                $stmtMeta->execute([$targetId]);
                $rowMeta = $stmtMeta->fetch(PDO::FETCH_ASSOC);

                if ($rowMeta) {
                    $s1 = ($rowMeta['archivo_path'] !== null) ? trim($rowMeta['archivo_path']) : '';
                    $s2 = ($rowMeta['server2']      !== null) ? trim($rowMeta['server2'])      : '';
                    $s3 = ($rowMeta['server3']      !== null) ? trim($rowMeta['server3'])      : '';
                    $s4 = ($rowMeta['server4']      !== null) ? trim($rowMeta['server4'])      : '';
                    $s5 = ($rowMeta['server5']      !== null) ? trim($rowMeta['server5'])      : '';

                    // Si ya existe en ALGÚN slot, no hacemos nada (evitar duplicados)
                    if ($s1 === $resolved_url || $s2 === $resolved_url || $s3 === $resolved_url || $s4 === $resolved_url || $s5 === $resolved_url) {
                        return $result; // Ya existe, terminamos
                    }

                    if ($tipo_resolucion === 'hls' || $tipo_resolucion === 'mp4') {
                        // S1: HLS/MP4 estable (sobrescribe lo que haya)
                        $pdo->prepare("UPDATE {$table} SET archivo_path = ? WHERE {$idCol} = ?")->execute([$resolved_url, $targetId]);
                        error_log("[Auto-Fill S1] #{$targetId} → archivo_path = {$resolved_url}");
                    } elseif ($tipo_resolucion === 'embed') {
                        // S2: Embed estable (sobrescribe lo que haya)
                        $pdo->prepare("UPDATE {$table} SET server2 = ? WHERE {$idCol} = ?")->execute([$resolved_url, $targetId]);
                        error_log("[Auto-Fill S2 embed] #{$targetId} → server2 = {$resolved_url}");
                    }
                }
            } catch (Exception $e) {
                error_log("[Auto-Fill Error] " . $e->getMessage());
            }
        }

        return $result;
    } catch (Exception $e) {
        error_log("[Autopilot Cache Error] saveResolvedCache failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Invalida o elimina el caché completo para una película o episodio.
 * Útil cuando el administrador edita los servidores maestros.
 * 
 * @param PDO $pdo
 * @param int $contenido_id
 * @param int|null $episodio_id
 * @return bool
 */
function invalidateCache($pdo, $contenido_id, $episodio_id = null) {
    try {
        $query = "DELETE FROM resolved_streams_cache 
                  WHERE contenido_id = :contenido_id 
                    AND (episodio_id = :episodio_id OR (:episodio_id IS NULL AND episodio_id IS NULL))";
        $stmt = $pdo->prepare($query);
        return $stmt->execute([
            'contenido_id' => $contenido_id,
            'episodio_id' => $episodio_id
        ]);
    } catch (Exception $e) {
        error_log("[Autopilot Cache Error] invalidateCache failed: " . $e->getMessage());
        return false;
    }
}
?>
