<?php
// ═══════════════════════════════════════════════════════════
//  GalixMovie — Inyección masiva TV en Vivo desde M3U
//  Lee mx_ONLINE.m3u y inserta canales en BD
//  Ejecutar: php inject_mx_tv.php
// ═══════════════════════════════════════════════════════════
require 'db.php';
header('Content-Type: text/plain; charset=utf-8');

$m3uFile = __DIR__ . '/mx_ONLINE.m3u';
if (!file_exists($m3uFile)) {
    echo "❌ No se encontró: $m3uFile\n";
    exit(1);
}

// ── PASO 1: Asegurar columna genero
$checkGenero = $pdo->query("SHOW COLUMNS FROM `contenido` LIKE 'genero'")->fetch();
if (!$checkGenero) {
    $pdo->exec("ALTER TABLE `contenido` ADD COLUMN `genero` VARCHAR(100) DEFAULT NULL AFTER `puntuacion`");
    echo "✅ Columna 'genero' creada\n";
}

// ── PASO 2: Parsear M3U
$content = file_get_contents($m3uFile);
$content = str_replace("\r\n", "\n", $content);
$lines = explode("\n", $content);

$canales = [];
$currentName = '';
for ($i = 0; $i < count($lines); $i++) {
    $line = trim($lines[$i]);
    if (strpos($line, '#EXTINF:') === 0) {
        // Extraer nombre: está entre la última coma y el final
        $commaPos = strrpos($line, ',');
        if ($commaPos !== false) {
            $rawName = substr($line, $commaPos + 1);
            // Limpiar: quitar resolución y tags
            $rawName = preg_replace('/\s*\(\d+p?\)/i', '', $rawName);
            $rawName = preg_replace('/\s*\[.*?\]/i', '', $rawName);
            $rawName = trim($rawName);
            $currentName = $rawName;
        }
    } elseif (strpos($line, 'http') === 0 && $currentName !== '') {
        $canales[] = [
            'titulo' => $currentName,
            'url'    => $line,
        ];
        $currentName = '';
    }
}

echo "📋 M3U parseado: " . count($canales) . " canales encontrados\n\n";

// ── PASO 3: Contar existentes
$existentes = $pdo->query("SELECT COUNT(*) FROM contenido WHERE genero = 'tv_live'")->fetchColumn();
echo "ℹ️  Canales TV existentes en BD: $existentes\n\n";

// ── PASO 4: Insertar canales
$insertados = 0;
$omitidos = 0;
$errores = 0;

foreach ($canales as $canal) {
    // Verificar duplicado por titulo
    $existe = $pdo->prepare("SELECT id FROM contenido WHERE titulo = ? AND genero = 'tv_live'");
    $existe->execute([$canal['titulo']]);
    if ($existe->fetch()) {
        echo "  ⏭️  Omitido: {$canal['titulo']}\n";
        $omitidos++;
        continue;
    }

    $pdo->beginTransaction();
    try {
        // Insertar en contenido
        $stmt = $pdo->prepare("
            INSERT INTO contenido (tipo, titulo, sinopsis, poster_path, backdrop_path, fecha_estreno, puntuacion, genero, is_online)
            VALUES ('movie', ?, ?, ?, ?, '2020-01-01', 7.0, 'tv_live', 1)
        ");
        $sinopsis = "Canal de televisión mexicana — EN VIVO";
        $poster = 'https://via.placeholder.com/300x170/1a1a2e/ef4444?text=TV';
        $stmt->execute([$canal['titulo'], $sinopsis, $poster, $poster]);
        $contenidoId = $pdo->lastInsertId();

        // Insertar metadata con URL en server2
        $stmt2 = $pdo->prepare("
            INSERT INTO peliculas_metadata (contenido_id, archivo_path, server2)
            VALUES (?, '', ?)
        ");
        $stmt2->execute([$contenidoId, $canal['url']]);

        $pdo->commit();
        echo "  ✅ {$canal['titulo']}\n";
        $insertados++;
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "  ❌ {$canal['titulo']}: " . $e->getMessage() . "\n";
        $errores++;
    }
}

echo "\n═══════════════════════════════════════\n";
echo " ✅ Insertados : $insertados\n";
echo " ⏭️  Omitidos  : $omitidos\n";
echo " ❌ Errores   : $errores\n";
echo " 📊 Total M3U : " . count($canales) . "\n";
echo " 📊 Total BD  : " . ($existentes + $insertados) . " canales TV\n";
echo "═══════════════════════════════════════\n";
?>
