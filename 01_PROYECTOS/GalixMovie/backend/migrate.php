<?php
/**
 * GalixMovie - Sonda de Migración Universal v3.0
 * Protocolo FENIX - Saneamiento de Base de Datos
 * ─────────────────────────────────────────────────────────────────
 */
require 'db.php';

echo "🚀 Iniciando Saneamiento de Base de Datos...\n";

function addColumn($pdo, $table, $column, $type) {
    try {
        // Verificar si la columna existe antes de intentar agregarla
        $check = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetch();
        if (!$check) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $column $type");
            echo "[✅] Columna '$column' agregada a '$table'.\n";
        } else {
            echo "[i] Columna '$column' ya existe en '$table'.\n";
        }
    } catch (Exception $e) {
        echo "[❌] Error en '$table.$column': " . $e->getMessage() . "\n";
    }
}

// 1. Estabilización de Tabla: contenido
addColumn($pdo, 'contenido', 'is_online', 'TINYINT(1) DEFAULT 1');
addColumn($pdo, 'contenido', 'oculta', 'TINYINT(1) DEFAULT 0');

// 2. Estabilización de Tabla: series_metadata
addColumn($pdo, 'series_metadata', 'server2', 'VARCHAR(500) DEFAULT NULL');
addColumn($pdo, 'series_metadata', 'server3', 'VARCHAR(500) DEFAULT NULL');
addColumn($pdo, 'series_metadata', 'server4', 'VARCHAR(500) DEFAULT NULL');
addColumn($pdo, 'series_metadata', 'server5', 'VARCHAR(500) DEFAULT NULL');
addColumn($pdo, 'series_metadata', 'subtitulos_path', 'VARCHAR(500) DEFAULT NULL');

// 3. Estabilización de Tabla: peliculas_metadata
addColumn($pdo, 'peliculas_metadata', 'server2', 'VARCHAR(500) DEFAULT NULL');
addColumn($pdo, 'peliculas_metadata', 'server3', 'VARCHAR(500) DEFAULT NULL');
addColumn($pdo, 'peliculas_metadata', 'server4', 'VARCHAR(500) DEFAULT NULL');
addColumn($pdo, 'peliculas_metadata', 'server5', 'VARCHAR(500) DEFAULT NULL');
addColumn($pdo, 'peliculas_metadata', 'subtitulos_path', 'VARCHAR(500) DEFAULT NULL');

// 4. Creación de Tabla: favoritos (Integridad Estructural)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS favoritos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT DEFAULT 1,
        contenido_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (contenido_id) REFERENCES contenido(id) ON DELETE CASCADE,
        UNIQUE KEY (usuario_id, contenido_id)
    )");
    echo "[✅] Tabla 'favoritos' verificada/creada.\n";
} catch (Exception $e) {
    echo "[❌] Error en 'favoritos': " . $e->getMessage() . "\n";
}

// 5. Creación de Tabla: resolved_streams_cache (Galix Autopilot Engine v1.0)
try {
    $pdo->exec("DROP TABLE IF EXISTS resolved_mirrors_cache");
    $pdo->exec("DROP TABLE IF EXISTS resolved_streams_cache");
    $pdo->exec("CREATE TABLE IF NOT EXISTS resolved_streams_cache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contenido_id INT NOT NULL,
        episodio_id INT DEFAULT NULL,
        seed_url TEXT NOT NULL,
        resolved_url TEXT NOT NULL,
        tipo_resolucion ENUM('hls', 'embed') NOT NULL,
        idioma VARCHAR(50) DEFAULT 'Latino',
        servidor_nombre VARCHAR(100) DEFAULT 'Desconocido',
        calidad VARCHAR(20) DEFAULT 'HD',
        status ENUM('online', 'offline') DEFAULT 'online',
        last_verified TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NOT NULL,
        INDEX idx_res_contenido (contenido_id),
        INDEX idx_res_episodio (episodio_id),
        INDEX idx_res_status (status),
        INDEX idx_res_expires (expires_at)
    )");
    echo "[✅] Tabla 'resolved_streams_cache' verificada/creada con índices lógicos.\n";
} catch (Exception $e) {
    echo "[❌] Error en 'resolved_streams_cache': " . $e->getMessage() . "\n";
}

echo "\n✨ Saneamiento completado exitosamente.\n";
?>
