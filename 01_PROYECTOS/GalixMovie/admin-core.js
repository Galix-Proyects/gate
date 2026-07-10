        // 🚀 DHARMA Premium UI: Smooth card panel expand/contract transition
        function toggleCardBody(bodyId, chevronId) {
            const body = document.getElementById(bodyId);
            const chevron = document.getElementById(chevronId);
            if (!body) return;

            const isCollapsed = body.style.maxHeight === '0px';

            if (isCollapsed) {
                // Expand
                body.style.maxHeight = body.scrollHeight + 'px';
                body.style.opacity = '1';
                if (chevron) chevron.style.transform = 'rotate(-180deg)';

                // Allow dynamic height changes after transition completes
                const onTransitionEnd = () => {
                    if (body.style.maxHeight !== '0px') {
                        body.style.maxHeight = 'none';
                    }
                    body.removeEventListener('transitionend', onTransitionEnd);
                };
                body.addEventListener('transitionend', onTransitionEnd);
            } else {
                // Collapse
                // Set explicit height to start the transition
                body.style.maxHeight = body.scrollHeight + 'px';
                body.offsetHeight; // Force reflow
                body.style.maxHeight = '0px';
                body.style.opacity = '0';
                if (chevron) chevron.style.transform = 'rotate(0deg)';
            }
        }

        function tmdbRow(r, checked, borderColor) {
            const defChecked = checked ? 'checked' : '';
            const border = borderColor ? 'border:1px solid ' + borderColor + ';' : 'border:1px solid #334155;';
            const bg = borderColor ? 'background:rgba(0,0,0,0.15);' : 'background:rgba(255,255,255,0.03);';
            const statusBadge = r.status === 'no_match' ? '<span style="color:#ef4444;font-size:0.7rem;">⚠️ Sin match</span>' :
                (r.status === 'skipped' ? '<span style="color:#64748b;font-size:0.7rem;">⏭️ Ignorado</span>' :
                (!r.confidence && r.status === 'ok' ? '<span style="color:#f59e0b;font-size:0.7rem;">⚠️ Baja confianza</span>' : ''));
            const safetyWarn = r.safety_msg ? '<br><span style="color:#f59e0b;font-size:0.7rem;">⚠️ ' + r.safety_msg + '</span>' : '';
            const episodeInfoBadge = r.is_episode && r.tmdb_title ?
                '<div style="font-size:0.75rem;color:#e2e8f0;margin-top:2px;">📺 <span style="color:#10b981;font-weight:600;">' + escHtml(r.episode_info || r.file) + '</span> — ' + escHtml(r.tmdb_title) + '</div>' : '';
            const proposedLine = !r.is_episode && r.status !== 'no_match' && r.proposed_name && r.proposed_name !== r.file ?
                '<div style="font-size:0.75rem;color:#e2e8f0;margin-top:2px;">⇒ <span style="color:#10b981;font-weight:600;">' + escHtml(r.proposed_name) + '</span></div>' : '';
            const orphanLine = r.status === 'orphaned_rename' ?
                '<div style="font-size:0.72rem;color:#7dd3fc;margin-top:2px;">🔄 Renombre manual — se actualizarán metadatos</div>' : '';
            const tmdbId = r.tmdb_id || '';
            const fileEsc = escHtml(r.file);
            const pathEsc = r.path ? escHtml(r.path) : '';
            return `
                <div style="${bg}${border}border-radius:8px;padding:10px;margin-bottom:6px;">
                    <label style="cursor:pointer;display:flex;align-items:flex-start;gap:10px;">
                        <input type="checkbox" class="scan-approve" data-index="${r.index}" ${defChecked} style="width:18px;height:18px;accent-color:#10b981;margin-top:3px;">
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:0.78rem;color:#94a3b8;">${fileEsc} ${statusBadge} ${fileMatchBadge(r)}</div>
                            <div style="font-size:0.65rem;color:#475569;margin-top:1px;word-break:break-all;">${pathEsc}</div>
                            ${episodeInfoBadge}${proposedLine}${orphanLine}${safetyWarn}
                            <div style="display:flex;gap:6px;margin-top:4px;align-items:center;">
                                <input type="text" class="scan-tmdb-id" data-index="${r.index}" value="${tmdbId}" placeholder="TMDB ID" style="width:100px;padding:3px 6px;border:1px solid #334155;border-radius:4px;background:#0f172a;color:#e2e8f0;font-size:0.75rem;">
                                <a href="https://www.themoviedb.org/search?query=${encodeURIComponent(r.file)}" target="_blank" rel="noopener" style="text-decoration:none;font-size:0.7rem;padding:3px 8px;border-radius:4px;background:#1e293b;color:#60a5fa;border:1px solid #334155;white-space:nowrap;" title="Buscar en TMDB">🔍 Buscar</a>
                                ${r.status === 'no_match' ? `<button onclick="ignoreFile('${escHtml(r.file).replace(/'/g, "\\'")}',this)" style="font-size:0.7rem;padding:3px 8px;border-radius:4px;background:#1e293b;color:#94a3b8;border:1px solid #334155;cursor:pointer;white-space:nowrap;" title="Ignorar este archivo en futuros escaneos">⏭️ Ignorar</button>` : ''}
                            </div>
                        </div>
                    </label>
                </div>`;
        }

        async function ignoreFile(fileName, btn) {
            btn.disabled = true;
            btn.textContent = '⏳';
            try {
                const row = btn.closest('div[style*="border-radius:8px;padding:10px"]');
                const tmdbInput = row ? row.querySelector('.scan-tmdb-id') : null;
                const tmdbId = tmdbInput ? tmdbInput.value.trim() : '';
                let url = 'backend/scrapper.php?action=skip_add&file=' + encodeURIComponent(fileName);
                if (tmdbId) url += '&tmdb_id=' + encodeURIComponent(tmdbId);
                const res = await fetch(url);
                const data = await res.json();
                if (data.status === 'ok') {
                    btn.textContent = '✅ Ignorado';
                    btn.style.color = '#10b981';
                    btn.style.borderColor = '#10b981';
                    if (row) row.style.opacity = '0.4';
                } else {
                    btn.textContent = '❌ Error';
                }
            } catch (e) {
                btn.textContent = '❌ Error';
            }
        }

        function escHtml(str) {
            const d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        function fileMatchBadge(r) {
            if (r.is_episode) return '<span style="color:#10b981;font-size:0.7rem;margin-left:6px;" title="Episodio de serie">📺</span>';
            if (!r.tmdb_title || r.status === 'no_match' || r.status === 'orphaned_rename') return '';
            const f = r.file.toLowerCase();
            const t = r.tmdb_title.toLowerCase();
            const nameMatch = f.includes(t) || t.includes(f);
            let yearMatch = true;
            const fileYear = f.match(/\((\d{4})\)$/);
            if (fileYear && r.tmdb_year) {
                yearMatch = fileYear[1] === String(r.tmdb_year);
            }
            const ok = nameMatch && yearMatch;
            return ok
                ? '<span style="color:#10b981;font-size:0.85rem;margin-left:6px;" title="Nombre coincide con metadatos">✅</span>'
                : '<span style="color:#ef4444;font-size:0.85rem;margin-left:6px;" title="Nombre NO coincide con metadatos">❌</span>';
        }

        async function runScan() {
            const btn = document.getElementById('scanBtn');
            const consoleBox = document.getElementById('consoleOutput');
            const progressContainer = document.getElementById('progressBarContainer');
            const progressFill = document.getElementById('progressBarFill');
            const progressText = document.getElementById('progressBarText');
            const progressMsg = document.getElementById('progressBarMsg');
            btn.disabled = true;

            consoleBox.innerHTML = '> 🔍 Iniciando escaneo inteligente...<br>';
            consoleBox.scrollTop = consoleBox.scrollHeight;
            consoleBox.classList.add('scanning');
            progressContainer.style.display = 'block';
            progressFill.style.width = '0%';
            progressText.textContent = '0%';
            progressMsg.textContent = 'Iniciando...';

            try {
                // ── 1. PREVIEW (XHR Streaming) ─────────────────────────
                const data = await new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    xhr.open('GET', 'backend/scrapper.php?action=preview&skip_indexed=1');

                    let timedOut = false;
                    const timeoutId = setTimeout(() => {
                        timedOut = true;
                        xhr.abort();
                    }, 600000);

                    let prevLen = 0;
                    let result = null;
                    let xhrError = null;

                    xhr.onprogress = () => {
                        const chunk = xhr.responseText.substring(prevLen);
                        prevLen = xhr.responseText.length;
                        let nl, start = 0;
                        while ((nl = chunk.indexOf('\n', start)) >= 0) {
                            const line = chunk.substring(start, nl).trim();
                            start = nl + 1;
                            if (!line) continue;
                            try {
                                const obj = JSON.parse(line);
                                if (obj.type === 'progress') {
                                    if (obj.total > 0) {
                                        const pct = Math.round((obj.current / obj.total) * 100);
                                        progressFill.style.width = pct + '%';
                                        progressText.textContent = pct + '%';
                                    } else {
                                        progressFill.style.width = '';
                                        progressText.textContent = '';
                                    }
                                    const statusIcon = obj.msg === 'OK' ? '✅' : (obj.msg === 'Sin match' ? '⚠️' : (obj.msg === 'Baja confianza' ? '⚠️' : (obj.msg === 'Renombre manual' ? '🔄' : '')));
                                    if (obj.current > 0 && obj.total > 0) {
                                        progressMsg.textContent = '[' + obj.current + '/' + obj.total + '] ' + (obj.file || obj.msg);
                                    } else {
                                        progressMsg.textContent = obj.msg;
                                    }
                                    const icon = obj.msg === 'OK' ? '✅' : '⚠️';
                                    const line = obj.file
                                        ? `> ${icon} ${obj.msg}: ${obj.file}`
                                        : `> ${obj.msg}`;
                                    consoleBox.innerHTML += line + '<br>';
                                    consoleBox.scrollTop = consoleBox.scrollHeight;
                                } else if (obj.type === 'error') {
                                    xhrError = new Error(obj.message);
                                } else if (obj.type === 'result') {
                                    result = obj;
                                }
                            } catch (e) { /* saltar líneas inválidas */ }
                        }
                    };

                    xhr.onload = () => {
                        clearTimeout(timeoutId);
                        if (xhrError) return reject(xhrError);
                        const ct = xhr.getResponseHeader('Content-Type') || '';
                        if (!ct.includes('ndjson')) {
                            return reject(new Error(
                                'El servidor devolvió formato incorrecto. ' +
                                xhr.responseText.substring(0, 200)
                            ));
                        }
                        // Último fragmento (result)
                        if (xhr.responseText) {
                            const lines = xhr.responseText.split('\n');
                            for (const line of lines) {
                                if (!line.trim()) continue;
                                try {
                                    const obj = JSON.parse(line.trim());
                                    if (obj.type === 'result') result = obj;
                                    else if (obj.type === 'error') return reject(new Error(obj.message));
                                } catch (e) {}
                            }
                        }
                        resolve(result || { status: 'error', message: 'Sin resultados' });
                    };

                    xhr.onabort = () => {
                        clearTimeout(timeoutId);
                        const err = new Error(timedOut ? 'La petición tardó demasiado (>5 min)' : 'Abortado');
                        err.name = 'AbortError';
                        reject(err);
                    };

                    xhr.onerror = () => {
                        clearTimeout(timeoutId);
                        reject(new Error('Error de red'));
                    };

                    xhr.send();
                });

                consoleBox.classList.remove('scanning');
                progressContainer.style.display = 'none';
                if (data && data.status === 'success') {
                    consoleBox.innerHTML += '> ✅ Escaneo completado (' + (data.results?.length || 0) + ' archivos)<br>';
                    consoleBox.scrollTop = consoleBox.scrollHeight;
                }

                if (!data || data.status !== 'success') {
                    Swal.close();
                    const msg = (data && data.message) || 'Error al escanear';
                    Swal.fire('Error', msg, 'error');
                    consoleBox.innerHTML += '❌ Error: ' + msg + '<br>';
                    return;
                }

                const results = data.results;

                if (results.length === 0) {
                    Swal.close();
                    await Swal.fire({ icon: 'info', title: 'Sin archivos', text: 'No se encontraron archivos en el directorio.', background: '#1e293b', color: '#fff', confirmButtonColor: '#10b981' });
                    return;
                }

                // ── GROUP ────────────────────────────────────────────
                const okIndexed = results.filter(r => r.status === 'ok' && !r.has_changed && r.already_indexed && r.confidence);
                const changed = results.filter(r => r.status === 'ok' && r.has_changed && !r.is_episode);
                const newEpisodes = results.filter(r => r.status === 'ok' && r.is_episode && !r.already_indexed && r.confidence);
                const orphaned = results.filter(r => r.status === 'orphaned_rename');
                const needsReview = results.filter(r => r.status === 'no_match' || (!r.confidence && r.status === 'ok' && !r.has_changed && !r.already_indexed));
                const skipped = results.filter(r => r.status === 'skipped');

                const hasActions = changed.length > 0 || newEpisodes.length > 0 || orphaned.length > 0 || needsReview.length > 0;

                // ── 2. BUILD MODAL HTML ───────────────────────────────
                let html = `<div style="text-align:left;max-height:65vh;overflow-y:auto;">`;
                html += `<div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
                    <button type="button" onclick="document.querySelectorAll('.scan-approve').forEach(c=>c.checked=true)" style="padding:6px 14px;border:none;border-radius:6px;background:#10b981;color:#fff;cursor:pointer;font-size:0.8rem;">✅ Seleccionar Todo</button>
                    <button type="button" onclick="document.querySelectorAll('.scan-approve').forEach(c=>c.checked=false)" style="padding:6px 14px;border:none;border-radius:6px;background:#475569;color:#fff;cursor:pointer;font-size:0.8rem;">☐ Ninguno</button>
                </div>`;

                // ── Section: Ya indexados ─────────────────────────────
                if (okIndexed.length > 0) {
                    html += `<details style="margin-bottom:10px;">
                        <summary style="cursor:pointer;color:#10b981;font-weight:600;font-size:0.95rem;padding:4px 0;">✅ Ya indexados (${okIndexed.length})</summary>
                        <div style="margin-top:6px;">`;
                    okIndexed.forEach(r => {
                        html += tmdbRow(r, false);
                    });
                    html += `</div></details>`;
                }

                // ── Section: Cambios propuestos ───────────────────────
                if (changed.length > 0) {
                    html += `<h3 style="color:#fbbf24;margin:10px 0 8px;font-size:0.95rem;">🔄 Cambios propuestos (${changed.length})</h3>`;
                    changed.forEach(r => {
                        html += tmdbRow(r, r.confidence);
                    });
                }

                // ── Section: Nuevos episodios ─────────────────────────
                if (newEpisodes.length > 0) {
                    html += `<h3 style="color:#a78bfa;margin:10px 0 8px;font-size:0.95rem;">📺 Nuevos episodios (${newEpisodes.length})</h3>`;
                    newEpisodes.forEach(r => {
                        html += tmdbRow(r, true, '#4c1d95');
                    });
                }

                // ── Section: Renombres manuales ───────────────────────
                if (orphaned.length > 0) {
                    html += `<h3 style="color:#38bdf8;margin:10px 0 8px;font-size:0.95rem;">🔄 Renombres manuales (${orphaned.length})</h3>`;
                    orphaned.forEach(r => {
                        html += tmdbRow(r, true, '#0c4a6e');
                    });
                }

                // ── Section: Sin coincidencia / Baja confianza ────────
                if (needsReview.length > 0) {
                    html += `<h3 style="color:#ef4444;margin:10px 0 8px;font-size:0.95rem;">⚠️ Sin coincidencia / Baja confianza (${needsReview.length})</h3>`;
                    needsReview.forEach(r => {
                        html += tmdbRow(r, false, '#7f1d1d');
                    });
                }

                // ── Section: Ignorados ────────────────────────────────
                if (skipped.length > 0) {
                    html += `<details style="margin-bottom:10px;">
                        <summary style="cursor:pointer;color:#64748b;font-weight:600;font-size:0.95rem;padding:4px 0;">⏭️ Ignorados (${skipped.length})</summary>
                        <div style="margin-top:6px;">`;
                    skipped.forEach(r => {
                        html += tmdbRow(r, true, '#334155');
                    });
                    html += `</div></details>`;
                }

                html += `</div>`;

                // ── 3. SHOW MODAL ─────────────────────────────────────
                const noPending = okIndexed.length === results.length;
                Swal.close();
                const modalResult = await Swal.fire({
                    title: `📋 Escaneo — ${results.length} archivos${noPending && !hasActions ? ' ✅ Todo en orden' : ''}`,
                    html,
                    showCancelButton: true,
                    confirmButtonText: hasActions ? '✅ Aplicar Seleccionados' : '✅ Aplicar (forzar re-index)',
                    cancelButtonText: 'Cancelar',
                    background: '#1e293b',
                    color: '#fff',
                    width: '90%',
                    confirmButtonColor: '#10b981',
                    preConfirm: () => {
                        const checks = document.querySelectorAll('.scan-approve:checked');
                        const approve = Array.from(checks).map(c => parseInt(c.dataset.index));
                        const customTmdb = {};
                        document.querySelectorAll('.scan-tmdb-id').forEach(inp => {
                            const val = parseInt(inp.value);
                            if (!isNaN(val) && val > 0) {
                                customTmdb[inp.dataset.index] = val;
                            }
                        });
                        return { approve, custom_tmdb: customTmdb };
                    }
                });

                if (!modalResult.isConfirmed) return;

                const { approve: approved, custom_tmdb } = modalResult.value;

                if (approved.length === 0) {
                    Swal.fire({ icon: 'info', title: 'Sin cambios', text: 'No seleccionaste ningún archivo.', background: '#1e293b', color: '#fff' });
                    return;
                }

                // ── 4. APPLY ──────────────────────────────────────────
                Swal.fire({
                    title: 'Aplicando cambios...',
                    allowOutsideClick: false,
                    background: '#1e293b',
                    didOpen: () => Swal.showLoading()
                });

                try {
                    const applyRes = await fetch('backend/scrapper.php?action=apply', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({approve: approved, custom_tmdb})
                    });
                    const text = await applyRes.text();
                    let applyData;
                    try {
                        applyData = JSON.parse(text);
                    } catch (parseErr) {
                        Swal.fire({
                            title: 'Error del servidor',
                            html: 'El servidor devolvió HTML en vez de JSON. Verifica la Consola de Sistema.<br><br><span style="color:#f87171;font-size:0.75rem;word-break:break-all;">' + escHtml(text.substring(0, 300)) + '</span>',
                            icon: 'error',
                            background: '#1e293b',
                            color: '#fff',
                            confirmButtonColor: '#ef4444',
                        });
                        return;
                    }

                    // ── 5. RESULT ─────────────────────────────────────────
                    let resultHtml = '<div style="text-align:left;">';
                    if (applyData.applied && applyData.applied.length > 0) {
                        resultHtml += `<h3 style="color:#10b981;margin:0 0 8px;">✅ Aplicados (${applyData.applied.length})</h3>`;
                        applyData.applied.forEach(a => {
                            resultHtml += `<div style="padding:3px 0;font-size:0.85rem;border-bottom:1px solid rgba(255,255,255,0.05);">📁 ${a.file} <span style="color:#64748b;">→ ID ${a.content_id}</span></div>`;
                        });
                    }
                    if (applyData.errors && applyData.errors.length > 0) {
                        resultHtml += `<h3 style="color:#ef4444;margin:10px 0 8px;">❌ Errores (${applyData.errors.length})</h3>`;
                        applyData.errors.forEach(e => {
                            resultHtml += `<div style="padding:3px 0;font-size:0.85rem;">⚠️ ${e.file}: ${e.message}</div>`;
                        });
                    }
                    resultHtml += '</div>';

                    Swal.fire({
                        title: 'Resultado',
                        html: resultHtml,
                        icon: applyData.errors?.length ? 'warning' : 'success',
                        background: '#1e293b',
                        color: '#fff',
                        confirmButtonColor: '#10b981',
                    });

                    loadStats();
                } catch (applyErr) {
                    Swal.fire({
                        title: 'Error al aplicar',
                        text: applyErr.message,
                        icon: 'error',
                        background: '#1e293b',
                        color: '#fff',
                        confirmButtonColor: '#ef4444',
                    });
                }

                // Volcar log completo a la Consola de Sistema (legacy)
                try {
                    const legacyRes = await fetch('backend/manual_scan.php');
                    const legacyData = await legacyRes.json();
                    if (legacyData.output) {
                        consoleBox.innerHTML += '<br>' + legacyData.output.replace(/\n/g, '<br>');
                        consoleBox.scrollTop = consoleBox.scrollHeight;
                    }
                } catch (legacyErr) {
                    consoleBox.innerHTML += '<br><span style="color:#f87171;">⚠️ Error cargando log legacy</span>';
                }

            } catch (e) {
                Swal.close();
                const msg = e.name === 'AbortError'
                    ? 'La petición tardó demasiado (>5 min). Reintenta con menos archivos.'
                    : e.message;
                Swal.fire('Error', 'No se pudo conectar con el scrapper: ' + msg, 'error');
            } finally {
                progressContainer.style.display = 'none';
                btn.disabled = false;
            }
        }

        function isLocalFilePath(p) {
            return p && !p.startsWith('http') && !p.startsWith('extract:') && !p.startsWith('sniper:') && !p.startsWith('backend/');
        }

        let existingUrls = new Set();

        // ── ORDENAR TABLA ────────────────────────────────────────────
        window.sortColumn = null;
        window.sortDir = {};
        window.sortTable = function(col) {
            const tbody = document.getElementById('movieTableBody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            if (rows.length === 0) return;

            document.querySelectorAll('.sort-icon').forEach(el => el.textContent = '⇅');

            if (sortColumn !== col) {
                sortColumn = col;
                sortDir[col] = 'asc';
            } else {
                sortDir[col] = sortDir[col] === 'asc' ? 'desc' : 'asc';
            }

            const icon = document.querySelector(`th[onclick*="sortTable('${col}')"] .sort-icon`);
            if (icon) icon.textContent = sortDir[col] === 'asc' ? '↑' : '↓';

            const colIdx = { id: 0, titulo: 1, tmdb_id: 8, rating: 9 };
            const idx = colIdx[col];

            rows.sort((a, b) => {
                const va = a.cells[idx]?.textContent?.trim() || '';
                const vb = b.cells[idx]?.textContent?.trim() || '';
                if (col === 'id' || col === 'tmdb_id' || col === 'rating') {
                    return sortDir[col] === 'asc' ? parseFloat(va) - parseFloat(vb) : parseFloat(vb) - parseFloat(va);
                }
                return sortDir[col] === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
            });

            rows.forEach(row => tbody.appendChild(row));
        };

        // Auto-detectar URLs de Embed/Iframe y forzar semilla extract:
        function autoPrefixUrl(input) {
            if (!input) return;
            let val = input.value.trim();
            if (val.startsWith('extract:') || val.startsWith('sniper:')) return;
            
            if (val.startsWith('http')) {
                const isDirectStream = val.includes('.m3u8') || val.includes('.mp4') || val.includes('.txt') || val.includes('.mkv');
                if (!isDirectStream) {
                    // No es archivo de video directo, es un portal/embed -> requiere Extractor
                    input.value = 'extract:' + val;
                }
            }
        }

        // 🧠 DHARMA FIX #29: Extrae el path base de una URL sin tokens dinámicos de sesión
        // Convierte: "https://p6.vimeos.zip/hls2/02/00010/rwyupavfa20a_,.../master.m3u8?t=XYZ&s=123"
        //       en:  "p6.vimeos.zip/hls2/02/00010/rwyupavfa20a_,...,/master.m3u8"
        // Esto permite comparar el CONTENIDO real ignorando los tokens efímeros de sesión.
        function getUrlBase(url) {
            if (!url) return '';
                // Limpiar directivas de GalixMovie para evitar falsos negativos en duplicados
            let cleanUrl = url.replace(/^extract:/i, '').replace(/^sniper:/i, '').trim();
            
            try {
                const u = new URL(cleanUrl);
                return (u.hostname + u.pathname).toLowerCase();
            } catch (e) {
                return cleanUrl.split('?')[0].toLowerCase();
            }
        }


        async function loadStats() {
            try {
                const res = await fetch('backend/get_content.php?admin=1');
                const data = await res.json();
                const movies = data.movies || [];
                const count = movies.length;
                document.getElementById('totalMovies').innerText = count;

                // 🧠 Guardar todos los links existentes para detectar duplicados
                // Usamos la ruta BASE (sin tokens dinámicos ?t=...) para detectar mirrors del mismo contenido
                existingUrls.clear();
                movies.forEach(m => {
                    if (m.archivo_path) existingUrls.add(getUrlBase(m.archivo_path.trim()));
                    if (m.server2) existingUrls.add(getUrlBase(m.server2.trim()));
                    if (m.server3) existingUrls.add(getUrlBase(m.server3.trim()));
                    if (m.server4) existingUrls.add(getUrlBase(m.server4.trim()));
                    if (m.server5) existingUrls.add(getUrlBase(m.server5.trim()));
                });

                const tbody = document.getElementById('movieTableBody');
                tbody.innerHTML = '';
                if (data.movies) {
                    data.movies.forEach(m => {
                        const rowStyle = m.is_online == 0 ? 'border: 2px solid #ef4444; background: rgba(239, 68, 68, 0.05);' : 'border-bottom: 1px solid #1e293b;';
                        const statusBadge = m.is_online == 0 ? '<span style="color:#ef4444; font-size:0.6rem; border:1px solid #ef4444; padding:2px 4px; border-radius:4px; margin-left:5px;">OFFLINE</span>' : '';

                        tbody.innerHTML += `
                            <tr style="${rowStyle}" data-movie-id="${m.id}">
                                <td style="padding: 10px;" data-label="ID">${m.id}</td>
                                <td style="padding: 10px; font-weight: 600;" data-label="Título">${m.titulo}${statusBadge}</td>
                                <td style="padding: 10px; text-align:center; cursor:grab;" id="s1-${m.id}" data-url="${m.archivo_path}" draggable="true" ondragstart="dragServer(event)" ondragover="allowDropServer(event)" ondrop="dropServer(event)" data-label="S1">${renderStatusPlaceholder(m.archivo_path, 1, m.id)}</td>
                                <td style="padding: 10px; text-align:center; cursor:grab;" id="s2-${m.id}" data-url="${m.server2}" draggable="true" ondragstart="dragServer(event)" ondragover="allowDropServer(event)" ondrop="dropServer(event)" data-label="S2">${renderStatusPlaceholder(m.server2, 2, m.id)}</td>
                                <td style="padding: 10px; text-align:center; cursor:grab;" id="s3-${m.id}" data-url="${m.server3}" draggable="true" ondragstart="dragServer(event)" ondragover="allowDropServer(event)" ondrop="dropServer(event)" data-label="S3">${renderStatusPlaceholder(m.server3, 3, m.id)}</td>
                                <td style="padding: 10px; text-align:center; cursor:grab;" id="s4-${m.id}" data-url="${m.server4}" draggable="true" ondragstart="dragServer(event)" ondragover="allowDropServer(event)" ondrop="dropServer(event)" data-label="S4">${renderStatusPlaceholder(m.server4, 4, m.id)}</td>
                                <td style="padding: 10px; text-align:center; cursor:grab;" id="s5-${m.id}" data-url="${m.server5}" draggable="true" ondragstart="dragServer(event)" ondragover="allowDropServer(event)" ondrop="dropServer(event)" data-label="S5">${renderStatusPlaceholder(m.server5, 5, m.id)}</td>
                                <td style="padding: 10px; text-align:center;" data-label="HLS">${m.hls_path ? '<span style="color:#10b981; font-size:0.75rem;">🟢 ' + m.hls_path + '</span>' : '<span style="color:#64748b; font-size:0.75rem;">—</span>'}</td>
                                <td style="padding: 10px;" data-label="Tipo">${m.tipo}</td>
                                <td style="padding: 10px;" data-label="TMDB ID">${m.tmdb_id}</td>
                                <td style="padding: 10px; color: #fbbf24;" data-label="Rating">★ ${m.puntuacion}</td>
                                <td style="padding: 10px;" data-label="Acciones">
                                    <button onclick="updateMovie(${m.id}, ${m.tmdb_id}, '${m.tipo}')" style="background:var(--accent); color:white; border:none; padding:5px 10px; border-radius:5px; cursor:pointer; margin-right:5px;" title="Editar">
                                        <b class="i-edit"></b>
                                    </button>
                                    ${(m.tipo === 'series' || m.tipo === 'tv') ? `<button onclick="auditSeries(${m.id})" style="background:#f59e0b; color:white; border:none; padding:5px 10px; border-radius:5px; cursor:pointer; margin-right:5px;" title="Diagnóstico de Capítulos">
                                        <b class="i-pulse"></b>
                                    </button>` : ''}
                                    <button onclick="deleteMovie(${m.id})" style="background:#ef4444; color:white; border:none; padding:5px 10px; border-radius:5px; cursor:pointer;" title="Eliminar">
                                        <b class="i-trash"></b>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                    
                    updateOnlineCount();
                    // 🛡️ Conectar validador en tiempo real DESPUÉS de tener existingUrls listo
                    bindManualDuplicateChecker();
                }
                // Cargar estadísticas del Autopiloto
                loadAutopilotStats();
            } catch (e) { }
        }

        // 🧠 Galix Autopilot Stats Loader
        async function loadAutopilotStats() {
            try {
                const res = await fetch('backend/autopilot.php?action=status');
                const data = await res.json();
                if (data.status === 'success') {
                    const metrics = data.metrics;
                    document.getElementById('totalAutopilot').innerText = `${metrics.active_healthy_streams} / ${metrics.total_cached_records}`;
                }
            } catch (err) {
                console.warn("Error cargando estadísticas de Autopilot:", err);
            }
            // Verificar si hay un reporte completado disponible
            try {
                const pr = await fetch('backend/autopilot.php?action=progress&t=' + Date.now());
                const pd = await pr.json();
                if (pd.status === 'completed' && pd.report) {
                    const reportBtn = document.getElementById('autopilotReportBtn');
                    if (reportBtn) reportBtn.style.display = 'inline-flex';
                }
            } catch (e) {}
        }

        // 🚀 Galix Autopilot Background Executor v2.0 (Worker + Toast en Vivo)
        let autopilotPollTimer = null;

        window.dismissAutopilotToast = () => {
            const t = document.getElementById('autopilotToast');
            if (t) t.style.display = 'none';
            if (autopilotPollTimer) { clearInterval(autopilotPollTimer); autopilotPollTimer = null; }
        };

        window.showAutopilotReport = () => {
            fetch('backend/autopilot.php?action=progress&t=' + Date.now())
                .then(r => r.json())
                .then(data => {
                    const r = data.report || {};
                    let deadListHtml = '';
                    if (r.static_dead_detected && r.static_dead_detected.length > 0) {
                        deadListHtml = '<h4 style="color:#ef4444; margin-top:15px;">🚨 Servidores Estáticos Caídos:</h4><div style="max-height:120px; overflow-y:auto; text-align:left; background:rgba(0,0,0,0.2); padding:8px; border-radius:6px; font-size:0.8rem; font-family:monospace; margin-bottom:10px;">';
                        r.static_dead_detected.forEach(d => {
                            deadListHtml += '• ' + d.titulo + ' [' + d.servidor + ']: ' + d.url + '<br>';
                        });
                        deadListHtml += '</div>';

                        const hasLocalDead = r.static_dead_detected.some(d => d.url.includes('(ARCHIVO FALTANTE)'));
                        if (hasLocalDead) {
                            deadListHtml += '<button onclick="purgeDeadRecords()" class="btn-scan" style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid #ef4444; width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 10px; font-weight: 600; border-radius: 8px; cursor: pointer; margin-top: 5px; transition: all 0.2s;" onmouseover="this.style.background=\'rgba(239, 68, 68, 0.25)\'" onmouseout="this.style.background=\'rgba(239, 68, 68, 0.15)\'">' +
                                '<b class="i-trash" style="font-size: 1.2rem;"></b> 🧹 Limpiar Registros Muertos' +
                                '</button>';
                        }
                    }
                    Swal.fire({
                        title: '📋 Reporte de Autopilot',
                        icon: 'info',
                        background: '#1e293b',
                        color: '#fff',
                        html: '<div style="text-align:left; font-size:0.9rem; line-height:1.6;">' +
                            '<p>✅ <strong>Contenidos Escaneados:</strong> ' + (r.scanned_contents || 0) + '</p>' +
                            '<p>🌱 <strong>Enlaces Semilla Activos:</strong> ' + (r.seeds_found || 0) + '</p>' +
                            '<p>🟢 <strong>Caché Saludable Preservado:</strong> ' + (r.healthy_cached || 0) + '</p>' +
                            '<p>🧹 <strong>Caché Muerto Podado:</strong> ' + (r.pruned_dead || 0) + '</p>' +
                            '<p>🚀 <strong>Streams Auto-Cosechados (Sanados):</strong> ' + (r.healed_streams || 0) + '</p>' +
                            '<p>⚠️ <strong>Requieren Acción de Cliente (Sniper):</strong> ' + (r.sniper_refresh_needed || 0) + '</p>' +
                            (r.sniper_links && r.sniper_links.length > 0 ? '<div style="max-height:250px; overflow-y:auto; text-align:left; background:rgba(0,0,0,0.3); padding:8px; border-radius:6px; font-size:0.75rem; font-family:monospace; margin:4px 0;">' + r.sniper_links.map(l => '<div style="padding:4px 0; border-bottom:1px solid rgba(255,255,255,0.05);">• <strong>' + l.titulo + '</strong><br><a href="' + l.url + '" target="_blank" style="color:#38bdf8; text-decoration:underline; word-break:break-all;">' + l.url + '</a></div>').join('') + '</div>' : '') +
                            (r.auto_filled && r.auto_filled.length > 0 ? '<hr style="border-color:#334155;margin:8px 0"><p>💾 <strong>Auto-Fill S1/S2 (' + r.auto_filled.length + '):</strong></p><ul style="margin:4px 0;padding-left:16px;font-size:0.8rem;color:#86efac;">' + r.auto_filled.map(x => '<li>' + x + '</li>').join('') + '</ul>' : '<p>💾 <strong>Auto-Fill S1/S2:</strong> 0 slots rellenados</p>') +
                            (r.fill_skip && r.fill_skip.length > 0 ? '<p style="font-size:0.75rem;color:#94a3b8;">⏭ Saltados: ' + r.fill_skip.length + ' — ' + r.fill_skip.slice(0,3).join(' | ') + '</p>' : '') +
                            deadListHtml +
                            '</div>',
                        confirmButtonText: 'Excelente'
                    });
                })
                .catch(() => {
                    Swal.fire('Error', 'No se pudo cargar el reporte.', 'error');
                });
        };

        window.purgeDeadRecords = () => {
            Swal.fire({
                title: '¿Estás seguro?',
                text: 'Se eliminarán permanentemente de la base de datos todos los registros de películas y episodios cuyos archivos locales (.mp4) no existan en el disco duro.',
                icon: 'warning',
                showCancelButton: true,
                background: '#1e293b',
                color: '#fff',
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#3b82f6',
                confirmButtonText: 'Sí, limpiar registros',
                cancelButtonText: 'Cancelar'
            }).then(async (result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Limpiando registros...',
                        allowOutsideClick: false,
                        background: '#1e293b',
                        color: '#fff',
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    try {
                        const res = await fetch('backend/purge_dead_records.php?t=' + Date.now(), {
                            method: 'POST'
                        });
                        const data = await res.json();
                        if (data.status === 'success') {
                            let msg = `Se eliminaron:\n- ${data.deleted_movies_count} películas\n- ${data.deleted_episodes_count} episodios\n- ${data.deleted_series_count} series vacías`;
                            if (data.deleted_titles && data.deleted_titles.length > 0) {
                                msg += `\n\nDetalle:\n` + data.deleted_titles.map(t => '• ' + t).join('\n');
                            }
                            Swal.fire({
                                icon: 'success',
                                title: 'Limpieza Exitosa 🧹',
                                html: '<pre style="text-align:left; font-size:0.8rem; background:rgba(0,0,0,0.2); padding:8px; border-radius:6px; max-height:200px; overflow-y:auto; color:#fff; font-family:monospace; margin:0;">' + msg + '</pre>',
                                background: '#1e293b',
                                color: '#fff'
                            }).then(() => {
                                loadStats();
                                showAutopilotReport();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message || 'No se pudo realizar la limpieza.',
                                background: '#1e293b',
                                color: '#fff'
                            });
                        }
                    } catch (e) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error de red',
                            text: e.message,
                            background: '#1e293b',
                            color: '#fff'
                        });
                    }
                }
            });
        };

        window.updateAutopilotToast = (data) => {
            const toast = document.getElementById('autopilotToast');
            const title = document.getElementById('toastTitle');
            const progress = document.getElementById('toastProgress');
            const barFill = document.getElementById('toastBarFill');
            const details = document.getElementById('toastDetails');
            const viewBtn = document.getElementById('toastViewReport');

            if (!toast) return;
            toast.style.display = 'block';

            if (data.status === 'running') {
                const pct = data.total > 0 ? Math.min(100, Math.round((data.processed / data.total) * 100)) : 0;
                title.innerHTML = '⚙️ Autopilot Engine — <span style="color:#a78bfa;">' + pct + '%</span>';
                progress.innerHTML = 'Procesados: ' + data.processed + ' / ' + data.total + ' · Curadas: ' + data.healed + ' · Saltadas: ' + data.skipped_healthy;
                barFill.style.width = pct + '%';
                if (data.current) {
                    details.style.display = 'block';
                    details.innerHTML = '🔍 ' + data.current;
                }
                if (viewBtn) viewBtn.style.display = 'none';
            } else if (data.status === 'completed') {
                const r = data.report || {};
                title.innerHTML = '✅ Autopilot Completado';
                progress.innerHTML = 'Curadas: ' + (r.healed_streams || 0) + ' · Podadas: ' + (r.pruned_dead || 0) + ' · Saludables: ' + (r.healthy_cached || 0);
                barFill.style.width = '100%';
                barFill.style.background = 'linear-gradient(90deg,#10b981,#38bdf8)';
                details.style.display = 'none';
                const reportBtn = document.getElementById('autopilotReportBtn');
                if (reportBtn) reportBtn.style.display = 'inline-flex';
                if (viewBtn) viewBtn.style.display = 'inline-flex';
                if (autopilotPollTimer) { clearInterval(autopilotPollTimer); autopilotPollTimer = null; }
                loadStats();
                // No ocultar — el usuario cierra manualmente o con el botón ✕
            } else if (data.status === 'failed') {
                title.innerHTML = '❌ Autopilot Falló';
                progress.innerHTML = data.error || 'Error desconocido en el worker';
                barFill.style.width = '100%';
                barFill.style.background = '#ef4444';
                if (autopilotPollTimer) { clearInterval(autopilotPollTimer); autopilotPollTimer = null; }
                setTimeout(() => { if (toast.style.display !== 'none') toast.style.display = 'none'; }, 10000);
            } else if (data.status === 'idle') {
                title.innerHTML = '⚠️ Autopilot Inactivo';
                progress.innerHTML = 'El worker no se inició o el progreso se perdió.';
                barFill.style.width = '0%';
                barFill.style.background = '#f59e0b';
                if (viewBtn) viewBtn.style.display = 'none';
                if (autopilotPollTimer) { clearInterval(autopilotPollTimer); autopilotPollTimer = null; }
                setTimeout(() => { if (toast.style.display !== 'none') toast.style.display = 'none'; }, 5000);
            }
        };

        window.runAutopilot = async () => {
            const btn = document.getElementById('autopilotBtn');
            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<b class="i-refresh spin"></b> Iniciando...';

            try {
                const res = await fetch('backend/autopilot.php?action=run&t=' + Date.now());
                const data = await res.json();
                btn.disabled = false;
                btn.innerHTML = originalHtml;

                if (data.status === 'started') {
                    console.log('[Autopilot] Worker lanzado:', data.diagnostics || {});
                    // Inicializar toast
                    const toast = document.getElementById('autopilotToast');
                    if (toast) {
                        document.getElementById('toastBarFill').style.background = 'linear-gradient(90deg,#a78bfa,#38bdf8)';
                        document.getElementById('toastViewReport').style.display = 'none';
                        window.updateAutopilotToast({
                            status: 'running',
                            total: 0,
                            processed: 0,
                            healed: 0,
                            skipped_healthy: 0,
                            current: 'Iniciando worker...'
                        });
                    }

                    // Polling cada 2s
                    if (autopilotPollTimer) clearInterval(autopilotPollTimer);
                    autopilotPollTimer = setInterval(async () => {
                        try {
                            const pr = await fetch('backend/autopilot.php?action=progress&t=' + Date.now());
                            const pd = await pr.json();
                            if (pd.status === 'completed' || pd.status === 'failed' || pd.status === 'idle') {
                                if (autopilotPollTimer) { clearInterval(autopilotPollTimer); autopilotPollTimer = null; }
                            }
                            window.updateAutopilotToast(pd);
                        } catch (e) {
                            // Silencio — no romper el polling por errores de red transitorios
                        }
                    }, 2000);
                } else {
                    console.error('[Autopilot] Error al lanzar worker:', data);
                    Swal.fire('Error', data.message || 'Error al iniciar el worker de Autopilot.', 'error');
                }
            } catch (err) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                Swal.fire('Error de Red', 'No se pudo conectar con el motor Autopilot.', 'error');
            }
        };

        function renderStatusPlaceholder(url, serverNum, id) {
            if (!url) return '<span style="color:#334155; font-size:0.8rem; font-weight:700;">-</span>';
            
            const isExtract = url.startsWith('extract:');
            const isSniper = url.startsWith('sniper:');
            const isUnsupported = url.toLowerCase().endsWith('.avi') || url.toLowerCase().endsWith('.mkv');
            
            if (isExtract || isSniper) {
                const icon = isExtract ? 'cube-outline' : 'flash-outline';
                const color = isExtract ? '#38bdf8' : '#a78bfa';
                const bg = isExtract ? 'rgba(56, 189, 248, 0.08)' : 'rgba(167, 139, 250, 0.08)';
                const border = isExtract ? 'rgba(56, 189, 248, 0.25)' : 'rgba(167, 139, 250, 0.25)';
                const glow = isExtract ? '0 0 10px rgba(56, 189, 248, 0.15)' : '0 0 10px rgba(167, 139, 250, 0.15)';
                const title = isExtract ? 'Semilla de Extracción (Fénix) - Carga Dinámica' : 'Contenedor Sniper - Extracción en Vivo';
                return `<div onclick="checkServerStatus('${url}', 's${serverNum}-${id}')" style="cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:5px; background:${bg}; color:${color}; border:1px solid ${border}; padding:4px 8px; border-radius:8px; font-size:0.75rem; font-weight:bold; box-shadow:${glow}; transition: 0.2s;" title="${title}"><b class="i-${icon}"></b> S${serverNum}</div>`;
            }
            
            const isLocal = !url.startsWith('http');
            let icon = isLocal ? 'server-outline' : 'radio-button-off-outline';
            let color = isLocal ? '#10b981' : '#94a3b8';
            let bg = isLocal ? 'rgba(16, 185, 129, 0.08)' : 'rgba(148, 163, 184, 0.05)';
            let border = isLocal ? 'rgba(16, 185, 129, 0.25)' : 'rgba(148, 163, 184, 0.15)';
            let glow = isLocal ? '0 0 10px rgba(16, 185, 129, 0.15)' : 'none';
            let title = isLocal ? 'Archivo Local - Siempre en línea' : 'Servidor Externo - Click para verificar';
            let extraBadge = '';

            if (isUnsupported) {
                icon = 'warning-outline';
                color = '#f43f5e';
                bg = 'rgba(244, 63, 94, 0.15)';
                border = 'rgba(244, 63, 94, 0.5)';
                glow = '0 0 15px rgba(244, 63, 94, 0.3)';
                title = 'FORMATO INVÁLIDO (.AVI / .MKV). Debe convertirse a .MP4 para reproducirse.';
                extraBadge = ' <span style="font-size:0.55rem; background:#f43f5e; color:#fff; padding:2px 4px; border-radius:4px; margin-left:3px; font-weight:900; letter-spacing:1px;">ERR</span>';
            }

            return `<div onclick="checkServerStatus('${url}', 's${serverNum}-${id}')" style="cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:5px; background:${bg}; color:${color}; border:1px solid ${border}; padding:4px 8px; border-radius:8px; font-size:0.75rem; font-weight:bold; box-shadow:${glow}; transition: 0.2s;" title="${title}"><b class="i-${icon}"></b> S${serverNum}${extraBadge}</div>`;
        }
        // DRAG AND DROP LOGIC FOR SERVERS
        let draggedCell = null;

        function dragServer(event) {
            draggedCell = event.target.closest('td');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', draggedCell.id);
        }

        function allowDropServer(event) {
            event.preventDefault(); // Permitir soltar
            const target = event.target.closest('td');
            if(target && target !== draggedCell && target.id.split('-')[1] === draggedCell.id.split('-')[1]) {
                event.dataTransfer.dropEffect = 'move';
            } else {
                event.dataTransfer.dropEffect = 'none';
            }
        }

        async function dropServer(event) {
            event.preventDefault();
            const targetCell = event.target.closest('td');
            if (!targetCell || !draggedCell) return;
            
            const sourceId = draggedCell.id; 
            const targetId = targetCell.id;  
            
            if (sourceId === targetId) return;
            
            const srcParts = sourceId.split('-');
            const tgtParts = targetId.split('-');
            
            if (srcParts[1] !== tgtParts[1]) {
                Swal.fire('Atención', 'Solo puedes reorganizar servidores dentro de la misma película.', 'warning');
                return;
            }
            
            const movieId = srcParts[1];
            const col1 = srcParts[0];
            const col2 = tgtParts[0];
            
            Swal.fire({ title: 'Intercambiando servidores...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            
            try {
                const res = await fetch('backend/swap_servers.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ movie_id: movieId, col1: col1, col2: col2 })
                });
                const resText = await res.text();
                let data;
                try {
                    data = JSON.parse(resText);
                } catch(e) {
                    Swal.fire('Error', 'Respuesta no válida del servidor.', 'error');
                    console.error("No JSON:", resText);
                    return;
                }

                if (data.status === 'success') {
                    Swal.close();
                    loadStats(); // Refrescar vista
                } else {
                    Swal.fire('Error', data.message || 'No se pudo mover', 'error');
                }
            } catch (e) {
                console.error("Fallo de red:", e);
                Swal.fire('Error', 'Fallo de red al mover', 'error');
            }
        }

        async function checkServerStatus(url, elementId) {
            const el = document.getElementById(elementId);
            if (!el) return;
            
            const isExtract = url.startsWith('extract:');
            const isSniper = url.startsWith('sniper:');
            
            if (isExtract || isSniper) {
                const icon = isExtract ? 'cube-outline' : 'flash-outline';
                const color = isExtract ? '#38bdf8' : '#a78bfa';
                const title = isExtract ? 'Semilla de Extracción (Fénix) - Activa y Saludable' : 'Contenedor Sniper - Activo y Saludable';
                el.innerHTML = `<b class="i-${icon}" style="color:${color};"></b>`;
                el.title = title;
                updateOnlineCount();
                return;
            }
            
            const isLocal = !url.startsWith('http');
            el.innerHTML = '<b class="i-refresh spin"></b>';
            
            let probeUrl = url;
            if (isLocal) {
                probeUrl = window.location.origin + window.location.pathname.replace('admin.html', '') + url;
            }

            try {
                const res = await fetch(`backend/check_status.php?url=${encodeURIComponent(probeUrl)}`);
                const data = await res.json();
                
                if (data.status === 'online') {
                    const icon = isLocal ? 'server-outline' : 'checkmark-circle';
                    el.innerHTML = `<b class="i-${icon}" style="color:#10b981;"></b>`;
                    el.title = "En línea (HTTP " + data.http_code + ")";
                } else if (data.status === 'down') {
                    const icon = isLocal ? 'server-outline' : 'close-circle';
                    el.innerHTML = `<b class="i-${icon}" style="color:#ef4444;"></b>`;
                    el.title = "Caído (HTTP " + data.http_code + ")";
                } else {
                    const icon = isLocal ? 'server-outline' : 'alert-circle';
                    const color = isLocal ? '#10b981' : '#fbbf24';
                    el.innerHTML = `<b class="i-${icon}" style="color:${color};"></b>`;
                    el.title = isLocal ? "Local - Conectado" : ("Error: " + (data.error || "Desconocido"));
                }
            } catch (e) {
                const icon = isLocal ? 'server-outline' : 'alert-circle';
                const color = isLocal ? '#10b981' : '#fbbf24';
                el.innerHTML = `<b class="i-${icon}" style="color:${color};"></b>`;
            }
            updateOnlineCount(); // Recalcular tras cada check
        }

        function updateOnlineCount() {
            const rows = document.querySelectorAll('#movieTableBody tr');
            let onlineCount = 0;
            
            rows.forEach(row => {
                // Una película está online si al menos un servidor está en verde (#10b981)
                const statuses = row.querySelectorAll('td[id^="s"] b[class^="i-"]');
                let isAnyOnline = false;
                statuses.forEach(icon => {
                    if (icon.style.color === 'rgb(16, 185, 129)') { // hex #10b981
                        isAnyOnline = true;
                    }
                });
                if (isAnyOnline) onlineCount++;
            });
            
            document.getElementById('totalMovies').innerText = `${onlineCount} / ${rows.length}`;
        }

