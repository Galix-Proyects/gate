import asyncio
import datetime
import json
import logging
import os
import threading
import time
from collections import defaultdict
from functools import wraps
from flask import Flask, request, jsonify

logger = logging.getLogger('GalixTelegramDaemon.API')

app = Flask(__name__)
daemon_state = {}

_request_times = defaultdict(list)

def rate_limit(max_per_second=10):
    def decorator(f):
        @wraps(f)
        def wrapper(*args, **kwargs):
            now = time.time()
            ip = request.remote_addr or 'unknown'
            times = _request_times[ip]
            _request_times[ip] = [t for t in times if now - t < 1]
            if len(_request_times[ip]) >= max_per_second:
                return jsonify({'success': False, 'error': 'Too many requests'}), 429
            _request_times[ip].append(now)
            return f(*args, **kwargs)
        return wrapper
    return decorator

@app.route('/status', methods=['GET'])
@rate_limit(10)
def status():
    sources = daemon_state.get('source_manager', None)
    downloader = daemon_state.get('downloader', None)
    client = daemon_state.get('client', None)
    connected = client and hasattr(client, 'is_connected') and client.is_connected()
    sources_list = sources.list_sources() if sources else []
    active_dls = downloader.get_active_downloads() if downloader else []
    daemon = daemon_state.get('daemon', None)
    scan = daemon.scan_status if daemon else None
    return jsonify({
        'success': True,
        'daemon': 'running',
        'telegram_connected': bool(connected),
        'sources_count': len(sources_list),
        'active_downloads': len(active_dls),
        'downloads': active_dls,
        'scan': scan
    })

@app.route('/health', methods=['GET'])
def health():
    sources = daemon_state.get('source_manager', None)
    downloader = daemon_state.get('downloader', None)
    client = daemon_state.get('client', None)
    daemon = daemon_state.get('daemon', None)
    connected = bool(client and hasattr(client, 'is_connected') and client.is_connected())
    active = len(downloader.get_active_downloads()) if downloader else 0
    scan = (daemon.scan_status if daemon else {}) or {}
    flag_path = daemon_state.get('auth_flag_path', os.path.join(os.path.dirname(__file__), '.auth_required'))
    auth_status = None
    if os.path.exists(flag_path):
        try:
            with open(flag_path) as f:
                auth_status = json.load(f).get('status')
        except Exception:
            auth_status = 'UNKNOWN'
    return jsonify({
        'ok': True,
        'tg_connected': connected,
        'auth_status': auth_status,
        'queue_depth': active,
        'sources': len(sources.list_sources()) if sources else 0,
        'scanning': bool(scan.get('scanning')),
        'last_scan_finished': scan.get('finished_at'),
    })

    # Compatibilidad: también responder en /api.php/health
    @app.route('/api.php/health', methods=['GET'])
    def api_php_health():
        return jsonify({
            'ok': True,
            'tg_connected': connected,
            'queue_depth': active,
            'sources': len(sources.list_sources()) if sources else 0,
            'scanning': bool(scan.get('scanning')),
            'last_scan_finished': scan.get('finished_at'),
        })

@app.route('/sources', methods=['GET'])
@rate_limit(10)
def list_sources():
    sources = daemon_state.get('source_manager', None)
    if not sources:
        return jsonify({'success': False, 'error': 'Source manager no disponible'}), 500
    return jsonify({'success': True, 'sources': sources.list_sources()})

@app.route('/sources', methods=['POST'])
def add_source():
    data = request.get_json(silent=True) or {}
    url = data.get('url', '').strip()
    auto_download = data.get('auto_download', 'manual')
    keywords = data.get('keywords', None)
    category = (data.get('category') or '').strip() or None
    if not url:
        return jsonify({'success': False, 'error': 'url requerida'}), 400
    sm = daemon_state.get('source_manager', None)
    client = daemon_state.get('client', None)
    loop = daemon_state.get('loop', None)
    if not sm or not client or not loop:
        return jsonify({'success': False, 'error': 'Daemon no listo'}), 503

    async def add_with_reconnect():
        if not client.is_connected():
            await client.connect()
        return await sm.add_source(client, url, auto_download, keywords, category)

    future = asyncio.run_coroutine_threadsafe(add_with_reconnect(), loop)
    try:
        result = future.result(timeout=30)
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)}), 500

    # Auto-backfill en background si se agrego un canal
    if result.get('success') and result.get('source_id'):
        downloader = daemon_state.get('downloader')
        db = daemon_state.get('db')
        if downloader and db:
            async def auto_backfill_new():
                await asyncio.sleep(2)
                new_source = db.get_source(result['source_id'])
                if new_source and new_source.get('source_type') in ('channel', None):
                    state = {'running': True, 'indexed': 0, 'errors': 0}
                    await downloader.backfill_single_channel(client, db, new_source, state)
            asyncio.run_coroutine_threadsafe(auto_backfill_new(), loop)
            result['backfill'] = 'auto_started'

    status_code = 200 if result.get('success') else 400
    return jsonify(result), status_code

@app.route('/sources/<int:source_id>', methods=['DELETE'])
def remove_source(source_id):
    sm = daemon_state.get('source_manager', None)
    if not sm:
        return jsonify({'success': False, 'error': 'Source manager no disponible'}), 500
    result = sm.remove_source(source_id)
    status_code = 200 if result.get('success') else 404
    return jsonify(result), status_code

@app.route('/sources/<int:source_id>', methods=['PATCH'])
def update_source(source_id):
    data = request.get_json(silent=True) or {}
    sm = daemon_state.get('source_manager', None)
    if not sm:
        return jsonify({'success': False, 'error': 'Source manager no disponible'}), 500
    result = sm.update_source(source_id, data)
    return jsonify(result)

@app.route('/download', methods=['POST'])
def download_by_link():
    data = request.get_json(silent=True) or {}
    url = data.get('url', '').strip()
    if not url:
        return jsonify({'success': False, 'error': 'url requerida'}), 400
    filename = data.get('filename')
    if filename:
        filename = filename.strip()
    folder = data.get('folder')
    if folder:
        folder = folder.strip()
    app.logger.info('POST /download url=%s filename=%r folder=%r', url, filename, folder)
    downloader = daemon_state.get('downloader', None)
    client = daemon_state.get('client', None)
    loop = daemon_state.get('loop', None)
    if not downloader or not client or not loop:
        return jsonify({'success': False, 'error': 'Daemon no listo'}), 503
    future = asyncio.run_coroutine_threadsafe(
        downloader.download_by_link(client, url, custom_filename=filename, custom_folder=folder), loop
    )
    result = future.result(timeout=600)
    status_code = 200 if (result.get('success') or result.get('already')) else 400
    return jsonify(result), status_code

@app.route('/check_roku', methods=['POST'])
def check_roku():
    data = request.get_json(silent=True) or {}
    url = data.get('url', '').strip()
    if not url:
        return jsonify({'success': False, 'error': 'url requerida'}), 400
    app.logger.info('POST /check_roku url=%s', url)
    downloader = daemon_state.get('downloader', None)
    client = daemon_state.get('client', None)
    loop = daemon_state.get('loop', None)
    if not downloader or not client or not loop:
        return jsonify({'success': False, 'error': 'Daemon no listo'}), 503
    future = asyncio.run_coroutine_threadsafe(downloader.check_roku(client, url), loop)
    try:
        result = future.result(timeout=120)
    except Exception as e:
        # Cancelar la corrutina: si no, sigue martillando GetFileRequest tras el 504
        # (tormenta de flood-wait que tumbaba la conexion de Telegram).
        future.cancel()
        return jsonify({'success': False, 'error': f'Timeout o error: {e}'}), 504
    status_code = 200 if result.get('success') else 400
    return jsonify(result), status_code

@app.route('/import/forum', methods=['POST'])
def import_forum_topics():
    db = daemon_state.get('db', None)
    client = daemon_state.get('client', None)
    loop = daemon_state.get('loop', None)
    sm = daemon_state.get('source_manager', None)
    if not all([db, client, loop, sm]):
        return jsonify({'success': False, 'error': 'Daemon no listo'}), 503
    data = request.get_json(silent=True) or {}
    source_id = data.get('source_id', 4)
    sources = db.get_sources(enabled_only=False)
    forum_source = next((s for s in sources if s['id'] == source_id), None)
    if not forum_source:
        return jsonify({'success': False, 'error': 'Fuente del foro no encontrada'}), 404

    async def do_import():
        from telethon.tl.functions.messages import GetForumTopicsRequest
        peer_id = forum_source.get('peer_id')
        ids_to_try = [peer_id]
        if peer_id and peer_id > 0:
            ids_to_try.append(int(f'-100{peer_id}'))
        entity = None
        for pid in ids_to_try:
            try:
                entity = await client.get_entity(pid)
                break
            except:
                pass
        if not entity:
            return {'success': False, 'error': 'No se pudo resolver la entidad del foro'}

        result = await client(GetForumTopicsRequest(entity, offset_date=None, offset_id=0, offset_topic=0, limit=100))
        topics = result.topics
        added = 0
        skipped = 0
        errors = 0
        for t in topics:
            try:
                r = await sm.add_topic_source(client, entity, t.id, t.title)
                if r.get('success'):
                    added += 1
                else:
                    skipped += 1
            except Exception as e:
                logger.error('Error agregando topic %s: %s', t.id, e)
                errors += 1

        return {
            'success': True,
            'total_topics': len(topics),
            'added': added,
            'skipped': skipped,
            'errors': errors,
            'forum_title': getattr(entity, 'title', '')
        }

    future = asyncio.run_coroutine_threadsafe(do_import(), loop)
    try:
        return jsonify(future.result(timeout=120))
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)}), 500

@app.route('/info/<int:source_id>', methods=['GET'])
def source_info(source_id):
    db = daemon_state.get('db', None)
    client = daemon_state.get('client', None)
    loop = daemon_state.get('loop', None)
    if not all([db, client, loop]):
        return jsonify({'success': False, 'error': 'Daemon no listo'}), 503
    import telethon.tl.types as ttypes
    sources = db.get_sources(enabled_only=False)
    source = next((s for s in sources if s['id'] == source_id), None)
    if not source:
        return jsonify({'success': False, 'error': 'Fuente no encontrada'}), 404
    async def do_info():
        peer_id = source.get('peer_id')
        ids_to_try = [peer_id]
        if peer_id and peer_id > 0:
            ids_to_try.append(int(f'-100{peer_id}'))
        entity = None
        for pid in ids_to_try:
            try:
                entity = await client.get_entity(pid)
                break
            except:
                pass
        if not entity:
            return {'error': 'entity not found'}
        info = {
            'id': entity.id,
            'title': getattr(entity, 'title', 'N/A'),
            'type': type(entity).__name__,
            'username': getattr(entity, 'username', None),
            'forum': getattr(entity, 'forum', False),
            'megagroup': getattr(entity, 'megagroup', False),
            'broadcast': getattr(entity, 'broadcast', False),
            'participants_count': getattr(entity, 'participants_count', 0),
        }
        if hasattr(entity, 'forum') and entity.forum:
            try:
                from telethon.tl.functions.messages import GetForumTopicsRequest
                topics = await client(GetForumTopicsRequest(entity, offset_date=None, offset_id=0, offset_topic=0, limit=100))
                info['topics'] = []
                for t in topics.topics:
                    info['topics'].append({
                        'id': t.id,
                        'title': t.title,
                    })
                info['topics_count'] = len(topics.topics)
            except Exception as e:
                info['topics_error'] = str(e)
        return info
    future = asyncio.run_coroutine_threadsafe(do_info(), loop)
    try:
        return jsonify(future.result(timeout=30))
    except Exception as e:
        return jsonify({'error': str(e)}), 500

@app.route('/preview/<int:source_id>', methods=['GET'])
def preview_source(source_id):
    db = daemon_state.get('db', None)
    downloader = daemon_state.get('downloader', None)
    client = daemon_state.get('client', None)
    loop = daemon_state.get('loop', None)
    if not all([db, downloader, client, loop]):
        return jsonify({'success': False, 'error': 'Daemon no listo'}), 503
    sources = db.get_sources(enabled_only=False)
    source = next((s for s in sources if s['id'] == source_id), None)
    if not source:
        return jsonify({'success': False, 'error': 'Fuente no encontrada'}), 404
    limit = request.args.get('limit', 100, type=int)
    offset_id = request.args.get('offset_id', 0, type=int)
    async def do_preview():
        result = await downloader.preview_source(client, source, limit=limit, offset_id=offset_id)
        if not isinstance(result, dict):
            result = {'videos': [], 'has_more': False}
        return result.get('videos', []), result.get('has_more', False)
    future = asyncio.run_coroutine_threadsafe(do_preview(), loop)
    try:
        videos, has_more = future.result(timeout=60)
        oldest_id = min((v['id'] for v in videos), default=0) if videos else 0
        return jsonify({'success': True, 'source': source['title'], 'videos': videos, 'count': len(videos), 'oldest_id': oldest_id, 'has_more': has_more})
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)}), 500

@app.route('/scan', methods=['POST'])
def scan():
    db = daemon_state.get('db', None)
    downloader = daemon_state.get('downloader', None)
    client = daemon_state.get('client', None)
    loop = daemon_state.get('loop', None)
    if not all([db, downloader, client, loop]):
        return jsonify({'success': False, 'error': 'Daemon no listo'}), 503

    async def do_scan():
        sources = db.get_sources(enabled_only=True)
        results = []
        for src in sources:
            messages = await downloader.scan_source(client, src, limit=50)
            if src['auto_download'] in ('all', 'filtered') and messages:
                for msg in messages:
                    try:
                        r = await downloader.download_message(client, msg, src.get('title'))
                        results.append(r if isinstance(r, dict) else {'success': False, 'error': str(r)})
                    except Exception as e:
                        results.append({'success': False, 'error': str(e)})
        return results

    future = asyncio.run_coroutine_threadsafe(do_scan(), loop)
    try:
        results = future.result(timeout=600)
        return jsonify({'success': True, 'downloaded': len([r for r in results if r.get('success')]), 'results': results})
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)}), 500

@app.route('/auth/status', methods=['GET'])
def auth_status():
    flag_path = daemon_state.get('auth_flag_path',
                                  os.path.join(os.path.dirname(__file__), '.auth_required'))
    client = daemon_state.get('client', None)
    if os.path.exists(flag_path):
        with open(flag_path) as f:
            flag = json.load(f)
        return jsonify({'success': True, 'authenticated': False, 'auth_status': flag.get('status')})
    if client and hasattr(client, 'is_connected') and client.is_connected():
        return jsonify({'success': True, 'authenticated': True})
    return jsonify({'success': True, 'authenticated': False, 'auth_status': 'UNKNOWN'})

@app.route('/auth/code', methods=['POST'])
def submit_code():
    data = request.get_json(silent=True) or {}
    code = data.get('code', '').strip()
    if not code:
        return jsonify({'success': False, 'error': 'code requerido'}), 400
    loop = daemon_state.get('loop', None)
    daemon = daemon_state.get('daemon', None)
    if not loop or not daemon:
        return jsonify({'success': False, 'error': 'Daemon no listo'}), 503
    future = asyncio.run_coroutine_threadsafe(daemon.complete_auth(code), loop)
    result = future.result(timeout=30)
    status_code = 200 if result.get('success') else 400
    return jsonify(result), status_code

@app.route('/auth/2fa', methods=['POST'])
def submit_2fa():
    data = request.get_json(silent=True) or {}
    password = data.get('password', '').strip()
    if not password:
        return jsonify({'success': False, 'error': 'password requerido'}), 400
    loop = daemon_state.get('loop', None)
    daemon = daemon_state.get('daemon', None)
    if not loop or not daemon:
        return jsonify({'success': False, 'error': 'Daemon no listo'}), 503
    future = asyncio.run_coroutine_threadsafe(daemon.complete_2fa(password), loop)
    result = future.result(timeout=30)
    status_code = 200 if result.get('success') else 400
    return jsonify(result), status_code

@app.route('/auth/resend', methods=['POST'])
def resend_code():
    loop = daemon_state.get('loop', None)
    daemon = daemon_state.get('daemon', None)
    if not loop or not daemon:
        return jsonify({'success': False, 'error': 'Daemon no listo'}), 503
    future = asyncio.run_coroutine_threadsafe(daemon.resend_code(), loop)
    try:
        result = future.result(timeout=30)
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)}), 500
    status_code = 200 if result.get('success') else 400
    return jsonify(result), status_code
    return jsonify(result), status_code

@app.route('/downloads', methods=['GET'])
def list_downloads():
    downloader = daemon_state.get('downloader', None)
    if not downloader:
        return jsonify({'success': False, 'error': 'Downloader no disponible'}), 500
    return jsonify({'success': True, 'downloads': downloader.get_active_downloads()})

@app.route('/search_videos', methods=['GET'])
def search_videos():
    q = request.args.get('q', '').strip().lower()
    if not q:
        return jsonify({'success': False, 'error': 'query q requerida'}), 400

    db = daemon_state.get('db', None)
    client = daemon_state.get('client', None)
    loop = daemon_state.get('loop', None)
    downloader = daemon_state.get('downloader', None)
    if not all([db, client, loop, downloader]):
        return jsonify({'success': False, 'error': 'Daemon no listo'}), 503

    order = request.args.get('order', 'asc')
    mode = request.args.get('mode', 'quick')

    async def do_search():
        results = []
        seen = set()

        async def search_global():
            try:
                from telethon.tl.functions.messages import SearchGlobalRequest
                from telethon.tl.types import InputMessagesFilterDocument, InputPeerEmpty
                from telethon.utils import get_peer_id
                r = await client(SearchGlobalRequest(
                    q=q,
                    filter=InputMessagesFilterDocument(),
                    limit=100,
                    offset_rate=0,
                    offset_peer=InputPeerEmpty(),
                    offset_id=0
                ))
                entity_map = {}
                for chat in (r.chats or []):
                    cid = getattr(chat, 'id', None)
                    if cid:
                        entity_map[cid] = getattr(chat, 'title', f'Channel {cid}')
                for msg in (r.messages or []):
                    if not downloader.is_video(msg):
                        continue
                    pid = get_peer_id(msg.peer_id)
                    if isinstance(pid, tuple):
                        pid = pid[0]
                    source_title = entity_map.get(pid, f'Source {pid}')
                    key = (pid, msg.id)
                    if key not in seen:
                        seen.add(key)
                        results.append({
                            'peer_id': pid,
                            'source_id': pid,
                            'source_title': source_title,
                            'source_type': 'channel',
                            'video_id': msg.id,
                            'video_title': downloader.get_file_name(msg),
                            'video_size': downloader._format_size(downloader.get_file_size(msg)),
                            'video_date': str(msg.date),
                        })
            except Exception as e:
                logger.debug('search_global error: %s', e)

        async def search_index():
            try:
                idx = db.search_forum_index(q, limit=200, order=order)
                for row in idx:
                    key = (row['peer_id'], row['message_id'])
                    if key not in seen:
                        seen.add(key)
                        results.append({
                            'peer_id': row['peer_id'],
                            'source_id': row.get('source_id') or row['peer_id'],
                            'source_title': row['topic_title'] or f'Source {row["peer_id"]}',
                            'source_type': 'channel',
                            'video_id': row['message_id'],
                            'video_title': row['filename'],
                            'video_size': downloader._format_size(row.get('file_size', 0)) if row.get('file_size', 0) else '',
                            'video_date': str(row['updated_at']) if row.get('updated_at') else '',
                        })
            except Exception as e:
                logger.debug('search_index error: %s', e)

        async def search_with_timeout(coro, timeout):
            try:
                return await asyncio.wait_for(coro, timeout=timeout)
            except:
                return None

        await asyncio.gather(
            search_with_timeout(search_index(), 5),
            search_with_timeout(search_global(), 10),
            return_exceptions=True,
        )

        if order == 'asc':
            results.sort(key=lambda r: r.get('video_date', ''))
        else:
            results.sort(key=lambda r: r.get('video_date', ''), reverse=True)

        return results

    future = asyncio.run_coroutine_threadsafe(do_search(), loop)
    try:
        r = future.result(timeout=30)
        return jsonify({'success': True, 'query': q, 'results': r, 'count': len(r)})
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)}), 500

@app.route('/download/<int:download_id>', methods=['DELETE'])
def cancel_download(download_id):
    downloader = daemon_state.get('downloader', None)
    if not downloader:
        return jsonify({'success': False, 'error': 'Downloader no disponible'}), 500
    result = downloader.cancel_download(download_id)
    status_code = 200 if result.get('success') else 404
@app.route('/auth/resend', methods=['POST'])

def cancel_all_downloads():
    downloader = daemon_state.get('downloader', None)
    if not downloader:
        return jsonify({'success': False, 'error': 'Downloader no disponible'}), 500
    active = downloader.get_active_downloads()
    ids = [dl['id'] for dl in active]
    for dl_id in ids:
        downloader.cancelled_downloads.add(dl_id)
    return jsonify({'success': True, 'cancelled': ids, 'count': len(ids)})

_index_build_task = None

@app.route('/forum/index', methods=['POST'])
def trigger_forum_index():
    global _index_build_task
    loop = daemon_state.get('loop', None)
    client = daemon_state.get('client', None)
    downloader = daemon_state.get('downloader', None)
    if not all([loop, client, downloader]):
        return jsonify({'success': False, 'error': 'Daemon no listo'}), 503
    if _index_build_task and not _index_build_task.done():
        return jsonify({'success': False, 'error': 'Ya hay un index en progreso'}), 409
    data = request.get_json(silent=True) or {}
    peer_id = data.get('peer_id', 2196080366)
    async def do_index():
        return await downloader.build_forum_index(client, peer_id)
    _index_build_task = asyncio.run_coroutine_threadsafe(do_index(), loop)
    return jsonify({'success': True, 'message': f'Index iniciado para peer_id={peer_id}, ver /forum/index/status'})

@app.route('/forum/index/build', methods=['GET'])
def forum_index_build_status():
    global _index_build_task
    if not _index_build_task:
        return jsonify({'success': True, 'running': False, 'message': 'Sin index previo'})
    if _index_build_task.done():
        try:
            result = _index_build_task.result()
            return jsonify({'success': True, 'running': False, 'result': result})
        except Exception as e:
            return jsonify({'success': True, 'running': False, 'error': str(e)})
    return jsonify({'success': True, 'running': True, 'message': 'Index en progreso...'})

@app.route('/forum/index/status', methods=['GET'])
def forum_index_status():
    db = daemon_state.get('db', None)
    if not db:
        return jsonify({'success': False, 'error': 'DB no disponible'}), 503
    stats = db.get_forum_index_stats()
    return jsonify({'success': True, **stats})

@app.route('/forum/search', methods=['GET'])
def search_forum_index():
    db = daemon_state.get('db', None)
    if not db:
        return jsonify({'success': False, 'error': 'DB no disponible'}), 503
    q = request.args.get('q', '').strip().lower()
    if not q:
        return jsonify({'success': False, 'error': 'query q requerida'}), 400
    results = db.search_forum_index(q, limit=200)
    return jsonify({'success': True, 'query': q, 'count': len(results), 'results': results})

_backfill_state = {
    'running': False,
    'total': 0,
    'completed': 0,
    'indexed': 0,
    'errors': 0,
    'current_channel': '',
    'started_at': None,
}

_single_backfill_progress = {}

@app.route('/backfill/channels', methods=['POST'])
def trigger_backfill():
    global _backfill_state
    if _backfill_state.get('running'):
        return jsonify({'success': False, 'error': 'Ya hay un backfill en progreso'}), 409
    downloader = daemon_state.get('downloader', None)
    client = daemon_state.get('client', None)
    db = daemon_state.get('db', None)
    loop = daemon_state.get('loop', None)
    if not all([downloader, client, db, loop]):
        return jsonify({'success': False, 'error': 'Daemon no listo'}), 503
    import datetime
    _backfill_state = {
        'running': True,
        'total': 0,
        'completed': 0,
        'indexed': 0,
        'errors': 0,
        'current_channel': '',
        'started_at': datetime.datetime.now().isoformat(),
    }
    asyncio.run_coroutine_threadsafe(
        downloader.backfill_all_channels(client, db, _backfill_state), loop
    )
    return jsonify({'success': True, 'message': 'Backfill iniciado en background'})

@app.route('/backfill/status', methods=['GET'])
def backfill_status():
    return jsonify({'success': True, **_backfill_state})

@app.route('/backfill/channel/<int:source_id>', methods=['POST'])
def trigger_single_backfill(source_id):
    global _single_backfill_progress
    if _single_backfill_progress.get(source_id, {}).get('running'):
        return jsonify({'success': False, 'error': 'Backfill ya en progreso para esta fuente'}), 409
    downloader = daemon_state.get('downloader', None)
    client = daemon_state.get('client', None)
    db = daemon_state.get('db', None)
    loop = daemon_state.get('loop', None)
    if not all([downloader, client, db, loop]):
        return jsonify({'success': False, 'error': 'Daemon no listo'}), 503
    source = db.get_source(source_id)
    if not source:
        return jsonify({'success': False, 'error': 'Fuente no encontrada'}), 404
    _single_backfill_progress[source_id] = {
        'running': True,
        'source_id': source_id,
        'title': source.get('title', f'ID {source_id}'),
        'indexed': 0,
        'errors': 0,
        'newest_id': 0,
        'started_at': datetime.datetime.now().isoformat(),
    }
    asyncio.run_coroutine_threadsafe(
        downloader.backfill_single_channel(client, db, source, _single_backfill_progress[source_id]),
        loop
    )
    return jsonify({'success': True, 'message': f'Backfill de canal iniciado: {source.get("title", source_id)}'})

@app.route('/backfill/channel/<int:source_id>/status', methods=['GET'])
@rate_limit(5)
def single_backfill_status(source_id):
    status = _single_backfill_progress.get(source_id, {})
    if not status:
        return jsonify({'success': True, 'running': False, 'source_id': source_id})
    return jsonify({'success': True, **status})

@app.route('/scan/channel/<int:source_id>', methods=['POST'])
def scan_single_source(source_id):
    db = daemon_state.get('db', None)
    downloader = daemon_state.get('downloader', None)
    client = daemon_state.get('client', None)
    loop = daemon_state.get('loop', None)
    if not all([db, downloader, client, loop]):
        return jsonify({'success': False, 'error': 'Daemon no listo'}), 503
    source = db.get_source(source_id)
    if not source:
        return jsonify({'success': False, 'error': 'Fuente no encontrada'}), 404

    async def do_scan():
        messages = await downloader.scan_source(client, source, limit=100)
        return {'videos_found': len(messages), 'messages': [{'id': m.id, 'filename': downloader.get_file_name(m)} for m in messages]}

    future = asyncio.run_coroutine_threadsafe(do_scan(), loop)
    try:
        result = future.result(timeout=120)
        return jsonify({'success': True, **result})
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)}), 500

def start_api(host, port, state):
    global daemon_state
    daemon_state.update(state)
    threading.Thread(target=lambda: app.run(
        host=host,
        port=port,
        debug=False,
        use_reloader=False,
        threaded=True
    ), daemon=True).start()
    logger.info('API HTTP iniciada en %s:%s', host, port)


# Rutas de compatibilidad legacy /api.php
@app.route('/api.php/status', methods=['GET'])
def api_php_status():
    return status()

@app.route('/api.php/health', methods=['GET'])
def api_php_health():
    return health()

@app.route('/api.php/sources', methods=['GET'])
def api_php_sources():
    return list_sources()


def api_php_sources_post():
    return add_source()

def api_php_auth_status():
    return auth_status()

def api_php_downloads():
    return list_downloads()

