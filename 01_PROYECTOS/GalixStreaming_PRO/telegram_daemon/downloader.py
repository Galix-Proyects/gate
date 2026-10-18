import asyncio
import hashlib
import json
import logging
import os
import re
import subprocess
import threading
import time
import unicodedata
import urllib.request
import urllib.parse

from telethon.errors import FloodWaitError
from FastTelethon import download_file as fast_download_file, download_range

logger = logging.getLogger('GalixTelegramDaemon.Downloader')

class Downloader:
    def __init__(self, db, config_path=None):
        self.db = db
        if config_path is None:
            config_path = os.path.join(os.path.dirname(__file__), 'config.json')
        with open(config_path) as f:
            cfg = json.load(f)
        self.download_path = cfg['download_path']
        self.bunker_root = os.path.dirname(self.download_path)
        self.video_extensions = cfg.get('video_extensions', ['.mp4', '.mkv', '.avi', '.mov', '.webm', '.m4v'])
        self.max_concurrent = cfg.get('max_concurrent_downloads', 3)
        self.max_file_size = cfg.get('max_file_size_mb', 2048) * 1024 * 1024
        self.semaphore = asyncio.Semaphore(self.max_concurrent)
        self.active_downloads = {}
        self.cancelled_downloads = set()
        self._bg_tasks = set()
        self.tmdb_api_key = cfg.get('tmdb_api_key', '')
        self.tmdb_lang = cfg.get('tmdb_lang', 'es-MX')
        # 🚫 Override DURO: post-procesado automático desactivado por decisión del operador.
        # El transcode/remux se hace MANUAL desde el Admin de GalixMovie. Se ignora el config.
        self.post_process = 'none'
        self.auto_scrape = cfg.get('auto_scrape', True)
        self.scrapper_path = cfg.get('scrapper_path', '')
        self.scrapper_debounce = cfg.get('scrapper_debounce_seconds', 25)
        self.web_url = (cfg.get('web_url') or '').rstrip('/')
        self._scrapper_lock = threading.Lock()
        self._scrapper_running = False
        self._scrapper_pending = False
        self._scrapper_timer = None
        self._scrapper_paths = set()
        # Cache de verificaciones Roku (boton "Verificar Roku"): {clave: (ts, resultado)}
        self._roku_cache = {}
        self._roku_cache_ttl = cfg.get('roku_cache_seconds', 600)
        # Serializa los sondeos Roku: un unico GetFileRequest-stream a la vez.
        # Sin esto, pulsar 📺 muchas veces genera una tormenta de GetFileRequest
        # -> flood-wait -> reconexiones/caida del cliente de Telegram.
        self._roku_lock = asyncio.Lock()
        # Bot de NOTIFICACIONES (avisos/botones). Credenciales desde 00_SISTEMA/.env
        self.tg_bot_token = ''
        self.tg_chat_id = ''
        self.pending_file = ''
        self._load_notify_env()

    def _load_notify_env(self):
        """Lee el bot de notificaciones desde 00_SISTEMA/.env (no el bot del agente IA)."""
        home = os.environ.get('HOME', '/data/data/com.termux/files/home')
        env_path = os.path.join(home, 'Proyectos', '00_SISTEMA', '.env')
        vals = {}
        try:
            with open(env_path) as f:
                for line in f:
                    line = line.strip()
                    if not line or line.startswith('#') or '=' not in line:
                        continue
                    k, v = line.split('=', 1)
                    vals[k.strip()] = v.strip().strip('"').strip("'")
        except Exception as e:
            logger.warning('No se pudo leer .env para notificaciones: %s', e)
            return
        self.tg_bot_token = vals.get('TELEGRAM_BOT_TOKEN', '')
        self.tg_chat_id = vals.get('TELEGRAM_ADMIN_ID') or vals.get('TELEGRAM_CHAT_ID') or ''
        self.pending_file = os.path.join(
            home, 'Proyectos', '00_SISTEMA', '_Poderes', 'TELEMETRY', 'telegram_pending.json')

    def _tg_api(self, method, payload):
        if not self.tg_bot_token:
            return None
        url = f'https://api.telegram.org/bot{self.tg_bot_token}/{method}'
        data = json.dumps(payload).encode('utf-8')
        req = urllib.request.Request(url, data=data, headers={'Content-Type': 'application/json'})
        try:
            with urllib.request.urlopen(req, timeout=20) as r:
                return json.loads(r.read().decode('utf-8'))
        except Exception as e:
            logger.warning('Telegram API %s falló: %s', method, e)
            return None

    def _queue_pending_entry(self, entry):
        """Registra una descarga pendiente de indexar en telegram_pending.json."""
        path = getattr(self, 'pending_file', '')
        if not path:
            return
        data = {'entries': []}
        try:
            if os.path.isfile(path):
                with open(path) as f:
                    j = json.load(f)
                if isinstance(j, dict) and isinstance(j.get('entries'), list):
                    data = j
        except Exception:
            pass
        # Purgar entradas cuyo archivo ya no existe
        data['entries'] = [e for e in data['entries'] if e.get('path') and os.path.isfile(e['path'])]
        data['entries'].append(entry)
        data['updated'] = time.strftime('%Y-%m-%dT%H:%M:%S')
        try:
            os.makedirs(os.path.dirname(path), exist_ok=True)
            with open(path, 'w') as f:
                json.dump(data, f, ensure_ascii=False)
        except Exception as e:
            logger.warning('No se pudo escribir pending: %s', e)

    def _tmdb_search_url(self, filename):
        """URL a la búsqueda de TMDB con el nombre del archivo precargado."""
        import re, urllib.parse
        name = os.path.splitext(os.path.basename(filename))[0]
        name = re.sub(r'[._]+', ' ', name)
        name = re.sub(r'\b(19|20)\d{2}\b', ' ', name)
        name = re.sub(r'\b(1080p|720p|2160p|4k|bluray|blu-?ray|brrip|bdrip|webrip|web-?dl|hdrip|dvdrip|x264|x265|h264|h265|hevc|av1|aac|ac3|dts|dual|latino|castellano|spanish|english|español|subs?|multi)\b', ' ', name, flags=re.I)
        name = re.sub(r'[\(\)\[\]\{\}]', ' ', name)
        name = re.sub(r'\s+', ' ', name).strip()
        if not name:
            name = os.path.splitext(os.path.basename(filename))[0]
        return 'https://www.themoviedb.org/search?query=' + urllib.parse.quote(name)

    def _send_index_button(self, path, download_id, filename, size):
        """Envía a Telegram un aviso con botón para indexar el archivo recién descargado."""
        if not self.tg_bot_token or not self.tg_chat_id:
            logger.warning('Sin credenciales Telegram; no se envía botón de indexado')
            return
        folder = os.path.basename(os.path.dirname(path))
        text = ('🎬 Nueva descarga lista para indexar\n'
                f'📄 {filename}\n'
                f'📦 {self._format_size(size)} · {folder}\n\n'
                f'🔎 TMDB: {self._tmdb_search_url(filename)}\n\n'
                '¿Indexar en GalixMovie (Roku)?')
        token = str(download_id)
        kb_rows = [
            [
                {'text': '✅ Indexar', 'callback_data': f'ix|go|{token}'},
                {'text': '✏️ Cambiar nombre/TMDB', 'callback_data': f'ix|ed|{token}'},
            ],
            [
                {'text': '🔎 Buscar en TMDB', 'url': self._tmdb_search_url(filename)},
            ],
        ]
        if self.web_url:
            kb_rows.append([
                {'text': '🌐 Abrir en la web', 'url': f'{self.web_url}/?tab=queue&dl={download_id}'},
            ])
        kb_rows.append([
            {'text': '⏭️ Ignorar', 'callback_data': f'ix|sk|{token}'},
        ])
        kb = {'inline_keyboard': kb_rows}
        res = self._tg_api('sendMessage', {
            'chat_id': self.tg_chat_id,
            'text': text,
            'reply_markup': kb,
        })
        mid = 0
        if isinstance(res, dict) and res.get('ok'):
            try:
                mid = int(res.get('result', {}).get('message_id', 0))
            except Exception:
                mid = 0
            try:
                self.db.mark_index_notified(download_id)
            except Exception:
                pass
        else:
            # No se pudo enviar: no marcar como notificado (se reintentará al arrancar)
            return
        self._queue_pending_entry({
            'message_id': mid,
            'chat_id': self.tg_chat_id,
            'token': token,
            'path': path,
            'filename': filename,
            'download_id': download_id,
            'size': size,
            'mode': 'await',
            'contenido_id': 0,
            'created': int(time.time()),
        })
        logger.info('Botón de indexado enviado (mid=%s) para %s', mid, filename)

    async def resend_missing_index_notifications(self):
        """Red de seguridad: al arrancar, reenvía el aviso de indexado de descargas
        completadas que se quedaron sin notificar (p. ej. por un reinicio del daemon)."""
        if self.auto_scrape:
            return
        try:
            rows = self.db.get_unnotified_completed_downloads()
        except Exception as e:
            logger.warning('No se pudieron leer descargas sin notificar: %s', e)
            return
        if not rows:
            return
        for row in rows:
            dl_id = row.get('id')
            path = row.get('final_path')
            if not path or not os.path.exists(path) or self.db.is_indexed_in_catalog(path):
                # Archivo ya indexado/renombrado o inexistente: marcar y no reenviar
                try:
                    self.db.mark_index_notified(dl_id)
                except Exception:
                    pass
                continue
            size = 0
            try:
                size = os.path.getsize(path)
            except Exception:
                pass
            try:
                await asyncio.to_thread(
                    self._send_index_button, path, dl_id,
                    os.path.basename(path), size)
                logger.info('Aviso de indexado reenviado para descarga %s', dl_id)
            except Exception as e:
                logger.warning('Reenvío de aviso de indexado falló para %s: %s', dl_id, e)

    def _queue_scrapper_path(self, path):
        """Encola la ruta de un archivo recién descargado para indexarlo puntualmente."""
        if not path:
            return
        with self._scrapper_lock:
            self._scrapper_paths.add(path)
        self._trigger_scrapper()

    def _roku_check(self, path):
        """Roku-Ready: si el archivo recién descargado es incompatible con Roku
        (no MP4/H264/AAC), avisa por Telegram con botón y lo encola para recodificar
        en la ventana nocturna. NO transcodea aquí (eso lo hace el daemon de noche)."""
        try:
            home = os.environ.get('HOME', '/data/data/com.termux/files/home')
            script = os.path.join(home, 'Proyectos', '00_SISTEMA', '_Poderes', 'roku_alert.sh')
            if not path or not os.path.isfile(path) or not os.path.isfile(script):
                return
            env = os.environ.copy()
            env['HOME'] = home
            subprocess.Popen(['bash', script, path], stdout=subprocess.DEVNULL,
                             stderr=subprocess.DEVNULL, stdin=subprocess.DEVNULL,
                             env=env, start_new_session=True)
        except Exception as e:
            logger.warning('roku_check falló: %s', e)

    def _trigger_scrapper(self):
        """Programa el scrapper de GalixMovie tras completar descargas (con debounce)."""
        if not self.auto_scrape or not self.scrapper_path:
            return
        if not os.path.exists(self.scrapper_path):
            logger.warning('Scrapper no encontrado: %s', self.scrapper_path)
            return
        with self._scrapper_lock:
            if self._scrapper_timer is not None:
                self._scrapper_timer.cancel()
            self._scrapper_timer = threading.Timer(self.scrapper_debounce, self._run_scrapper)
            self._scrapper_timer.daemon = True
            self._scrapper_timer.start()
        logger.info('Scrapper programado en %ss (nuevo contenido descargado)', self.scrapper_debounce)

    def _scrapper_proc_running(self):
        """True si ya hay un PHP corriendo el scrapper (p.ej. lanzado a mano desde el admin).

        Patron preciso 'php...scrapper.php' para no confundir shells que solo mencionan
        la ruta (p.ej. monitores con 'pgrep -f scrapper.php').
        """
        try:
            return subprocess.run(['pgrep', '-f', r'php.*scrapper\.php'],
                                  stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0
        except Exception:
            return False

    def _run_scrapper(self):
        """Ejecuta el scrapper de GalixMovie desacoplado, en baja prioridad y sin capturar salida en memoria.

        Se lanza en una sesion nueva (start_new_session) y con nice 19 para no poder
        tumbar ni asfixiar al daemon; la salida va a archivo, no a la RAM del daemon.
        """
        with self._scrapper_lock:
            if self._scrapper_running:
                self._scrapper_pending = True
                return
            self._scrapper_running = True
            paths = sorted(self._scrapper_paths)
            self._scrapper_paths = set()
        lock_path = os.path.join(os.path.dirname(__file__), 'scrapper.lock')
        log_path = os.path.join(os.path.dirname(__file__), 'scrapper.log')
        try:
            if not paths:
                return
            if self._scrapper_proc_running():
                logger.info('Scrapper: ya hay un escaneo en curso; se reintentará')
                with self._scrapper_lock:
                    self._scrapper_paths.update(paths)
                self._trigger_scrapper()
                return
            prefix = '/data/data/com.termux/files/usr'
            php = os.path.join(prefix, 'bin', 'php')
            if not os.path.exists(php):
                php = 'php'
            nice = os.path.join(prefix, 'bin', 'nice')
            cmd = ([nice, '-n', '19'] if os.path.exists(nice) else []) + [php, self.scrapper_path]
            env = dict(os.environ)
            env['HOME'] = env.get('HOME', '/data/data/com.termux/files/home')
            env['PREFIX'] = prefix
            env['PATH'] = prefix + '/bin:' + prefix + '/bin/applets:/system/bin:' + env.get('PATH', '')
            env['GALIX_ONLY_PATHS'] = '\n'.join(paths)
            with open(lock_path, 'w') as lf:
                lf.write(str(os.getpid()))
            logger.info('Scrapper: indexando %d archivo(s) nuevo(s) en GalixMovie (nice 19, desacoplado)...', len(paths))
            with open(log_path, 'a') as out:
                out.write('\n===== %s =====\n' % time.strftime('%Y-%m-%d %H:%M:%S'))
                out.flush()
                proc = subprocess.Popen(cmd, stdout=out, stderr=subprocess.STDOUT,
                                        stdin=subprocess.DEVNULL, env=env, start_new_session=True)
                rc = proc.wait()
            logger.info('Scrapper terminado (rc=%s) — log: %s', rc, log_path)
        except Exception as e:
            logger.error('Scrapper error: %s', e)
        finally:
            try:
                os.remove(lock_path)
            except OSError:
                pass
            with self._scrapper_lock:
                self._scrapper_running = False
                rerun = self._scrapper_pending
                self._scrapper_pending = False
            if rerun:
                self._trigger_scrapper()

    def post_process_remux(self, path):
        import subprocess
        if not os.path.exists(path):
            return
        try:
            probe = subprocess.run(
                ['ffprobe', '-v', 'error', '-show_entries', 'format=format_name',
                 '-of', 'default=noprint_wrappers=1:nokey=1', path],
                capture_output=True, text=True, timeout=30)
            fmt = probe.stdout.strip().lower() if probe.returncode == 0 else ''
            vprobe = subprocess.run(
                ['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=codec_name',
                 '-of', 'default=noprint_wrappers=1:nokey=1', path],
                capture_output=True, text=True, timeout=30)
            vcodec = vprobe.stdout.strip().lower() if vprobe.returncode == 0 else ''
        except Exception as e:
            logger.warning('Post-process: ffprobe falló para %s: %s', path, e)
            return
        is_mkv = 'matroska' in fmt or 'webm' in fmt
        browser_ok = {'h264', 'vp8', 'vp9', 'av1'}
        need_video = (vcodec == 'hevc') or (
            self.post_process == 'transcode' and vcodec != '' and vcodec not in browser_ok)
        if not is_mkv and not need_video:
            return
        tmp = path + '.remux.tmp'
        try:
            if os.path.exists(tmp):
                os.remove(tmp)
            if need_video:
                cmd = ['ffmpeg', '-y', '-loglevel', 'error', '-i', path,
                       '-map', '0:v:0', '-map', '0:a:0',
                       '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21',
                       '-c:a', 'copy', '-movflags', '+faststart', '-f', 'mp4', tmp]
            else:
                cmd = ['ffmpeg', '-y', '-loglevel', 'error', '-i', path,
                       '-map', '0:v:0', '-map', '0:a:0', '-c', 'copy',
                       '-movflags', '+faststart', '-f', 'mp4', tmp]
            r = subprocess.run(cmd, capture_output=True, text=True, timeout=3600)
            if r.returncode == 0 and os.path.exists(tmp) and os.path.getsize(tmp) > 0:
                probe2 = subprocess.run(
                    ['ffprobe', '-v', 'error', '-show_entries', 'format=format_name',
                     '-of', 'default=noprint_wrappers=1:nokey=1', tmp],
                    capture_output=True, text=True, timeout=30)
                if 'mp4' in probe2.stdout.lower():
                    os.rename(tmp, path)
                    logger.info('Post-process: %s aplicado a %s',
                                'transcode' if need_video else 'remux', os.path.basename(path))
                else:
                    os.remove(tmp)
                    logger.warning('Post-process: salida no MP4, descartada para %s', os.path.basename(path))
            else:
                if os.path.exists(tmp):
                    os.remove(tmp)
                logger.warning('Post-process: ffmpeg rc=%s para %s: %s', r.returncode, os.path.basename(path), r.stderr[-300:])
        except Exception as e:
            if os.path.exists(tmp):
                try:
                    os.remove(tmp)
                except Exception:
                    pass
            logger.warning('Post-process: error en %s: %s', os.path.basename(path), e)

    def sanitize_filename(self, name):
        name = re.sub(r'[\\/*?:"<>|]', '', name)
        name = name.strip()[:200]
        return name or 'unknown'

    def _normalize_title(self, name):
        name = os.path.splitext(name)[0]
        name = unicodedata.normalize('NFKD', name)
        name = name.encode('ascii', 'ignore').decode('ascii')
        name = re.sub(r'[^\w\s\-\.\(\)]', ' ', name)
        name = re.sub(r'\s+', ' ', name).strip()
        return name

    def _normalize_for_match(self, s):
        return re.sub(r'[^a-z0-9]', '', s.lower())

    def _http_get_json(self, url, timeout=6):
        req = urllib.request.Request(url, headers={'User-Agent': 'GalixStreaming-PRO/1.0'})
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return json.loads(resp.read().decode('utf-8'))

    def _pick_tmdb_result(self, results, query, year=None, prefer_tv=False):
        query_norm = self._normalize_for_match(query)
        query_words = {w for w in re.findall(r'[a-z0-9]{3,}', query.lower())}
        best_score = 0
        best_poster = None
        for item in results:
            if item.get('media_type') not in ('movie', 'tv'):
                continue
            poster_path = item.get('poster_path')
            if not poster_path:
                continue
            name = item.get('name') or item.get('title') or ''
            name_norm = self._normalize_for_match(name)
            item_year = str(item.get('first_air_date') or item.get('release_date') or '')[:4]
            score = 0
            if query_norm and name_norm == query_norm:
                score += 20
            elif query_norm and (name_norm in query_norm or query_norm in name_norm):
                score += 12
            if score < 12 and query_words:
                name_words = {w for w in re.findall(r'[a-z0-9]{3,}', name.lower())}
                common = query_words & name_words
                if common and len(common) >= max(2, len(query_words) // 2):
                    score += 8
            if year and item_year == year:
                score += 8
            if prefer_tv and item.get('media_type') == 'tv':
                score += 1
            if score > best_score:
                best_score = score
                best_poster = poster_path
        return best_poster if best_score >= 8 else None

    def _tmdb_search_poster(self, query, year=None, lang=None, prefer_tv=False):
        lang = lang or self.tmdb_lang
        api_base = 'https://api.themoviedb.org/3/'
        for ep in ['search/multi', 'search/movie', 'search/tv']:
            url = (api_base + ep + '?api_key=' + urllib.parse.quote(self.tmdb_api_key) +
                   '&query=' + urllib.parse.quote(query) + '&language=' + urllib.parse.quote(lang) +
                   '&include_adult=false')
            data = self._http_get_json(url)
            poster = self._pick_tmdb_result(data.get('results', []), query, year, prefer_tv)
            if poster:
                return poster
        return None

    def _wikipedia_en_title(self, title):
        try:
            search_url = ('https://es.wikipedia.org/w/api.php?action=query&format=json&list=search&srlimit=1&srsearch='
                          + urllib.parse.quote(title))
            data = self._http_get_json(search_url)
            results = data.get('query', {}).get('search', [])
            if not results:
                return None
            page_title = results[0]['title']
            lang_url = ('https://es.wikipedia.org/w/api.php?action=query&format=json&prop=langlinks&lllang=en&lllimit=1&titles='
                        + urllib.parse.quote(page_title))
            data = self._http_get_json(lang_url)
            for page in data.get('query', {}).get('pages', {}).values():
                links = page.get('langlinks') or []
                if links:
                    return links[0].get('*')
            return None
        except Exception:
            return None

    def _resolve_tmdb_poster(self, filename):
        if not self.tmdb_api_key:
            return None
        try:
            clean = self._normalize_title(filename)
            if not clean:
                return None
            year_match = re.search(r'(?:\((\d{4})\)|(?:^|\s)(\d{4})(?:\s|$))', clean)
            year = (year_match.group(1) or year_match.group(2)) if year_match else None
            query = re.sub(r'[\(\)]', ' ', clean)
            query = re.sub(r'\s{2,}', ' ', query).strip()
            is_series = bool(re.search(
                r'(?:S\d{1,3}\s*E\d{1,3}|S\d{1,3}|E\d{1,3}|\d{1,2}\s*x\s*\d{1,3}'
                r'|(?:Temporada|Temp\.?|Season|Capitulo|Cap\.?|Episodio|Episode|Ep\.?)\s*\d{1,4})',
                query, flags=re.IGNORECASE))
            tag_pattern = r'\b(?:DUAL|MULTI|LATINO|SUB|VOSE|VOS|COMPLETA|COMPLETE|4K|UHD|HDR|WEB|WEBRIP|WEB-DL|BLURAY|BRRIP|REMUX|1080p|720p|2160p)\b'
            query = re.sub(tag_pattern, ' ', query, flags=re.IGNORECASE)
            query = re.sub(r'\bS\d{1,3}(?:\s*E\d{1,4})+\b', ' ', query, flags=re.IGNORECASE)
            query = re.sub(r'\bS\d{1,3}\s*E\d{1,3}(?:\s*-\s*S?\d{1,3}\s*E?\d{1,3})?\b', ' ', query, flags=re.IGNORECASE)
            query = re.sub(r'\bS\d{1,3}\s*E?\d{1,3}\b', ' ', query, flags=re.IGNORECASE)
            query = re.sub(r'\b\d{1,2}\s*x\s*\d{1,3}(?:\s*-\s*\d{1,2}\s*x?\s*\d{1,3})?\b', ' ', query)
            query = re.sub(r'\b(?:Temporada|Temp\.?|Season|Capitulo|Cap\.?|Episodio|Episode|Ep\.?)\s*\d{1,4}\b', ' ', query, flags=re.IGNORECASE)
            query = re.sub(r'\bEP?\d{1,4}\b', ' ', query, flags=re.IGNORECASE)
            query = re.sub(r'\bE\d{1,4}\b', ' ', query, flags=re.IGNORECASE)
            query = re.sub(r'\(?\b\d{4}\b\)?', ' ', query)
            query = re.sub(r'\s{2,}', ' ', query).strip()
            if not query:
                return None
            poster = self._tmdb_search_poster(query, year, prefer_tv=is_series)
            if poster:
                return 'https://image.tmdb.org/t/p/w342' + poster
            en_title = self._wikipedia_en_title(query)
            if en_title and self._normalize_for_match(en_title) != self._normalize_for_match(query):
                poster = self._tmdb_search_poster(en_title, year=None, lang='en-US', prefer_tv=is_series)
                if poster:
                    return 'https://image.tmdb.org/t/p/w342' + poster
            return None
        except Exception:
            return None

    def is_video(self, message):
        if message.video:
            return True
        if message.document:
            mime = getattr(message.document, 'mime_type', '') or ''
            ext = os.path.splitext(getattr(message.document, 'attributes', [None])[0].file_name if
                         hasattr(message.document, 'attributes') and message.document.attributes
                         and hasattr(message.document.attributes[0], 'file_name') else '')[1].lower()
            if ext in self.video_extensions:
                return True
            if mime.startswith('video/'):
                return True
            if any(e in mime for e in ['mp4', 'mkv', 'avi', 'webm']):
                return True
        return False

    def get_file_size(self, message):
        if message.video:
            return getattr(message.video, 'size', 0)
        if message.document:
            return getattr(message.document, 'size', 0)
        return 0

    def get_message_text(self, message):
        text = (message.message or '').strip()
        if not text and hasattr(message, 'media') and message.media:
            if hasattr(message.media, 'caption'):
                text = (message.media.caption or '').strip()
        return text

    def get_file_name(self, message):
        name = None
        if message.video and hasattr(message.video, 'attributes'):
            for attr in message.video.attributes:
                if hasattr(attr, 'file_name') and attr.file_name:
                    name = attr.file_name
                    break
        if not name and message.document and hasattr(message.document, 'attributes'):
            for attr in message.document.attributes:
                if hasattr(attr, 'file_name') and attr.file_name:
                    name = attr.file_name
                    break
        generic = name and re.match(r'^(video|telegram|file)_\d+', name, re.I)
        caption = self.get_message_text(message)
        if generic and caption:
            caption_line = caption.split('\n')[0][:100]
            base, ext = os.path.splitext(name) if name else ('', '.mp4')
            if not ext:
                ext = '.mp4'
            return f'{caption_line}{ext}'
        if not name and caption:
            caption_line = caption.split('\n')[0][:100]
            return f'{caption_line}.mp4'
        if name:
            return name
        if message.video and hasattr(message.video, 'id'):
            return f'video_{message.id}.mp4'
        return f'telegram_{message.id}.mp4'

    SPANISH_ENGLISH = {
        'odisea': 'odyssey', 'odiseo': 'odysseus',
        'leon': 'lion', 'leones': 'lions',
        'arana': 'spider',
        'perro': 'dog', 'gato': 'cat', 'lobo': 'wolf',
        'proyecto': 'project', 'proyectos': 'projects',
        'guerra': 'war', 'muerte': 'death', 'vida': 'life',
        'mundial': 'world',
        'rey': 'king', 'reina': 'queen', 'principe': 'prince', 'princesa': 'princess',
        'mundo': 'world', 'tierra': 'earth',
        'fuego': 'fire', 'agua': 'water', 'aire': 'air', 'sangre': 'blood',
        'noche': 'night', 'dia': 'day', 'sol': 'sun', 'luna': 'moon',
        'historia': 'story', 'cuento': 'tale', 'leyenda': 'legend',
        'gran': 'great', 'nuevo': 'new', 'viejo': 'old', 'nueva': 'new',
        'casa': 'house', 'hogar': 'home', 'castillo': 'castle',
        'camino': 'road', 'viaje': 'journey', 'aventura': 'adventure', 'aventuras': 'adventures',
        'secreto': 'secret', 'misterio': 'mystery',
        'final': 'end', 'principio': 'beginning', 'origen': 'origin',
        'corazon': 'heart', 'alma': 'soul', 'espiritu': 'spirit',
        'sueno': 'dream', 'pesadilla': 'nightmare',
        'cazador': 'hunter', 'guerrero': 'warrior', 'caballero': 'knight',
        'demonio': 'demon', 'angel': 'angel', 'dios': 'god', 'diosa': 'goddess',
        'infierno': 'hell', 'cielo': 'heaven', 'paraiso': 'paradise',
        'tiempo': 'time', 'espacio': 'space',
        'poder': 'power', 'fuerza': 'strength', 'voluntad': 'will',
        'sombra': 'shadow', 'luz': 'light', 'oscuridad': 'darkness',
        'oscuro': 'dark', 'oscura': 'dark', 'rojo': 'red', 'roja': 'red',
        'azul': 'blue', 'verde': 'green', 'negro': 'black', 'negra': 'black',
        'blanco': 'white', 'blanca': 'white', 'dorado': 'golden', 'plateado': 'silver',
        'temporada': 'season', 'capitulo': 'episode', 'episodio': 'episode',
        'pelicula': 'movie', 'peliculas': 'movies', 'serie': 'series',
        'coleccion': 'collection', 'saga': 'saga', 'completo': 'complete', 'completa': 'complete',
        'latino': 'latin', 'castellano': 'spanish', 'espanol': 'spanish',
        'subtitulado': 'subtitled', 'subtitulos': 'subtitles',
        'original': 'original', 'audio': 'audio',
        'estreno': 'premiere', 'estrenos': 'premieres', 'trailer': 'trailer',
        'teatro': 'theater', 'cine': 'cinema',
        'documental': 'documentary', 'documentales': 'documentaries',
        'animada': 'animated', 'animado': 'animated', 'animacion': 'animation',
        'infantil': 'children', 'familia': 'family', 'familiar': 'family',
        'accion': 'action', 'comedia': 'comedy', 'drama': 'drama',
        'terror': 'horror', 'suspenso': 'suspense',
        'ficcion': 'fiction', 'fantasia': 'fantasy', 'fantastico': 'fantastic',
        'musical': 'musical', 'romance': 'romance', 'thriller': 'thriller',
        'hombre': 'man', 'mujer': 'woman', 'nino': 'child', 'nina': 'child',
        'padre': 'father', 'madre': 'mother', 'hermano': 'brother', 'hermana': 'sister',
        'hijo': 'son', 'hija': 'daughter', 'amigo': 'friend', 'enemigo': 'enemy',
        'senor': 'lord', 'senora': 'lady', 'amo': 'master', 'esclavo': 'slave',
        'selva': 'jungle', 'bosque': 'forest', 'montana': 'mountain', 'mar': 'sea',
        'rio': 'river', 'lago': 'lake', 'ciudad': 'city', 'pueblo': 'village',
        'trono': 'throne', 'corona': 'crown', 'espada': 'sword', 'escudo': 'shield',
        'anillo': 'ring', 'libro': 'book', 'magia': 'magic', 'hechizo': 'spell',
        'profecia': 'prophecy', 'destino': 'destiny', 'salvacion': 'salvation',
        'resurreccion': 'resurrection', 'renacimiento': 'rebirth',
        'justicia': 'justice', 'venganza': 'revenge', 'traicion': 'betrayal',
        'mentira': 'lie', 'verdad': 'truth', 'libertad': 'freedom',
        'gloria': 'glory', 'honor': 'honor', 'valor': 'courage',
        'esperanza': 'hope', 'fe': 'faith', 'caridad': 'charity',
    }

    SPANISH_STOPWORDS = {
        'la', 'el', 'los', 'las', 'un', 'una', 'unos', 'unas',
        'de', 'del', 'y', 'e', 'o', 'a', 'en', 'por', 'para',
        'con', 'sin', 'su', 'sus', 'tu', 'mi', 'al', 'lo', 'le',
        'se', 'no', 'es', 'que', 'como', 'mas', 'pero', 'entre',
        'donde', 'cuando', 'este', 'esta', 'esto', 'eso', 'esa',
        'todo', 'toda', 'todos', 'todas', 'muy', 'asi', 'ya',
    }

    def _normalize_text(self, text):
        text = unicodedata.normalize('NFKD', text).encode('ascii', 'ignore').decode('ascii')
        return text.lower().strip()

    def _translate_query(self, text):
        normalized = self._normalize_text(text)
        words = normalized.split()
        keywords = [w for w in words if w not in self.SPANISH_STOPWORDS]
        if not keywords:
            return None
        translated = []
        for w in keywords:
            translated.append(self.SPANISH_ENGLISH.get(w, w))
        result = ' '.join(translated)
        return result if result != normalized else None

    async def download_message(self, client, message, source_title=None, custom_filename=None, custom_folder=None):
        if not self.is_video(message):
            return {'success': False, 'error': 'No es un video'}
        file_size = self.get_file_size(message)
        if file_size > self.max_file_size:
            return {'success': False, 'error': f'Archivo demasiado grande: {file_size / 1024 / 1024:.0f}MB > {self.max_file_size / 1024 / 1024:.0f}MB'}
        content_hash = hashlib.sha256(f'{message.chat_id}_{message.id}'.encode()).hexdigest()[:16]
        existing = self.db.get_download_by_hash(content_hash)
        if existing:
            status = existing.get('status')
            if status in ('pending', 'downloading'):
                return {'success': False, 'already': 'active', 'download_id': existing['id'],
                        'error': 'Este enlace ya está en cola o descargándose'}
            if status == 'completed':
                disk_path = existing.get('final_path') or os.path.join(self.download_path, existing['title'])
                if disk_path and os.path.exists(disk_path):
                    folder = os.path.dirname(disk_path)
                    return {'success': False, 'already': 'completed', 'download_id': existing['id'],
                            'error': f'Este enlace ya fue descargado como "{existing["title"]}" en {os.path.basename(folder)}'}
        prepared = await self._prepare_download(message, source_title, file_size, custom_filename,
                                                hash_suffix='_retry' if existing else None,
                                                custom_folder=custom_folder)
        if not prepared:
            return {'success': False, 'error': 'No se pudo preparar la descarga'}
        task = asyncio.create_task(self._download_worker(client, message, prepared))
        self._bg_tasks.add(task)
        task.add_done_callback(self._bg_tasks.discard)
        return {
            'success': True,
            'download_id': prepared['download_id'],
            'queued': True,
            'title': prepared['title']
        }

    def _resolve_dest_folder(self, folder):
        """Resuelve una carpeta relativa a BUNKER (o None para la predeterminada).
        Valida que quede dentro de BUNKER (anti path-traversal)."""
        if not folder:
            return self.download_path
        root = os.path.realpath(self.bunker_root)
        rel = str(folder).strip().lstrip('/')
        if not rel or '..' in rel.split('/'):
            return None
        candidate = os.path.join(root, rel)
        existing = candidate
        guard = 0
        while not os.path.exists(existing):
            parent = os.path.dirname(existing)
            if parent == existing or parent == root or guard > 32:
                break
            existing = parent
            guard += 1
        real = os.path.realpath(existing)
        if not real.startswith(root + os.sep):
            return None
        return candidate

    async def _prepare_download(self, message, source_title, file_size, custom_filename, hash_suffix=None, custom_folder=None):
        try:
            target = self._resolve_dest_folder(custom_folder)
            if target is None:
                logger.error('Carpeta destino inválida: %r', custom_folder)
                return None
            os.makedirs(target, exist_ok=True)
            if custom_filename:
                original_name = custom_filename
            else:
                original_name = self.get_file_name(message)
            clean_name = self.sanitize_filename(original_name)
            base, ext = os.path.splitext(clean_name)
            if not ext:
                ext = '.mp4'
                clean_name = base + ext
            final_path = os.path.join(target, clean_name)
            if os.path.exists(final_path):
                name_no_ext = os.path.splitext(clean_name)[0]
                final_path = os.path.join(target, f'{name_no_ext}_{message.id}{ext}')
            temp_path = final_path + '.tdd'
            message_id = message.id
            chat_id = message.chat_id
            content_hash = hashlib.sha256(f'{chat_id}_{message_id}'.encode()).hexdigest()[:16]
            if hash_suffix:
                content_hash = f'{content_hash}{hash_suffix}'
            title = source_title or clean_name
            poster = await asyncio.to_thread(self._resolve_tmdb_poster, clean_name) or ''
            download_id = self.db.insert_download(
                magnet=f'tg://{chat_id}/{message_id}',
                title=title,
                hash_val=content_hash,
                quality='',
                size_label=self._format_size(file_size),
                lang_tag='latino',
                poster=poster,
                status='pending',
                final_path=final_path
            )
            return {
                'download_id': download_id,
                'final_path': final_path,
                'temp_path': temp_path,
                'clean_name': clean_name,
                'message_id': message_id,
                'chat_id': chat_id,
                'title': title,
                'file_size': file_size,
            }
        except Exception as e:
            logger.error('Error preparando descarga: %s', e)
            return None

    async def _download_worker(self, client, message, prepared):
        try:
            async with self.semaphore:
                return await self._do_download(client, message, prepared)
        except Exception as e:
            logger.error('Error en worker de descarga %s: %s', prepared.get('title'), e)
            try:
                self.db.fail_download(prepared['download_id'])
            except Exception:
                pass

    async def _do_download(self, client, message, prepared):
        download_id = prepared['download_id']
        final_path = prepared['final_path']
        temp_path = prepared['temp_path']
        title = prepared['title']
        message_id = prepared['message_id']
        chat_id = prepared['chat_id']
        file_size = prepared['file_size']
        self.db.set_download_status(download_id, 'downloading')
        self.active_downloads[download_id] = {
            'message_id': message_id,
            'chat_id': chat_id,
            'title': title,
            'progress': 0,
            'downloaded': 0,
            'total': file_size,
            'speed': 0,
            'eta': 0,
            'elapsed': 0
        }

        if not hasattr(self, '_speed_track'):
            self._speed_track = {}

        start_time = time.time()
        last_progress_bytes = 0
        last_progress_time = time.time()
        _speed_time = 0
        _speed_bytes = 0
        _dl = self.active_downloads[download_id]

        def progress_callback(current, total):
            nonlocal last_progress_bytes, last_progress_time, _speed_time, _speed_bytes
            if download_id in self.cancelled_downloads:
                raise Exception('cancelado')
            now = time.time()
            if current == last_progress_bytes:
                if now - last_progress_time > 60:
                    raise Exception('estancado')
            else:
                last_progress_bytes = current
                last_progress_time = now

            _dl['downloaded'] = current
            _dl['total'] = total
            _dl['progress'] = round(current / total * 100, 1) if total > 0 else 0
            _dl['elapsed'] = now - start_time

            elapsed = now - _speed_time
            if elapsed >= 2.0:
                delta = current - _speed_bytes
                _dl['speed'] = delta / elapsed if elapsed > 0 else 0
                _dl['eta'] = ((total - current) / _dl['speed']) if _dl['speed'] > 0 else 0
                _speed_time = now
                _speed_bytes = current

        try:
            document = message.document or message.video
            with open(temp_path, 'wb') as f:
                await fast_download_file(client, document, f, progress_callback=progress_callback)
            if not os.path.exists(temp_path) or os.path.getsize(temp_path) == 0:
                raise Exception('Descarga fallida: archivo no encontrado')
            os.rename(temp_path, final_path)
            file_size_actual = os.path.getsize(final_path)
            self.db.insert_local_content(final_path, os.path.basename(final_path), file_size_actual)
            self.db.finish_download(download_id)
            # 🚫 POST-PROCESADO AUTOMÁTICO DESACTIVADO PERMANENTEMENTE.
            # El transcode/remux se realiza MANUALMENTE desde el Admin de GalixMovie.
            # NUNCA reactivar automático aquí (satura la Box Symmetry). No reintroducir esta llamada.
            # if self.post_process in ('remux', 'transcode'):
            #     await asyncio.to_thread(self.post_process_remux, final_path)
            elapsed = time.time() - start_time
            logger.info('Descargado: %s (%s en %.0fs)', os.path.basename(final_path),
                        self._format_size(file_size_actual), elapsed)
            self.active_downloads.pop(download_id, None)
            self.cancelled_downloads.discard(download_id)
            # Indexado del recién descargado:
            #  - auto_scrape=true  → indexar automáticamente (comportamiento viejo)
            #  - auto_scrape=false → avisar por Telegram con BOTÓN manual (nombre/TMDB a elección)
            if self.auto_scrape:
                self._queue_scrapper_path(final_path)
            else:
                try:
                    await asyncio.to_thread(
                        self._send_index_button, final_path, download_id,
                        os.path.basename(final_path), file_size_actual)
                except Exception as e:
                    logger.warning('Botón de indexado falló: %s', e)
            # Roku-Ready: verifica compatibilidad (MP4/H264/AAC) y encola si hace falta.
            self._roku_check(final_path)
            return {
                'success': True,
                'download_id': download_id,
                'filepath': final_path,
                'filename': os.path.basename(final_path),
                'size': file_size_actual,
                'elapsed': elapsed
            }
        except Exception as e:
            if os.path.exists(temp_path):
                os.remove(temp_path)
            self.active_downloads.pop(download_id, None)
            self.cancelled_downloads.discard(download_id)
            try:
                self.db.fail_download(download_id)
            except Exception:
                pass
            logger.error('Error descargando %s: %s', title, e)
            return {'success': False, 'error': str(e), 'download_id': download_id}

    async def download_by_link(self, client, link_text, source_title=None, custom_filename=None, custom_folder=None):
        from source_manager import SourceManager
        sm = SourceManager(self.db)
        parsed = sm.parse_telegram_link(link_text)
        if not parsed:
            return {'success': False, 'error': 'Enlace inválido'}
        if 'message_id' not in parsed or not parsed['message_id']:
            return {'success': False, 'error': 'El enlace debe incluir un message_id (ej: t.me/canal/123)'}
        entity = await sm.resolve_entity(client, parsed)
        if not entity:
            return {'success': False, 'error': 'No se pudo resolver la entidad'}
        try:
            message = await client.get_messages(entity, ids=parsed['message_id'])
            if not message:
                return {'success': False, 'error': 'Mensaje no encontrado'}
            return await self.download_message(client, message, source_title, custom_filename, custom_folder)
        except Exception as e:
            return {'success': False, 'error': str(e)}

    @staticmethod
    def _mp4_mdat_end(path, max_read=8 * 1024 * 1024):
        """Offset absoluto donde termina el primer box 'mdat' de un MP4, o None.
        Sirve para saber donde empieza el moov (tipicamente justo despues del mdat)
        y bajar solo esa cola en vez de un bloque fijo grande."""
        try:
            with open(path, 'rb') as f:
                data = f.read(max_read)
        except OSError:
            return None
        pos, n = 0, len(data)
        while pos + 8 <= n:
            sz = int.from_bytes(data[pos:pos + 4], 'big')
            typ = data[pos + 4:pos + 8]
            hdr = 8
            if sz == 1:
                if pos + 16 > n:
                    return None
                sz = int.from_bytes(data[pos + 8:pos + 16], 'big')
                hdr = 16
            if typ == b'moov':
                return None
            if typ == b'mdat':
                return pos + sz if sz > hdr else None
            if sz < hdr:
                return None
            pos += sz
        return None

    async def check_roku(self, client, link_text, head_bytes=3 * 1024 * 1024, tail_bytes=8 * 1024 * 1024):
        """Verifica si un video de Telegram sera compatible con Roku (MP4/H264/AAC)
        SIN descargarlo completo:
          1. Baja la CABEZA (~3 MB) y la sondea. Si es MP4 con moov al frente
             (faststart) o un contenedor con codecs al inicio (MKV/AVI) -> veredicto.
          2. Si el sondeo de cabeza es inconcluso (MP4 con moov al final) baja la
             COLA (~3 MB), arma un archivo SPARSE del tamano real (cabeza al frente,
             cola al final, el 'mdat' queda en hueco) y lo vuelve a sondear.
        Usa la regla oficial del ecosistema: _Poderes/roku_compat_check.sh.
        """
        from source_manager import SourceManager
        sm = SourceManager(self.db)
        parsed = sm.parse_telegram_link(link_text)
        if not parsed or not parsed.get('message_id'):
            return {'success': False, 'error': 'Enlace invalido (falta message_id)'}
        entity = await sm.resolve_entity(client, parsed)
        if not entity:
            return {'success': False, 'error': 'No se pudo resolver la entidad'}
        try:
            message = await client.get_messages(entity, ids=parsed['message_id'])
        except Exception as e:
            return {'success': False, 'error': f'No se pudo obtener el mensaje: {e}'}
        if not message:
            return {'success': False, 'error': 'Mensaje no encontrado'}
        if not self.is_video(message):
            return {'success': False, 'error': 'El mensaje no contiene un video'}

        cache_key = f'{message.chat_id}_{message.id}'
        cached = self._roku_cache.get(cache_key)
        if cached and (time.time() - cached[0]) < self._roku_cache_ttl:
            res = dict(cached[1])
            res['cached'] = True
            return res

        # Serializa los sondeos Roku: un unico GetFileRequest-stream a la vez.
        # Evita la tormenta de flood-wait que tumbaba la conexion de Telegram
        # cuando se pulsaba el boton 📺 muchas veces seguidas.
        async with self._roku_lock:
            # Otro request pudo resolverse mientras esperabamos el lock.
            cached = self._roku_cache.get(cache_key)
            if cached and (time.time() - cached[0]) < self._roku_cache_ttl:
                res = dict(cached[1])
                res['cached'] = True
                return res

            document = message.document or message.video
            size = self.get_file_size(message)
            title = self.get_file_name(message)
            home = os.environ.get('HOME', '/data/data/com.termux/files/home')
            script = os.path.join(home, 'Proyectos', '00_SISTEMA', '_Poderes', 'roku_compat_check.sh')
            if not os.path.isfile(script):
                return {'success': False, 'error': 'roku_compat_check.sh no encontrado'}

            tmp_dir = os.path.join(os.path.dirname(__file__), 'probe_tmp')
            try:
                os.makedirs(tmp_dir, exist_ok=True)
            except OSError:
                pass
            base = os.path.join(tmp_dir, f'probe_{message.chat_id}_{message.id}')
            head_path = base + '.head'
            sparse_path = base + '.sparse'
            probe_bytes = 0
            try:
                head_len = size if (size and size <= head_bytes + tail_bytes) else head_bytes
                with open(head_path, 'wb') as f:
                    probe_bytes += await download_range(client, document, 0, head_len, f)
                probe = await self._run_roku_probe(script, head_path)
                # Inconcluso + archivo grande -> traer la cola (donde suele estar el moov)
                # y armar un sparse del tamano real para re-sondear.
                if probe.get('compatible') == 'unknown' and size and size > head_len:
                    tail_off = self._mp4_mdat_end(head_path) or max(0, size - tail_bytes)
                    tail_off = min(tail_off, size)
                    tail_off -= tail_off % (1024 * 1024)
                    if tail_off > head_len:
                        with open(sparse_path, 'wb') as f:
                            f.truncate(size)
                            f.seek(0)
                            with open(head_path, 'rb') as h:
                                f.write(h.read())
                            probe_bytes += await download_range(client, document, tail_off, size - tail_off, f)
                        probe = await self._run_roku_probe(script, sparse_path)
                compat = probe.get('compatible')
                result = {
                    'success': True,
                    'compatible': compat is True,
                    'status': 'ok' if compat is True else ('unknown' if compat == 'unknown' else 'bad'),
                    'reasons': probe.get('reasons', []) or [],
                    'container': probe.get('container', '') or '',
                    'vcodec': probe.get('vcodec', '') or '',
                    'acodec': probe.get('acodec', '') or '',
                    'probe_bytes': probe_bytes,
                    'size': size,
                    'size_label': self._format_size(size),
                    'title': title,
                    'error': probe.get('error'),
                }
                if probe.get('compatible') in (True, False):
                    self._roku_cache[cache_key] = (time.time(), result)
                return result
            except Exception as e:
                return {'success': False, 'error': str(e)}
            finally:
                for p in (head_path, sparse_path):
                    try:
                        os.remove(p)
                    except OSError:
                        pass

    async def _run_roku_probe(self, script, path):
        """Ejecuta roku_compat_check.sh (bloqueante) en un hilo y devuelve su JSON."""
        home = os.environ.get('HOME', '/data/data/com.termux/files/home')
        env = os.environ.copy()
        env['HOME'] = home
        env['ROKU_PROBESIZE'] = '10000000'

        def _run():
            return subprocess.run(['bash', script, path], capture_output=True,
                                  text=True, timeout=90, env=env)

        proc = await asyncio.to_thread(_run)
        out = (proc.stdout or '').strip()
        line = out.splitlines()[-1] if out else ''
        try:
            return json.loads(line)
        except Exception:
            return {'compatible': 'unknown', 'error': 'probe_parse'}

    async def _get_entity(self, source):
        peer_username = source.get('peer_username') or source.get('username')
        peer_id = source.get('peer_id')
        if peer_username:
            return await self._client.get_entity(peer_username)
        elif peer_id:
            ids_to_try = [peer_id]
            if peer_id > 0:
                ids_to_try.append(int(f'-100{peer_id}'))
            for pid in ids_to_try:
                try:
                    return await self._client.get_entity(pid)
                except:
                    pass
        return None

    def _get_topic_id(self, source):
        return source.get('topic_id') if source.get('source_type') == 'forum_topic' else None

    async def scan_source(self, client, source, limit=100):
        self._client = client
        entity = None
        peer_username = source.get('peer_username') or source.get('username')
        peer_id = source.get('peer_id')
        try:
            if peer_username:
                entity = await client.get_entity(peer_username)
            elif peer_id:
                ids_to_try = [peer_id]
                if peer_id > 0:
                    ids_to_try.append(int(f'-100{peer_id}'))
                for pid in ids_to_try:
                    try:
                        entity = await client.get_entity(pid)
                        if entity:
                            break
                    except Exception:
                        continue
        except Exception as e:
            logger.warning('No se pudo resolver source %s: %s', source.get('title', source['id']), e)
            return []
        if not entity:
            return []
        last_id = source.get('last_message_id', 0)
        topic_id = self._get_topic_id(source)
        is_forum = topic_id is not None
        if last_id == 0 and not is_forum:
            return await self._backfill_source(client, source, entity, limit)
        # Paginacion incremental: recorre lotes si hay mas mensajes que el limite
        all_video_messages = []
        current_min_id = last_id
        max_pages = 5
        page = 0
        while page < max_pages:
            page += 1
            try:
                messages = await client.get_messages(entity, limit=limit, min_id=current_min_id)
            except Exception as e:
                logger.warning('Error obteniendo mensajes de %s: %s', source.get('title', source['id']), e)
                break
            if not messages:
                break
            video_messages = [m for m in messages if self.is_video(m)]
            if not is_forum and video_messages:
                self._index_videos(video_messages, entity, source)
            all_video_messages.extend(video_messages)
            if len(messages) < limit:
                break
            new_min = max(m.id for m in messages)
            if new_min <= current_min_id:
                break
            current_min_id = new_min
            await asyncio.sleep(1)
        if all_video_messages:
            new_last = max(m.id for m in all_video_messages)
            try:
                self.db.update_last_message(source['id'], new_last)
            except Exception as e:
                logger.warning('Error actualizando last_message de %s: %s', source.get('title', source['id']), e)
            logger.info('Source %s: %s nuevos videos (paginas=%s)',
                        source.get('title', source['id']), len(all_video_messages), page)
        return all_video_messages

    async def _backfill_source(self, client, source, entity, limit=50):
        logger.info('Backfill %s: escaneando historial completo...', source.get('title', source['id']))
        all_video_messages = []
        max_id = 0
        total_scanned = 0
        newest_id = 0
        while True:
            try:
                messages = await client.get_messages(entity, limit=limit, max_id=max_id)
            except FloodWaitError as e:
                logger.warning('Backfill flood wait %ss', e.seconds)
                await asyncio.sleep(e.seconds + 2)
                continue
            except Exception as e:
                logger.warning('Backfill error: %s', e)
                break
            if not messages:
                break
            video_messages = [m for m in messages if self.is_video(m)]
            all_video_messages.extend(video_messages)
            min_in_batch = min(m.id for m in messages)
            max_in_batch = max(m.id for m in messages)
            total_scanned += len(messages)
            if max_in_batch > newest_id:
                newest_id = max_in_batch
            if video_messages:
                self._index_videos(video_messages, entity, source)
            logger.info('Backfill %s: lote=%s, acum=%s mensajes, %s videos (hasta ID %s)',
                        source.get('title', source['id']), len(messages), total_scanned,
                        len(video_messages), max_in_batch)
            if min_in_batch <= 1:
                break
            max_id = min_in_batch - 1
            await asyncio.sleep(2)
        if newest_id:
            self.db.update_last_message(source['id'], newest_id)
        logger.info('Backfill %s completado: %s mensajes, %s videos totales (newest_id=%s)',
                    source.get('title', source['id']), total_scanned, len(all_video_messages), newest_id)
        return all_video_messages

    def _index_videos(self, video_messages, entity, source):
        peer_id = entity.id
        title = getattr(entity, 'title', source.get('title', 'Canal'))
        for m in video_messages:
            try:
                self.db.insert_forum_video(
                    peer_id=peer_id,
                    topic_id=0,
                    topic_title=title,
                    message_id=m.id,
                    filename=self.get_file_name(m),
                    caption=self.get_message_text(m) or None,
                    file_size=self.get_file_size(m),
                )
            except Exception as e:
                logger.warning('Error indexando video %s: %s', m.id, e)

    async def preview_source(self, client, source, limit=100, offset_id=0):
        peer_username = source.get('peer_username') or source.get('username')
        peer_id = source.get('peer_id')
        entity = None
        try:
            if peer_username:
                entity = await client.get_entity(peer_username)
            elif peer_id:
                ids_to_try = [peer_id]
                if peer_id > 0:
                    ids_to_try.append(int(f'-100{peer_id}'))
                for pid in ids_to_try:
                    try:
                        entity = await client.get_entity(pid)
                        if entity:
                            logger.info('Preview: entity resuelta con ID %s -> %s', pid, getattr(entity, 'title', 'N/A'))
                            break
                    except Exception as e2:
                        logger.debug('Preview: ID %s falló: %s', pid, e2)
        except Exception as e:
            logger.warning('Preview: no se pudo resolver source %s: %s', source.get('title', source['id']), e)
            return {'videos': [], 'has_more': False}
        if not entity:
            logger.warning('Preview: entity es None para source %s (peer_id=%s)', source.get('title', source['id']), peer_id)
            return {'videos': [], 'has_more': False}
        topic_id = self._get_topic_id(source)
        kwargs = {'limit': limit}
        if topic_id:
            kwargs['reply_to'] = topic_id
        if offset_id:
            kwargs['offset_id'] = offset_id
        try:
            messages = await client.get_messages(entity, **kwargs)
        except Exception as e:
            logger.warning('Preview: error obteniendo mensajes: %s', e)
            return {'videos': [], 'has_more': False}
        previews = []
        for m in messages:
            if self.is_video(m):
                previews.append({
                    'id': m.id,
                    'date': str(m.date),
                    'title': self.get_file_name(m),
                    'size': self._format_size(self.get_file_size(m)),
                    'has_video': bool(m.video),
                    'grouped_id': m.grouped_id if hasattr(m, 'grouped_id') else None
                })
        has_more = len(messages) >= limit
        logger.info('Preview: source %s, %s mensajes, %s videos (has_more=%s)', source.get('title', source['id']), len(messages), len(previews), has_more)
        return {'videos': previews, 'has_more': has_more}

    async def search_forum_videos(self, client, forum_source, query, mode='quick'):
        peer_id = forum_source.get('peer_id')
        ids_to_try = [peer_id]
        if peer_id > 0:
            ids_to_try.append(int(f'-100{peer_id}'))
        entity = None
        for pid in ids_to_try:
            try:
                entity = await client.get_entity(pid)
                if entity:
                    break
            except:
                pass
        if not entity:
            return []
        q = query.lower()
        results = []
        seen_ids = set()

        search_terms = [q]
        tr = self._translate_query(q)
        if tr and tr not in search_terms:
            search_terms.append(tr)

        from telethon.tl.functions.messages import GetForumTopicsRequest
        try:
            r = await asyncio.wait_for(
                client(GetForumTopicsRequest(entity, offset_date=None, offset_id=0, offset_topic=0, limit=100)),
                timeout=10
            )
            topics = r.topics
        except:
            return []

        topic_map = {t.id: (getattr(t, 'title', '') or '') for t in topics}

        def _topic_title(msg):
            try:
                rt = getattr(msg, 'reply_to', None)
                if rt:
                    rid = getattr(rt, 'reply_to_top_id', None) or getattr(rt, 'reply_to_msg_id', None)
                    return topic_map.get(rid, '')
            except:
                pass
            return ''

        for sq in search_terms:
            try:
                from telethon.tl.functions.messages import SearchRequest
                from telethon.tl.types import InputMessagesFilterDocument
                sr = await client(SearchRequest(
                    peer=entity, q=sq,
                    filter=InputMessagesFilterDocument(),
                    limit=30, add_offset=0, max_id=0, min_id=0, hash=0,
                    min_date=None, max_date=None, offset_id=0,
                ))
                for m in sr.messages:
                    if self.is_video(m) and m.id not in seen_ids:
                        seen_ids.add(m.id)
                        results.append({
                            'id': m.id,
                            'date': str(m.date),
                            'title': self.get_file_name(m),
                            'size': self._format_size(self.get_file_size(m)),
                            'topic_title': _topic_title(m),
                        })
            except Exception:
                pass

            try:
                smsgs = await client.search_messages(entity, query=sq, limit=20)
                for m in smsgs:
                    if self.is_video(m) and m.id not in seen_ids:
                        seen_ids.add(m.id)
                        results.append({
                            'id': m.id,
                            'date': str(m.date),
                            'title': self.get_file_name(m),
                            'size': self._format_size(self.get_file_size(m)),
                            'topic_title': _topic_title(m),
                        })
            except Exception:
                pass

        for t in topics:
            tname = (getattr(t, 'title', '') or '').lower()
            if any(term in tname for term in search_terms):
                try:
                    msgs = await asyncio.wait_for(
                        client.get_messages(entity, reply_to=t.id, limit=3),
                        timeout=15
                    )
                except:
                    continue
                for m in msgs:
                    if self.is_video(m) and m.id not in seen_ids:
                        seen_ids.add(m.id)
                        results.append({
                            'id': m.id,
                            'date': str(m.date),
                            'title': self.get_file_name(m),
                            'size': self._format_size(self.get_file_size(m)),
                            'topic_title': getattr(t, 'title', ''),
                        })

        if mode == 'quick':
            return results

        scanned = 0
        for t in topics:
            tname = (getattr(t, 'title', '') or '').lower()
            if any(term in tname for term in search_terms):
                continue
            if scanned >= 3:
                break
            scanned += 1
            try:
                msgs = await asyncio.wait_for(
                    client.get_messages(entity, reply_to=t.id, limit=2),
                    timeout=15
                )
            except:
                continue
            for m in msgs:
                if not self.is_video(m) or m.id in seen_ids:
                    continue
                title = self.get_file_name(m).lower()
                caption = self.get_message_text(m).lower()
                if any(term in title or term in caption for term in search_terms):
                    seen_ids.add(m.id)
                    results.append({
                        'id': m.id,
                        'date': str(m.date),
                        'title': self.get_file_name(m),
                        'size': self._format_size(self.get_file_size(m)),
                        'topic_title': getattr(t, 'title', ''),
                    })
            await asyncio.sleep(6)

        return results

    async def build_forum_index(self, client, peer_id, on_progress=None):
        from telethon.tl.functions.messages import GetForumTopicsRequest
        ids_to_try = [peer_id]
        if peer_id > 0:
            ids_to_try.append(int(f'-100{peer_id}'))
        entity = None
        for pid in ids_to_try:
            try:
                entity = await client.get_entity(pid)
                if entity:
                    break
            except:
                pass
        if not entity:
            return {'error': f'entity not found for peer_id={peer_id}'}
        r = await client(GetForumTopicsRequest(entity, offset_date=None, offset_id=0, offset_topic=0, limit=100))
        topics = r.topics
        stats = {'topics_total': len(topics), 'scanned': 0, 'indexed': 0, 'errors': 0}
        for t in topics:
            topic_title = (getattr(t, 'title', '') or '')
            offset_id = 0
            page = 0
            while True:
                page += 1
                for attempt in range(3):
                    try:
                        msgs = await asyncio.wait_for(
                            client.get_messages(entity, reply_to=t.id, limit=100, offset_id=offset_id),
                            timeout=20
                        )
                        break
                    except asyncio.TimeoutError:
                        logger.warning('Index: topic %s page %s timeout 20s', t.id, page)
                        if attempt == 2:
                            stats['errors'] += 1
                        else:
                            await asyncio.sleep(5)
                    except FloodWaitError as e:
                        logger.warning('Index: topic %s page %s flood wait %ss, esperando...', t.id, page, e.seconds)
                        await asyncio.sleep(e.seconds + 2)
                    except Exception as e:
                        if attempt == 2:
                            logger.warning('Index: topic %s page %s (%s) error tras 3 intentos: %s', t.id, page, topic_title[:30], e)
                            stats['errors'] += 1
                        else:
                            await asyncio.sleep(5)
                else:
                    stats['errors'] += 1
                    break
                for m in msgs:
                    if not self.is_video(m):
                        continue
                    filename = self.get_file_name(m)
                    caption = self.get_message_text(m) or None
                    self.db.insert_forum_video(
                        peer_id=peer_id, topic_id=t.id,
                        topic_title=topic_title, message_id=m.id,
                        filename=filename, caption=caption,
                        file_size=self.get_file_size(m),
                    )
                    stats['indexed'] += 1
                if len(msgs) < 100:
                    break
                offset_id = msgs[-1].id
            stats['scanned'] += 1
            if on_progress:
                on_progress(stats)
            await asyncio.sleep(5)
        return stats

    async def backfill_single_channel(self, client, db, source, state=None):
        if state is None:
            state = {'running': True, 'indexed': 0, 'errors': 0, 'newest_id': 0}
        title = source.get('title', f'ID {source["id"]}')
        state['running'] = True
        state['source_id'] = source['id']
        state['title'] = title

        entity = await self._get_entity(source)
        if not entity:
            state['running'] = False
            state['errors'] += 1
            logger.warning('Backfill single: no se pudo resolver %s', title)
            return state

        MAX_PAGES = 500
        last_msg_id = source.get('last_message_id', 0) or 1
        total_est = min(MAX_PAGES, max(1, last_msg_id // 100 + 1))
        state['pages'] = 0
        state['total_pages'] = total_est
        max_id = 0
        pages = 0
        indexed = 0
        newest_id = 0
        prev_max_id = None
        while pages < MAX_PAGES:
            try:
                msgs = await asyncio.wait_for(
                    client.get_messages(entity, limit=100, max_id=max_id),
                    timeout=20
                )
            except asyncio.TimeoutError:
                logger.warning('Backfill single: timeout %s page %s', title, pages)
                break
            except FloodWaitError as e:
                logger.warning('Backfill single: flood wait %ss en %s', e.seconds, title)
                await asyncio.sleep(e.seconds + 2)
                continue
            except Exception as e:
                logger.warning('Backfill single: error %s: %s', title, e)
                break
            if not msgs:
                break
            for m in msgs:
                if m.id > newest_id:
                    newest_id = m.id
                if not self.is_video(m):
                    continue
                try:
                    db.insert_forum_video(
                        peer_id=entity.id,
                        topic_id=0,
                        topic_title=title,
                        message_id=m.id,
                        filename=self.get_file_name(m),
                        caption=self.get_message_text(m) or None,
                        file_size=self.get_file_size(m),
                    )
                    indexed += 1
                except Exception as e:
                    logger.warning('Backfill single: error insertando video %s: %s', m.id, e)
            if len(msgs) < 100:
                break
            new_max_id = msgs[-1].id - 1
            if prev_max_id is not None and new_max_id >= prev_max_id:
                logger.warning('Backfill single: loop detectado en %s', title)
                break
            prev_max_id = new_max_id
            max_id = new_max_id
            pages += 1
            state['pages'] = pages
            state['indexed'] = indexed
            await asyncio.sleep(3)
        if pages >= MAX_PAGES:
            logger.warning('Backfill single: max pages alcanzado en %s', title)

        if newest_id:
            db.update_last_message(source['id'], newest_id)
        db.mark_backfilled(source['id'])
        state['indexed'] = indexed
        state['newest_id'] = newest_id
        state['running'] = False
        logger.info('Backfill single: %s completado, %s paginas, %s videos', title, pages, indexed)
        return state

    async def backfill_all_channels(self, client, db, state):
        sources = db.get_sources(enabled_only=True)
        channels = [s for s in sources if s.get('source_type') in ('channel', None)]
        state['total'] = len(channels)
        state['running'] = True
        entity_cache = {}

        async def resolve(peer_username, peer_id):
            if peer_id and peer_id in entity_cache:
                return entity_cache[peer_id]
            entity = None
            try:
                if peer_username:
                    entity = await client.get_entity(peer_username)
                elif peer_id:
                    ids_to_try = []
                    if peer_id > 0:
                        ids_to_try.append(int(f'-100{peer_id}'))
                    ids_to_try.append(peer_id)
                    for pid in ids_to_try:
                        try:
                            entity = await client.get_entity(pid)
                            if entity:
                                break
                        except:
                            continue
            except:
                pass
            if entity and peer_id:
                entity_cache[peer_id] = entity
            return entity

        for src in channels:
            if not state.get('running', True):
                break
            title = src.get('title', f'ID {src["id"]}')
            state['current_channel'] = title
            entity = await resolve(
                src.get('peer_username') or src.get('username'),
                src.get('peer_id')
            )
            if not entity:
                state['errors'] += 1
                logger.warning('Backfill: no se pudo resolver %s', title)
                state['completed'] += 1
                continue
            logger.info('Backfill: %s resuelta con entity.id=%s', title, entity.id)

            MAX_PAGES = 500
            max_id = 0
            pages = 0
            indexed = 0
            prev_max_id = None
            while pages < MAX_PAGES:
                try:
                    msgs = await asyncio.wait_for(
                        client.get_messages(entity, limit=100, max_id=max_id),
                        timeout=20
                    )
                except asyncio.TimeoutError:
                    logger.warning('Backfill: timeout %s page %s', title, pages)
                    break
                except FloodWaitError as e:
                    logger.warning('Backfill: flood wait %ss en %s', e.seconds, title)
                    await asyncio.sleep(e.seconds + 2)
                    continue
                except Exception as e:
                    logger.warning('Backfill: error %s: %s', title, e)
                    break
                if not msgs:
                    break
                for m in msgs:
                    if not self.is_video(m):
                        continue
                    try:
                        db.insert_forum_video(
                            peer_id=entity.id,
                            topic_id=0,
                            topic_title=title,
                            message_id=m.id,
                            filename=self.get_file_name(m),
                            caption=self.get_message_text(m) or None,
                            file_size=self.get_file_size(m),
                        )
                        indexed += 1
                    except Exception as e:
                        logger.warning('Backfill: error insertando video %s: %s', m.id, e)
                if len(msgs) < 100:
                    break
                new_max_id = msgs[-1].id - 1
                if prev_max_id is not None and new_max_id >= prev_max_id:
                    logger.warning('Backfill: loop detectado en %s (max_id no disminuye)', title)
                    break
                prev_max_id = new_max_id
                max_id = new_max_id
                pages += 1
                if pages % 5 == 0:
                    state['indexed'] = indexed
                await asyncio.sleep(3)
                if not state.get('running', True):
                    break
            if pages >= MAX_PAGES:
                logger.warning('Backfill: max pages alcanzado en %s', title)

            state['indexed'] = indexed
            state['completed'] += 1
            db.mark_backfilled(src['id'])
            logger.info('Backfill: %s completado, %s paginas, %s videos', title, pages, indexed)
            await asyncio.sleep(10)

        state['running'] = False
        return state

    def cancel_download(self, download_id):
        if download_id in self.active_downloads:
            self.cancelled_downloads.add(download_id)
            logger.info('Download %s marcado para cancelar', download_id)
            return {'success': True, 'message': 'Cancelación señalada'}
        return {'success': False, 'error': 'Download no encontrado o ya completado'}

    def get_active_downloads(self):
        result = []
        for dl_id, dl in self.active_downloads.items():
            entry = dict(dl)
            entry['id'] = dl_id
            entry['size_label'] = self._format_size(dl.get('total', 0))
            entry['downloaded_label'] = self._format_size(dl.get('downloaded', 0))
            speed = dl.get('speed', 0)
            entry['speed_label'] = f'{self._format_size(speed)}/s' if speed > 0 else ''
            eta = dl.get('eta', 0)
            if eta > 0:
                m, s = divmod(int(eta), 60)
                h, m = divmod(m, 60)
                entry['eta_label'] = f'{h}h {m}m {s}s' if h else f'{m}m {s}s'
            else:
                entry['eta_label'] = ''
            result.append(entry)
        return result

    def _format_size(self, bytes_val):
        if bytes_val <= 0:
            return '0 B'
        int_val = int(bytes_val)
        units = ['B', 'KB', 'MB', 'GB', 'TB']
        i = int(min(len(units) - 1, (int_val.bit_length() - 1) // 10))
        return f'{int_val / (1024 ** i):.2f} {units[i]}'
