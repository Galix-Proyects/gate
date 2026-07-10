<?php
// ═══════════════════════════════════════════════════════════
//  GalixMovie — Actualizar posters de canales TV en Vivo
//  Ejecutar: php update_tv_posters.php
// ═══════════════════════════════════════════════════════════
require 'db.php';
header('Content-Type: text/plain; charset=utf-8');

// ── Logos oficiales (Wikipedia/Wikimedia Commons — URLs verificadas 2026-06-11)
$posters = [
    // Canales nacionales principales
    'Las Estrellas'           => 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/4f/Las_Estrellas_logo_%282016%29.png/330px-Las_Estrellas_logo_%282016%29.png',
    'ADN 40'                  => 'https://upload.wikimedia.org/wikipedia/commons/5/51/Logo_ADN_40.png',
    'Azteca Internacional'    => 'https://upload.wikimedia.org/wikipedia/commons/e/eb/Azteca_Internacional_logo_2023.png',
    'Canal 22 Nacional'       => 'https://upload.wikimedia.org/wikipedia/commons/thumb/b/b4/Canal_22_Logo_2011.jpg/330px-Canal_22_Logo_2011.jpg',
    'Canal del Congreso 45.1' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/0/07/Logo_Canal_del_Congreso_%28Mexico%29.svg/330px-Logo_Canal_del_Congreso_%28Mexico%29.svg.png',
    'Capital 21'              => 'https://upload.wikimedia.org/wikipedia/commons/thumb/7/72/LOGO_CAPITAL.png/330px-LOGO_CAPITAL.png',
    'De Pelicula Latin America'=> 'https://placehold.co/300x170/0f172a/f59e0b?text=De+Pelicula&font=roboto',
    'El Chavo TV'             => 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/El_Chavo_%28simple_logo%29.svg/330px-El_Chavo_%28simple_logo%29.svg.png',
    'Mexiquense TV'           => 'https://upload.wikimedia.org/wikipedia/commons/a/a0/Televisi%C3%B3n_Mexiquense_%28c._2020%29.png',
    'MVS TV'                  => 'https://upload.wikimedia.org/wikipedia/commons/thumb/0/04/MVStv_logo.png/330px-MVStv_logo.png',
    'PSN'                     => 'https://upload.wikimedia.org/wikipedia/commons/2/29/PSN_TV_Pan_American_Sports_Network_Logo_.png',
    'TUDN'                    => 'https://upload.wikimedia.org/wikipedia/commons/thumb/6/6c/TUDN_Logo.svg/330px-TUDN_Logo.svg.png',
    'TeleFórmula'             => 'https://placehold.co/300x170/0f172a/f59e0b?text=TeleFormula&font=roboto',
    'TV BUAP'                 => 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a7/Logo_de_la_BUAP.svg/330px-Logo_de_la_BUAP.svg.png',
    'Canal Parlamento del Congreso de Jalisco' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/0/07/Logo_Canal_del_Congreso_%28Mexico%29.svg/330px-Logo_Canal_del_Congreso_%28Mexico%29.svg.png',
    'Nayarit Comunica'        => 'https://placehold.co/300x170/0f172a/f59e0b?text=Nayarit&font=roboto',
    'TV UG'                   => 'https://placehold.co/300x170/0f172a/f59e0b?text=TV+UG&font=roboto',
    'ZAZ'                     => 'https://placehold.co/300x170/0f172a/f59e0b?text=ZAZ&font=roboto',

    // Canales regionales — placehold.co con nombre del canal
];

// Generar placeholders con nombre del canal para canales regionales
$regionales = [
    'B15 Fresnillo', 'B15 Zacatecas', 'Canal 10 Chiapas',
    'Canal 15 ILCE Summa Sabres', 'Canal 33 Tijuana',
    'Canal 44 Chihuahua', 'Canal 44 Ciudad Juárez', 'Canal 5 TV Cozumel',
    '8NTV', 'Alcance TV', 'AMX Noticias', 'Conecta TV', 'CreaLaTV',
    'Eclipse TV', 'El Sonorense', 'Expresa TV', 'GikTVMX',
    'ICRTV Colima', 'IERTBCS Canal 8 La Paz', 'IERTBCS Canal 8.2 La Paz',
    'ITV Deportes', 'Jalisco TV', 'Justicia TV', 'Lobo TV',
    'María Visión Mexico', 'Monte Maria', 'Notigram TV (XHFGL-TDT)',
    'Nueve TV San Luís Potosí', 'Presumiendo México', 'Radiotele Morelia',
    'Raly TV', 'RTG', 'RTQ Querétaro', 'Señal España (XHUNES-TDT)',
    'SET Televisión Canal 26.1', 'SET Televisión Canal 26.2',
    'SIPSE TV 8.1', 'SIPSE TVCUN 8.1', 'Sistema Michoacano de TV',
    'SIZART Canal 24 (XHZHZ-TDT)', 'SQCS Canal 4', 'Super Channel 12',
    'Tele Saltillo', 'Tele Yucatan', 'Telemax (XEWH-TDT)',
    'Teleplay Sureste', 'Teleritmo', 'Tlaxcala Televisión',
    'TRC Televisión', 'Turistik TV', 'TV Cuatro 4.1', 'TV Cuatro 4.2',
    'TV Lobo Durango', 'TV Mar La Paz', 'TV Mar Los Cabos',
    'TV Mar Puerto Vallarta', 'TV Nuevo León Canal 28 (XHMNL-TDT)',
    'TV UJAT (XHUJAT-TDT)', 'TVP Culiacán', 'TVP Los Mochis',
    'TVP Mazatlán', 'TVP Obregón', 'Ultra TV Puebla', 'UMTV',
    'VB Media TV', 'Visión Televisión'
];

foreach ($regionales as $titulo) {
    $short = urlencode(substr($titulo, 0, 12));
    $posters[$titulo] = "https://placehold.co/300x170/0f172a/ef4444?text=$short&font=roboto";
}

$placeholder = 'https://via.placeholder.com/300x170/1a1a2e/ef4444?text=TV';
$actualizados = 0;
$sin_cambio = 0;
$no_encontrados = 0;

echo "📺 Actualizando posters de canales TV en Vivo...\n\n";

foreach ($posters as $titulo => $posterUrl) {
    $stmt = $pdo->prepare("UPDATE contenido SET poster_path = ?, backdrop_path = ? WHERE titulo = ? AND genero = 'tv_live' AND poster_path = ?");
    $stmt->execute([$posterUrl, $posterUrl, $titulo, $placeholder]);
    $affected = $stmt->rowCount();
    
    if ($affected > 0) {
        echo "  ✅ $titulo\n";
        $actualizados++;
    } else {
        // Verificar si ya tiene un poster personalizado
        $check = $pdo->prepare("SELECT poster_path FROM contenido WHERE titulo = ? AND genero = 'tv_live'");
        $check->execute([$titulo]);
        $current = $check->fetchColumn();
        if ($current && $current !== $placeholder) {
            echo "  ⏭️  $titulo (ya tiene poster personalizado)\n";
        } else {
            echo "  ❓ $titulo (no encontrado en BD)\n";
        }
        $sin_cambio++;
    }
}

echo "\n═══════════════════════════════════════\n";
echo " ✅ Posters actualizados: $actualizados\n";
echo " ⏭️  Sin cambio: $sin_cambio\n";
echo "═══════════════════════════════════════\n";
?>
