/* GalixStreaming PRO — Módulo Telegram (extraído de app.js, Fase 0.1)
   Depende de globals de app.js: API, showToast, escapeHtml, previewTMDB,
   closeTMDBPreview, loadQueue, _openFolderModal (folder picker compartido).
*/

/* ── Telegram ── */

let tgAllSources = [];
let tgSearchTimeout = null;
let tgSelectAll = false;
let tgSelectedIds = new Set();

async function tgGlobalSearch() {
    const q = document.getElementById('tgGlobalSearch').value.trim();
    if (!q) { showToast('Escribe un título', 'error'); return; }

    const resultsEl = document.getElementById('tgGlobalResults');
    const clearBtn = document.getElementById('tgGlobalSearchClear');
    resultsEl.classList.remove('hidden');
    clearBtn.classList.remove('hidden');
    document.getElementById('tgSourcesGrid').classList.add('hidden');
    document.getElementById('tgSourcesEmpty').classList.add('hidden');
    resultsEl.innerHTML = '<div class="loading"><div class="spinner"></div><span>Buscando en canales y foros...</span></div>';

    try {
        const mode = 'quick';
        const res = await fetch(`${API}?action=telegram&tg=search_videos&q=${encodeURIComponent(q)}&mode=${mode}`);
        const data = await res.json();

        if (!data.success) {
            resultsEl.innerHTML = `<div class="empty-state" style="padding:40px"><span class="empty-icon">❌</span><p>Error: ${escapeHtml(data.error)}</p></div>`;
            return;
        }

        if (!data.count) {
            resultsEl.innerHTML = `<div class="empty-state" style="padding:40px"><span class="empty-icon">📭</span><p>Sin videos remotos para "${escapeHtml(q)}"</p><p style="font-size:0.8rem;color:#64748b;margin-top:8px;">Mostrando fuentes que coinciden...</p></div>`;
            tgFilterText = normalizeText(q);
            document.getElementById('tgSourcesGrid').classList.remove('hidden');
            renderSourcesGrid();
            return;
        }

        resultsEl.innerHTML = `
            <div class="tg-global-count">
                ${data.count} resultado${data.count > 1 ? 's' : ''} para "${escapeHtml(q)}"
                <button class="btn-secondary" onclick="tgDeepSearch()" style="margin-left:12px;font-size:12px;padding:4px 10px">🔍 Búsqueda profunda</button>
                <span style="margin-left:12px;color:#64748b;font-size:0.8rem">🔘 <button class="btn-tertiary" onclick="toggleSelectAll()">${tgSelectAll ? 'Deseleccionar todos' : 'Seleccionar todos'}</button></span>
            </div>
            <div class="tg-global-list">
                ${data.results.map(r => {
                    const size = r.video_size || '';
                    const title_v = r.video_title || `video_${r.video_id}`;
                    const srcRow = (r.peer_id != null)
                        ? (tgAllSources.find(s => s.peer_id == r.peer_id) || {})
                        : (tgAllSources.find(s => s.id === r.source_id) || {});
                    const msgUrl = buildTgMsgUrl(r.peer_id ?? r.source_id, r.video_id, srcRow.topic_id, r.source_type === 'forum_topic');
                    const isChecked = tgSelectedIds.has(r.video_id) ? 'checked' : '';
                    return `
                        <div class="tg-global-item">
                            <div class="tg-global-info">
                                <div class="tg-global-name">${escapeHtml(title_v)}</div>
                                <div class="tg-global-meta">
                                    <span class="tg-card-type ${r.source_type === 'forum_topic' ? 'type-movie' : 'type-channel'}">${r.source_type === 'forum_topic' ? 'Foro' : 'Canal'}</span>
                                    ${size ? `<span>📦 ${size}</span>` : ''}
                                    <span>📁 ${escapeHtml(r.source_title)}</span>
                                </div>
                            </div>
                            <div class="tg-global-actions">
                                ${msgUrl ? `<a class="tg-btn" href="${msgUrl}" target="_blank" rel="noopener" title="Abrir en Telegram">📨</a><button class="tg-btn tg-btn-roku" data-url="${msgUrl}" onclick="checkRoku(this)" title="Verificar compatibilidad con Roku">📺</button><span class="tg-roku-badge"></span>` : ''}
                                <button class="tg-btn tg-btn-preview" data-title="${escapeHtml(title_v)}" onclick="previewFromTitle(this.dataset.title)">🔍</button>
                                <button class="tg-btn tg-btn-download" data-url="${escapeHtml(msgUrl)}" data-title="${escapeHtml(title_v)}" onclick="downloadTelegramVideo(this.dataset.url, this.dataset.title)" ${isChecked ? 'style="opacity:0.5;pointer-events:none"' : ''}>⬇️</button>
                            </div>
                            <input type="checkbox" class="tg-global-checkbox" data-video-id="${r.video_id}" ${isChecked} onchange="toggleVideoSelect(this)">
                        </div>
                    `;
                }).join('')}
                ${data.count > 0 ? `<div class="tg-batch-actions" style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border);"><button class="btn-primary" onclick="batchDownloadSelected()" style="font-size:0.75rem;padding:6px 12px">⬇️ Descargar seleccionados (${tgSelectedIds.size})</button><button class="btn-secondary" onclick="clearSelectAll()" style="font-size:0.75rem;padding:6px 12px;margin-left:8px">Limpiar</button></div>` : ''}
            </div>
        `;
    } catch (e) {
        resultsEl.innerHTML = `<div class="empty-state" style="padding:40px"><span class="empty-icon">❌</span><p>Error de conexión</p></div>`;
    }
}

// Funciones de selección múltiple para multi-download
function toggleSelectAll() {
    tgSelectAll = !tgSelectAll;
    tgSelectedIds.clear();
    document.querySelectorAll('.tg-global-checkbox').forEach(cb => {
        cb.checked = tgSelectAll;
        if (tgSelectAll) tgSelectedIds.add(cb.dataset.videoId);
        else cb.dataset.videoId = ''; // clear
    });
    updateBatchButton();
    // Re-render checkboxes with correct state
    const inputs = document.querySelectorAll('.tg-global-checkbox');
    inputs.forEach(cb => {
        cb.checked = tgSelectAll;
    });
}

function toggleVideoSelect(cb) {
    const vidId = cb.dataset.videoId;
    if (cb.checked) {
        tgSelectedIds.add(vidId);
    } else {
        tgSelectedIds.delete(vidId);
    }
    tgSelectAll = document.querySelectorAll('.tg-global-checkbox:checked').length === document.querySelectorAll('.tg-global-checkbox').length;
    updateBatchButton();
}

function updateBatchButton() {
    const btn = document.querySelector('.tg-batch-actions .btn-primary');
    if (btn) {
        btn.textContent = `⬇️ Descargar seleccionados (${tgSelectedIds.size})`;
        btn.disabled = tgSelectedIds.size === 0;
        btn.style.opacity = tgSelectedIds.size > 0 ? '1' : '0.5';
        btn.style.pointerEvents = tgSelectedIds.size > 0 ? 'auto' : 'none';
    }
}

function batchDownloadSelected() {
    if (tgSelectedIds.size === 0) { showToast('Ningún video seleccionado', 'error'); return; }
    const items = [];
    document.querySelectorAll('.tg-global-item').forEach(row => {
        const cb = row.querySelector('.tg-global-checkbox');
        if (cb && cb.checked) {
            const url = row.querySelector('.tg-btn-download')?.dataset.url;
            const title = row.querySelector('.tg-global-name')?.textContent;
            if (url) items.push({ url, filename: (title || '').trim() });
        }
    });
    if (items.length === 0) { showToast('No se pudieron obtener los videos seleccionados', 'error'); return; }
    _openFolderModal({ type: 'batch', items });
}

function clearSelectAll() {
    tgSelectAll = false;
    tgSelectedIds.clear();
    document.querySelectorAll('.tg-global-checkbox').forEach(cb => cb.checked = false);
    updateBatchButton();
}

async function tgDeepSearch() {
    const q = document.getElementById('tgGlobalSearch').value.trim();
    if (!q) return;
    const resultsEl = document.getElementById('tgGlobalResults');
    resultsEl.innerHTML = '<div class="loading"><div class="spinner"></div><span>Búsqueda profunda: escaneando más foros...</span></div>';
    try {
        const res = await fetch(`${API}?action=telegram&tg=search_videos&q=${encodeURIComponent(q)}&mode=deep`);
        const data = await res.json();
        if (!data.success) {
            resultsEl.innerHTML = `<div class="empty-state" style="padding:40px"><span class="empty-icon">❌</span><p>Error: ${escapeHtml(data.error)}</p></div>`;
            return;
        }
        if (!data.count) {
            resultsEl.innerHTML = `<div class="empty-state" style="padding:40px"><span class="empty-icon">📭</span><p>Sin resultados en foros para "${escapeHtml(q)}"</p></div>`;
            return;
        }
        const html = data.results.map(r => {
            const size = r.video_size || '?';
            const title_v = r.video_title || 'video_' + r.video_id;
            const srcRow = (r.peer_id != null)
                ? (tgAllSources.find(s => s.peer_id == r.peer_id) || {})
                : (tgAllSources.find(s => s.id === r.source_id) || {});
            const msgUrl = buildTgMsgUrl(r.peer_id ?? r.source_id, r.video_id, srcRow.topic_id, r.source_type === 'forum_topic');
            return '<div class="tg-global-item">'
                + '<div class="tg-global-info">'
                + '<div class="tg-global-name">' + escapeHtml(title_v) + '</div>'
                + '<div class="tg-global-meta">'
                + '<span class="tg-card-type ' + (r.source_type === 'forum_topic' ? 'type-movie' : 'type-channel') + '">' + (r.source_type === 'forum_topic' ? 'Foro' : 'Canal') + '</span>'
                + '<span>📦 ' + size + '</span>'
                + '<span>📁 ' + escapeHtml(r.source_title) + '</span>'
                + '</div></div>'
                + '<div class="tg-global-actions">'
                + (msgUrl ? '<a class="tg-btn" href="' + msgUrl + '" target="_blank" rel="noopener" title="Abrir en Telegram">📨</a><button class="tg-btn tg-btn-roku" data-url="' + msgUrl + '" onclick="checkRoku(this)" title="Verificar compatibilidad con Roku">📺</button><span class="tg-roku-badge"></span>' : '')
                + '<button class="tg-btn tg-btn-preview" data-title="' + escapeHtml(title_v) + '" onclick="previewFromTitle(this.dataset.title)">🔍</button>'
                + '<button class="tg-btn tg-btn-download" data-url="' + escapeHtml(msgUrl) + '" data-title="' + escapeHtml(title_v) + '" onclick="downloadTelegramVideo(this.dataset.url, this.dataset.title)">⬇️</button>'
                + '</div>';
        }).join('');
        resultsEl.innerHTML = '<div class="tg-global-count">' + data.count + ' resultado' + (data.count > 1 ? 's' : '') + ' para "' + escapeHtml(q) + '" (canales + foros)</div>'
            + '<div class="tg-global-list">' + html + '</div>';
    } catch (e) {
        resultsEl.innerHTML = `<div class="empty-state" style="padding:40px"><span class="empty-icon">❌</span><p>Error de conexión</p></div>`;
    }
}
async function previewFromTitle(title) {
    let clean = title.replace(/\.[^.]+$/, '');
    clean = clean.replace(/^[^\w]+|[^\w]+$/g, '').replace(/[._\-]/g, ' ').replace(/\s+/g, ' ').trim();
    clean = clean.replace(/\b\d{4}\b/gi, '').replace(/\b(1080[pi]|720[pi]|480[pi]|2160[pi]|4k|uhd|hd|web[ -]?dl|blu[ -]?ray|hdtv|dvd[ -]?rip|br[ -]?rip|x264|x265|hevc|aac|ac3|dts|mp3|latino|espanol|castellano|sub[ -]?espanol|ingles|english|telesync|ts|cam|screener|r5|line|micro[ -]?hd|mkv|mp4|avi)\b/gi, '').replace(/\s+/g, ' ').trim();
    if (clean.length < 2) { previewTMDB({ tmdb_id: null, title: title, poster: null, overview: 'Título demasiado corto para buscar', rating: null, year: null }); return; }
    try {
        const res = await fetch(`${API}?action=tmdb_preview&q=${encodeURIComponent(clean)}`);
        const data = await res.json();
        if (data.success) {
            previewTMDB({ tmdb_id: data.tmdb_id, title: data.title, poster: data.poster, overview: data.overview, rating: data.rating, year: data.year });
        } else {
            previewTMDB({ tmdb_id: null, title: clean, poster: null, overview: 'Sin resultados en TMDB para: ' + clean, rating: null, year: null });
        }
    } catch (e) {
        previewTMDB({ tmdb_id: null, title: clean, poster: null, overview: 'Error de conexión', rating: null, year: null });
    }
}

async function checkRoku(btn) {
    const url = btn.dataset.url;
    const badge = btn.nextElementSibling;
    if (!url) { showToast('Sin enlace de Telegram', 'error'); return; }
    if (badge) { badge.textContent = ''; badge.className = 'tg-roku-badge'; badge.title = ''; }
    const prev = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳';
    try {
        const res = await fetch(`${API}?action=telegram&tg=roku_check`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ url })
        });
        const data = await res.json();
        renderRokuBadge(btn, data);
    } catch (e) {
        if (badge) { badge.textContent = '⚠️'; badge.className = 'tg-roku-badge warn'; badge.title = 'Error de conexión'; }
        showToast('Error verificando compatibilidad', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = prev;
    }
}

function renderRokuBadge(btn, data) {
    const badge = btn.nextElementSibling;
    if (!badge) return;
    const detail = (data.vcodec || '?') + ' · ' + (data.acodec || '?') + (data.container ? ' · ' + data.container : '');
    if (data && data.success && data.compatible) {
        badge.textContent = '✅';
        badge.className = 'tg-roku-badge ok';
        badge.title = `Compatible con Roku (${detail})`;
        showToast(`✅ Compatible con Roku — ${detail}`, 'success');
    } else if (data && data.success && data.status === 'unknown') {
        badge.textContent = '⚠️';
        badge.className = 'tg-roku-badge warn';
        badge.title = 'No se pudo determinar el códec del archivo';
        showToast('⚠️ Compatibilidad indeterminada', 'error');
    } else if (data && data.success) {
        badge.textContent = '❌';
        badge.className = 'tg-roku-badge bad';
        const reasons = (data.reasons || []).join(', ');
        badge.title = `No compatible: ${reasons || detail}`;
        showToast(`❌ No compatible con Roku — ${reasons || detail}`, 'error');
    } else {
        badge.textContent = '⚠️';
        badge.className = 'tg-roku-badge warn';
        badge.title = (data && data.error) || 'Error';
        showToast('No se pudo verificar: ' + ((data && data.error) || 'error'), 'error');
    }
}

function normalizeText(s) {
    return (s || '')
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[0134578]/g, c => ({ '0': 'o', '1': 'i', '3': 'e', '4': 'a', '5': 's', '7': 't', '8': 'b' }[c]));
}
let tgFilterCat = 'all';
let tgFilterText = '';

function tgInternalId(pid) {
    const s = String(pid ?? '');
    if (s.startsWith('-100')) return s.slice(4);
    const n = s.replace(/^-/, '');
    return /^\d+$/.test(n) ? n : '';
}

function buildTgMsgUrl(sourceId, videoId, topicId, isTopic) {
    const internal = tgInternalId(sourceId);
    if (!internal) return '';
    if (isTopic && topicId) return `https://t.me/c/${internal}/${topicId}/${videoId}`;
    return `https://t.me/c/${internal}/${videoId}`;
}

function initTelegram() {
    const $ = id => document.getElementById(id);

    $('refreshTelegram').addEventListener('click', loadTelegram);
    $('restartTelegram').addEventListener('click', restartTelegram);

    $('tgAddSourceBtn').addEventListener('click', () => {
        $('tgAddSourceForm').classList.remove('hidden');
        $('tgSourceUrl').focus();
    });
    $('tgAddSourceCancel').addEventListener('click', () => {
        $('tgAddSourceForm').classList.add('hidden');
        $('tgSourceUrl').value = '';
    });
    $('tgAddSourceSubmit').addEventListener('click', addSource);
    $('tgSourceUrl').addEventListener('keydown', e => { if (e.key === 'Enter') addSource(); });

    $('tgSourceSearch').addEventListener('input', () => {
        tgFilterText = normalizeText($('tgSourceSearch').value);
        renderSourcesGrid();
    });

    document.querySelectorAll('.tg-cat-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tg-cat-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            tgFilterCat = btn.dataset.cat;
            renderSourcesGrid();
        });
    });

    // Mapeo de categorías UI → filtros internos
    const tgCatFilterMap = {
        all: null,
        peliculas: 'movies',
        series: 'series',
        anime: 'anime',
        documentales: 'documentaries'
    };

    $('tgDownloadBtn').addEventListener('click', telegramDownload);
    $('tgDownloadUrl').addEventListener('keydown', e => { if (e.key === 'Enter') telegramDownload(); });

    $('tgPreviewClose').addEventListener('click', closePreview);
    document.querySelector('.tg-modal-backdrop')?.addEventListener('click', closePreview);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { closePreview(); closeTMDBPreview(); } });

    const tmdbBackdrop = document.querySelector('#tmdbPreviewModal .tg-modal-backdrop');
    if (tmdbBackdrop) tmdbBackdrop.addEventListener('click', closeTMDBPreview);
    const tmdbClose = document.querySelector('#tmdbPreviewModal .tg-modal-close');
    if (tmdbClose) tmdbClose.addEventListener('click', closeTMDBPreview);

    $('renameCancel').addEventListener('click', () => {
        $('renameModal').classList.add('hidden');
    });
    $('renameConfirm').addEventListener('click', async () => {
        const url = $('renameUrl').value;
        const filename = $('renameInput').value.trim();
        const folder = $('folderInput').value.trim() || null;
        showToast(`📁 Descargando a: ${folder || 'HDD_500GB (por defecto)'}`, 'info');
        $('renameModal').classList.add('hidden');
        const p = _folderPending;
        if (p && p.type === 'batch') {
            await _execBatchDownload(p.items, folder);
        } else if (p && p.type === 'torrent') {
            await _execTorrentDownload(p.torrent, folder);
        } else {
            await _execDownload(url, filename || null, folder);
        }
    });
    document.querySelector('#renameModal .tg-modal-backdrop')?.addEventListener('click', () => {
        $('renameModal').classList.add('hidden');
    });
    $('renameInput').addEventListener('keydown', e => {
        if (e.key === 'Enter') $('renameConfirm').click();
    });
    $('folderExploreBtn').addEventListener('click', () => {
        const explorer = $('folderExplorer');
        if (explorer.classList.contains('hidden')) {
            explorer.classList.remove('hidden');
            _browseTo(_lastFolder || '');
        } else {
            explorer.classList.add('hidden');
        }
    });
    $('folderRootBtn').addEventListener('click', () => {
        _lastFolder = '';
        _browsePath = '';
        $('folderInput').value = '';
        $('folderExplorer').classList.add('hidden');
        showToast('📁 Guardarás en HDD_500GB (por defecto)', 'info');
    });

    $('tgGlobalSearch').addEventListener('keydown', e => { if (e.key === 'Enter') tgGlobalSearch(); });
    $('tgGlobalSearchBtn').addEventListener('click', tgGlobalSearch);
    $('tgGlobalSearchClear').addEventListener('click', () => {
        $('tgGlobalSearch').value = '';
        $('tgGlobalResults').classList.add('hidden');
        $('tgGlobalSearchClear').classList.add('hidden');
        tgFilterText = '';
        $('tgSourcesGrid').classList.remove('hidden');
        $('tgSourcesEmpty').classList.remove('hidden');
        renderSourcesGrid();
    });

    return loadTelegram();
}

async function loadTelegram() {
    const statusEl = document.getElementById('tgStatus');
    statusEl.innerHTML = `<span class="tg-status-text"><em>Actualizando...</em></span>`;
    let scan = null;
    try {
        const res = await fetch(`${API}?action=telegram&tg=status`);
        const data = await res.json();
        if (data.success) {
            const dot = data.telegram_connected ? '🟢' : '🔴';
            scan = data.scan || null;
            statusEl.innerHTML = `
                <span class="tg-status-dot ${data.telegram_connected ? 'on' : 'off'}"></span>
                <span class="tg-status-text">Daemon: <strong>Activo</strong></span>
                <span class="tg-status-sep">·</span>
                <span class="tg-status-text">Telegram: <strong>${data.telegram_connected ? 'Conectado' : 'Desconectado'}</strong></span>
                <span class="tg-status-sep">·</span>
                <span class="tg-status-text">Fuentes: <strong>${data.sources_count}</strong></span>
                <span class="tg-status-sep">·</span>
                <span class="tg-status-text">Descargas activas: <strong>${data.active_downloads}</strong></span>
            ` + renderScanStatus(scan);
        } else {
            statusEl.innerHTML = `<span class="tg-status-dot off"></span><span class="tg-status-text">Daemon: <strong class="c-error">No conectado</strong></span>`;
        }
    } catch {
        statusEl.innerHTML = `<span class="tg-status-dot off"></span><span class="tg-status-text">Daemon: <strong class="c-error">No conectado</strong></span>`;
    }
    scheduleScanPoll(scan);
    await loadAuthStatus();
    await loadSources();
}

let scanPollTimer = null;

function renderScanStatus(scan) {
    if (!scan) {
        return `<div class="tg-scan-row"><span class="tg-scan-dot idle"></span><span class="tg-status-text">Escaneo: <strong>Sin escanear aún</strong></span></div>`;
    }
    if (scan.scanning) {
        const pct = scan.pct || 0;
        return `<div class="tg-scan-row">
            <span class="tg-scan-dot running"></span>
            <span class="tg-scan-text">Escaneando: <strong>${scan.done}/${scan.total} (${pct}%)</strong>${scan.current ? ` · <em>${escapeHtml(scan.current)}</em>` : ''}</span>
            <div class="tg-scan-bar"><div class="tg-scan-fill" style="width:${pct}%"></div></div>
        </div>`;
    }
    const fin = scan.finished_at ? ` · terminó ${scan.finished_at.slice(11)}` : '';
    return `<div class="tg-scan-row"><span class="tg-scan-dot done"></span><span class="tg-scan-text">Escaneo: <strong>Índice actualizado ${scan.done}/${scan.total}</strong>${fin}</span></div>`;
}

function scheduleScanPoll(scan) {
    if (scanPollTimer) { clearTimeout(scanPollTimer); scanPollTimer = null; }
    if (scan && scan.scanning) {
        scanPollTimer = setTimeout(async () => {
            try {
                const res = await fetch(`${API}?action=telegram&tg=status`);
                const data = await res.json();
                if (data.success && data.scan) {
                    const rowEl = document.querySelector('.tg-scan-row');
                    if (rowEl && rowEl.parentElement === document.getElementById('tgStatus')) {
                        rowEl.outerHTML = renderScanStatus(data.scan);
                        if (data.scan.scanning) scheduleScanPoll(data.scan);
                    }
                }
            } catch {}
        }, 10000);
    }
}


async function loadAuthStatus() {
    const el = document.getElementById('tgAuth');
    const formEl = document.getElementById('tgAuthForm');
    try {
        const res = await fetch(`${API}?action=telegram&tg=auth_status`);
        const data = await res.json();
        if (data.success && data.authenticated) { el.classList.add('hidden'); return; }
        el.classList.remove('hidden');
        const s = data.auth_status || 'UNKNOWN';
        let html = '';
        if (s === 'PHONE_REQUIRED' || s === 'UNKNOWN')
            html = `<p>Configura <code>phone</code> en <code>config.json</code></p><input type="text" id="tgPhone" placeholder="+521234567890"><button onclick="sendPhone()" class="btn-primary">Enviar</button>`;
        else if (s === 'CODE_REQUIRED')
            html = `<p>📱 Código enviado a Telegram:</p><input type="text" id="tgCode" placeholder="12345"><div style="display:flex;gap:8px"><button onclick="sendCode()" class="btn-primary" style="flex:1">Verificar</button><button onclick="resendCode()" class="btn-secondary" style="flex:1">Reenviar código</button></div>`;
        else if (s === '2FA_REQUIRED')
            html = `<p>🔐 Contraseña 2FA:</p><input type="password" id="tg2fa" placeholder="Contraseña"><button onclick="send2FA()" class="btn-primary">Verificar</button>`;
        else if (s === 'API_ID_REQUIRED')
            html = `<p>❌ Configura <code>api_id</code> y <code>api_hash</code> en <code>config.json</code></p>`;
        formEl.innerHTML = html;
    } catch { el.classList.add('hidden'); }
}

async function sendPhone() { showToast('Configura el número en config.json y reinicia', 'info'); }

async function sendCode() {
    const code = document.getElementById('tgCode').value.trim();
    if (!code) { showToast('Ingresa el código', 'error'); return; }
    try {
        const res = await fetch(`${API}?action=telegram&tg=auth_code`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code })
        });
        const d = await res.json();
        if (d.success) { showToast('✅ Autenticado', 'success'); loadTelegram(); }
        else { showToast(d.error || 'Error', 'error'); loadAuthStatus(); }
    } catch { showToast('Error de conexión', 'error'); }
}

async function send2FA() {
    const pwd = document.getElementById('tg2fa').value.trim();
    if (!pwd) { showToast('Ingresa la contraseña', 'error'); return; }
    try {
        const res = await fetch(`${API}?action=telegram&tg=auth_2fa`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: pwd })
        });
        const d = await res.json();
        if (d.success) { showToast('✅ 2FA verificado', 'success'); loadTelegram(); }
        else showToast(d.error || 'Error', 'error');
    } catch { showToast('Error de conexión', 'error'); }
}

async function loadSources() {
    try {
        const res = await fetch(`${API}?action=telegram&tg=sources`);
        const data = await res.json();
        if (!data.success || !data.sources.length) {
            document.getElementById('tgSourcesGrid').innerHTML = '';
            document.getElementById('tgSourcesEmpty').classList.remove('hidden');
            return;
        }
        document.getElementById('tgSourcesEmpty').classList.add('hidden');
        tgAllSources = data.sources;
        renderSourcesGrid();
    } catch (e) { console.error(e); }
}

const _bfPollers = {};

async function renderSourcesGrid() {
    const grid = document.getElementById('tgSourcesGrid');
    let sources = tgAllSources;

    const tgCatToDbMap = {
        all: null,
        peliculas: 'Películas',
        series: 'Series',
        anime: 'Anime',
        documentales: 'Documentales'
    };

    if (tgFilterCat && tgCatToDbMap[tgFilterCat]) {
        const dbCat = tgCatToDbMap[tgFilterCat];
        sources = sources.filter(s => s.category === dbCat);
    } else if (tgFilterCat === 'channel') {
        sources = sources.filter(s => s.type === 'channel' && s.topic_id === null);
    } else if (tgFilterCat === 'movies') {
        sources = sources.filter(s => s.type === 'forum_topic' && s.peer_id === 2196080366);
    } else if (tgFilterCat === 'series') {
        sources = sources.filter(s => s.type === 'forum_topic' && s.peer_id === 2246141105);
    }

    if (tgFilterText) {
        const toks = tgFilterText.split(/\s+/).filter(Boolean);
        sources = sources.filter(s => {
            const hay = normalizeText((s.title || '') + ' ' + (s.username || ''));
            if (toks.every(t => hay.includes(t))) return true;
            const words = hay.split(/[^a-z0-9]+/).filter(Boolean);
            return toks.every(t => {
                if (t.length < 4) return false;
                const pfx = t.slice(0, 5);
                return words.some(w => w.startsWith(pfx));
            });
        });
    }

    if (!sources.length) {
        grid.innerHTML = `<div class="empty-state" style="padding:40px"><span class="empty-icon">📭</span><p>Sin resultados</p></div>`;
        return;
    }

    // Detectar backfills activos que no tienen polling (ej. tras F5)
    for (const s of sources) {
        if (s.type === 'channel' && !s.backfilled_at) {
            if (_bfPollers[s.id]) continue;
            if (!sessionStorage.getItem('bf_' + s.id)) continue;
            await new Promise(r => setTimeout(r, 200));
            try {
                const r = await fetch(`${API}?action=telegram&tg=backfill_channel_status&id=${s.id}`);
                const st = await r.json();
                if (st.running) {
                    s._bf_running = true;
                    s._bf_indexed = st.indexed || 0;
                    s._bf_pages = st.pages || 0;
                    s._bf_total = st.total_pages || 0;
                    startBackfillPoll(s.id, true);
                }
            } catch {}
        }
    }

    grid.innerHTML = sources.map(s => renderSourceCard(s)).join('');
}

function startBackfillPoll(id, silent = false) {
    if (_bfPollers[id]) clearInterval(_bfPollers[id]);
    _bfPollers[id] = setInterval(async () => {
        try {
            const r = await fetch(`${API}?action=telegram&tg=backfill_channel_status&id=${id}`);
            const st = await r.json();
            const card = document.querySelector(`.tg-card[data-id="${id}"]`);
            const btn = card?.querySelector('.tg-btn-backfill');
            if (btn) {
                const p = st.pages || 0;
                const t = st.total_pages || 0;
                btn.textContent = t ? `📥 ${st.indexed} vids · pág ${p}/${t}` : `📥 ${st.indexed || 0} vids`;
            }
            if (!st.running) {
                clearInterval(_bfPollers[id]);
                delete _bfPollers[id];
                sessionStorage.removeItem('bf_' + id);
                if (!silent) showToast(`✅ Backfill completado: ${st.indexed} videos`, 'success');
                loadSources();
            }
        } catch {
            clearInterval(_bfPollers[id]);
            delete _bfPollers[id];
        }
    }, 5000);
}

function renderSourceCard(s) {
    const id = s.id;
    const title = escapeHtml(s.title || 'Sin título');
    const type = s.type;
    const isTopic = type === 'forum_topic';
    const isChannel = type === 'channel';
    const pid = s.peer_id;
    const topicId = s.topic_id;

    let icon = '📺';
    if (type === 'forum_topic') {
        icon = pid === 2246141105 ? '📺' : '🎬';
    } else if (type === 'channel') {
        icon = '📡';
    }

    let typeLabel = isTopic ? (pid === 2246141105 ? 'Serie' : 'Película') : 'Canal';
    let typeClass = isTopic ? (pid === 2246141105 ? 'type-series' : 'type-movie') : 'type-channel';

    let autoBadge = '';
    if (s.auto_download && s.auto_download !== 'manual') {
        autoBadge = `<span class="tg-card-badge tg-badge-auto" title="Auto: ${s.auto_download}">${s.auto_download === 'filtered' ? '⚙️' : '🤖'}</span>`;
    }

    const catEmojiMap = {
        'Películas': '🎬',
        'Series': '📺',
        'Anime': '🅰️',
        'Documentales': '📚',
        'General': '📡'
    };
    const catBadge = s.category && catEmojiMap[s.category]
        ? `<span class="tg-card-badge tg-badge-category" title="${s.category}">${catEmojiMap[s.category]}</span>`
        : '';

    // Video count badge from backfill data
    const bfIndexed = s._bf_indexed || 0;
    const bfTotal = s._bf_total || 0;
    const videoBadge = bfIndexed > 0
        ? `<span class="tg-card-badge tg-badge-videos" title="${bfIndexed} videos">${bfTotal > 0 ? `📥 ${bfIndexed}/${bfTotal} pág` : `📥 ${bfIndexed} vids`}</span>`
        : '';

    const neverBackfilled = isChannel && !s.backfilled_at;
    const isRunning = s._bf_running;
    const backfillBadge = isRunning
        ? `<span class="tg-badge-running">📥 Indexando...</span>`
        : neverBackfilled
            ? `<span class="tg-badge-pending">⏳ Pendiente</span>`
            : (isChannel && s.backfilled_at ? `<span class="tg-badge-done">✅ Indexado</span>` : '');

    return `
        <div class="tg-card" data-id="${id}">
            <div class="tg-card-head">
                <span class="tg-card-icon">${icon}</span>
                <div class="tg-card-info">
                    <div class="tg-card-title" title="${title}">${title}</div>
                    <div class="tg-card-meta">
                        <span class="tg-card-type ${typeClass}">${typeLabel}</span>
                        ${autoBadge}
                        ${catBadge}
                        ${videoBadge}
                        ${backfillBadge}
                        <span class="tg-card-id">ID ${id}</span>
                    </div>
                </div>
            </div>
            <div class="tg-card-actions">
                <button class="tg-btn tg-btn-preview" onclick="previewSource(${id})" title="Previsualizar videos">👁️ Preview</button>
                ${isChannel && isRunning ? `
                    <button class="tg-btn tg-btn-backfill" disabled title="Backfill en progreso">📥 ${s._bf_indexed} vids · pág ${s._bf_pages}/${s._bf_total}</button>
                ` : ''}
                ${isChannel && !isRunning && neverBackfilled ? `
                    <button class="tg-btn tg-btn-backfill" onclick="backfillChannel(${id})" title="Backfill completo del canal">📥 Backfill</button>
                ` : ''}
                ${isChannel && !neverBackfilled ? `
                    <button class="tg-btn tg-btn-scan" onclick="scanChannel(${id})" title="Escanear nuevos videos">🔄 Scan</button>
                ` : ''}
                ${isTopic ? `
                    <button class="tg-btn tg-btn-dl" onclick="downloadTopicVideo(${pid},${topicId})" title="Descargar todos los videos nuevos">⬇️ DL</button>
                ` : ''}
                <button class="tg-btn tg-btn-del" onclick="removeSource(${id})" title="Eliminar fuente">✕</button>
            </div>
        </div>
    `;
}

async function backfillChannel(id) {
    try {
        const res = await fetch(`${API}?action=telegram&tg=backfill_channel&id=${id}`, { method: 'POST' });
        const d = await res.json();
        if (!d.success) {
            showToast('❌ ' + (d.error || 'Error'), 'error');
            return;
        }
        showToast('📥 Backfill iniciado', 'success');
        sessionStorage.setItem('bf_' + id, '1');
        loadSources();
    } catch {
        showToast('❌ Error de conexión', 'error');
    }
}

async function scanChannel(id) {
    const card = document.querySelector(`.tg-card[data-id="${id}"]`);
    const btn = card?.querySelector('.tg-btn-scan');
    if (btn) { btn.disabled = true; btn.textContent = '⏳...'; }

    try {
        const res = await fetch(`${API}?action=telegram&tg=scan_channel&id=${id}`, { method: 'POST' });
        const d = await res.json();
        if (!d.success) {
            showToast('❌ ' + (d.error || 'Error'), 'error');
        } else {
            showToast(`✅ Scan completado: ${d.videos_found || 0} videos nuevos`, d.videos_found > 0 ? 'success' : 'info');
            if (d.videos_found > 0) loadSources();
        }
    } catch {
        showToast('❌ Error de conexión', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = '🔄 Scan'; }
    }
}

let tgPreviewState = { sourceId: 0, videos: [], oldestId: 0, loading: false };

async function previewSource(id, replace = true) {
    const modal = document.getElementById('tgPreviewModal');
    const titleEl = document.getElementById('tgPreviewTitle');
    const bodyEl = document.getElementById('tgPreviewBody');

    modal.classList.remove('hidden');

    if (replace) {
        tgPreviewState = { sourceId: id, videos: [], oldestId: 0, loading: false };
        titleEl.textContent = 'Cargando...';
        bodyEl.innerHTML = '<div class="tg-loading">🔍 Escaneando videos...</div>';
    } else {
        bodyEl.innerHTML += '<div class="tg-loading">⏳ Cargando más...</div>';
    }

    const source = tgAllSources.find(s => s.id === id);
    if (replace && source) titleEl.textContent = `🎬 ${escapeHtml(source.title)}`;

    tgPreviewState.loading = true;
    try {
        const limit = 100;
        const offsetId = replace ? 0 : tgPreviewState.oldestId;
        const res = await fetch(`${API}?action=telegram&tg=preview&id=${id}&limit=${limit}&offset_id=${offsetId}`);
        const data = await res.json();

        if (!data.success) {
            bodyEl.innerHTML = `<div class="empty-state" style="padding:40px"><span class="empty-icon">❌</span><p>Error: ${escapeHtml(data.error)}</p></div>`;
            tgPreviewState.loading = false;
            return;
        }

        const isTopic = source && source.type === 'forum_topic';
        const pid = source ? source.peer_id : 0;
        const topicId = source ? source.topic_id : 0;

        const oldCount = tgPreviewState.videos.length;
        if (data.videos && data.videos.length) {
            tgPreviewState.videos = replace ? data.videos : tgPreviewState.videos.concat(data.videos);
            tgPreviewState.oldestId = data.oldest_id || 0;
        }

        if (!tgPreviewState.videos.length) {
            bodyEl.innerHTML = `<div class="empty-state" style="padding:40px"><span class="empty-icon">📭</span><p>Sin videos disponibles</p></div>`;
            tgPreviewState.loading = false;
            return;
        }

        const hasMore = data.has_more;
        const total = tgPreviewState.videos.length;
        const loadMoreBtn = hasMore
            ? `<button class="btn-primary" onclick="previewSource(${id}, false)" style="display:block;margin:12px auto;padding:8px 20px">⬇️ Cargar más (${total} actualmente)</button>`
            : '';

        let html = '<div class="tg-preview-count">' + total + ' video' + (total > 1 ? 's' : '') + ' encontrados</div>'
            + '<div class="tg-preview-list">';

        for (const v of tgPreviewState.videos) {
            const size = v.size || '?';
            const date = v.date ? new Date(v.date).toLocaleDateString('es-MX', {day:'2-digit',month:'short',year:'numeric'}) : '?';
            const title_v = v.title || 'video_' + v.id + '.mp4';
            const msgUrl = isTopic
                ? 't.me/c/' + pid + '/' + topicId + '/' + v.id
                : 't.me/c/' + pid + '/' + v.id;
            html += '<div class="tg-preview-item">'
                + '<div class="tg-preview-info">'
                + '<div class="tg-preview-name" title="' + escapeHtml(title_v) + '">' + escapeHtml(title_v) + '</div>'
                + '<div class="tg-preview-meta">'
                + '<span>📦 ' + size + '</span>'
                + '<span>📅 ' + date + '</span>'
                + '</div></div>'
                + '<button class="tg-btn tg-btn-download" data-url="' + escapeHtml(msgUrl) + '" data-title="' + escapeHtml(title_v) + '" onclick="downloadTelegramVideo(this.dataset.url, this.dataset.title)" title="Descargar este video">⬇️</button>'
                + '</div>';
        }

        html += '</div>' + loadMoreBtn;
        bodyEl.innerHTML = html;
    } catch (e) {
        bodyEl.innerHTML = '<div class="empty-state" style="padding:40px"><span class="empty-icon">❌</span><p>Error al cargar videos</p></div>';
    }
    tgPreviewState.loading = false;
}

function closePreview() {
    document.getElementById('tgPreviewModal').classList.add('hidden');
}

async function _execDownload(url, filename, folder) {
    try {
        const body = { url };
        if (filename) body.filename = filename;
        if (folder) body.folder = folder;
        const res = await fetch(`${API}?action=telegram&tg=download`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        const d = await res.json();
        if (d.success) {
            showToast('✅ Descarga iniciada', 'success');
            document.querySelector('[data-tab="queue"]').click();
        } else {
            showToast(d.error || 'Error', d.already ? 'info' : 'error');
        }
    } catch { showToast('Error de conexión', 'error'); }
}

async function _execBatchDownload(items, folder) {
    if (!items || !items.length) { showToast('Ningún video seleccionado', 'error'); return; }
    showToast(`📁 Enviando ${items.length} descarga${items.length > 1 ? 's' : ''} a: ${folder || 'HDD_500GB (por defecto)'}`, 'info');
    let ok = 0, fail = 0;
    await Promise.all(items.map(async (it) => {
        try {
            const body = { url: it.url };
            if (it.filename) body.filename = it.filename;
            if (folder) body.folder = folder;
            const res = await fetch(`${API}?action=telegram&tg=download`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            const d = await res.json();
            if (d.success) ok++; else fail++;
        } catch { fail++; }
    }));
    if (ok > 0) {
        showToast(`✅ ${ok} en cola${fail ? ` · ${fail} con error` : ''}`, 'success');
        tgSelectedIds.clear();
        document.querySelectorAll('.tg-global-checkbox').forEach(cb => cb.checked = false);
        updateBatchButton();
        document.querySelector('[data-tab="queue"]').click();
    } else {
        showToast('No se pudo iniciar ninguna descarga', 'error');
    }
}

async function downloadTopicVideo(peerId, topicId) {
    showToast('⏳ Iniciando descarga de topic...', 'info');
    try {
        const res = await fetch(`${API}?action=telegram&tg=scan`, { method: 'POST' });
        const d = await res.json();
        if (d.success) {
            showToast(`✅ ${d.downloaded} descargas iniciadas`, 'success');
            loadQueue();
        } else {
            showToast(d.error || 'Error', 'error');
        }
    } catch { showToast('Error de conexión', 'error'); }
}

async function addSource() {
    const url = document.getElementById('tgSourceUrl').value.trim();
    if (!url) { showToast('Ingresa un enlace', 'error'); return; }
    try {
        const res = await fetch(`${API}?action=telegram&tg=add_source`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ url })
        });
        const d = await res.json();
        if (d.success) {
            const backfillMsg = d.backfill ? ' 📥 Indexando en background...' : '';
            showToast(`✅ Agregado: ${d.title}${backfillMsg}`, 'success');
            document.getElementById('tgSourceUrl').value = '';
            document.getElementById('tgAddSourceForm').classList.add('hidden');
            loadSources();
            loadTelegram();
            // Si hay auto-backfill, refrescar sources en 5s para ver cambio de estado
            if (d.backfill) setTimeout(loadSources, 5000);
        } else {
            showToast(d.error || 'Error', 'error');
        }
    } catch { showToast('Error de conexión', 'error'); }
}

async function restartTelegram() {
    if (!confirm('¿Reiniciar el Daemon de Telegram?')) return;
    const btn = document.getElementById('restartTelegram');
    btn.disabled = true;
    btn.textContent = '⏳...';
    try {
        const res = await fetch(`_start_daemon.php?action=restart`);
        const d = await res.json();
        if (d.success) {
            showToast('✅ Daemon reiniciado', 'success');
            setTimeout(loadTelegram, 3000);
        } else {
            showToast('❌ Error: ' + (d.output || 'desconocido'), 'error');
        }
    } catch {
        showToast('❌ Error de conexión', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = '🔁 Reiniciar';
    }
}

async function removeSource(id) {
    if (!confirm('¿Eliminar esta fuente?')) return;
    try {
        const res = await fetch(`${API}?action=telegram&tg=remove_source&id=${id}`, { method: 'DELETE' });
        const d = await res.json();
        if (d.success) { showToast('Eliminada', 'success'); loadSources(); loadTelegram(); }
        else showToast(d.error || 'Error', 'error');
    } catch { showToast('Error de conexión', 'error'); }
}

let _browsePath = '';

let _folderPending = null;

let _lastFolder = '';

function _renderBreadcrumb() {
    const parts = _browsePath ? _browsePath.split('/') : [];
    const crumbs = [{ label: 'BUNKER', path: '' }, ...parts.map((p, i) => ({
        label: p,
        path: parts.slice(0, i + 1).join('/')
    }))];
    document.getElementById('folderBreadcrumb').innerHTML = crumbs.map(c =>
        `<span class="folder-crumb" data-path="${escapeHtml(c.path)}">${escapeHtml(c.label)}</span>`
    ).join('<span style="opacity:0.4">›</span>');
    document.querySelectorAll('#folderBreadcrumb .folder-crumb').forEach(el => {
        el.addEventListener('click', () => _browseTo(el.dataset.path));
    });
}

async function _browseTo(rel) {
    _browsePath = rel || '';
    _lastFolder = _browsePath;
    document.getElementById('folderInput').value = _browsePath;
    const container = document.getElementById('folderChildren');
    container.innerHTML = '<div class="folder-empty">⏳ Cargando…</div>';
    _renderBreadcrumb();
    try {
        const q = rel ? `&parent=${encodeURIComponent(rel)}` : '';
        const res = await fetch(`${API}?action=folders${q}`);
        const d = await res.json();
        container.innerHTML = '';
        if (!d.success) {
            container.innerHTML = `<div class="folder-empty">Error: ${escapeHtml(d.error || 'desconocido')}</div>`;
            return;
        }
        const children = rel ? (d.children || []) : (d.roots || []);
        const files = rel ? (d.files || []) : [];
        if (!children.length && !files.length) {
            container.innerHTML = '<div class="folder-empty">Carpeta vacía</div>';
            return;
        }
        children.forEach(c => {
            const name = c.split('/').pop();
            const row = document.createElement('div');
            row.className = 'folder-row';
            row.innerHTML = `<span class="folder-row-name">📁 ${escapeHtml(name)}</span>` +
                `<span class="folder-row-chevron">›</span>`;
            row.querySelector('.folder-row-name').addEventListener('click', () => _browseTo(c));
            container.appendChild(row);
        });
        if (files.length) {
            const grid = document.createElement('div');
            grid.className = 'folder-files';
            files.forEach(f => {
                const chip = document.createElement('span');
                chip.className = 'folder-file';
                chip.title = `${f.name} (${f.size_h})`;
                chip.textContent = `🎬 ${f.name} · ${f.size_h}`;
                grid.appendChild(chip);
            });
            container.appendChild(grid);
        }
    } catch {
        container.innerHTML = '<div class="folder-empty">Error de conexión</div>';
    }
}

async function _openFolderModal(p) {
    _folderPending = p;
    document.getElementById('renameUrl').value = p.url || '';
    const nameInput = document.getElementById('renameInput');
    const confirmBtn = document.getElementById('renameConfirm');
    if (p.type === 'batch') {
        nameInput.value = '';
        nameInput.disabled = true;
        nameInput.placeholder = `Se descargarán ${p.items.length} archivos (con el nombre original de cada uno)`;
        if (confirmBtn) confirmBtn.textContent = `Descargar ${p.items.length}`;
    } else {
        nameInput.disabled = false;
        nameInput.placeholder = '';
        nameInput.value = p.filename || '';
        if (confirmBtn) confirmBtn.textContent = 'Descargar';
    }
    document.getElementById('folderInput').value = _lastFolder || '';
    _browsePath = _lastFolder || '';
    document.getElementById('renameModal').classList.remove('hidden');
    const explorer = document.getElementById('folderExplorer');
    explorer.classList.remove('hidden');
    _browseTo(_lastFolder || '');
    if (_lastFolder) showToast(`📁 Carpeta vigente: ${_lastFolder} (cámbiala en el explorador)`, 'info');
    if (p.type !== 'batch') setTimeout(() => nameInput.focus(), 100);
}

function downloadTelegramVideo(tgUrl, defaultName) {
    _openFolderModal({ type: 'tg', url: tgUrl, filename: defaultName });
}

async function telegramDownload() {
    const url = document.getElementById('tgDownloadUrl').value.trim();
    if (!url) { showToast('Ingresa un enlace con message_id', 'error'); return; }
    _openFolderModal({ type: 'tg', url, filename: '' });
}
async function resendCode() {
    try {
        const res = await fetch(`${API}?action=telegram&tg=auth_resend`, { method: "POST" });
        const d = await res.json();
        if (d.success) showToast("📱 Código reenviado", "success");
        else showToast(d.error || "Error", "error");
    } catch { showToast("Error de conexión", "error"); }
}

