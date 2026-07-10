<?php
// ═══════════════════════════════════════════════════════════
//  GalixMovie — DHARMA Fix #65: Inyección TV en Vivo + Cine Clásico
//  Ejecutar UNA SOLA VEZ desde la terminal:
//  php /data/data/com.termux/files/home/Proyectos/01_PROYECTOS/GalixMovie/backend/inject_tv_channels.php
// ═══════════════════════════════════════════════════════════
require 'db.php';
header('Content-Type: text/plain; charset=utf-8');

// ── PASO 1: Asegurarse de que exista la columna "genero" en la tabla contenido
$checkGenero = $pdo->query("SHOW COLUMNS FROM `contenido` LIKE 'genero'")->fetch();
if (!$checkGenero) {
    $pdo->exec("ALTER TABLE `contenido` ADD COLUMN `genero` VARCHAR(100) DEFAULT NULL AFTER `puntuacion`");
    echo "✅ Columna 'genero' añadida a tabla contenido\n";
} else {
    echo "ℹ️  Columna 'genero' ya existe\n";
}

// ── PASO 2: Canales de TV en Vivo verificados (✅ Online)
$canales = [
    [
        'titulo'       => 'Azteca Uno',
        'sinopsis'     => 'Canal líder de televisión abierta en México. Noticias, entretenimiento, deportes y telenovelas en vivo.',
        'poster_path'  => 'https://upload.wikimedia.org/wikipedia/commons/thumb/f/fe/Azteca_Uno_2022.svg/512px-Azteca_Uno_2022.svg.png',
        'stream_url'   => 'https://mdstrm.com/live-stream-playlist/609b243156cca108312822a6.m3u8',
        'puntuacion'   => 8.2,
    ],
    [
        'titulo'       => 'Azteca 7',
        'sinopsis'     => 'Canal de entretenimiento, deportes y series de TV Azteca. Transmisión en vivo 24/7.',
        'poster_path'  => 'https://upload.wikimedia.org/wikipedia/commons/thumb/e/e8/Az7_2019.svg/512px-Az7_2019.svg.png',
        'stream_url'   => 'https://mdstrm.com/live-stream-playlist/609ad46a7a441137107d7a81.m3u8',
        'puntuacion'   => 7.8,
    ],
    [
        'titulo'       => 'A+ (Azteca Plus)',
        'sinopsis'     => 'Canal de entretenimiento familiar con series, películas y reality shows de TV Azteca.',
        'poster_path'  => 'https://upload.wikimedia.org/wikipedia/commons/thumb/1/14/A%2B_2021.svg/512px-A%2B_2021.svg.png',
        'stream_url'   => 'https://mdstrm.com/live-stream-playlist/60b56be1000ea50835fa1e63.m3u8',
        'puntuacion'   => 7.5,
    ],
    [
        'titulo'       => 'ADN40',
        'sinopsis'     => 'Canal de noticias y análisis político de TV Azteca. Información en tiempo real.',
        'poster_path'  => 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a8/ADN40_2022.svg/512px-ADN40_2022.svg.png',
        'stream_url'   => 'https://mdstrm.com/live-stream-playlist/60b578b060947317de7b57ac.m3u8',
        'puntuacion'   => 7.9,
    ],
    [
        'titulo'       => 'TVMÁS (XHGV-TDT)',
        'sinopsis'     => 'Canal regional de Guadalajara con programación local, noticias y entretenimiento en vivo.',
        'poster_path'  => 'https://i.imgur.com/15kcNRb.png',
        'stream_url'   => 'https://5ca9af4645e15.streamlock.net/rtv/videortv/playlist.m3u8',
        'puntuacion'   => 7.0,
    ],
    [
        'titulo'       => 'Teleritmo',
        'sinopsis'     => 'Canal de música y entretenimiento en español con los mejores ritmos latinos en vivo.',
        'poster_path'  => 'https://i.imgur.com/fcnRf1f.png',
        'stream_url'   => 'http://mdstrm.com/live-stream-playlist/57b4dc126338448314449d0c.m3u8',
        'puntuacion'   => 7.3,
    ],
    [
        'titulo'       => 'Estrella TV',
        'sinopsis'     => 'Canal hispano de entretenimiento y noticias disponible para toda América. Shows, humor y variedades.',
        'poster_path'  => 'https://upload.wikimedia.org/wikipedia/en/archive/9/99/20200205000404%21Estrella_TV_-_2020_logo.png',
        'stream_url'   => 'https://estrellatv-roku.amagi.tv/playlist.m3u8',
        'puntuacion'   => 7.6,
    ],
    [
        'titulo'       => 'FashionBox HD',
        'sinopsis'     => 'Canal de moda, estilo de vida y diseño. Tendencias y lifestyle las 24 horas.',
        'poster_path'  => 'https://i.postimg.cc/RZ6x4Kmr/FASHIONBOOX.png',
        'stream_url'   => 'http://service-stitcher.clusters.pluto.tv/stitch/hls/channel/5ee8d84bfb286e0007285aad/master.m3u8?advertisingId=&appName=web&appVersion=unknown&clientTime=0&deviceDNT=0&deviceId=bff24a64-6307-11eb-b3fa-019cb96f121b&deviceMake=Chrome&deviceModel=web&deviceType=web&deviceVersion=unknown&includeExtendedEvents=false&sid=dcad69d9-cbe2-4e00-a9bc-3c865cdc4424&serverSideAds=true',
        'puntuacion'   => 7.1,
    ],
    [
        'titulo'       => 'Telemax Hermosillo',
        'sinopsis'     => 'Canal regional de Sonora con noticias locales, programación cultural y entretenimiento del noroeste de México.',
        'poster_path'  => 'https://i.imgur.com/LznCVuT.png',
        'stream_url'   => 'http://s5.mexside.net:1935/telemax/telemax/.m3u8',
        'puntuacion'   => 6.8,
    ],
    [
        'titulo'       => 'Multimedios Torreón',
        'sinopsis'     => 'Canal regional de La Laguna con noticias, entretenimiento y programación local en vivo.',
        'poster_path'  => 'https://upload.wikimedia.org/wikipedia/commons/thumb/0/0a/Multimedios_Estrellas_de_Oro_logo.svg/512px-Multimedios_Estrellas_de_Oro_logo.svg.png',
        'stream_url'   => 'http://mdstrm.com/live-stream-playlist/57bf686a61ff39e1085d43e1.m3u8?ref=http://www.multimedios.com',
        'puntuacion'   => 7.2,
    ],
    [
        'titulo'       => 'Canal 26 Aguascalientes',
        'sinopsis'     => 'Televisión local de Aguascalientes con programación regional, noticias y entretenimiento.',
        'poster_path'  => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcT-jOJk895V3pzx3VwAYIMG1_h2-vQypKMXmjQ0Xcp3BI2UQ41IgAt1aaT5iIrG7ngGGUU&usqp=CAU',
        'stream_url'   => 'http://streamingcws10.com:1935/telemetrika3/videotelemetrika3/.m3u8',
        'puntuacion'   => 6.5,
    ],
    [
        'titulo'       => 'Videorola',
        'sinopsis'     => 'Canal de videoclips y música regional mexicana, norteña y grupera en vivo.',
        'poster_path'  => 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/aa/Videorola_Logo.png/512px-Videorola_Logo.png',
        'stream_url'   => 'https://d3b2epqdk0p7vd.cloudfront.net/out/v1/8a448b5e16384af4a3c8146a7b049c32/index.m3u8',
        'puntuacion'   => 7.4,
    ],
    [
        'titulo'       => 'Jalisco TV',
        'sinopsis'     => 'Canal del gobierno de Jalisco con programación cultural, noticias y entretenimiento regional.',
        'poster_path'  => 'http://directostv.teleame.com/wp-content/uploads/2018/02/Jalisco-TV-2-en-vivo-Online-218x150.png',
        'stream_url'   => 'https://5fa5de1a545ae.streamlock.net/sisjalisciense/sisjalisciense/playlist.m3u8',
        'puntuacion'   => 7.0,
    ],
];

// ── PASO 3: Películas Clásicas Mexicanas (Archive.org)
$clasicas = [
    [
        'titulo'      => 'Vacaciones en Acapulco (1977)',
        'sinopsis'    => 'El Chavo del 8 y sus amigos viajan a Acapulco en una aventura llena de humor y situaciones cómicas. Película del icónico programa de Roberto Gómez Bolaños.',
        'poster_path' => 'https://i.imgur.com/4hnOzaz.png',
        'stream_url'  => 'https://ia801502.us.archive.org/3/items/elchavo_201709/El%20Chavo%20del%208%20-%20Vacaciones%20en%20Acapulco.mp4',
        'year'        => '1977',
    ],
    [
        'titulo'      => 'La Presidenta Municipal (1975)',
        'sinopsis'    => 'La India María se convierte en presidenta de un pequeño pueblo y debe enfrentar los retos del poder con su peculiar estilo y humor.',
        'poster_path' => 'https://ringostrack.com/bundles/soundtrackindex/img/cover/28971_la-presidenta-municipal.jpg',
        'stream_url'  => 'https://ia801503.us.archive.org/11/items/LaIndiaMariaLaPresidentaMunicipal/La%20India%20Maria%20La%20Presidenta%20Municipal.mp4',
        'year'        => '1975',
    ],
    [
        'titulo'      => 'El Libro de Piedra (1969)',
        'sinopsis'    => 'Una institutriz llega a una hacienda y descubre que la niña de la familia tiene como único amigo a un inquietante ser sobrenatural ligado a una estatua de piedra.',
        'poster_path' => 'https://imgur.com/kKqhm5R.jpg',
        'stream_url'  => 'https://ia801507.us.archive.org/16/items/ElLibroDePiedra/el%20libro%20de%20piedra.mp4',
        'year'        => '1969',
    ],
    [
        'titulo'      => 'Macario (1960)',
        'sinopsis'    => 'Un leñador pobre pacta con la muerte para obtener poderes curativos. Obra maestra del cine mexicano de oro, nominada al Oscar como Mejor Película Extranjera.',
        'poster_path' => 'https://imgur.com/U9RiSMD.jpg',
        'stream_url'  => 'https://ia801503.us.archive.org/17/items/tecnotv_201709/Macario.mp4',
        'year'        => '1960',
    ],
    [
        'titulo'      => 'El Bello Durmiente (1952)',
        'sinopsis'    => 'Tin Tan protagoniza esta parodia del cuento clásico con su inconfundible estilo pachucho lleno de carcajadas y situaciones hilarantes.',
        'poster_path' => 'https://imgur.com/OOGQjcp.jpg',
        'stream_url'  => 'https://ia801504.us.archive.org/10/items/TinTanElBelloDurmiente/Tin%20Tan%2C%20El%20Bello%20Durmiente.mp4',
        'year'        => '1952',
    ],
    [
        'titulo'      => 'Simbad el Mareado (1950)',
        'sinopsis'    => 'Tin Tan como el famoso marinero Simbad en una comedia de aventuras y enredos que mezcla el humor con las leyendas de Las mil y una noches.',
        'poster_path' => 'https://imgur.com/lzZMzXw.jpg',
        'stream_url'  => 'https://ia801502.us.archive.org/1/items/SimbadElMareado/Simbad%20el%20mareado.mp4',
        'year'        => '1950',
    ],
    [
        'titulo'      => 'Nosotros los Pobres (1948)',
        'sinopsis'    => 'La historia de Pepe el Toro, un carpintero honrado del barrio que lucha por sacar adelante a su familia entre la pobreza y la injusticia. Un clásico absoluto del cine mexicano.',
        'poster_path' => 'https://imgur.com/9ZOU6Hq.jpg',
        'stream_url'  => 'https://ia801505.us.archive.org/34/items/NosotrosLosPobres/Nosotros%20los%20pobres.mp4',
        'year'        => '1948',
    ],
    [
        'titulo'      => 'Los Tres Huastecos (1948)',
        'sinopsis'    => 'Pedro Infante protagoniza a tres hermanos trillizos con personalidades completamente distintas que se enredan en situaciones cómicas y dramáticas.',
        'poster_path' => 'https://imgur.com/JzyYqis.jpg',
        'stream_url'  => 'https://ia801500.us.archive.org/31/items/LosTresHuaztecos/Los%20tres%20huaztecos.mp4',
        'year'        => '1948',
    ],
    [
        'titulo'      => 'Ustedes los Ricos (1948)',
        'sinopsis'    => 'Secuela de Nosotros los Pobres. Pepe el Toro sigue su lucha contra la injusticia social en el mismo barrio, mientras nuevos conflictos surgen en su vida.',
        'poster_path' => 'https://imgur.com/3S8RST5.jpg',
        'stream_url'  => 'https://ia601504.us.archive.org/23/items/Ustedes.Los.Ricos/Ustedes.Los.Ricos.mp4',
        'year'        => '1948',
    ],
    [
        'titulo'      => 'La Familia Pérez (1949)',
        'sinopsis'    => 'Una entrañable comedia familiar mexicana que retrata la vida cotidiana de una familia de clase media con sus alegrías, problemas y amor.',
        'poster_path' => 'https://imgur.com/lzZMzXw.jpg',
        'stream_url'  => 'https://ia801507.us.archive.org/20/items/LaFamiliaPerez_201709/La%20familia%20Perez.mp4',
        'year'        => '1949',
    ],
];

$insertados = 0;
$omitidos   = 0;

// ── Inyectar canales de TV
echo "\n🔵 Procesando " . count($canales) . " canales de TV en Vivo...\n";
foreach ($canales as $canal) {
    // Verificar si ya existe
    $existe = $pdo->prepare("SELECT id FROM contenido WHERE titulo = ? AND genero = 'tv_live'");
    $existe->execute([$canal['titulo']]);
    if ($existe->fetch()) {
        echo "  ⏭️  Omitido (ya existe): {$canal['titulo']}\n";
        $omitidos++;
        continue;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO contenido (tipo, titulo, sinopsis, poster_path, backdrop_path, fecha_estreno, puntuacion, genero, is_online)
            VALUES ('movie', ?, ?, ?, ?, '2020-01-01', ?, 'tv_live', 1)
        ");
        $stmt->execute([
            $canal['titulo'],
            $canal['sinopsis'],
            $canal['poster_path'],
            $canal['poster_path'], // backdrop = mismo logo para TV
            $canal['puntuacion'],
        ]);
        $contenidoId = $pdo->lastInsertId();

        // Insertar metadatos con URL del stream en server2 (archivo_path queda para locales)
        $stmt2 = $pdo->prepare("
            INSERT INTO peliculas_metadata (contenido_id, archivo_path, server2)
            VALUES (?, '', ?)
        ");
        $stmt2->execute([$contenidoId, $canal['stream_url']]);

        $pdo->commit();
        echo "  ✅ Insertado: {$canal['titulo']}\n";
        $insertados++;
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "  ❌ Error en {$canal['titulo']}: " . $e->getMessage() . "\n";
    }
}

// ── Inyectar películas clásicas
echo "\n🟡 Procesando " . count($clasicas) . " películas clásicas mexicanas...\n";
foreach ($clasicas as $pelicula) {
    $existe = $pdo->prepare("SELECT id FROM contenido WHERE titulo = ? AND genero = 'clasica'");
    $existe->execute([$pelicula['titulo']]);
    if ($existe->fetch()) {
        echo "  ⏭️  Omitida (ya existe): {$pelicula['titulo']}\n";
        $omitidos++;
        continue;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO contenido (tipo, titulo, sinopsis, poster_path, backdrop_path, fecha_estreno, puntuacion, genero, is_online)
            VALUES ('movie', ?, ?, ?, ?, ?, 7.5, 'clasica', 1)
        ");
        $stmt->execute([
            $pelicula['titulo'],
            $pelicula['sinopsis'],
            $pelicula['poster_path'],
            $pelicula['poster_path'],
            $pelicula['year'] . '-01-01',
        ]);
        $contenidoId = $pdo->lastInsertId();

        $stmt2 = $pdo->prepare("
            INSERT INTO peliculas_metadata (contenido_id, archivo_path)
            VALUES (?, ?)
        ");
        $stmt2->execute([$contenidoId, $pelicula['stream_url']]);

        $pdo->commit();
        echo "  ✅ Insertada: {$pelicula['titulo']}\n";
        $insertados++;
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "  ❌ Error en {$pelicula['titulo']}: " . $e->getMessage() . "\n";
    }
}

echo "\n═══════════════════════════════════════\n";
echo " ✅ Insertados : $insertados\n";
echo " ⏭️  Omitidos  : $omitidos\n";
echo " DHARMA Fix #65 completado.\n";
echo "═══════════════════════════════════════\n";
?>
