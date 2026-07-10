<?php
/**
 * GalixMovie - Scrapper Inteligente v3.1
 * ─────────────────────────────────────────────────────────────────
 * PRIORIDAD INTELIGENTE (DHARMA Fix #57):
 *   1. Nombre del archivo parseado → fuente de verdad para el título
 *   2. TMDB con isConfidentMatch() → si es confiable (año, completado, traducción, typos),
 *      usa el título corregido/traducido de TMDB
 *   3. Si TMDB no es confiable → mantiene el nombre del archivo
 *   4. Metadatos embebidos (FFprobe) → fallback solo si TMDB no dio match
 * ─────────────────────────────────────────────────────────────────
 */
ini_set('display_errors', '0');
error_reporting(0);
set_time_limit(0);

// Evitar advertencias __bionic_open_tzdata_path en CLI de Termux
if (!getenv('ANDROID_ROOT')) putenv('ANDROID_ROOT=/system');
if (!getenv('ANDROID_DATA')) putenv('ANDROID_DATA=/data');

require 'db.php';

// --- CONFIGURACIÓN CENTRALIZADA (.env) ---
if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if(!file_exists($path)) return;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach($lines as $line) {
            if(strpos(trim($line), '#') === 0) continue;
            list($name, $value) = explode('=', $line, 2);
            $_ENV[trim($name)] = trim($value);
        }
    }
}
loadEnv(__DIR__ . '/../../../00_SISTEMA/.env');

// ━━━ CONFIGURACIÓN ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
define('TMDB_API_KEY', $_ENV['TMDB_API_KEY'] ?? 'aa99c189865340e6421390ff192384b6');
define('TMDB_BASE',    'https://api.themoviedb.org/3');
define('TMDB_IMG',     'https://image.tmdb.org/t/p/w500');
define('MEDIA_DIR',    '/data/data/com.termux/files/home/BUNKER');

// Ruta a FFprobe (si está instalado — OPCIONAL pero RECOMENDADO)
$ffprobeLocations = [
    '/data/data/com.termux/files/usr/bin/ffprobe', // Termux (Box Symmetry)
    'C:\\ffmpeg\\bin\\ffprobe.exe',
    'C:\\Program Files\\ffmpeg\\bin\\ffprobe.exe',
    'C:\\laragon\\bin\\ffmpeg\\ffprobe.exe',
    'ffprobe' // En PATH del sistema
];
define('FFPROBE', findFFprobe($ffprobeLocations));
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

function findFFprobe(array $locations): ?string {
    $isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    foreach ($locations as $path) {
        if ($path === 'ffprobe') {
            if ($isWin) {
                exec('where ffprobe 2>nul', $out, $code);
            } else {
                exec('which ffprobe 2>/dev/null', $out, $code);
            }
            if ($code === 0 && !empty($out)) return 'ffprobe';
        } elseif (file_exists($path)) {
            return $path;
        }
    }
    return null;
}

/** ESTRATEGIA 1: Leer metadatos embebidos con FFprobe */
function getEmbeddedMetadata(string $filePath): array {
    if (!FFPROBE) return [];

    $cmd = '"' . FFPROBE . '" -v quiet -print_format json -show_format "' . addslashes($filePath) . '"';
    $output = shell_exec($cmd);
    if (!$output) return [];

    $data = json_decode($output, true);
    $tags = $data['format']['tags'] ?? [];

    // Normalizar claves (FFprobe puede usar mayúsculas o minúsculas)
    $tags = array_change_key_case($tags, CASE_LOWER);

    $meta = [];
    if (!empty($tags['title']))   $meta['title'] = $tags['title'];
    if (!empty($tags['year']))    $meta['year']  = $tags['year'];
    if (!empty($tags['date']))    $meta['year']  = substr($tags['date'], 0, 4);

    return $meta;
}

/** ESTRATEGIA 2: Limpiar nombre de archivo y extraer año (fallback) */
function parseFileName(string $name): array {
    $isEpisode = false;
    $season = 0;
    $episode = 0;

    // Detectar formato de episodio: S01E01, S02E05, S03X03, 2x01, etc.
    if (preg_match('/[Ss](\d{1,2})[EeXx](\d{1,3})/', $name, $m)) {
        $isEpisode = true;
        $season = intval($m[1]);
        $episode = intval($m[2]);
    } elseif (preg_match('/(\d{1,2})[xX](\d{1,3})/', $name, $m)) {
        // Formato alternativo: 2x01, 1x05, etc.
        $isEpisode = true;
        $season = intval($m[1]);
        $episode = intval($m[2]);
    }

    // Extraer año del nombre (1900-2099)
    $year = 0;
    if (preg_match('/\b(19|20)(\d{2})\b/', $name, $m)) {
        $year = intval($m[0]);
    }

    // Eliminar extensión si quedó
    $name = preg_replace('/\.(mp4|mkv|avi|mov|webm)$/i', '', $name);
    // Reemplazar separadores por espacios
    $name = str_replace(['.', '_', '-'], ' ', $name);
    // Eliminar año y todo lo que sigue (incluyendo paréntesis y corchetes)
    $name = preg_replace('/\s*[\(\[]?(19|20)\d{2}[\)\]]?\s*.*/i', '', $name);
    // Eliminar tags técnicos
    $name = preg_replace('/\s*[\(\[]?\b(1080p|720p|4k|2160p|BluRay|BDRip|WEB[-.]?DL|x26[45]|H\.?26[45]|HEVC|AAC|DTS|HDR|DVDRip|Lat|Latino|ESP|ENG|CAM|HDCAM|TS|PROPER|REPACK|INTERNAL)\b[\)\]]?\s*.*/i', '', $name);
    // Eliminar número de episodio S01E01 o 2x01 si quedó
    $name = preg_replace('/\s*[Ss]\d{1,2}[Ee]\d{1,3}\s*/', ' ', $name);
    $name = preg_replace('/\s*\d{1,2}[xX]\d{1,3}\s*/', ' ', $name);
    // Eliminar toda puntuación, símbolos y acentos NFD (combining marks) para mejorar la búsqueda en TMDB
    $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);
    // Eliminar múltiples espacios
    $name = trim(preg_replace('/\s+/', ' ', $name));

    return [
        'title' => $name,
        'year' => $year,
        'is_episode' => $isEpisode,
        'season' => $season,
        'episode' => $episode
    ];
}

/** Validación inteligente: determinar si un match de TMDB es confiable */
function isConfidentMatch(string $query, int $queryYear, ?array $tmdbResult): bool {
    if (!$tmdbResult) return false;

    $tmdbTitle = $tmdbResult['title'] ?? $tmdbResult['name'] ?? '';
    if (!$tmdbTitle) return false;

    $originalTitle = $tmdbResult['original_title'] ?? $tmdbResult['original_name'] ?? '';

    $tmdbYear = 0;
    $releaseDate = $tmdbResult['release_date'] ?? $tmdbResult['first_air_date'] ?? null;
    if ($releaseDate) $tmdbYear = (int)substr($releaseDate, 0, 4);

    // 1. Año coincide → altísima confianza
    if ($queryYear > 0 && $tmdbYear > 0 && $queryYear === $tmdbYear) return true;

    // Normalizar ambos para comparación textual
    $q = mb_strtolower(trim($query));

    // Probar contra title localizado y original
    $candidates = [$tmdbTitle];
    if ($originalTitle && $originalTitle !== $tmdbTitle) $candidates[] = $originalTitle;

    foreach ($candidates as $cand) {
        $t = mb_strtolower(trim($cand));
        $t = preg_replace('/[^\p{L}\p{N}\s]/u', '', $t);
        $t = preg_replace('/\s+/', ' ', trim($t));
        if (strlen($t) < 3) continue;

        if (str_contains($t, $q)) return true;
        if (str_contains($q, $t)) return true;

        $maxLen = max(strlen($q), strlen($t));
        if ($maxLen > 0 && $maxLen < 256) {
            $dist = levenshtein($q, $t);
            if ($dist / $maxLen < 0.4) return true;
        }

        similar_text($q, $t, $percent);
        if ($percent > 65) return true;
    }

    return false;
}

/** Safety net: rechazar si TMDB reduce drásticamente el título vs el filename */
function applySafetyNet(bool $confident, string $query, ?array $tmdbResult): array {
    if (!$confident || !$tmdbResult) return [$confident, null];
    $tmdbTitle = $tmdbResult['title'] ?? $tmdbResult['name'] ?? '';
    if (!$tmdbTitle) return [$confident, null];
    $fw = str_word_count($query);
    $tw = str_word_count($tmdbTitle);
    if ($fw >= 3 && $tw <= 2) {
        return [false, "TMDB sugirió '$tmdbTitle' que es mucho más corto que '$query' — preservando filename"];
    }
    return [$confident, null];
}

/** Buscar en TMDB por ID numérico directo */
function tmdbSearchById(int $id, string $tipo = 'movie'): ?array {
    $url = TMDB_BASE . "/$tipo/$id?" . http_build_query([
        'api_key' => TMDB_API_KEY,
        'language' => 'es-MX'
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (is_resource($ch)) { curl_close($ch); }

    if ($code !== 200) return null;
    return json_decode($raw, true);
}

/** Buscar en TMDB con validación anti-falso-positivo */
function tmdbSearch(string $query, int $year = 0, string $tipo = 'movie'): ?array {
    $params = ['api_key' => TMDB_API_KEY, 'query' => $query, 'language' => 'es-MX'];
    // NO filtrar por año en la API — el año se usa solo para validación post-búsqueda (isConfidentMatch)
    // Si el año del filename no coincide exactamente con el release year de TMDB, la búsqueda fallaría.

    $url = TMDB_BASE . "/search/$tipo?" . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (is_resource($ch)) { curl_close($ch); } // Evitar deprecation warning en PHP 8+

    if ($code !== 200) return null;
    $data = json_decode($raw, true);
    $results = $data['results'] ?? [];

    // Validación anti-falso-positivo:
    // Si el primer resultado está en un script no-latino (ej: chino, árabe, ruso)
    // y el query está en caracteres latinos, ignorarlo
    foreach ($results as $result) {
        $title = $result['title'] ?? $result['name'] ?? '';
        // Detectar caracteres no-ASCII (CJK, árabe, cirílico, etc.)
        $hasNonLatin = preg_match('/[\x{0400}-\x{04FF}\x{4E00}-\x{9FFF}\x{0600}-\x{06FF}]/u', $title);
        $queryIsLatin = !preg_match('/[\x{4E00}-\x{9FFF}]/u', $query);
        if ($hasNonLatin && $queryIsLatin) continue; // Saltar resultado dudoso
        return $result;
    }

    return null;
}

/** Obtener título de episodio individual desde TMDB */
function tmdbGetSeasonEpisodes(int $seriesId, int $season): array {
    $url = TMDB_BASE . "/tv/$seriesId/season/$season?" . http_build_query([
        'api_key' => TMDB_API_KEY,
        'language' => 'es-MX'
    ]);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (is_resource($ch)) curl_close($ch);
    if ($code !== 200) return [];
    $data = json_decode($raw, true);
    $episodes = $data['episodes'] ?? [];
    $map = [];
    foreach ($episodes as $ep) {
        $num = (int)$ep['episode_number'];
        $map[$num] = [
            'name'    => $ep['name'] ?? '',
            'still'   => !empty($ep['still_path']) ? TMDB_IMG . $ep['still_path'] : null,
            'overview' => $ep['overview'] ?? '',
            'vote'    => $ep['vote_average'] ?? null,
        ];
    }
    return $map;
}

/** Detectar serie desde estructura de carpetas: media/series/[Serie]/[Temporada N]/[archivo].mp4 o gdrive:HLS_TEST/[Serie]/[SxxEyy]/playlist.m3u8 */
function detectSeriesFromPath(string $filePath): ?array {
    $normalized = str_replace('\\', '/', $filePath);

    // 1. Caso Google Drive
    if (strpos($normalized, 'gdrive:') === 0) {
        $parts = explode('/', $normalized);
        if (count($parts) >= 3) {
            $seriesName = '';
            $season = 0;
            $episode = 0;

            // Busquemos el componente SxxEyy
            $sIdx = -1;
            foreach ($parts as $idx => $part) {
                if (preg_match('/[Ss](\d{1,2})[Ee](\d{1,3})/i', $part, $m)) {
                    $sIdx = $idx;
                    $season = intval($m[1]);
                    $episode = intval($m[2]);
                    break;
                }
            }

            if ($sIdx > 1) {
                $seriesName = $parts[$sIdx - 1];
            } else {
                $seriesName = $parts[count($parts) - 2];
                if (strtolower($seriesName) === 'playlist.m3u8' || preg_match('/\.m3u8$/i', $seriesName)) {
                    $seriesName = $parts[count($parts) - 3];
                }
            }

            // Limpiar " S01", " S1", " Temporada 1" etc. del nombre de la serie
            if (preg_match('/^(.*?)\s+([Ss]\d+|[Tt]emporada\s*\d+)\s*$/i', $seriesName, $sm)) {
                $seriesName = $sm[1];
                if ($season === 0 && preg_match('/[Ss](\d+)/i', $sm[2], $sM)) {
                    $season = intval($sM[1]);
                }
            }

            $seriesName = trim(str_replace(['_', '.'], ' ', $seriesName));

            if ($episode === 0) {
                $basename = end($parts);
                if (preg_match('/[Ee]p[.\s]*(\d+)/i', $basename, $m)) {
                    $episode = (int)$m[1];
                } elseif (preg_match('/[Ss]\d{1,2}[Ee](\d{1,3})/', $basename, $m)) {
                    $episode = (int)$m[1];
                } elseif (preg_match('/(\d{1,2})[xX](\d{1,3})/', $basename, $m)) {
                    $episode = (int)$m[2];
                } elseif (preg_match('/\b(\d{1,3})\b/', $basename, $m)) {
                    $episode = (int)$m[1];
                }
            }

            if ($seriesName) {
                return [
                    'series'  => $seriesName,
                    'season'  => $season ?: 1,
                    'episode' => $episode,
                ];
            }
        }
    }

    // 2. Caso local estándar (series) — soporta SERIES/, SERIES2/, etc.
    if (preg_match('~/series\d*/~i', $normalized, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1];
        $prefixLen = strlen($m[0][0]);
        $afterSeries = substr($normalized, $pos + $prefixLen);
        $parts = explode('/', $afterSeries);
        if (count($parts) >= 2) {
            $seriesName = trim(str_replace(['_', '.'], ' ', $parts[0]));
            if ($seriesName) {
                // La temporada se obtiene del nombre de la carpeta (terminación numérica)
                $season = 0;
                if (preg_match('/(\d+)$/', $parts[1], $m)) {
                    $season = (int)$m[1];
                }

                $basename = end($parts);
                $epName = preg_replace('/\.(mp4|mkv|avi|mov|webm)$/i', '', $basename);

                // El episodio se obtiene del nombre del archivo (prioridad: SxxExx > Ep X > número suelto)
                $episode = 0;
                if (preg_match('/[Ee]p[.\s]*(\d+)/i', $epName, $m)) {
                    $episode = (int)$m[1];
                } elseif (preg_match('/[Ss]\d{1,2}[Ee](\d{1,3})/', $epName, $m)) {
                    $episode = (int)$m[1];
                } elseif (preg_match('/(\d{1,2})[xX](\d{1,3})/', $epName, $m)) {
                    $episode = (int)$m[2];
                } elseif (preg_match('/\b(\d{1,3})\b/', $epName, $m)) {
                    $episode = (int)$m[1];
                }

                return [
                    'series'  => $seriesName,
                    'season'  => $season,
                    'episode' => $episode,
                ];
            }
        }
    }

    // 3. Caso local alternativo (HDD_500GB) — solo si la subcarpeta NO es carpeta de películas
    $pos = strpos($normalized, '/HDD_500GB/');
    if ($pos !== false) {
        $afterHdd = substr($normalized, $pos + 11);
        $parts = explode('/', $afterHdd);
        // Debe ser una subcarpeta dentro de HDD_500GB, no un archivo raíz directo
        if (count($parts) >= 2) {
            $firstDir = $parts[0];
            // Ignorar subcarpetas genéricas de películas (PELICULAS, PELICULAS2, MOVIES, etc.)
            if (preg_match('/^(PELICULAS|MOVIES|MOVIE|VIDEOS|CINE)(\d*)$/i', $firstDir)) {
                return null;
            }
            $seriesName = $firstDir;
            $season = 0;

            // Si el nombre de la subcarpeta tiene formato "Nombre Serie S01" o "Nombre Serie Temporada 1"
            if (preg_match('/^(.*?)\s+([Ss]\d+|[Tt]emporada\s*\d+)\s*$/i', $seriesName, $sm)) {
                $seriesName = $sm[1];
                if (preg_match('/[Ss](\d+)/i', $sm[2], $sM)) {
                    $season = intval($sM[1]);
                }
            }

            $seriesName = trim(str_replace(['_', '.'], ' ', $seriesName));

            // Si hay otro subdirectorio intermedio antes del archivo (ej: HDD_500GB/Serie/GOT1/S01E01.mp4)
            if (count($parts) >= 3 && $season === 0) {
                if (preg_match('/(\d+)$/', $parts[1], $m)) {
                    $season = (int)$m[1];
                }
            }

            $basename = end($parts);
            $epName = preg_replace('/\.(mp4|mkv|avi|mov|webm)$/i', '', $basename);

            // Buscar episodio
            $episode = 0;
            if (preg_match('/[Ee]p[.\s]*(\d+)/i', $epName, $m)) {
                $episode = (int)$m[1];
            } elseif (preg_match('/[Ss]\d{1,2}[Ee](\d{1,3})/', $epName, $m)) {
                $episode = (int)$m[1];
            } elseif (preg_match('/(\d{1,2})[xX](\d{1,3})/', $epName, $m)) {
                $episode = (int)$m[2];
            } elseif (preg_match('/\b(\d{1,3})\b/', $epName, $m)) {
                $episode = (int)$m[1];
            }

            if ($seriesName) {
                return [
                    'series'  => $seriesName,
                    'season'  => $season ?: 1,
                    'episode' => $episode,
                ];
            }
        }
    }

    return null;
}

/** Escaneo de archivos locales — excluye carpetas internas del sistema */
function scanMedia(string $dir): array {
    $exts = ['mp4', 'mkv', 'avi', 'mov', 'webm'];
    // Carpetas a excluir del escaneo (archivos temporales, basura macOS, etc.)
    $excludeDirs = ['temp_hls', 'lost+found', 'Backup', '.DS_Store'];
    $results = [];
    if (!is_dir($dir)) return $results;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($files as $f) {
        // Saltar carpetas excluidas
        if ($f->isDir()) {
            if (in_array($f->getBasename(), $excludeDirs)) {
                $files->next();
                continue;
            }
            continue;
        }
        if (!$f->isFile()) continue;
        $basename = $f->getBasename();
        if (str_starts_with($basename, '._')) continue;
        if (!in_array(strtolower($f->getExtension()), $exts)) continue;
        $results[] = ['path' => $f->getPathname(), 'name' => $f->getBasename('.' . $f->getExtension()), 'file_size' => $f->getSize()];
    }
    return $results;
}

/** Localizar binario de rclone en Termux/Linux */
function findRcloneBinary(): string {
    $candidates = [
        '/data/data/com.termux/files/usr/bin/rclone',
        '/usr/local/bin/rclone',
        '/usr/bin/rclone',
        trim(shell_exec('which rclone 2>/dev/null') ?: ''),
    ];
    foreach ($candidates as $bin) {
        if ($bin && file_exists($bin) && is_executable($bin)) {
            return $bin;
        }
    }
    return 'rclone'; // fallback: esperar que esté en PATH
}

/**
 * Escaneo de Google Drive via rclone.
 * Escanea archivos de video MP4/MKV/etc Y playlists HLS (playlist.m3u8)
 * dentro de las carpetas configuradas en GDrive.
 *
 * @param array $gFolders Carpetas de GDrive a escanear (ej: ['HLS_TEST', 'Movies'])
 */
function rcloneExecSafe(string $cmd, int $timeoutSec = 15): string {
    $timeoutBin = trim(shell_exec('which timeout 2>/dev/null') ?: '');
    if ($timeoutBin) {
        $cmd = escapeshellarg($timeoutBin) . ' ' . $timeoutSec . ' ' . $cmd;
    }
    return shell_exec($cmd) ?? '';
}

function scanGoogleDrive(array $gFolders = ['HLS_TEST']): array {
    $results = [];

    $rcloneBin    = findRcloneBinary();
    $rcloneConfig = '/data/data/com.termux/files/home/.config/rclone/rclone.conf';
    $configFlag   = file_exists($rcloneConfig) ? ' --config ' . escapeshellarg($rcloneConfig) : '';
    $env          = 'HOME=/data/data/com.termux/files/home ';

    // ── PRE-FLIGHT: verificar que rclone responda ─────────────────────────
    $healthCheck = $env . escapeshellarg($rcloneBin) . $configFlag . ' version 2>/dev/null';
    $healthOut = rcloneExecSafe($healthCheck, 5);
    if (!$healthOut) {
        error_log('[scrapper] rclone no responde — omitiendo escaneo GDrive');
        return $results;
    }

    foreach ($gFolders as $folder) {
        $folder = trim($folder, '/ ');
        if (empty($folder)) continue;
        $gdriveRoot   = ($folder === '.') ? 'gdrive:' : "gdrive:{$folder}/";
        $gdrivePrefix = ($folder === '.') ? 'gdrive:/' : "gdrive:{$folder}/";

        // ── 1. Buscar archivos de video (mp4, mkv, etc.) ──────────────────────
        $videoCmd = $env . escapeshellarg($rcloneBin) . $configFlag .
            ' ls ' . escapeshellarg($gdriveRoot) .
            ' --include "*.mp4" --include "*.mkv" --include "*.avi" --include "*.mov" --include "*.webm"' .
            ' 2>/dev/null';
        $videoOut = rcloneExecSafe($videoCmd, 15);

        foreach (explode("\n", trim($videoOut)) as $line) {
            $line = trim($line);
            if (!$line) continue;
            if (preg_match('/^(\d+)\s+(.+)$/', $line, $m)) {
                $fullPath   = $m[2];
                $basename   = basename($fullPath);
                $nameNoExt  = preg_replace('/\.[^.]+$/', '', $basename);
                $results[] = [
                    'path'      => $gdrivePrefix . $fullPath,
                    'name'      => $nameNoExt,
                    'file_size' => intval($m[1]),
                    'gdrive_folder' => $folder,
                ];
            }
        }

        // ── 2. Buscar playlists HLS (SxxEyy/playlist.m3u8) ──────────────────
        $hlsCmd = $env . escapeshellarg($rcloneBin) . $configFlag .
            ' lsf ' . escapeshellarg($gdriveRoot) .
            ' --recursive --files-only --include "**/playlist.m3u8"' .
            ' 2>/dev/null';
        $hlsOut = rcloneExecSafe($hlsCmd, 15);

        foreach (explode("\n", trim($hlsOut)) as $line) {
            $line = trim($line);
            if (!$line) continue;
            if (!preg_match('/[Ss](\d{1,2})[Ee](\d{1,3})\/playlist\.m3u8$/i', $line, $m)) continue;

            $season   = intval($m[1]);
            $episode  = intval($m[2]);
            $virtPath = $gdrivePrefix . $line;

            $results[] = [
                'path'          => $virtPath,
                'name'          => sprintf('S%02dE%02d', $season, $episode),
                'file_size'     => 0,
                'gdrive_folder' => $folder,
                'hls_season'    => $season,
                'hls_episode'   => $episode,
            ];
        }
    }

    return $results;
}

/**
 * Escaneo combinado: local (toda la carpeta media/) + Google Drive.
 * Las carpetas de GDrive se pueden configurar con la variable de entorno
 * GDRIVE_SCAN_FOLDERS (separadas por coma), ej: HLS_TEST,Movies
 */
function scanAllMedia(string $localDir): array {
    $local = scanMedia($localDir);

    // Escanear directorios locales adicionales (EXTRA_SCAN_DIRS en .env, separados por coma)
    // Ej: EXTRA_SCAN_DIRS=/HDD_500GB,/media/series
    $extraRaw = $_ENV['EXTRA_SCAN_DIRS'] ?? getenv('EXTRA_SCAN_DIRS') ?: '';
    if ($extraRaw) {
        foreach (array_filter(array_map('trim', explode(',', $extraRaw))) as $extraDir) {
            if ($extraDir && is_dir($extraDir) && realpath($extraDir) !== realpath($localDir)) {
                $local = array_merge($local, scanMedia($extraDir));
            }
        }
    }

    // Leer carpetas de GDrive desde .env o escanear raíz completa
    $gFoldersRaw = $_ENV['GDRIVE_SCAN_FOLDERS'] ?? getenv('GDRIVE_SCAN_FOLDERS') ?: '';
    if (empty(trim($gFoldersRaw))) {
        $gFolders = ['.'];  // . = raíz de gdrive:
    } else {
        $gFolders = array_filter(array_map('trim', explode(',', $gFoldersRaw)));
    }

    $gdrive = scanGoogleDrive($gFolders);
    return array_merge($local, $gdrive);
}

/** Guardar en BD + Auto-renombrar archivo físico */
function upsertContent(PDO $pdo, array $tmdbData, string $tipo, string $filePath, string $filenameTitle = '', int $filenameYear = 0, bool $confidentMatch = true, int $season = 0, int $episode = 0, int $fileSize = 0): int {
    // Buscar por tmdb_id sin filtrar por tipo (evita duplicados por mismatch de tipo)
    $check = $pdo->prepare("SELECT id, tipo FROM contenido WHERE tmdb_id = ?");
    $check->execute([$tmdbData['id']]);
    $existingRow = $check->fetch(PDO::FETCH_ASSOC);
    $existing = $existingRow ? (int)$existingRow['id'] : false;
    if ($existing) {
        $tipo = $existingRow['tipo']; // usar el tipo que ya tiene en BD
    }

    $poster   = !empty($tmdbData['poster_path'])   ? TMDB_IMG . $tmdbData['poster_path'] : null;
    $backdrop = !empty($tmdbData['backdrop_path']) ? 'https://image.tmdb.org/t/p/original' . $tmdbData['backdrop_path'] : null;
    $sinopsis = $tmdbData['overview'] ?? '';
    $fecha    = $tmdbData['release_date'] ?? $tmdbData['first_air_date'] ?? null;
    if ($fecha === '') $fecha = null;
    $rating   = $tmdbData['vote_average'] ?? 0;
    $tmdbYear     = $fecha ? (int)substr($fecha, 0, 4) : 0;

    // ── TÍTULO INTELIGENTE ────────────────────────────────────────────────
    // Si el match es confiable → usar título de TMDB (corregido/completado/traducido)
    // Si NO es confiable → preservar el nombre del archivo original
    if ($confidentMatch && !empty($tmdbData['title'] ?? $tmdbData['name'] ?? '')) {
        $localized = $tmdbData['title'] ?? $tmdbData['name'] ?? '';
        $original  = $tmdbData['original_title'] ?? $tmdbData['original_name'] ?? '';
        $titulo = $localized;
        $year   = $tmdbYear ?: ($filenameYear ?: '');
    } else {
        $titulo = $filenameTitle ?: ($tmdbData['title'] ?? $tmdbData['name'] ?? 'Sin título');
        $year   = $filenameYear ?: ($tmdbYear ?: '');
    }

    // ── DETECCIÓN DE SUBTÍTULOS (solo archivos locales) ──
    $subExts  = ['vtt', 'srt', 'ass'];
    $subPath  = null;
    if (strpos($filePath, 'gdrive:') !== 0) {
        $baseName = pathinfo($filePath, PATHINFO_FILENAME);
        foreach ($subExts as $se) {
            $potential = dirname($filePath) . DIRECTORY_SEPARATOR . $baseName . '.' . $se;
            if (file_exists($potential)) {
                $subPath = $potential;
                break;
            }
        }
    }

    // ── AUTO-RENOMBRADO — solo para contenido NUEVO y archivos locales (NUNCA episodios) ──
    if (!$existing && strpos($filePath, 'gdrive:') !== 0 && !($season > 0 && $episode > 0)) {
        $ext         = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $safeTitle   = preg_replace('/[<>:"\/\\|?*]/', '', $titulo);
        $newFileName = $safeTitle . ($year ? " ($year)" : '') . '.' . $ext;
        $newFilePath = dirname($filePath) . DIRECTORY_SEPARATOR . $newFileName;

        if ($filePath !== $newFilePath && !file_exists($newFilePath)) {
            if (rename($filePath, $newFilePath)) {
                $filePath = $newFilePath;
                
                if ($subPath) {
                    $subExt = pathinfo($subPath, PATHINFO_EXTENSION);
                    $newSubName = $safeTitle . ($year ? " ($year)" : '') . '.' . $subExt;
                    $newSubPath = dirname($filePath) . DIRECTORY_SEPARATOR . $newSubName;
                    if (rename($subPath, $newSubPath)) {
                        $subPath = $newSubPath;
                    }
                }
            }
        }
    }
    // ────────────────────────────────────────────────────────────────────────
    
    // Log de subtítulos para el usuario
    if ($subPath) {
        global $log;
        $log[] = "    [SUB 📝] Detectado: " . basename($subPath);
    }

    if ($existing) {
        $pdo->prepare("UPDATE contenido SET sinopsis=?,poster_path=?,backdrop_path=?,fecha_estreno=?,puntuacion=? WHERE id=?")
            ->execute([$sinopsis, $poster, $backdrop, $fecha, $rating, $existing]);
        $contenido_id = $existing;
    } else {
        $pdo->prepare("INSERT INTO contenido (tipo,titulo,sinopsis,poster_path,backdrop_path,fecha_estreno,tmdb_id,puntuacion) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$tipo, $titulo, $sinopsis, $poster, $backdrop, $fecha, $tmdbData['id'], $rating]);
        $contenido_id = $pdo->lastInsertId();
    }

    // ── GUARDAR METADATOS ─────────────────────────────────────────────────────
    // Si es un episodio de serie → series_metadata (tabla oficial de episodios)
    // Si es película → peliculas_metadata (tabla de películas)
    if ($season > 0 && $episode > 0) {
        // Limpiar cualquier residuo viejo de peliculas_metadata para evitar duplicados
        $pdo->prepare("DELETE FROM peliculas_metadata WHERE contenido_id = ? AND season = ? AND episode = ?")
            ->execute([$contenido_id, $season, $episode]);

        $existingSeriesMeta = $pdo->prepare("SELECT id FROM series_metadata WHERE contenido_id = ? AND temporada = ? AND episodio = ?");
        $existingSeriesMeta->execute([$contenido_id, $season, $episode]);
        $seriesMetaId = $existingSeriesMeta->fetchColumn();

        static $seasonCache = [];
        $cacheKey = $tmdbData['id'] . '_' . $season;
        if (!isset($seasonCache[$cacheKey])) {
            $seasonCache[$cacheKey] = tmdbGetSeasonEpisodes($tmdbData['id'], $season);
        }
        $epData = $seasonCache[$cacheKey][$episode] ?? [];
        $epTitle   = $epData['name']    ?? ('Episodio ' . $episode);
        $epStill   = $epData['still']   ?? null;
        $epOverview = $epData['overview'] ?? null;
        $epVote    = $epData['vote']    ?? null;
        if ($seriesMetaId) {
            $pdo->prepare("UPDATE series_metadata SET archivo_path=?, subtitulos_path=?, titulo_episodio=?, episode_still=?, episode_overview=?, episode_vote=? WHERE id=?")
                ->execute([$filePath, $subPath, $epTitle, $epStill, $epOverview, $epVote, $seriesMetaId]);
        } else {
            $pdo->prepare("INSERT INTO series_metadata (contenido_id, temporada, episodio, archivo_path, subtitulos_path, titulo_episodio, episode_still, episode_overview, episode_vote) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$contenido_id, $season, $episode, $filePath, $subPath, $epTitle, $epStill, $epOverview, $epVote]);
        }
    } else {
        // Película normal → peliculas_metadata
        $existingMeta = $pdo->prepare("SELECT id FROM peliculas_metadata WHERE contenido_id = ? AND season IS NULL");
        $existingMeta->execute([$contenido_id]);
        $metaId = $existingMeta->fetchColumn();
        if ($metaId) {
            $pdo->prepare("UPDATE peliculas_metadata SET archivo_path=?, subtitulos_path=?, file_size=? WHERE id=?")
                ->execute([$filePath, $subPath, $fileSize, $metaId]);
        } else {
            $pdo->prepare("INSERT INTO peliculas_metadata (contenido_id, archivo_path, subtitulos_path, season, episode, file_size) VALUES (?,?,?,?,?,?)")
                ->execute([$contenido_id, $filePath, $subPath, null, null, $fileSize]);
        }
    }
    return $contenido_id;
}

/** Aplicar cambios solo para índices aprobados (desde el modal de preview) */
function runApply(PDO $pdo, array $approved, array $customTmdb = [], array $customNames = [], array $filePaths = []): array {
    $videos = scanAllMedia(MEDIA_DIR);
    $applied = []; $errors = [];

    // Match archivos por path (enviado desde preview) en vez de índice frágil
    $approvedPathToIndex = [];
    foreach ($approved as $idx) {
        if (isset($filePaths[$idx])) {
            $approvedPathToIndex[$filePaths[$idx]] = $idx;
        }
    }
    $usePathMatching = !empty($approvedPathToIndex);
    $seenOrigIndices = [];

    foreach ($videos as $scanIdx => $video) {
        if ($usePathMatching) {
            $origIndex = $approvedPathToIndex[$video['path']] ?? null;
            if ($origIndex === null) continue;
            if (isset($seenOrigIndices[$origIndex])) continue;
            $seenOrigIndices[$origIndex] = true;
            $i = $origIndex;
        } else {
            if (!in_array($scanIdx, $approved)) continue;
            $i = $scanIdx;
        }

        $seriesPath = detectSeriesFromPath($video['path']);
        if ($seriesPath) {
            $parsed = parseFileName($video['name']);
            $parsed['title']      = $seriesPath['series'];
            $parsed['season']     = $seriesPath['season'];
            $parsed['episode']    = $seriesPath['episode'] ?: $parsed['episode'];
            $parsed['is_episode'] = $parsed['episode'] > 0;
        } else {
            $parsed = parseFileName($video['name']);
        }
        $isEpisode = $parsed['is_episode'];

        $customName = $customNames[$i] ?? null;
        if ($customName) {
            $nameNoExt = pathinfo($customName, PATHINFO_FILENAME);
            $ext = strtolower(pathinfo($video['path'], PATHINFO_EXTENSION));
            $customNameFull = $nameNoExt . '.' . $ext;
            $newPath = dirname($video['path']) . DIRECTORY_SEPARATOR . $customNameFull;

            // Parse year from custom name
            $customYear = 0;
            if (preg_match('/\((\d{4})\)/', $nameNoExt, $yM)) {
                $customYear = (int)$yM[1];
                $cleanTitle = trim(preg_replace('/\s*\(\d{4}\)\s*$/', '', $nameNoExt));
            } else {
                $cleanTitle = $nameNoExt;
            }

            // Rename file on disk
            if ($newPath !== $video['path'] && !file_exists($newPath)) {
                if (@rename($video['path'], $newPath)) {
                    $video['path'] = $newPath;
                    $video['name'] = $customNameFull;
                    // Rename subtitles
                    $oldBase = pathinfo($video['path'], PATHINFO_FILENAME);
                    $newBase = pathinfo($newPath, PATHINFO_FILENAME);
                    $dir = dirname($newPath);
                    foreach (['srt', 'vtt', 'ass', 'ssa', 'sub'] as $se) {
                        $oldSub = $dir . DIRECTORY_SEPARATOR . $oldBase . '.' . $se;
                        $newSub = $dir . DIRECTORY_SEPARATOR . $newBase . '.' . $se;
                        if (file_exists($oldSub) && !file_exists($newSub)) {
                            @rename($oldSub, $newSub);
                        }
                    }
                }
            }

            // Use custom title/year for TMDB search
            $parsed['title'] = $cleanTitle;
            $parsed['year'] = $customYear;
        }

        if (isset($customTmdb[$i]) && $customTmdb[$i] > 0) {
            $result = tmdbSearchById($customTmdb[$i], 'movie');
            $tipo = 'movie';
            if (!$result) {
                $result = tmdbSearchById($customTmdb[$i], 'tv');
                if ($result) $tipo = 'series';
            }
            if (!$result) {
                $errors[] = ['index' => $i, 'file' => $video['name'], 'message' => 'TMDB ID inválido: ' . $customTmdb[$i]];
                continue;
            }
            $confident = true;
        } else {
            // Si es episodio, buscar como serie directamente
            if ($isEpisode) {
                $result = tmdbSearch($parsed['title'], $parsed['year'], 'tv');
                $tipo = 'series';
            } else {
                $result = tmdbSearch($parsed['title'], $parsed['year'], 'movie');
                $tipo = 'movie';
                if (!$result) {
                    $result = tmdbSearch($parsed['title'], $parsed['year'], 'tv');
                    if ($result) $tipo = 'series';
                }
            }

            if (!$result) {
                $errors[] = ['index' => $i, 'file' => $video['name'], 'message' => 'Sin coincidencia en TMDB'];
                continue;
            }

            $confident = isConfidentMatch($parsed['title'], $parsed['year'], $result);
            list($confident, $_) = applySafetyNet($confident, $parsed['title'], $result);
        }

        $id = upsertContent($pdo, $result, $tipo, $video['path'], $parsed['title'], $parsed['year'], $confident, $parsed['season'], $parsed['episode'], $video['file_size'] ?? 0);
        $applied[] = ['index' => $i, 'file' => $video['name'], 'content_id' => $id, 'status' => 'ok'];

        usleep(250000);
    }

    return ['applied' => $applied, 'errors' => $errors];
}

// ━━━ SKIP LIST (archivos ignorados manualmente) ━━━━━━
$SKIP_FILE = __DIR__ . '/scanner_skip.json';
function loadSkipList($file) {
    return file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
}
function saveSkipList($file, $list) {
    file_put_contents($file, json_encode($list, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// ━━━ EJECUCIÓN PRINCIPAL ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
$isWeb = (php_sapi_name() !== 'cli');
require 'auth.php';
if ($isWeb) checkAuth();
$log   = [];

// ── HANDLE WEB ACTIONS ───────────────────────────────
if ($isWeb) {
    $action = $_GET['action'] ?? 'full';

    // ── SKIP ADD ──────────────────────────────────────────
    if ($action === 'skip_add') {
        header('Content-Type: application/json');
        $file = $_GET['file'] ?? '';
        $tmdbId = (int)($_GET['tmdb_id'] ?? 0);
        if ($file) {
            $list = loadSkipList($SKIP_FILE);
            $list[$file] = $tmdbId;
            saveSkipList($SKIP_FILE, $list);
            echo json_encode(['status' => 'ok', 'message' => 'Archivo ignorado: ' . $file]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Falta parámetro file']);
        }
        exit;
    }

    // ── SKIP REMOVE ───────────────────────────────────────
    if ($action === 'skip_remove') {
        header('Content-Type: application/json');
        $file = $_GET['file'] ?? '';
        if ($file) {
            $list = loadSkipList($SKIP_FILE);
            unset($list[$file]);
            saveSkipList($SKIP_FILE, $list);
            echo json_encode(['status' => 'ok', 'message' => 'Archivo restaurado: ' . $file]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Falta parámetro file']);
        }
        exit;
    }

    // ── SKIP LIST ─────────────────────────────────────────
    if ($action === 'skip_list') {
        header('Content-Type: application/json');
        echo json_encode(['files' => array_keys(loadSkipList($SKIP_FILE))]);
        exit;
    }

    // ── PREVIEW MODE (dry-run, sin writes) ──────────────────
    if ($action === 'preview') {
        $skipIndexed = isset($_GET['skip_indexed']) && $_GET['skip_indexed'] === '1';
        set_time_limit(330);
        while (ob_get_level() > 0) ob_end_clean();
        ini_set('zlib.output_compression', 'Off');
        header('Content-Type: application/x-ndjson');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Content-Encoding: identity');
        ignore_user_abort(true);
        if (function_exists('apache_setenv')) apache_setenv('no-gzip', '1');
        ob_start();

        try {
            $videos = scanAllMedia(MEDIA_DIR);
            $total = count($videos);
            $pad = str_repeat(' ', 4096);

            echo json_encode(['type' => 'progress', 'current' => 0, 'total' => $total, 'file' => '', 'msg' => "Escaneando directorio... $total archivos"]) . $pad . "\n";
            ob_flush();
            flush();

            $stmt = $pdo->query("SELECT archivo_path FROM peliculas_metadata WHERE archivo_path IS NOT NULL");
            $existingFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Incluir también los episodios de series_metadata para reconocer archivos ya indexados
            $stmt2 = $pdo->query("SELECT archivo_path FROM series_metadata WHERE archivo_path IS NOT NULL");
            $existingFiles = array_merge($existingFiles, $stmt2->fetchAll(PDO::FETCH_COLUMN));

            echo json_encode(['type' => 'progress', 'current' => 0, 'total' => $total, 'file' => '', 'msg' => 'Consultando base de datos...']) . "\n";
            ob_flush();
            flush();

            // Orphaned index para preview
            $orphaned = [];
            $oStmt = $pdo->query("SELECT c.id, c.tmdb_id, pm.archivo_path FROM contenido c JOIN peliculas_metadata pm ON pm.contenido_id = c.id WHERE c.tmdb_id > 0 AND pm.archivo_path IS NOT NULL AND pm.archivo_path != ''");
            if ($oStmt) {
                while ($o = $oStmt->fetch(PDO::FETCH_ASSOC)) {
                    $o['archivo_path'] = realpath($o['archivo_path']) ?: $o['archivo_path'];
                    if (!file_exists($o['archivo_path'])) {
                        $orphaned[$o['tmdb_id']] = $o['id'];
                    }
                }
            }

            // ── DATOS DE CONTENIDO YA INDEXADO (para sobrescribir tmdb_id) ──
            $existingContent = [];
            $cStmt = $pdo->query("SELECT c.id, c.titulo, c.tmdb_id, c.tipo, pm.archivo_path FROM contenido c JOIN peliculas_metadata pm ON pm.contenido_id = c.id WHERE c.tmdb_id > 0 AND pm.archivo_path IS NOT NULL AND pm.archivo_path != ''");
            if ($cStmt) {
                while ($c = $cStmt->fetch(PDO::FETCH_ASSOC)) {
                    $fn = pathinfo($c['archivo_path'], PATHINFO_FILENAME);
                    if ($fn) $existingContent[$fn] = $c;
                }
            }

            echo json_encode(['type' => 'progress', 'current' => 0, 'total' => $total, 'file' => '', 'msg' => 'Conectando con TMDB...']) . "\n";
            ob_flush();
            flush();

            $results = [];
            $skipList = loadSkipList($SKIP_FILE);

            foreach ($videos as $i => $video) {
            $seriesPath = detectSeriesFromPath($video['path']);
            // Skip list: solo aplicar a NO-series (películas sueltas).
            // Episodios de serie se detectan por estructura de carpetas, no por nombre de archivo.
            if (!$seriesPath && (isset($skipList[$video['name']]) || in_array($video['name'], $skipList))) {
                $skipTmdbId = isset($skipList[$video['name']]) ? (int)$skipList[$video['name']] : 0;
                $results[] = [
                    'index'   => $i,
                    'file'    => $video['name'],
                    'path'    => $video['path'],
                    'status'  => 'skipped',
                    'tmdb_id' => $skipTmdbId
                ];
                continue;
            }

            // EARLY SKIP: si ya está en BD y skipIndexed activo, saltar TMDB
            $isAlreadyIndexed = in_array($video['path'], $existingFiles);
            if ($skipIndexed && $isAlreadyIndexed) {
                echo json_encode(['type' => 'progress', 'current' => $i+1, 'total' => count($videos), 'file' => $video['name'], 'msg' => 'OK (ya indexado)']) . "\n";
                ob_flush(); flush();
                continue;
            }

            if ($seriesPath) {
                $parsed = parseFileName($video['name']);
                $parsed['title']      = $seriesPath['series'];
                $parsed['season']     = $seriesPath['season'];
                $parsed['episode']    = $seriesPath['episode'] ?: $parsed['episode'];
                $parsed['is_episode'] = $parsed['episode'] > 0;
            } else {
                $parsed = parseFileName($video['name']);
            }
            $searchQuery = $parsed['title'];
            $searchYear  = $parsed['year'];
            $isEpisode   = $parsed['is_episode'];

            // Si es episodio, buscar como serie directamente
            if ($isEpisode) {
                $result = tmdbSearch($searchQuery, $searchYear, 'tv');
                $tipo = 'series';
            } else {
                $result = tmdbSearch($searchQuery, $searchYear, 'movie');
                $tipo = 'movie';
                if (!$result) {
                    $result = tmdbSearch($searchQuery, $searchYear, 'tv');
                    if ($result) $tipo = 'series';
                }
            }

            $confident = isConfidentMatch($searchQuery, $searchYear, $result);
            list($confident, $safetyMsg) = applySafetyNet($confident, $searchQuery, $result);

            if (!$confident) {
                $embedded = getEmbeddedMetadata($video['path']);
                if (!empty($embedded['title'])) {
                    $result2 = tmdbSearch($embedded['title'], intval($embedded['year'] ?? 0), 'movie');
                    $tipo2 = 'movie';
                    if (!$result2) {
                        $result2 = tmdbSearch($embedded['title'], intval($embedded['year'] ?? 0), 'tv');
                        if ($result2) $tipo2 = 'series';
                    }
                    $confident2 = isConfidentMatch($embedded['title'], intval($embedded['year'] ?? 0), $result2);
                    list($confident2, $_) = applySafetyNet($confident2, $embedded['title'], $result2);
                    if ($confident2) {
                        $result = $result2;
                        $tipo = $tipo2;
                        $confident = true;
                    }
                }
            }

            $tmdbTitle = $result['title'] ?? $result['name'] ?? '';
            $tmdbYear = 0;
            if ($result && ($f = $result['release_date'] ?? $result['first_air_date'] ?? null)) {
                $tmdbYear = (int)substr($f, 0, 4);
            }

            // Para episodios de serie, NO renombrar — preservar SxxExx y mostrar el título TMDB como info
            if ($isEpisode) {
                $proposedName = $video['name'];
                $episodeInfo  = sprintf('S%02dE%02d', $parsed['season'], $parsed['episode']);
            } else {
                $episodeInfo = null;
                $proposedTitle = ($confident && $result) ? $tmdbTitle : ($searchQuery ?: $tmdbTitle);
                $proposedYear  = ($confident && $result) ? $tmdbYear : $searchYear;
                $ext = strtolower(pathinfo($video['name'], PATHINFO_EXTENSION));
                $safeTitle = trim(preg_replace('/[<>:"\/\\|?*]/', '', $proposedTitle), " \t\n\r\0\x0B.");
                $yearPart = $proposedYear ? " ($proposedYear)" : '';
                $proposedName = $safeTitle . $yearPart . ($ext ? '.' . $ext : '');
            }

            $orphanedMatch = $result && $confident ? ($orphaned[$result['id']] ?? null) : null;
            $isAlreadyIndexed = false;
            $ec = $orphanedMatch ? null : ($existingContent[$video['name']] ?? null);

            $results[] = [
                'index'           => $i,
                'file'            => $video['name'],
                'path'            => $video['path'],
                'current_title'   => $searchQuery,
                'current_year'    => $searchYear,
                'tmdb_title'      => $ec ? $ec['titulo'] : $tmdbTitle,
                'tmdb_id'         => $ec ? (int)$ec['tmdb_id'] : ($result['id'] ?? 0),
                'tmdb_year'       => 0,
                'confidence'      => $ec ? true : $confident,
                'tipo'            => $ec ? ($ec['tipo'] ?: $tipo) : $tipo,
                'status'          => $orphanedMatch ? 'orphaned_rename' : (($result || $ec) ? 'ok' : 'no_match'),
                'already_indexed' => $isAlreadyIndexed,
                'proposed_name'   => $ec ? $video['name'] : $proposedName,
                'has_changed'     => $ec ? false : (($proposedName !== $video['name']) || (bool)$orphanedMatch),
                'safety_msg'      => $orphanedMatch ? "🔄 Renombre manual — se actualizarán metadatos de contenido existente (ID: $orphanedMatch)" : $safetyMsg,
                'is_episode'      => $isEpisode,
                'episode_info'    => $episodeInfo,
            ];

            $pMsg = $orphanedMatch ? 'Renombre manual' : ($ec ? 'OK' : ($result ? ($confident ? 'OK' : 'Baja confianza') : 'Sin match'));
            echo json_encode(['type' => 'progress', 'current' => $i+1, 'total' => count($videos), 'file' => $video['name'], 'msg' => $pMsg]) . "\n";
            ob_flush();
            flush();
            usleep(250000);
        }

        echo json_encode(['type' => 'result', 'status' => 'success', 'results' => $results], JSON_UNESCAPED_UNICODE) . "\n";
        ob_flush();
        flush();
        } catch (Throwable $e) {
            echo json_encode(['type' => 'error', 'message' => $e->getMessage()]) . "\n";
            ob_flush();
            flush();
        }
        exit;
    }

    // ── APPLY MODE (escribe DB + renombra solo índices aprobados) ──
    if ($action === 'apply') {
        ob_clean();
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json');
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $approved = $input['approve'] ?? [];
            $customTmdb = $input['custom_tmdb'] ?? [];
            $customNames = $input['custom_names'] ?? [];
            $filePaths = $input['file_paths'] ?? [];
            $response = runApply($pdo, $approved, $customTmdb, $customNames, $filePaths);
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[Apply ERROR] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            echo json_encode(['status' => 'error', 'message' => $e->getMessage() . ' (line ' . $e->getLine() . ')']);
        }
        exit;
    }

    // ── AUTO_SCAN MODE (full automático: escanea + indexa + stream progreso) ──
    if ($action === 'auto_scan') {
        set_time_limit(330);
        while (ob_get_level() > 0) ob_end_clean();
        ini_set('zlib.output_compression', 'Off');
        header('Content-Type: application/x-ndjson');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Content-Encoding: identity');
        ignore_user_abort(true);
        if (function_exists('apache_setenv')) apache_setenv('no-gzip', '1');
        ob_start();

        try {
            $videos = scanAllMedia(MEDIA_DIR);
            $total  = count($videos);
            $pad    = str_repeat(' ', 4096);

            echo json_encode(['type' => 'progress', 'current' => 0, 'total' => $total, 'file' => '', 'msg' => "Escaneando BUNKER... $total archivos"]) . $pad . "\n";
            ob_flush(); flush();

            $stmt = $pdo->query("SELECT archivo_path FROM peliculas_metadata WHERE archivo_path IS NOT NULL");
            $existingFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $stmt2 = $pdo->query("SELECT archivo_path FROM series_metadata WHERE archivo_path IS NOT NULL");
            $existingFiles = array_merge($existingFiles, $stmt2->fetchAll(PDO::FETCH_COLUMN));

            echo json_encode(['type' => 'progress', 'current' => 0, 'total' => $total, 'file' => '', 'msg' => 'Consultando base de datos...']) . "\n";
            ob_flush(); flush();

            // Orphaned index
            $orphaned = [];
            $oStmt = $pdo->query("SELECT c.id, c.tmdb_id, pm.archivo_path, pm.season, pm.episode FROM contenido c JOIN peliculas_metadata pm ON pm.contenido_id = c.id WHERE c.tmdb_id > 0 AND pm.archivo_path IS NOT NULL AND pm.archivo_path != ''");
            if ($oStmt) while ($o = $oStmt->fetch(PDO::FETCH_ASSOC)) {
                if (strpos($o['archivo_path'], 'gdrive:') === 0) continue;
                $o['archivo_path'] = realpath($o['archivo_path']) ?: $o['archivo_path'];
                if (!file_exists($o['archivo_path'])) {
                    $orphaned[$o['tmdb_id']] = ['id' => $o['id'], 'season' => $o['season'], 'episode' => $o['episode']];
                }
            }
            echo json_encode(['type' => 'progress', 'current' => 0, 'total' => $total, 'file' => '', 'msg' => 'Conectando con TMDB...']) . "\n";
            ob_flush(); flush();

            $ok = 0; $skip = 0; $omitidos = 0;

            foreach ($videos as $i => $video) {
                $basename = $video['name'];
                $path     = $video['path'];

                if (in_array($path, $existingFiles)) {
                    echo json_encode(['type' => 'progress', 'current' => $i + 1, 'total' => $total, 'file' => $basename, 'msg' => 'Ya indexado']) . "\n";
                    ob_flush(); flush();
                    $omitidos++;
                    continue;
                }

                $seriesPath = detectSeriesFromPath($path);
                if ($seriesPath) {
                    $parsed = parseFileName($basename);
                    $parsed['title']      = $seriesPath['series'];
                    $parsed['season']     = $seriesPath['season'];
                    $parsed['episode']    = $seriesPath['episode'] ?: $parsed['episode'];
                    $parsed['is_episode'] = $parsed['episode'] > 0;
                } else {
                    $parsed = parseFileName($basename);
                }
                $searchQuery = $parsed['title'];
                $searchYear  = $parsed['year'];
                $isEpisode   = $parsed['is_episode'];

                if ($isEpisode) {
                    $result = tmdbSearch($searchQuery, $searchYear, 'tv');
                    $tipo = 'series';
                } else {
                    $result = tmdbSearch($searchQuery, $searchYear, 'movie');
                    $tipo = 'movie';
                    if (!$result) {
                        $result = tmdbSearch($searchQuery, $searchYear, 'tv');
                        if ($result) $tipo = 'series';
                    }
                }

                $confident = isConfidentMatch($searchQuery, $searchYear, $result);
                list($confident, $safetyMsg) = applySafetyNet($confident, $searchQuery, $result);

                if (!$confident) {
                    $embedded = getEmbeddedMetadata($path);
                    if (!empty($embedded['title'])) {
                        $result2 = tmdbSearch($embedded['title'], intval($embedded['year'] ?? 0), 'movie');
                        $tipo2 = 'movie';
                        if (!$result2) {
                            $result2 = tmdbSearch($embedded['title'], intval($embedded['year'] ?? 0), 'tv');
                            if ($result2) $tipo2 = 'series';
                        }
                        $confident2 = isConfidentMatch($embedded['title'], intval($embedded['year'] ?? 0), $result2);
                        list($confident2, $_) = applySafetyNet($confident2, $embedded['title'], $result2);
                        if ($confident2) {
                            $result = $result2;
                            $tipo = $tipo2;
                            $confident = true;
                        }
                    }
                }

                // Orphaned: archivo renombrado manualmente
                $orphanedMatch = null;
                if ($result && $confident && isset($orphaned[$result['id']])) {
                    if ($isEpisode) {
                        foreach ($orphaned as $tid => $odata) {
                            if ($tid == $result['id'] && $odata['season'] == $parsed['season'] && $odata['episode'] == $parsed['episode']) {
                                $orphanedMatch = $odata['id'];
                                break;
                            }
                        }
                    } else {
                        $orphanedMatch = $orphaned[$result['id']]['id'] ?? null;
                    }
                }

                if ($orphanedMatch) {
                    $otitle = $result['title'] ?? $result['name'] ?? '?';
                    $oposter = !empty($result['poster_path']) ? TMDB_IMG . $result['poster_path'] : null;
                    $obackdrop = !empty($result['backdrop_path']) ? 'https://image.tmdb.org/t/p/original' . $result['backdrop_path'] : null;
                    $osinopsis = $result['overview'] ?? '';
                    $ofecha = $result['release_date'] ?? $result['first_air_date'] ?? null;
                    $orating = $result['vote_average'] ?? 0;
                    $pdo->prepare("UPDATE contenido SET titulo=?, sinopsis=?, poster_path=?, backdrop_path=?, fecha_estreno=?, puntuacion=? WHERE id=?")
                        ->execute([$otitle, $osinopsis, $oposter, $obackdrop, $ofecha, $orating, $orphanedMatch]);
                    $pdo->prepare("UPDATE peliculas_metadata SET archivo_path=? WHERE contenido_id=? AND season=? AND episode=?")
                        ->execute([$path, $orphanedMatch, $parsed['season'] ?: null, $parsed['episode'] ?: null]);
                    echo json_encode(['type' => 'progress', 'current' => $i + 1, 'total' => $total, 'file' => $basename, 'msg' => "Renombre: $otitle"]) . "\n";
                    ob_flush(); flush();
                    $ok++;
                } elseif ($result && $confident) {
                    $title = $result['title'] ?? $result['name'] ?? '?';
                    upsertContent($pdo, $result, $tipo, $path, $parsed['title'], $parsed['year'], true, $parsed['season'], $parsed['episode'], $video['file_size'] ?? 0);
                    echo json_encode(['type' => 'progress', 'current' => $i + 1, 'total' => $total, 'file' => $basename, 'msg' => "OK: $title"]) . "\n";
                    ob_flush(); flush();
                    $ok++;
                } elseif ($result && !$confident) {
                    $title = $result['title'] ?? $result['name'] ?? '?';
                    upsertContent($pdo, $result, $tipo, $path, $parsed['title'], $parsed['year'], false, $parsed['season'], $parsed['episode'], $video['file_size'] ?? 0);
                    echo json_encode(['type' => 'progress', 'current' => $i + 1, 'total' => $total, 'file' => $basename, 'msg' => 'Baja confianza']) . "\n";
                    ob_flush(); flush();
                    $ok++;
                } else {
                    echo json_encode(['type' => 'progress', 'current' => $i + 1, 'total' => $total, 'file' => $basename, 'msg' => 'Sin match']) . "\n";
                    ob_flush(); flush();
                    $skip++;
                }

                usleep(250000);
            }

            echo json_encode([
                'type' => 'result',
                'status' => 'success',
                'results' => ['ok' => $ok, 'skip' => $skip, 'omitidos' => $omitidos, 'total' => $total],
                'message' => "Indexados: $ok | Omitidos: $omitidos | Sin match: $skip"
            ]) . "\n";
            ob_flush(); flush();
        } catch (Throwable $e) {
            echo json_encode(['type' => 'result', 'status' => 'error', 'message' => $e->getMessage()]) . "\n";
            ob_flush(); flush();
        }
        exit;
    }
}

// ━━━ FULL MODE (existente — CLI o web sin action) ━━━━━
$log[] = "═══════════════════════════════════════════════";
$log[] = " GalixMovie Scrapper Inteligente v3.1";
$log[] = "═══════════════════════════════════════════════";
$log[] = "FFprobe: " . (FFPROBE ? "✅ Disponible (" . FFPROBE . ")" : "⚠️  No instalado (usando modo nombre)");
$log[] = "";

$videos = scanAllMedia(MEDIA_DIR);
$log[] = "Directorio: " . MEDIA_DIR;
$log[] = "Archivos encontrados: " . count($videos);

$stmt = $pdo->query("SELECT archivo_path FROM peliculas_metadata");
$existingFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
$log[] = "Archivos ya en catálogo: " . count($existingFiles);
$log[] = "";

// ── ORPHANED INDEX: registros cuyo archivo ya no existe en disco ──
$orphaned = [];
$oStmt = $pdo->query("SELECT c.id, c.tmdb_id, pm.archivo_path, pm.season, pm.episode FROM contenido c JOIN peliculas_metadata pm ON pm.contenido_id = c.id WHERE c.tmdb_id > 0 AND pm.archivo_path IS NOT NULL AND pm.archivo_path != ''");
if ($oStmt) while ($o = $oStmt->fetch(PDO::FETCH_ASSOC)) {
    // Archivos gdrive: nunca son huérfanos (son virtuales)
    if (strpos($o['archivo_path'], 'gdrive:') === 0) continue;
    $o['archivo_path'] = realpath($o['archivo_path']) ?: $o['archivo_path'];
    if (!file_exists($o['archivo_path'])) {
        $orphaned[$o['tmdb_id']] = ['id' => $o['id'], 'season' => $o['season'], 'episode' => $o['episode']];
    }
}
$log[] = "Huérfanos detectados (archivos renombrados/eliminados): " . count($orphaned);

$ok = 0; $skip = 0; $omitidos = 0; $sinMatch = [];

foreach ($videos as $video) {
    $log[] = "→ Archivo: {$video['name']}";

    if (in_array($video['path'], $existingFiles)) {
        $log[] = "  [OMITIDO ⏭️] Ya está en el catálogo. Se omitió para ahorrar tiempo.";
        $log[] = "";
        $omitidos++;
        continue;
    }

    // ── PASO 1: Detectar serie desde estructura de carpetas ──
    // Si el archivo está bajo media/series/[Serie]/[Temporada N]/, usar la carpeta como fuente de datos
    $seriesPath = detectSeriesFromPath($video['path']);
    if ($seriesPath) {
        $parsed = parseFileName($video['name']);
        $parsed['title']      = $seriesPath['series'];
        $parsed['season']     = $seriesPath['season'];
        $parsed['episode']    = $seriesPath['episode'] ?: $parsed['episode'];
        $parsed['is_episode'] = $parsed['episode'] > 0;
        $log[] = "  [SERIE 📁] Detectado desde carpeta: '{$seriesPath['series']}' Temporada {$seriesPath['season']}" . ($seriesPath['episode'] ? " Episodio {$seriesPath['episode']}" : "");
    } else {
        // ── PASO 1 (alternativo): Parsear nombre de archivo SIEMPRE (fuente principal) ──
        $parsed = parseFileName($video['name']);
    }
    $searchQuery = $parsed['title'];
    $searchYear  = $parsed['year'];
    $isEpisode   = $parsed['is_episode'];
    $log[] = "  [FILE] Nombre parseado: '$searchQuery'" . ($searchYear ? " (año: $searchYear)" : "") . ($isEpisode ? " [Episodio S{$parsed['season']}E{$parsed['episode']}]" : "");

    // ── PASO 2: Buscar TMDB con el nombre del archivo ─────────────────
    // Si es episodio, buscar como serie directamente
    if ($isEpisode) {
        $result = tmdbSearch($searchQuery, $searchYear, 'tv');
        $tipo = 'series';
    } else {
        $result = tmdbSearch($searchQuery, $searchYear, 'movie');
        $tipo = 'movie';
        if (!$result) {
            $result = tmdbSearch($searchQuery, $searchYear, 'tv');
            if ($result) $tipo = 'series';
        }
    }

    // ── PASO 3: Validar + Safety Net ──────────────────────────────────
    $confident = isConfidentMatch($searchQuery, $searchYear, $result);
    list($confident, $safetyMsg) = applySafetyNet($confident, $searchQuery, $result);
    if ($safetyMsg) $log[] = "  [🛡️ SAFETY] $safetyMsg";

    // ── PASO 4: Si NO es confiable, intentar con metadatos embebidos ──
    if (!$confident) {
        $embedded = getEmbeddedMetadata($video['path']);
        if (!empty($embedded['title'])) {
            $log[] = "  [META] Match no confiable con filename. Re-intentando con metadatos embebidos: '{$embedded['title']}'";
            $result2 = tmdbSearch($embedded['title'], intval($embedded['year'] ?? 0), 'movie');
            $tipo2 = 'movie';
            if (!$result2) {
                $result2 = tmdbSearch($embedded['title'], intval($embedded['year'] ?? 0), 'tv');
                if ($result2) $tipo2 = 'series';
            }
            $confident2 = isConfidentMatch($embedded['title'], intval($embedded['year'] ?? 0), $result2);
            list($confident2, $_) = applySafetyNet($confident2, $embedded['title'], $result2);
            if ($confident2) {
                $result = $result2;
                $tipo = $tipo2;
                $confident = true;
                $log[] = "  [META ✅] Match confiable con metadatos embebidos.";
            }
        }
    }

    // ── ORPHANED: archivo renombrado manualmente (actualizar existente) ──
    $orphanedMatch = null;
    if ($result && $confident && isset($orphaned[$result['id']])) {
        // Para series, buscar huérfano por season+episode específico
        if ($isEpisode) {
            foreach ($orphaned as $tid => $odata) {
                if ($tid == $result['id'] && $odata['season'] == $parsed['season'] && $odata['episode'] == $parsed['episode']) {
                    $orphanedMatch = $odata['id'];
                    break;
                }
            }
        } else {
            $orphanedMatch = $orphaned[$result['id']]['id'] ?? null;
        }
    }
    if ($orphanedMatch) {
        $otitle = $result['title'] ?? $result['name'] ?? '?';
        $oposter = !empty($result['poster_path']) ? TMDB_IMG . $result['poster_path'] : null;
        $obackdrop = !empty($result['backdrop_path']) ? 'https://image.tmdb.org/t/p/original' . $result['backdrop_path'] : null;
        $osinopsis = $result['overview'] ?? '';
        $ofecha = $result['release_date'] ?? $result['first_air_date'] ?? null;
        $orating = $result['vote_average'] ?? 0;
        $pdo->prepare("UPDATE contenido SET titulo=?, sinopsis=?, poster_path=?, backdrop_path=?, fecha_estreno=?, puntuacion=? WHERE id=?")
            ->execute([$otitle, $osinopsis, $oposter, $obackdrop, $ofecha, $orating, $orphanedMatch]);
        $pdo->prepare("UPDATE peliculas_metadata SET archivo_path=? WHERE contenido_id=? AND season=? AND episode=?")
            ->execute([$video['path'], $orphanedMatch, $parsed['season'] ?: null, $parsed['episode'] ?: null]);
        $log[] = "  [🔄 RENOMBRE MANUAL] '$otitle' (ID: $orphanedMatch) — metadatos actualizados";
        $ok++;
    } elseif ($result && $confident) {
        $title = $result['title'] ?? $result['name'] ?? '?';
        $id = upsertContent($pdo, $result, $tipo, $video['path'], $parsed['title'], $parsed['year'], true, $parsed['season'], $parsed['episode'], $video['file_size'] ?? 0);
        $log[] = "  [OK ✅] Indexado como: '$title'" . ($isEpisode ? " (S{$parsed['season']}E{$parsed['episode']})" : "") . " (TMDB confiable)";
        $ok++;
    } elseif ($result && !$confident) {
        $title = $result['title'] ?? $result['name'] ?? '?';
        $id = upsertContent($pdo, $result, $tipo, $video['path'], $parsed['title'], $parsed['year'], false, $parsed['season'], $parsed['episode'], $video['file_size'] ?? 0);
        $log[] = "  [⚠️ BAJA CONFIANZA] TMDB sugirió '$title' pero se usó nombre del archivo. DB ID: $id";
        $ok++;
    } else {
        $log[] = "  [SIN MATCH ⚠️] No se encontró coincidencia en TMDB.";
        $sinMatch[] = [
            'archivo'  => $video['name'],
            'buscado'  => $searchQuery,
            'path'     => $video['path']
        ];
        $skip++;
    }

    $log[] = "";
    usleep(250000);
}

$log[] = "═══════════════════════════════════════════════";
$log[] = " Completado: ✅ $ok nuevos | ⏭️  $omitidos omitidos | ⚠️  $skip sin match";
$log[] = "═══════════════════════════════════════════════";

if (!empty($sinMatch)) {
    $log[] = "";
    $log[] = "─── ARCHIVOS SIN COINCIDENCIA EN TMDB ────────";
    $log[] = "  Para cada archivo, tienes estas opciones:";
    $log[] = "";
    foreach ($sinMatch as $item) {
        $log[] = "  📁 Archivo:  {$item['archivo']}";
        $log[] = "  🔍 Búsqueda: '{$item['buscado']}' → Sin resultados";
        $log[] = "";
        $log[] = "  OPCIONES:";
        $log[] = "  1️⃣  Renombra el archivo al título exacto en inglés o español.";
        $log[] = "     Ejemplo: 'Face.Off.1997.mp4' → 'Face Off.mp4'";
        $log[] = "  2️⃣  Instala FFmpeg para leer metadatos embebidos automáticamente.";
        $log[] = "     Comando en Termux: pkg install ffmpeg";
        $log[] = "  3️⃣  Usa el panel Admin para indexar manualmente con el ID de TMDB.";
        $log[] = "─────────────────────────────────────────────";
        $log[] = "";
    }
}

$log[] = "";
$log[] = "💡 Los archivos marcados como 'BAJA CONFIANZA' se indexaron con el";
$log[] = "   nombre del archivo original como título. TMDB encontró una posible";
$log[] = "   coincidencia pero no fue lo suficientemente confiable.";
$log[] = "   Si el título es incorrecto, edítalo manualmente en el panel Admin.";

$output = implode("\n", $log);

if ($isWeb) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'output' => $output, 'sin_match' => $sinMatch]);
} else {
    echo $output . "\n";
}
?>
