function toggleCardBody(bodyId, chevronId) {
const body = document.getElementById(bodyId);
const chevron = document.getElementById(chevronId);
if (!body) return;

const isCollapsed = body.style.maxHeight === '0px';

if (isCollapsed) {
body.style.maxHeight = body.scrollHeight + 'px';
body.style.opacity = '1';
if (chevron) chevron.style.transform = 'rotate(-180deg)';

const onTransitionEnd = () => {
if (body.style.maxHeight !== '0px') {
body.style.maxHeight = 'none';
}
body.removeEventListener('transitionend', onTransitionEnd);
};
body.addEventListener('transitionend', onTransitionEnd);
} else {
body.style.maxHeight = body.scrollHeight + 'px';
body.offsetHeight;
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
const proposedLine = r.proposed_name ?
'<div style="font-size:0.75rem;color:#e2e8f0;margin-top:2px;">⇒ <input type="text" class="scan-rename" data-index="' + r.index + '" value="' + escHtml(r.proposed_name) + '" style="width:100%;padding:3px 6px;border:1px solid #334155;border-radius:4px;background:#0f172a;color:#e2e8f0;font-size:0.75rem;font-family:monospace;" spellcheck="false"></div>' : '';
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
${proposedLine}${orphanLine}${safetyWarn}
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

  consoleBox.innerHTML = '> 🔍 Escaneando BUNKER...<br>';
  consoleBox.scrollTop = consoleBox.scrollHeight;
  consoleBox.classList.add('scanning');
  progressContainer.style.display = 'block';
  progressFill.style.width = '0%';
  progressText.textContent = '0%';
  progressMsg.textContent = 'Iniciando...';

  try {
    const data = await new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('GET', 'backend/scrapper.php?action=preview&skip_indexed=1');

      let timedOut = false;
      const timeoutId = setTimeout(() => { timedOut = true; xhr.abort(); }, 600000);

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
              }
              const icon = obj.msg === 'OK' ? '✅' : (obj.msg === 'Sin match' ? '⚠️' : (obj.msg === 'Baja confianza' ? '⚠️' : (obj.msg === 'Renombre manual' ? '🔄' : '')));
              if (obj.current > 0 && obj.total > 0) {
                progressMsg.textContent = '[' + obj.current + '/' + obj.total + '] ' + (obj.file || obj.msg);
              } else {
                progressMsg.textContent = obj.msg;
              }
              const lineOut = obj.file
                ? `> ${icon} ${obj.msg}: ${obj.file}`
                : `> ${obj.msg}`;
              consoleBox.innerHTML += lineOut + '<br>';
              consoleBox.scrollTop = consoleBox.scrollHeight;
            } else if (obj.type === 'error') {
              xhrError = new Error(obj.message);
            } else if (obj.type === 'result') {
              result = obj;
            }
          } catch (e) {}
        }
      };

      xhr.onload = () => {
        clearTimeout(timeoutId);
        if (xhrError) return reject(xhrError);
        if (xhr.responseText) {
          const lines = xhr.responseText.split('\n');
          for (const l of lines) {
            if (!l.trim()) continue;
            try {
              const obj = JSON.parse(l.trim());
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

      xhr.onerror = () => reject(new Error('Error de red'));

      xhr.send();
    });

    consoleBox.classList.remove('scanning');
    progressContainer.style.display = 'none';

    if (!data || data.status !== 'success') {
      const msg = (data && data.message) || 'Error al escanear';
      Swal.fire('Error', msg, 'error');
      consoleBox.innerHTML += '❌ Error: ' + msg + '<br>';
      return;
    }

    const results = data.results;
    if (!results || results.length === 0) {
      await Swal.fire({ icon: 'info', title: 'Sin archivos', text: 'No se encontraron archivos en el directorio.', background: '#1e293b', color: '#fff', confirmButtonColor: '#10b981' });
      return;
    }

    consoleBox.innerHTML += '> ✅ Escaneo completado (' + results.length + ' archivos)<br>';
    consoleBox.scrollTop = consoleBox.scrollHeight;

    // ── GROUP RESULTS ───────────────────────────────────────────
    const okIndexed = results.filter(r => r.status === 'ok' && !r.has_changed && r.already_indexed && r.confidence);
    const changed = results.filter(r => r.status === 'ok' && r.has_changed && !r.is_episode);
    const newEpisodes = results.filter(r => r.status === 'ok' && r.is_episode && !r.already_indexed && r.confidence);
    const orphaned = results.filter(r => r.status === 'orphaned_rename');
    const needsReview = results.filter(r => r.status === 'no_match' || (!r.confidence && r.status === 'ok' && !r.has_changed && !r.already_indexed));
    const skipped = results.filter(r => r.status === 'skipped');

    const hasActions = changed.length > 0 || newEpisodes.length > 0 || orphaned.length > 0 || needsReview.length > 0;

    // ── BUILD MODAL HTML ────────────────────────────────────────
    let html = `<div style="text-align:left;max-height:65vh;overflow-y:auto;">`;
    html += `<div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
        <button type="button" onclick="document.querySelectorAll('.scan-approve').forEach(c=>c.checked=true)" style="padding:6px 14px;border:none;border-radius:6px;background:#10b981;color:#fff;cursor:pointer;font-size:0.8rem;">✅ Seleccionar Todo</button>
        <button type="button" onclick="document.querySelectorAll('.scan-approve').forEach(c=>c.checked=false)" style="padding:6px 14px;border:none;border-radius:6px;background:#475569;color:#fff;cursor:pointer;font-size:0.8rem;">☐ Ninguno</button>
    </div>`;

    if (okIndexed.length > 0) {
      html += `<details style="margin-bottom:10px;">
        <summary style="cursor:pointer;color:#10b981;font-weight:600;font-size:0.95rem;padding:4px 0;">✅ Ya indexados (${okIndexed.length})</summary>
        <div style="margin-top:6px;">`;
      okIndexed.forEach(r => { html += tmdbRow(r, false); });
      html += `</div></details>`;
    }

    if (changed.length > 0) {
      html += `<h3 style="color:#fbbf24;margin:10px 0 8px;font-size:0.95rem;">🔄 Cambios propuestos (${changed.length})</h3>`;
      changed.forEach(r => { html += tmdbRow(r, r.confidence); });
    }

    if (newEpisodes.length > 0) {
      html += `<h3 style="color:#a78bfa;margin:10px 0 8px;font-size:0.95rem;">📺 Nuevos episodios (${newEpisodes.length})</h3>`;
      newEpisodes.forEach(r => { html += tmdbRow(r, true, '#4c1d95'); });
    }

    if (orphaned.length > 0) {
      html += `<h3 style="color:#38bdf8;margin:10px 0 8px;font-size:0.95rem;">🔄 Renombres manuales (${orphaned.length})</h3>`;
      orphaned.forEach(r => { html += tmdbRow(r, true, '#0c4a6e'); });
    }

    if (needsReview.length > 0) {
      html += `<h3 style="color:#ef4444;margin:10px 0 8px;font-size:0.95rem;">⚠️ Sin coincidencia / Baja confianza (${needsReview.length})</h3>`;
      needsReview.forEach(r => { html += tmdbRow(r, false, '#7f1d1d'); });
    }

    if (skipped.length > 0) {
      html += `<details style="margin-bottom:10px;">
        <summary style="cursor:pointer;color:#64748b;font-weight:600;font-size:0.95rem;padding:4px 0;">⏭️ Ignorados (${skipped.length})</summary>
        <div style="margin-top:6px;">`;
      skipped.forEach(r => { html += tmdbRow(r, true, '#334155'); });
      html += `</div></details>`;
    }

    html += `</div>`;

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
          if (!isNaN(val) && val > 0) customTmdb[inp.dataset.index] = val;
        });
        return { approve, custom_tmdb: customTmdb };
      }
    });

    if (!modalResult.isConfirmed) return;

    const { approve: approved, custom_tmdb } = modalResult.value;

    // Build path map from preview data para match estable en apply
    const filePaths = {};
    approved.forEach(idx => {
      const found = results.find(r => r.index === idx);
      if (found && found.path) filePaths[idx] = found.path;
    });

    if (approved.length === 0) {
      Swal.fire({ icon: 'info', title: 'Sin cambios', text: 'No seleccionaste ningún archivo.', background: '#1e293b', color: '#fff' });
      return;
    }

    // ── APPLY ───────────────────────────────────────────────────
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
        body: JSON.stringify({approve: approved, custom_tmdb, file_paths: filePaths})
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

      let resultHtml = '<div style="text-align:left;">';
      if (applyData.status === 'error') {
        resultHtml += `<h3 style="color:#ef4444;margin:0 0 8px;">❌ Error del servidor</h3><p style="color:#f87171;font-size:0.85rem;">${escHtml(applyData.message || 'Error desconocido')}</p>`;
        Swal.fire({ title: 'Error del servidor', html: resultHtml, icon: 'error', background: '#1e293b', color: '#fff', confirmButtonColor: '#ef4444' });
        consoleBox.innerHTML += `<br>> ❌ Error: ${applyData.message || 'Error desconocido'}<br>`;
        return;
      }
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

      consoleBox.innerHTML += `<br>> ✅ Indexados: ${(applyData.applied||[]).length} | Errores: ${(applyData.errors||[]).length}<br>`;
      consoleBox.scrollTop = consoleBox.scrollHeight;
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
window.allMovies = [];
window.filteredMovies = [];
window.currentPage = 1;
window.itemsPerPage = 30;
window.currentSort = { col: 'id', dir: 'asc' };

// Concurrent queue runner for parallel processes
async function runConcurrent(tasks, limit, onProgress) {
    let active = 0;
    let completed = 0;
    const results = [];
    const queue = [...tasks];
    
    return new Promise((resolve) => {
        function runNext() {
            if (queue.length === 0 && active === 0) {
                resolve(results);
                return;
            }
            
            while (queue.length > 0 && active < limit) {
                active++;
                const task = queue.shift();
                task.fn(task.item).then((res) => {
                    results[task.index] = res;
                    completed++;
                    active--;
                    if (onProgress) onProgress(completed, tasks.length, task.item, res);
                    runNext();
                }).catch((err) => {
                    results[task.index] = { error: err };
                    completed++;
                    active--;
                    if (onProgress) onProgress(completed, tasks.length, task.item, { error: err });
                    runNext();
                });
            }
        }
        runNext();
    });
}

window.sortTable = function(col) {
    if (window.currentSort.col === col) {
        window.currentSort.dir = window.currentSort.dir === 'asc' ? 'desc' : 'asc';
    } else {
        window.currentSort.col = col;
        window.currentSort.dir = 'asc';
    }
    
    document.querySelectorAll('.sort-icon').forEach(el => el.textContent = '⇅');
    const header = document.querySelector(`th[onclick*="sortTable('${col}')"]`);
    if (header) {
        const icon = header.querySelector('.sort-icon');
        if (icon) icon.textContent = window.currentSort.dir === 'asc' ? '↑' : '↓';
    }

    window.currentPage = 1;
    window.renderMoviesPage();
};

function autoPrefixUrl(input) {
    if (!input) return;
    let val = input.value.trim();
    if (val.startsWith('extract:') || val.startsWith('sniper:')) return;
    
    if (val.startsWith('http')) {
        const isDirectStream = val.includes('.m3u8') || val.includes('.mp4') || val.includes('.txt') || val.includes('.mkv');
        if (!isDirectStream) {
            input.value = 'extract:' + val;
        }
    }
}

function getUrlBase(url) {
    if (!url) return '';
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
        window.allMovies = data.movies || [];

        existingUrls.clear();
        let localMovies = 0, series = 0, tv = 0;
        window.allMovies.forEach(m => {
            if (m.archivo_path) existingUrls.add(getUrlBase(m.archivo_path.trim()));
            if (m.server2) existingUrls.add(getUrlBase(m.server2.trim()));
            if (m.server3) existingUrls.add(getUrlBase(m.server3.trim()));
            if (m.server4) existingUrls.add(getUrlBase(m.server4.trim()));
            if (m.server5) existingUrls.add(getUrlBase(m.server5.trim()));
            
            var gen = m.genero || '';
            if (gen === 'tv_live' || m.tipo === 'tv') { tv++; }
            else if (m.tipo === 'series') { series++; }
            else if (m.archivo_path && !m.archivo_path.startsWith('http')) { localMovies++; }
        });

        ['statLocalMovies','statSeries','statTv'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.innerText = id === 'statLocalMovies' ? localMovies : id === 'statSeries' ? series : tv;
            }
        });

        window.applyFilter(document.getElementById('librarySearch')?.value || '');
        // Set initial sort icon after first render
        const initHeader = document.querySelector(`th[onclick*="sortTable('${window.currentSort.col}')"]`);
        if (initHeader) {
            const icon = initHeader.querySelector('.sort-icon');
            if (icon) icon.textContent = window.currentSort.dir === 'asc' ? '↑' : '↓';
        }
        loadAutopilotStats();
    } catch (e) {
        console.error("Error loading stats:", e);
    }
}

window.renderPaginationControls = function(totalItems, totalPages) {
    const container = document.getElementById('paginationControls');
    if (!container) return;

    if (totalItems === 0) {
        container.innerHTML = '<span style="color:#64748b; font-size:0.9rem;">Sin resultados</span>';
        return;
    }

    const startIdx = (window.currentPage - 1) * window.itemsPerPage + 1;
    const endIdx = Math.min(window.currentPage * window.itemsPerPage, totalItems);

    let html = `
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin-top:1.5rem; padding:10px 15px; background:rgba(30,41,59,0.4); border-radius:10px; border:1px solid rgba(255,255,255,0.05); font-size:0.85rem; color:#94a3b8;">
            <div>
                Mostrando <strong style="color:#fff;">${startIdx}-${endIdx}</strong> de <strong style="color:#fff;">${totalItems}</strong> elementos
            </div>
            
            <div style="display:flex; align-items:center; gap:8px;">
                <button onclick="window.changePage(1)" ${window.currentPage === 1 ? 'disabled' : ''} class="btn-page" title="Primera Página">«</button>
                <button onclick="window.changePage(${window.currentPage - 1})" ${window.currentPage === 1 ? 'disabled' : ''} class="btn-page">Anterior</button>
                
                <span style="padding:0 5px;">Página <strong style="color:#fff;">${window.currentPage}</strong> de <strong style="color:#fff;">${totalPages}</strong></span>
                
                <button onclick="window.changePage(${window.currentPage + 1})" ${window.currentPage === totalPages ? 'disabled' : ''} class="btn-page">Siguiente</button>
                <button onclick="window.changePage(${totalPages})" ${window.currentPage === totalPages ? 'disabled' : ''} class="btn-page" title="Última Página">»</button>
            </div>
            
            <div style="display:flex; align-items:center; gap:8px;">
                <span>Mostrar:</span>
                <select onchange="window.setItemsPerPage(this.value)" style="background:#0f172a; border:1px solid #334155; color:#fff; padding:4px 8px; border-radius:6px; outline:none; cursor:pointer;">
                    <option value="20" ${window.itemsPerPage === 20 ? 'selected' : ''}>20</option>
                    <option value="30" ${window.itemsPerPage === 30 ? 'selected' : ''}>30</option>
                    <option value="50" ${window.itemsPerPage === 50 ? 'selected' : ''}>50</option>
                    <option value="100" ${window.itemsPerPage === 100 ? 'selected' : ''}>100</option>
                </select>
            </div>
        </div>
    `;
    container.innerHTML = html;
};

window.changePage = function(page) {
    window.currentPage = page;
    window.renderMoviesPage();
};

window.setItemsPerPage = function(val) {
    window.itemsPerPage = parseInt(val) || 30;
    window.currentPage = 1;
    window.renderMoviesPage();
};

window.applyFilter = function(query) {
    const q = query.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    if (!q) {
        window.filteredMovies = [...window.allMovies];
    } else {
        window.filteredMovies = window.allMovies.filter(m => {
            const title = (m.titulo || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            const id = String(m.id);
            const tmdb = String(m.tmdb_id || '');
            return title.includes(q) || id.includes(q) || tmdb.includes(q);
        });
    }
    window.currentPage = 1;
    window.renderMoviesPage();
};

window.renderMoviesPage = function() {
    const tbody = document.getElementById('movieTableBody');
    if (!tbody) return;

    const { col, dir } = window.currentSort;
    const sorted = [...window.filteredMovies];
    
    sorted.sort((a, b) => {
        let va, vb;
        if (col === 'id') {
            va = parseInt(a.id) || 0;
            vb = parseInt(b.id) || 0;
        } else if (col === 'titulo') {
            va = (a.titulo || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            vb = (b.titulo || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        } else if (col === 'tipo') {
            va = (a.tipo || '').toLowerCase();
            vb = (b.tipo || '').toLowerCase();
            // Normalize to match display labels: agrupar tv_live/tv, mapear movie→pelicula
            va = (a.genero === 'tv_live' || va === 'tv') ? 'tv_en_vivo' : va === 'movie' ? 'pelicula' : va;
            vb = (b.genero === 'tv_live' || vb === 'tv') ? 'tv_en_vivo' : vb === 'movie' ? 'pelicula' : vb;
        } else if (col === 'tmdb_id') {
            va = parseInt(a.tmdb_id) || 0;
            vb = parseInt(b.tmdb_id) || 0;
        } else if (col === 'rating') {
            va = parseFloat(a.puntuacion) || 0;
            vb = parseFloat(b.puntuacion) || 0;
        } else {
            va = a[col] || '';
            vb = b[col] || '';
        }
        
        if (va < vb) return dir === 'asc' ? -1 : 1;
        if (va > vb) return dir === 'asc' ? 1 : -1;
        return 0;
    });

    const totalItems = sorted.length;
    const totalPages = Math.ceil(totalItems / window.itemsPerPage) || 1;
    if (window.currentPage > totalPages) window.currentPage = totalPages;
    if (window.currentPage < 1) window.currentPage = 1;

    const startIdx = (window.currentPage - 1) * window.itemsPerPage;
    const pageItems = sorted.slice(startIdx, startIdx + window.itemsPerPage);

    const htmlRows = [];
    pageItems.forEach(m => {
        const rowStyle = m.is_online == 0 ? 'border: 2px solid #ef4444; background: rgba(239, 68, 68, 0.05);' : 'border-bottom: 1px solid #1e293b;';
        const statusBadge = m.is_online == 0 ? '<span style="color:#ef4444; font-size:0.6rem; border:1px solid #ef4444; padding:2px 4px; border-radius:4px; margin-left:5px;">OFFLINE</span>' : '';
        const tipoLabel = m.genero === 'tv_live' ? 'TV en Vivo' : m.tipo === 'movie' ? 'Película' : m.tipo === 'series' ? 'Serie' : m.tipo === 'tv' ? 'TV en Vivo' : m.tipo;

        htmlRows.push(`
            <tr style="${rowStyle}" data-movie-id="${m.id}">
                <td style="padding: 10px;" data-label="ID">${m.id}</td>
                <td style="padding: 10px; font-weight: 600;" data-label="Título">${m.titulo}${statusBadge}</td>
                <td style="padding: 10px; text-align:center; cursor:grab;" id="s1-${m.id}" data-url="${m.archivo_path || ''}" draggable="true" ondragstart="dragServer(event)" ondragover="allowDropServer(event)" ondrop="dropServer(event)" data-label="S1 📁">${renderStatusPlaceholder(m.archivo_path, 1, m.id)}</td>
                <td style="padding: 10px; text-align:center; cursor:grab;" id="s2-${m.id}" data-url="${m.server2 || ''}" draggable="true" ondragstart="dragServer(event)" ondragover="allowDropServer(event)" ondrop="dropServer(event)" data-label="S2 ☁️">${renderStatusPlaceholder(m.server2, 2, m.id)}</td>
                <td style="padding: 10px; text-align:center; cursor:grab;" id="s3-${m.id}" data-url="${m.server3 || ''}" draggable="true" ondragstart="dragServer(event)" ondragover="allowDropServer(event)" ondrop="dropServer(event)" data-label="S3 ☁️">${renderStatusPlaceholder(m.server3, 3, m.id)}</td>
                <td style="padding: 10px; text-align:center; cursor:grab;" id="s4-${m.id}" data-url="${m.server4 || ''}" draggable="true" ondragstart="dragServer(event)" ondragover="allowDropServer(event)" ondrop="dropServer(event)" data-label="S4 🌱">${renderStatusPlaceholder(m.server4, 4, m.id)}</td>
                <td style="padding: 10px; text-align:center; cursor:grab;" id="s5-${m.id}" data-url="${m.server5 || ''}" draggable="true" ondragstart="dragServer(event)" ondragover="allowDropServer(event)" ondrop="dropServer(event)" data-label="S5 🌱">${renderStatusPlaceholder(m.server5, 5, m.id)}</td>
                <td style="padding: 10px; text-align:center;" data-label="HLS">${m.hls_path ? '<span style="color:#10b981; font-size:0.75rem;">🟢 ' + m.hls_path + '</span>' : '<span style="color:#64748b; font-size:0.75rem;">—</span>'}</td>
                <td style="padding: 10px;" data-label="Tipo">${tipoLabel}</td>
                <td style="padding: 10px;" data-label="TMDB ID">${m.tmdb_id}</td>
                <td style="padding: 10px; color: #fbbf24;" data-label="Rating">★ ${m.puntuacion}</td>
                <td style="padding: 10px; text-align:center;" data-label="Roku"><button onclick="toggleRoku(${m.id}, ${m.visible_roku ?? 1})" style="border:none;cursor:pointer;padding:4px 8px;border-radius:6px;font-size:0.7rem;font-weight:bold;background:${m.visible_roku ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.15)'};color:${m.visible_roku ? '#10b981' : '#ef4444'};border:1px solid ${m.visible_roku ? 'rgba(16,185,129,0.35)' : 'rgba(239,68,68,0.35)'};">${m.visible_roku ? '🟢 Roku' : '🔴 Roku'}</button></td>
                <td style="padding: 10px;" data-label="Acciones">
                    <button onclick="updateMovie(${m.id}, ${m.tmdb_id}, '${m.tipo}')" style="background:var(--accent); color:white; border:none; padding:5px 10px; border-radius:5px; cursor:pointer; margin-right:5px;" title="Editar">
                        <b class="i-edit"></b>
                    </button>
                    ${m.tipo === 'series' ? `<button onclick="auditSeries(${m.id})" style="background:#f59e0b; color:white; border:none; padding:5px 10px; border-radius:5px; cursor:pointer; margin-right:5px;" title="Diagnóstico de Capítulos">
                        <b class="i-pulse"></b>
                    </button>` : ''}
                    <button onclick="deleteMovie(${m.id})" style="background:#ef4444; color:white; border:none; padding:5px 10px; border-radius:5px; cursor:pointer;" title="Eliminar">
                        <b class="i-trash"></b>
                    </button>
                </td>
            </tr>
        `);
    });

    tbody.innerHTML = htmlRows.join('');
    renderPaginationControls(totalItems, totalPages);
    bindManualDuplicateChecker();
    updateOnlineCount();
};



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
try {
const pr = await fetch('backend/autopilot.php?action=progress&t=' + Date.now());
const pd = await pr.json();
if (pd.status === 'completed' && pd.report) {
const reportBtn = document.getElementById('autopilotReportBtn');
if (reportBtn) reportBtn.style.display = 'inline-flex';
}
} catch (e) {}
}

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

const slotEmoji = serverNum === 1 ? '📁' : (serverNum <= 3 ? '☁️' : '🌱');
const slotLabel = serverNum === 1 ? 'S1' : (serverNum <= 3 ? 'S' + serverNum : 'S' + serverNum);
const slotTitle = serverNum === 1 ? 'Ubicación Local .mp4' : (serverNum <= 3 ? 'Link Nube .m3u8' : 'Semilla (extract:/sniper:)');

const isExtract = url.startsWith('extract:');
const isSniper = url.startsWith('sniper:');
const isUnsupported = url.toLowerCase().endsWith('.avi') || url.toLowerCase().endsWith('.mkv');

if (isExtract || isSniper) {
const icon = isExtract ? 'cube-outline' : 'flash-outline';
const color = '#a78bfa';
const bg = 'rgba(167, 139, 250, 0.08)';
const border = 'rgba(167, 139, 250, 0.25)';
const glow = '0 0 10px rgba(167, 139, 250, 0.15)';
const title = slotTitle + ' — ' + (isExtract ? 'Semilla Fénix' : 'Contenedor Sniper');
return `<div onclick="checkServerStatus('${url}', 's${serverNum}-${id}')" style="cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:5px; background:${bg}; color:${color}; border:1px solid ${border}; padding:4px 8px; border-radius:8px; font-size:0.75rem; font-weight:bold; box-shadow:${glow}; transition: 0.2s;" title="${title}">${slotEmoji} ${slotLabel}</div>`;
}

const isGDrive = url.startsWith('gdrive:');
if (isGDrive) {
const color = '#34d399';
const bg = 'rgba(52, 211, 153, 0.1)';
const border = 'rgba(52, 211, 153, 0.35)';
const glow = '0 0 12px rgba(52, 211, 153, 0.2)';
const title = slotTitle + ' — Google Drive';
const svg = `<svg width="16" height="16" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:2px;"><polygon points="12,2 22,20 2,20" fill="none" stroke="#34a853" stroke-width="3" stroke-linejoin="round"/><line x1="12" y1="2" x2="22" y2="20" stroke="#fbbc04" stroke-width="3" stroke-linecap="round"/><line x1="22" y1="20" x2="2" y2="20" stroke="#4285f4" stroke-width="3" stroke-linecap="round"/></svg>`;
return `<div onclick="checkServerStatus('${url}', 's${serverNum}-${id}')" style="cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:4px; background:${bg}; color:${color}; border:1px solid ${border}; padding:4px 8px; border-radius:8px; font-size:0.75rem; font-weight:bold; box-shadow:${glow}; transition: 0.2s;" title="${title}">${svg} ${slotLabel}</div>`;
}

let icon, color, bg, border, glow, title, extraBadge = '';

if (serverNum === 1) {
icon = 'hdd'; color = '#10b981'; bg = 'rgba(16, 185, 129, 0.12)'; border = 'rgba(16, 185, 129, 0.35)'; glow = '0 0 12px rgba(16, 185, 129, 0.25)';
title = slotTitle;
} else if (serverNum <= 3) {
icon = 'cloud'; color = '#38bdf8'; bg = 'rgba(56, 189, 248, 0.08)'; border = 'rgba(56, 189, 248, 0.25)'; glow = 'none';
title = slotTitle;
} else {
icon = 'flash-outline'; color = '#a78bfa'; bg = 'rgba(167, 139, 250, 0.08)'; border = 'rgba(167, 139, 250, 0.25)'; glow = '0 0 10px rgba(167, 139, 250, 0.15)';
title = slotTitle;
}

if (isUnsupported) {
icon = 'warning'; color = '#fff'; bg = 'rgba(244, 63, 94, 0.15)'; border = 'rgba(244, 63, 94, 0.5)'; glow = '0 0 15px rgba(244, 63, 94, 0.3)';
title = 'FORMATO INVÁLIDO (.AVI / .MKV). Debe convertirse a .MP4 para reproducirse.';
extraBadge = ' <span style="font-size:0.55rem; background:#f43f5e; color:#fff; padding:2px 4px; border-radius:4px; margin-left:3px; font-weight:900; letter-spacing:1px;">ERR</span>';
}

return `<div onclick="checkServerStatus('${url}', 's${serverNum}-${id}')" style="cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:5px; background:${bg}; color:${color}; border:1px solid ${border}; padding:4px 8px; border-radius:8px; font-size:0.75rem; font-weight:bold; box-shadow:${glow}; transition: 0.2s;" title="${title}">${slotEmoji} ${slotLabel}${extraBadge}</div>`;
}
let draggedCell = null;

function dragServer(event) {
draggedCell = event.target.closest('td');
event.dataTransfer.effectAllowed = 'move';
event.dataTransfer.setData('text/plain', draggedCell.id);
}

function allowDropServer(event) {
event.preventDefault();
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
loadStats();
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
const parts = elementId.split('-');
const serverNum = parts[0].replace('s', '');

const isExtract = url.startsWith('extract:');
const isSniper = url.startsWith('sniper:');

if (isExtract || isSniper) {
const icon = isExtract ? 'cube-outline' : 'flash-outline';
const color = isExtract ? '#38bdf8' : '#a78bfa';
const title = isExtract ? 'Semilla de Extracción (Fénix) - Activa y Saludable' : 'Contenedor Sniper - Activo y Saludable';
el.innerHTML = `<div style="cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:5px;padding:4px 8px;border-radius:8px;font-size:0.75rem;font-weight:bold;color:${color};"><b class="i-${icon}"></b> S${serverNum}</div>`;
el.title = title;
updateOnlineCount();
return;
}

const isLocal = !url.startsWith('http');
el.innerHTML = '<div style="cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:5px;padding:4px 8px;border-radius:8px;font-size:0.75rem;font-weight:bold;color:#fff;background:rgba(148,163,184,0.1);border:1px solid rgba(148,163,184,0.2);"><b class="i-refresh spin"></b> S' + serverNum + '</div>';

let probeUrl = url;

try {
const res = await fetch(`backend/check_status.php?url=${encodeURIComponent(probeUrl)}`);
const data = await res.json();

let icon, title, bg, border, glow;
if (data.status === 'online') {
icon = 'check'; title = (isLocal ? "HDD" : "Servidor") + " en línea (HTTP " + data.http_code + ")";
bg = 'rgba(16,185,129,0.12)'; border = 'rgba(16,185,129,0.4)'; glow = '0 0 12px rgba(16,185,129,0.2)';
} else {
icon = 'warning'; title = (isLocal ? "HDD" : "Servidor") + " no disponible (" + (data.error || "HTTP " + data.http_code) + ")";
bg = 'rgba(251,191,36,0.12)'; border = 'rgba(251,191,36,0.4)'; glow = '0 0 12px rgba(251,191,36,0.2)';
}
el.innerHTML = `<div onclick="checkServerStatus('${url.replace(/'/g, "\\'")}','${elementId}')" style="cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:5px;background:${bg};color:#fff;border:1px solid ${border};padding:4px 8px;border-radius:8px;font-size:0.75rem;font-weight:bold;box-shadow:${glow};transition:.2s;" title="${title}"><b class="i-${icon}"></b> S${serverNum}</div>`;
} catch (e) {
el.innerHTML = `<div style="cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:5px;background:rgba(251,191,36,0.12);color:#fff;border:1px solid rgba(251,191,36,0.4);padding:4px 8px;border-radius:8px;font-size:0.75rem;font-weight:bold;box-shadow:0 0 12px rgba(251,191,36,0.2);"><b class="i-warning"></b> S${serverNum}</div>`;
}
updateOnlineCount();
}

function updateOnlineCount() {
const all = window.allMovies || [];
document.getElementById('totalMovies').innerText = all.length;
}

window.auditSeries = async (id) => {
    const resData = await fetch(`backend/get_content.php?admin=1&id=${id}&t=${Date.now()}`).then(r => r.json());
    const movie = resData.movies && resData.movies.length > 0 ? resData.movies[0] : null;
    if (!movie || !movie.episodes || movie.episodes.length === 0) {
        Swal.fire('Sin Capítulos', 'Esta serie no tiene capítulos para analizar.', 'info');
        return;
    }

    const eps = movie.episodes.sort((a, b) => {
        if (parseInt(a.temporada) !== parseInt(b.temporada)) return parseInt(a.temporada) - parseInt(b.temporada);
        return parseInt(a.episodio) - parseInt(b.episodio);
    });

    let html = '<div style="max-height:400px; overflow-y:auto; text-align:left; background:rgba(0,0,0,0.3); padding:10px; border-radius:8px; scrollbar-width:thin;">';
    eps.forEach(ep => {
        html += `<div id="ep-status-${ep.meta_id}" style="padding:8px; border-bottom:1px solid rgba(255,255,255,0.05); display:flex; justify-content:space-between; align-items:center;">
            <strong style="color:#a78bfa;">T${ep.temporada} - Cap ${ep.episodio}</strong>
            <span id="ep-icon-${ep.meta_id}" style="color:#64748b; font-size:0.9rem;">⏳ En espera...</span>
        </div>`;
    });
    html += '</div>';

    Swal.fire({
        title: 'Diagnóstico: ' + movie.titulo,
        html: html,
        background: '#1e293b',
        color: '#fff',
        showConfirmButton: true,
        confirmButtonText: 'Cerrar',
        allowOutsideClick: false
    });

    const tasks = eps.map((ep, idx) => {
        return {
            index: idx,
            item: ep,
            fn: async (episode) => {
                const icon = document.getElementById(`ep-icon-${episode.meta_id}`);
                if (!icon) return;
                
                icon.innerHTML = '<b class="i-refresh spin"></b> Revisando...';
                icon.style.color = '#fbbf24';
                
                const urls = [episode.archivo_path, episode.server2, episode.server3, episode.server4, episode.server5].filter(u => u && u.trim() !== '');
                if (urls.length === 0) {
                    icon.innerHTML = '❌ Sin enlaces';
                    icon.style.color = '#ef4444';
                    return;
                }
                
                let anyOnline = false;
                for (let url of urls) {
                    if (!url.startsWith('http')) {
                        anyOnline = true;
                        break;
                    }
                    try {
                        const res = await fetch(`backend/check_status.php?url=${encodeURIComponent(url.trim())}`);
                        const data = await res.json();
                        if (data.status === 'online') {
                            anyOnline = true;
                            break;
                        }
                    } catch (e) {}
                }
                
                if (anyOnline) {
                    icon.innerHTML = '✅ OK';
                    icon.style.color = '#10b981';
                } else {
                    icon.innerHTML = '❌ Caído';
                    icon.style.color = '#ef4444';
                }
            }
        };
    });

    await runConcurrent(tasks, 5);
};

async function syncGateway() {
Swal.fire({
title: 'Sincronizando Gateway...',
text: 'Enviando señal de túnel a GitHub...',
allowOutsideClick: false,
didOpen: () => { Swal.showLoading(); }
});

try {
const res = await fetch('backend/faro.php');
const data = await res.json();
if (data.status === 'success') {
Swal.fire('¡Sincronizado!', `El túnel se ha actualizado a: ${data.url}`, 'success');
} else {
Swal.fire('Error', data.message, 'error');
}
} catch (err) {
Swal.fire('Error Fatal', 'No se pudo conectar con el motor Faro.', 'error');
}
}

// ─── DHARMA #GDRIVE: Sincronización Global de Google Drive ────────────────
// Escanea gdrive:HLS_TEST (o la carpeta que el usuario elija),
// detecta todos los SxxEyy/playlist.m3u8, y los indexa automáticamente
// en series_metadata para la serie que seleccione el admin.
async function syncGDriveGlobal() {
    Swal.fire({
        title: '☁️ Sincronizando Google Drive...',
        html: `<div style="text-align:left; font-size:0.9rem; color:#94a3b8;">
            Escaneando todas las carpetas de GDrive en busca de episodios
            <code style="color:#a78bfa;">SxxEyy/playlist.m3u8</code>.<br><br>
            Las series se auto-crean si no existen en el catálogo.
        </div>`,
        allowOutsideClick: false,
        background: '#0f172a',
        color: '#f1f5f9',
        didOpen: () => { Swal.showLoading(); }
    });

    try {
        const res = await fetch('backend/sync_gdrive_auto.php');
        const data = await res.json();

        if (data.status === 'success') {
            let details = '';
            let logLines = '';

            const movies = data.movies || [];
            const movieCreated = movies.filter(m => m.status === 'created');
            const movieExists = movies.filter(m => m.status === 'already_exists' || m.status === 'metadata_added');
            if (movieCreated.length > 0) {
                details += `<h4 style="color:#38bdf8; margin:8px 0;">🎬 Películas nuevas (${movieCreated.length})</h4>`;
                movieCreated.forEach(m => {
                    details += `<div style="font-size:0.85rem; padding:3px 0; color:#94a3b8;">+ ${m.file}</div>`;
                    logLines += `  [PELI] ${m.file}: creada (ID ${m.contenido_id})\n`;
                });
            }
            if (movieExists.length > 0) {
                details += `<h4 style="color:#64748b; margin:8px 0;">📁 Películas ya existentes (${movieExists.length})</h4>`;
                movieExists.forEach(m => {
                    details += `<div style="font-size:0.85rem; padding:3px 0; color:#64748b;">✓ ${m.file}</div>`;
                });
            }

            if (data.series && data.series.length > 0) {
                details += `<h4 style="color:#a78bfa; margin:12px 0 8px;">📺 Series</h4>`;
                data.series.forEach(s => {
                    const creada = s.creada ? '🆕 Creada' : '✓ Existente';
                    details += `<div style="margin:8px 0; padding:10px; background:rgba(30,41,59,0.4); border-radius:8px; border:1px solid rgba(255,255,255,0.05);">
                        <div style="font-weight:700; color:#fff;">${creada}: ${s.serie}</div>
                        <div style="font-size:0.8rem; color:#94a3b8; margin-top:4px;">
                            ${s.episodios} episodios en GDrive |
                            <span style="color:#10b981;">+${s.added.length} añadidos</span> |
                            <span style="color:#38bdf8;">↻${s.updated.length} actualizados</span> |
                            <span style="color:#64748b;">=${s.ignored.length} sin cambios</span>
                        </div>`;
                    if (s.added.length > 0) {
                        details += `<div style="font-size:0.75rem; color:#10b981; margin-top:4px;">Añadidos: ${s.added.map(e=>`T${e.temporada}E${String(e.episodio).padStart(2,'0')}`).join(', ')}</div>`;
                    }
                    details += `</div>`;
                    logLines += `  [${s.serie}] ${s.added.length} añadidos, ${s.updated.length} actualizados, ${s.ignored.length} sin cambios\n`;
                });
            }

            if (!details) {
                details = `<p style="color:#64748b;">No se encontraron archivos en GDrive.</p>`;
            }

            if (window.switchTab) window.switchTab('console');
            const consoleBox = document.getElementById('consoleOutput');
            if (consoleBox) {
                consoleBox.innerHTML += `\n[GDrive Auto Sync] ${new Date().toLocaleString()}\n` + logLines;
            }

            Swal.fire({
                title: '✅ Sincronización Completada',
                html: `<div style="text-align:left; font-size:0.9rem;">${details}</div>`,
                icon: 'success',
                background: '#0f172a',
                color: '#f1f5f9',
                confirmButtonColor: '#10b981'
            });

            if (window.loadStats) window.loadStats();
        } else {
            Swal.fire({
                title: 'Error en Sincronización',
                text: data.message || 'Error al conectar con rclone.',
                icon: 'error',
                background: '#0f172a',
                color: '#f1f5f9'
            });
        }
    } catch (err) {
        Swal.fire({
            title: 'Error de Red',
            text: err.message,
            icon: 'error',
            background: '#0f172a',
            color: '#f1f5f9'
        });
    }
}

async function runAuditor() {
    const consoleBox = document.getElementById('consoleOutput');
    if (window.switchTab) window.switchTab('console');
    
    const progressContainer = document.getElementById('progressBarContainer');
    const progressFill = document.getElementById('progressBarFill');
    const progressText = document.getElementById('progressBarText');
    const progressMsg = document.getElementById('progressBarMsg');
    
    if (progressContainer) progressContainer.style.display = 'block';
    if (consoleBox) consoleBox.innerHTML = '⚡ Iniciando Auditoría FENIX de Alta Velocidad (In-memory y concurrente)...\n';
    
    Swal.fire({
        title: 'Auditando Biblioteca...',
        html: 'Analizando integridad de mirrors de forma concurrente.<br><strong id="auditProgress">0</strong> de ' + window.allMovies.length,
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });
    
    let completedCount = 0;
    let offlineCount = 0;
    let onlineCount = 0;
    let deadServersList = [];
    
    const tasks = window.allMovies.map((m, idx) => {
        return {
            index: idx,
            item: m,
            fn: async (movie) => {
                const mirrors = [
                    { col: 'archivo_path', val: movie.archivo_path },
                    { col: 'server2', val: movie.server2 },
                    { col: 'server3', val: movie.server3 },
                    { col: 'server4', val: movie.server4 },
                    { col: 'server5', val: movie.server5 }
                ];
                
                let anyOnline = false;
                let mirrorsChecked = 0;
                let mirrorsFoundDown = 0;
                
                for (const mirror of mirrors) {
                    const url = mirror.val;
                    if (!url || url === 'null' || url === '' || url === 'undefined') continue;
                    
                    mirrorsChecked++;
                    const isSeed = url.startsWith('extract:') || url.startsWith('sniper:');
                    const isLocal = !url.startsWith('http') && !isSeed && !url.startsWith('backend/');
                    
                    if (isLocal || isSeed) {
                        anyOnline = true;
                        continue;
                    }
                    
                    try {
                        const probeRes = await fetch(`backend/check_status.php?url=${encodeURIComponent(url.trim())}`);
                        const probeData = await probeRes.json();
                        
                        if (probeData.status === 'online') {
                            anyOnline = true;
                        } else {
                            mirrorsFoundDown++;
                            deadServersList.push({ id: movie.id, column: mirror.col === 'archivo_path' ? 's1' : mirror.col.replace('server', 's'), url: url, title: movie.titulo });
                        }
                    } catch (e) {
                        console.error(`Error auditing mirror ${url} of ${movie.titulo}`, e);
                    }
                }
                
                const finalStatus = (mirrorsChecked > 0 && !anyOnline) ? '0' : '1';
                
                // Update online status in database
                const formData = new FormData();
                formData.append('id', movie.id);
                formData.append('status', finalStatus);
                await fetch('backend/set_online_status.php', { method: 'POST', body: formData });
                
                // Update local in-memory record so it updates instantly in the table
                movie.is_online = parseInt(finalStatus);
                
                return { movie, finalStatus, mirrorsChecked, mirrorsFoundDown };
            }
        };
    });
    
    await runConcurrent(tasks, 12, (completed, total, item, res) => {
        completedCount++;
        document.getElementById('auditProgress').innerText = completedCount;
        
        const percent = Math.round((completedCount / total) * 100);
        if (progressFill) progressFill.style.width = `${percent}%`;
        if (progressText) progressText.innerText = `${percent}%`;
        if (progressMsg) progressMsg.innerText = `Auditando: ${completedCount}/${total} títulos.`;
        
        if (res.finalStatus === '0') {
            offlineCount++;
            if (consoleBox) {
                consoleBox.innerHTML += `<span style="color:#ef4444">> 🚨 OFFLINE: ${item.titulo} (${res.mirrorsFoundDown}/${res.mirrorsChecked} caídos)</span>\n`;
            }
        } else {
            onlineCount++;
            if (completedCount % 5 === 0 && consoleBox) {
                consoleBox.innerHTML += `> ✅ OK: ${item.titulo}\n`;
            }
        }
        if (consoleBox) {
            consoleBox.scrollTop = consoleBox.scrollHeight;
        }
    });
    
    // Rerender table page to show new status instantly
    window.renderMoviesPage();
    
    let confirmButtonHtml = `<b>${offlineCount}</b> película(s) retiradas de cartelera.<br><b>${onlineCount}</b> película(s) en línea.`;
    let showCancel = false;
    let confirmText = 'OK';
    
    if (deadServersList.length > 0) {
        let serverListHtml = deadServersList.map(s =>
            `<div style="background:rgba(239,68,68,0.08);border:1px solid #7f1d1d;border-radius:6px;padding:8px;margin:6px 0;font-size:0.8rem;">
                <div style="color:#fca5a5;font-weight:600;">📁 ${s.title}</div>
                <div style="margin-top:4px;color:#94a3b8;word-break:break-all;">
                    <span style="color:#f59e0b;">${s.column}</span>: ${s.url}
                </div>
            </div>`
        ).join('');
        
        confirmButtonHtml += `
            <br><br>
            <div style="color:#ef4444;font-weight:bold;margin-bottom:8px;">Se detectaron ${deadServersList.length} servidores estáticos caídos:</div>
            <div style="max-height:250px;overflow-y:auto;scrollbar-width:thin;">${serverListHtml}</div>`;
        showCancel = true;
        confirmText = 'Eliminar Servidores Caídos';
    }
    
    Swal.fire({
        icon: 'success',
        title: 'Auditoría Finalizada ✅',
        html: confirmButtonHtml,
        background: '#1e293b',
        color: '#fff',
        showCancelButton: showCancel,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#3b82f6',
        confirmButtonText: confirmText,
        cancelButtonText: 'Cerrar'
    }).then(async (result) => {
        if (result.isConfirmed && deadServersList.length > 0) {
            Swal.fire({ title: 'Limpiando...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            try {
                const res = await fetch('backend/delete_dead_servers.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(deadServersList)
                });
                const resText = await res.text();
                let data;
                try {
                    data = JSON.parse(resText);
                } catch (parseErr) {
                    console.error("No JSON response:", resText);
                    Swal.fire('Error', 'El servidor devolvió un error inesperado', 'error');
                    return;
                }
                
                if (data.status === 'success') {
                    Swal.fire('¡Limpieza Completada!', `Se eliminaron ${data.deleted} enlaces caídos.`, 'success');
                    loadStats();
                } else {
                    Swal.fire('Error', 'No se pudieron eliminar', 'error');
                }
            } catch (e) {
                console.error("Network failure deleting dead servers:", e);
                Swal.fire('Error', 'Fallo de red: ' + e.message, 'error');
            }
        }
    });
}

async function checkAllServers() {
    const cells = document.querySelectorAll('[id^="s1-"], [id^="s2-"], [id^="s3-"], [id^="s4-"], [id^="s5-"]');
    const tasks = [];
    
    if (window.switchTab) window.switchTab('console');
    
    const progressContainer = document.getElementById('progressBarContainer');
    const progressFill = document.getElementById('progressBarFill');
    const progressText = document.getElementById('progressBarText');
    const progressMsg = document.getElementById('progressBarMsg');
    const consoleOutput = document.getElementById('consoleOutput');
    
    if (progressContainer) progressContainer.style.display = 'block';
    if (consoleOutput) consoleOutput.innerHTML = '⚡ Iniciando verificación masiva de servidores...\n';

    let index = 0;
    cells.forEach(cell => {
        const url = cell.getAttribute('data-url');
        if (url && url !== 'null' && url !== 'undefined' && url.trim() !== '') {
            tasks.push({
                index: index++,
                item: { cell: cell, url: url.trim() },
                fn: async (item) => {
                    await checkServerStatus(item.url, item.cell.id);
                    return true;
                }
            });
        }
    });

    if (tasks.length === 0) {
        if (consoleOutput) consoleOutput.innerHTML += '\n✅ No se encontraron enlaces en la página actual para verificar.';
        return;
    }

    await runConcurrent(tasks, 15, (completed, total, item) => {
        const percent = Math.round((completed / total) * 100);
        if (progressFill) progressFill.style.width = `${percent}%`;
        if (progressText) progressText.innerText = `${percent}%`;
        if (progressMsg) progressMsg.innerText = `Verificado: ${completed}/${total} enlaces.`;
        if (consoleOutput) {
            consoleOutput.innerHTML += `[${percent}%] Verificado link en ${item.cell.id}: ${item.url.substring(0, 50)}...\n`;
            consoleOutput.scrollTop = consoleOutput.scrollHeight;
        }
    });

    if (progressMsg) progressMsg.innerText = `¡Completado! ${tasks.length} enlaces verificados.`;
    if (consoleOutput) {
        consoleOutput.innerHTML += `\n✅ Verificación masiva de servidores finalizada.`;
        consoleOutput.scrollTop = consoleOutput.scrollHeight;
    }
}

async function updateMovie(id, currentTmdbId, tipo) {
const resData = await fetch(`backend/get_content.php?admin=1&id=${id}&t=${Date.now()}`).then(r => r.json());
const movie = resData.movies && resData.movies.length > 0 ? resData.movies[0] : null;

let initialPath = '', initialS2 = '', initialS3 = '', initialS4 = '', initialS5 = '';
let defaultTemp = 1, defaultEp = 1, currentMetaId = null;

if (tipo === 'series' || tipo === 'tv') {
const eps = movie && movie.episodes ? movie.episodes : [];
if (eps.length > 0) {
const latest = eps[eps.length - 1];
defaultTemp = latest.temporada;
defaultEp = latest.episodio;
initialPath = latest.archivo_path || '';
initialS2 = latest.server2 || '';
initialS3 = latest.server3 || '';
initialS4 = latest.server4 || '';
initialS5 = latest.server5 || '';
currentMetaId = latest.meta_id;
}
} else {
initialPath = movie ? (movie.archivo_path || '') : '';
initialS2 = movie ? (movie.server2 || '') : '';
initialS3 = movie ? (movie.server3 || '') : '';
initialS4 = movie ? (movie.server4 || '') : '';
initialS5 = movie ? (movie.server5 || '') : '';
currentMetaId = movie ? movie.meta_id : null;
}

let currentFilename = '', currentDir = '';
if (isLocalFilePath(initialPath)) {
const idx = initialPath.lastIndexOf('/');
currentFilename = idx >= 0 ? initialPath.substring(idx + 1) : initialPath;
currentDir = idx > 0 ? initialPath.substring(0, idx) : '';
}

const tipoApi = (tipo === 'series' || tipo === 'tv') ? 'tv' : 'movie';
const tmdbLink = currentTmdbId > 0
? `   <a id="tmdbVerifyLink" href="https://www.themoviedb.org/${tipoApi}/${currentTmdbId}" target="_blank" style="display:inline-flex;align-items:center;gap:4px;margin-left:8px;color:#38bdf8;font-size:0.75rem;text-decoration:none;border:1px solid rgba(56,189,248,0.3);padding:2px 8px;border-radius:6px;background:rgba(56,189,248,0.08);" title="Abrir TMDB para verificar">🔍 Verificar</a>`
: '<span style="margin-left:8px;font-size:0.7rem;color:#64748b;">(modo manual)</span>';
let modalHtml = `<label style="text-align:left; display:block; font-size:0.9rem; margin-bottom:5px;">ID TMDB: ${tmdbLink}</label>` +
`<div style="display:flex;gap:8px;margin:0 0 15px 0;">` +
`<input id="swal-input1" class="swal2-input" value="${currentTmdbId}" style="flex:1;margin:0;max-width:100%;" oninput="updateTmdbVerifyLink(this.value)">` +
`</div>` +
`<div style="margin-bottom:10px;padding:10px;background:rgba(99,102,241,0.08);border-radius:8px;border:1px solid rgba(99,102,241,0.2);">` +
`<label style="text-align:left;display:block;font-size:0.85rem;margin-bottom:5px;color:#a5b4fc;"><b class="i-doc"></b> Nombre del archivo <span style="color:#fbbf24;font-size:0.7rem;font-weight:400;">(cámbialo para renombrar el archivo físico en disco)</span>:</label>` +
`<input id="swal-filename" class="swal2-input" value="${currentFilename}" placeholder="Ej: Mi Película (2024).mp4" style="margin:0;width:100%;font-family:monospace;">` +
`<div id="swal-filename-dir" style="font-size:0.7rem;color:#64748b;margin-top:4px;">📁 ${currentDir}/</div></div>`;

if (tipo === 'series' || tipo === 'tv') {
modalHtml += `<div style="display:flex; gap:10px; margin-bottom:15px; background:rgba(255,255,255,0.05); padding:10px; border-radius:8px; flex-wrap:wrap;">` +
`<div style="flex:1;"><label style="text-align:left; display:block; font-size:0.8rem; margin-bottom:5px; color:#a78bfa;">Temporada:</label>` +
`<input id="swal-temp" type="number" min="1" class="swal2-input" value="${defaultTemp}" oninput="if(this.value < 1) this.value = 1;" style="margin:0; width:100%;"></div>` +
`<div style="flex:1;"><label style="text-align:left; display:block; font-size:0.8rem; margin-bottom:5px; color:#a78bfa;">Episodio:</label>` +
`<input id="swal-ep" type="number" min="1" class="swal2-input" value="${defaultEp}" oninput="if(this.value < 1) this.value = 1;" style="margin:0; width:100%;"></div>` +
`<div style="flex-basis: 100%; margin-top:5px;"><button type="button" onclick="checkModalServers()" style="width:100%; background:rgba(16,185,129,0.1); color:#10b981; border:1px solid #10b981; padding:8px; border-radius:5px; cursor:pointer; font-weight:bold;"><b class="i-pulse"></b> Probar Enlaces del Capítulo</button></div>` +
`<div style="flex-basis: 100%; margin-top:5px;"><button type="button" onclick="syncGDriveForSeries(${id})" style="width:100%; background:rgba(99,102,241,0.1); color:#818cf8; border:1px solid #818cf8; padding:8px; border-radius:5px; cursor:pointer; font-weight:bold;"><b class="i-cloud"></b> Sincronizar Google Drive</button></div>` +
`</div>`;
}

window.checkModalServers = async () => {
const btn = document.querySelector('button[onclick="checkModalServers()"]');
if (btn) btn.innerHTML = '<b class="i-hourglass"></b> Probando...';

const inputs = [
document.getElementById('swal-input2'),
document.getElementById('swal-input3'),
document.getElementById('swal-input4'),
document.getElementById('swal-input5'),
document.getElementById('swal-input6')
];

for (let input of inputs) {
if (!input) continue;
const statusSpan = document.getElementById('status-' + input.id);
if (statusSpan) statusSpan.innerHTML = '';

if (input.value.trim() !== '') {
input.style.border = '2px solid #fbbf24';
input.style.backgroundColor = 'rgba(251,191,36,0.1)';
if (statusSpan) statusSpan.innerHTML = '⏳';

try {
const url = input.value.trim();
const res = await fetch(`backend/check_status.php?url=${encodeURIComponent(url)}`);
const data = await res.json();
if (data.status === 'online') {
input.style.border = '2px solid #10b981';
input.style.backgroundColor = 'rgba(16,185,129,0.1)';
if (statusSpan) statusSpan.innerHTML = '✅';
} else {
input.style.border = '2px solid #ef4444';
input.style.backgroundColor = 'rgba(239,68,68,0.1)';
if (statusSpan) statusSpan.innerHTML = '❌';
}
} catch {
input.style.border = '2px solid #ef4444';
input.style.backgroundColor = 'rgba(239,68,68,0.1)';
if (statusSpan) statusSpan.innerHTML = '❌';
}
} else {
input.style.border = '1px solid #334155';
input.style.backgroundColor = 'rgba(0,0,0,0.5)';
}
}

if (btn) btn.innerHTML = '<b class="i-pulse"></b> Probar Enlaces del Capítulo';
};

window.syncGDriveForSeries = async (id) => {
    const { value: folder } = await Swal.fire({
        title: 'Sincronizar Episodios de GDrive',
        input: 'text',
        inputLabel: 'Carpeta en Google Drive (bajo gdrive:)',
        inputValue: 'HLS_TEST',
        showCancelButton: true,
        background: '#0f172a',
        color: '#f1f5f9',
        confirmButtonColor: '#3b82f6',
        cancelButtonColor: '#ef4444',
        inputValidator: (value) => {
            if (!value) {
                return '¡Debes ingresar un nombre de carpeta!';
            }
        }
    });

    if (folder) {
        Swal.fire({
            title: 'Sincronizando...',
            html: 'Escaneando archivos en gdrive:<b>' + folder + '</b>...',
            allowOutsideClick: false,
            background: '#0f172a',
            color: '#f1f5f9',
            didOpen: () => {
                Swal.showLoading();
            }
        });

        try {
            const res = await fetch(`backend/sync_gdrive.php?contenido_id=${id}&folder=${encodeURIComponent(folder)}`);
            const data = await res.json();
            
            if (data.status === 'success') {
                const added = data.added.length;
                const updated = data.updated.length;
                
                let details = '';
                if (data.added.length > 0) {
                    details += '<p style="color:#10b981; margin: 5px 0;"><b>Añadidos:</b> ' + data.added.map(e => `T${e.temporada}E${e.episodio}`).join(', ') + '</p>';
                }
                if (data.updated.length > 0) {
                    details += '<p style="color:#38bdf8; margin: 5px 0;"><b>Actualizados:</b> ' + data.updated.map(e => `T${e.temporada}E${e.episodio}`).join(', ') + '</p>';
                }
                if (data.added.length === 0 && data.updated.length === 0) {
                    details = '<p>Todos los episodios ya estaban completamente indexados.</p>';
                }

                Swal.fire({
                    title: '¡Sincronización Completada!',
                    html: `<div style="text-align:left; font-size:0.9rem;">
                        <p>Serie: <b>${data.serie}</b></p>
                        ${details}
                    </div>`,
                    icon: 'success',
                    background: '#0f172a',
                    color: '#f1f5f9',
                    confirmButtonColor: '#10b981'
                });
                
                // Cerrar modal de edición anterior para reflejar los cambios
                Swal.close();
                // Recargar el catálogo
                if (window.loadStats) window.loadStats();
            } else {
                Swal.fire({
                    title: 'Error',
                    text: data.message || 'Error desconocido',
                    icon: 'error',
                    background: '#0f172a',
                    color: '#f1f5f9'
                });
            }
        } catch (err) {
            Swal.fire({
                title: 'Error de Red',
                text: err.message,
                icon: 'error',
                background: '#0f172a',
                color: '#f1f5f9'
            });
        }
    }
};

const categoriaOpts = [
{ val: 'movie', label: '🎬 Película' },
{ val: 'series', label: '📺 Serie' },
{ val: 'tv', label: '📡 TV en Vivo' }
].map(o => `<option value="${o.val}" ${tipo === o.val ? 'selected' : ''}>${o.label}</option>`).join('');

modalHtml += `<div style="margin-bottom:14px;padding:10px 12px;background:rgba(99,102,241,0.08);border-radius:8px;border:1px solid rgba(99,102,241,0.25);">` +
`<label style="text-align:left;display:block;font-size:0.8rem;margin-bottom:6px;color:#a5b4fc;">🗂️ Categoría:</label>` +
`<select id="swal-categoria" style="width:100%;padding:9px 12px;background:#0f172a;color:#f1f5f9;border:1px solid #334155;border-radius:6px;font-size:0.9rem;cursor:pointer;">` +
categoriaOpts +
`</select></div>` +
`<label style="text-align:left; display:block; font-size:0.8rem; margin-bottom:5px; color:#10b981;font-weight:bold;">S1 📁 Ubicación Local .mp4: <span id="status-swal-input2"></span></label>` +
`<input id="swal-input2" class="swal2-input" value="${initialPath}" placeholder="Ruta local .mp4" style="margin:0 0 10px 0; width:100%;">` +
`<label style="text-align:left; display:block; font-size:0.8rem; margin-bottom:5px; color:#38bdf8;font-weight:bold;">S2 ☁️ Link Nube .m3u8: <span id="status-swal-input3"></span></label>` +
`<input id="swal-input3" class="swal2-input" value="${initialS2}" placeholder="URL .m3u8 en la nube" style="margin:0 0 10px 0; width:100%;">` +
`<label style="text-align:left; display:block; font-size:0.8rem; margin-bottom:5px; color:#38bdf8;font-weight:bold;">S3 ☁️ Link Nube .m3u8: <span id="status-swal-input4"></span></label>` +
`<input id="swal-input4" class="swal2-input" value="${initialS3}" placeholder="URL .m3u8 en la nube" style="margin:0 0 10px 0; width:100%;">` +
`<label style="text-align:left; display:block; font-size:0.8rem; margin-bottom:5px; color:#a78bfa;font-weight:bold;">S4 🌱 Semilla: <span id="status-swal-input5"></span></label>` +
`<input id="swal-input5" class="swal2-input" value="${initialS4}" placeholder="extract: o sniper:" style="margin:0 0 10px 0; width:100%;">` +
`<label style="text-align:left; display:block; font-size:0.8rem; margin-bottom:5px; color:#a78bfa;font-weight:bold;">S5 🌱 Semilla: <span id="status-swal-input6"></span></label>` +
`<input id="swal-input6" class="swal2-input" value="${initialS5}" placeholder="extract: o sniper:" style="margin:0; width:100%;">` +

`<div style="margin-top:18px;padding-top:15px;border-top:1px solid rgba(255,255,255,0.08);">` +
`  <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">` +
`    <div>` +
`      <label style="text-align:left;display:block;font-size:0.8rem;margin-bottom:5px;color:#fbbf24;font-weight:bold;">🎴 Poster URL</label>` +
`      <input id="swal-poster" class="swal2-input" value="${movie?.poster_path || ''}" placeholder="https://image.tmdb.org/t/p/w500/..." style="margin:0 0 5px 0;width:100%;font-size:0.75rem;">` +
`      <img id="swal-poster-preview" src="${movie?.poster_path || ''}" style="width:100%;max-width:120px;border-radius:6px;object-fit:cover;margin-top:4px;${movie?.poster_path ? '' : 'display:none;'}" onerror="this.style.display='none'">` +
`    </div>` +
`    <div>` +
`      <label style="text-align:left;display:block;font-size:0.8rem;margin-bottom:5px;color:#38bdf8;font-weight:bold;">🖼️ Hero/Backdrop URL</label>` +
`      <input id="swal-backdrop" class="swal2-input" value="${movie?.backdrop_path || ''}" placeholder="https://image.tmdb.org/t/p/original/..." style="margin:0 0 5px 0;width:100%;font-size:0.75rem;">` +
`      <img id="swal-backdrop-preview" src="${movie?.backdrop_path || ''}" style="width:100%;max-width:200px;border-radius:6px;object-fit:cover;margin-top:4px;${movie?.backdrop_path ? '' : 'display:none;'}" onerror="this.style.display='none'">` +
`    </div>` +
`  </div>` +
`</div>`;

window.updateTmdbVerifyLink = function(val) {
const l = document.getElementById('tmdbVerifyLink');
const cat = document.getElementById('swal-categoria');
const t = cat ? cat.value : tipoApi;
if (l) {
const apiTipo = (t === 'series' || t === 'tv') ? 'tv' : 'movie';
const num = parseInt(val);
l.href = 'https://www.themoviedb.org/' + apiTipo + '/' + (num > 0 ? num : 1);
l.style.display = num > 0 ? 'inline-flex' : 'none';
}
};

const { value: formValues } = await Swal.fire({
title: 'Editar Metadatos y Redundancia',
background: '#1e293b',
color: '#fff',
html: modalHtml,
focusConfirm: false,
showCancelButton: true,
confirmButtonColor: '#10b981',
cancelButtonColor: '#ef4444',
preConfirm: () => {
const catSelect = document.getElementById('swal-categoria');
const newTipo = catSelect ? catSelect.value : tipo;
return {
meta_id: document.getElementById('swal-meta-id') ? document.getElementById('swal-meta-id').value : currentMetaId,
tipo: newTipo,
temporada: document.getElementById('swal-temp') ? document.getElementById('swal-temp').value : 0,
episodio: document.getElementById('swal-ep') ? document.getElementById('swal-ep').value : 0,
tmdb_id: document.getElementById('swal-input1').value,
new_filename: document.getElementById('swal-filename') ? document.getElementById('swal-filename').value : '',
archivo_path: document.getElementById('swal-input2') ? document.getElementById('swal-input2').value : '',
server2: document.getElementById('swal-input3') ? document.getElementById('swal-input3').value : '',
server3: document.getElementById('swal-input4') ? document.getElementById('swal-input4').value : '',
server4: document.getElementById('swal-input5') ? document.getElementById('swal-input5').value : '',
server5: document.getElementById('swal-input6') ? document.getElementById('swal-input6').value : '',
poster_path: document.getElementById('swal-poster') ? document.getElementById('swal-poster').value : '',
backdrop_path: document.getElementById('swal-backdrop') ? document.getElementById('swal-backdrop').value : ''
				}
},
didOpen: () => {
if (tipo === 'series' || tipo === 'tv') {
const tempInput = document.getElementById('swal-temp');
const epInput = document.getElementById('swal-ep');

const hiddenMeta = document.createElement('input');
hiddenMeta.type = 'hidden';
hiddenMeta.id = 'swal-meta-id';
hiddenMeta.value = currentMetaId || '';
document.querySelector('.swal2-html-container').appendChild(hiddenMeta);

const updateEpisodeData = () => {
const t = parseInt(tempInput.value) || 1;
const e = parseInt(epInput.value) || 1;
const eps = movie && movie.episodes ? movie.episodes : [];
const targetEp = eps.find(x => parseInt(x.temporada) === t && parseInt(x.episodio) === e);

document.getElementById('swal-input2').value = targetEp ? (targetEp.archivo_path || '') : '';
document.getElementById('swal-input3').value = targetEp ? (targetEp.server2 || '') : '';
document.getElementById('swal-input4').value = targetEp ? (targetEp.server3 || '') : '';
document.getElementById('swal-input5').value = targetEp ? (targetEp.server4 || '') : '';
document.getElementById('swal-input6').value = targetEp ? (targetEp.server5 || '') : '';
hiddenMeta.value = targetEp ? targetEp.meta_id : '';

const fnInput = document.getElementById('swal-filename');
const fnDir = document.getElementById('swal-filename-dir');
if (fnInput && fnDir) {
const epPath = targetEp ? (targetEp.archivo_path || '') : '';
if (isLocalFilePath(epPath)) {
const idx = epPath.lastIndexOf('/');
fnInput.value = idx >= 0 ? epPath.substring(idx + 1) : epPath;
fnInput.disabled = false;
fnInput.placeholder = 'con extensión';
fnDir.textContent = '📁 ' + (idx > 0 ? epPath.substring(0, idx) : '') + '/';
} else {
fnInput.value = '';
fnInput.disabled = true;
fnInput.placeholder = 'No aplica (enlace externo)';
fnDir.textContent = 'No aplica';
}
}

[2,3,4,5,6].forEach(i => {
const sp = document.getElementById('status-swal-input' + i);
if (sp) sp.innerHTML = '';
const inp = document.getElementById('swal-input' + i);
if (inp) {
inp.style.border = '1px solid #334155';
inp.style.backgroundColor = 'rgba(0,0,0,0.5)';
}
});
};

tempInput.addEventListener('input', updateEpisodeData);
epInput.addEventListener('input', updateEpisodeData);
}

setTimeout(() => {
const inputs = [
document.getElementById('swal-input2'),
document.getElementById('swal-input3'),
document.getElementById('swal-input4'),
document.getElementById('swal-input5'),
document.getElementById('swal-input6')
];

let tempUrls = new Set(existingUrls);
[initialPath, initialS2, initialS3, initialS4, initialS5].forEach(u => { if(u) tempUrls.delete(getUrlBase(u.trim())); });
const checkDuplicates = () => {
const localValues = inputs.map(i => i ? getUrlBase(i.value.trim()) : '');
console.log("🧠 --- DETECCIÓN DE DUPLICADOS ---");
console.log("Set de URLs permitidas (tempUrls):", Array.from(tempUrls));

inputs.forEach(input => {
if (!input) return;
const val = input.value.trim();
const baseVal = getUrlBase(val);
const isDuplicateLocally = baseVal !== '' && localValues.indexOf(baseVal) !== localValues.lastIndexOf(baseVal);
const isDuplicateInDB = baseVal !== '' && tempUrls.has(baseVal);

console.log(`Input ${input.id} ("${val.substring(0, 30)}..."):`, {
baseVal,
isDuplicateLocally,
isDuplicateInDB,
hasInTempUrls: tempUrls.has(baseVal)
});

if (isDuplicateLocally || isDuplicateInDB) {
input.classList.add('duplicate-error');
input.title = isDuplicateInDB ? "⚠️ Este link ya existe en otra película" : "⚠️ Link repetido en este formulario";
} else {
input.classList.remove('duplicate-error');
input.title = "";
}
});
};

const rebindSeriesCheck = () => {
tempUrls = new Set(existingUrls);
inputs.forEach(i => {
if (i && i.value) tempUrls.delete(getUrlBase(i.value.trim()));
});
checkDuplicates();
};

if (tipo === 'series' || tipo === 'tv') {
const tempInput = document.getElementById('swal-temp');
const epInput = document.getElementById('swal-ep');
if(tempInput) tempInput.addEventListener('input', () => setTimeout(rebindSeriesCheck, 50));
if(epInput) epInput.addEventListener('input', () => setTimeout(rebindSeriesCheck, 50));
}

inputs.forEach(input => {
if(input) {
input.addEventListener('input', () => {
autoPrefixUrl(input);
checkDuplicates();
});
}
});

['swal-poster', 'swal-backdrop'].forEach(id => {
const inp = document.getElementById(id);
if (inp) {
inp.addEventListener('input', () => {
const preview = document.getElementById(id + '-preview');
if (preview) {
preview.src = inp.value.trim() || '';
preview.style.display = inp.value.trim() ? '' : 'none';
}
});
}
});

checkDuplicates();
}, 100);
}
});

if (!formValues) return;

if (!formValues.tmdb_id || (!formValues.archivo_path && !formValues.server2 && !formValues.server3 && !formValues.server4 && !formValues.server5)) {
Swal.fire({
icon: 'error',
title: 'Incompleto',
text: 'Debes proporcionar el ID de TMDB y al menos un servidor.',
background: '#1e293b',
color: '#fff'
});
return;
}

const body = new FormData();
body.append('id', id);
body.append('meta_id', formValues.meta_id || '');
body.append('tipo', formValues.tipo);
body.append('temporada', formValues.temporada);
body.append('episodio', formValues.episodio);
body.append('tmdb_id', formValues.tmdb_id);
body.append('new_filename', formValues.new_filename);
body.append('archivo_path', formValues.archivo_path);
body.append('server2', formValues.server2);
body.append('server3', formValues.server3);
body.append('server4', formValues.server4);
body.append('server5', formValues.server5);
body.append('poster_path', formValues.poster_path);
body.append('backdrop_path', formValues.backdrop_path);

try {
const res = await fetch('backend/update_movie.php', { method: 'POST', body });
const data = await res.json();
if (data.status === 'success') {
const renamed = data.data.renamed ? ' 📁 Archivo renombrado en disco.' : '';
Swal.fire({
icon: 'success',
title: 'Actualizado',
text: `Ahora es: ${data.data.titulo}${renamed}`,
background: '#1e293b',
color: '#fff'
});
loadStats();
} else {
alert("Error: " + data.message);
}
} catch (e) {
console.error("Error en updateMovie:", e);
Swal.fire({
icon: 'warning',
title: 'Fallo de Telemetría ⚠️',
text: 'El servidor tardó demasiado en responder o devolvió un formato inválido. Revisa tus links e intenta de nuevo.',
background: '#1e293b',
color: '#fff'
});
}
}

async function deleteMovie(id) {
if (!confirm("🚨 ADVERTENCIA PELIGROSA 🚨\n\n¿Estás completamente seguro de eliminar esta película?\n\nEsto borrará el registro de la base de datos Y TAMBIÉN ELIMINARÁ EL ARCHIVO FÍSICO (video y subtítulos) de tu disco duro de forma irrecuperable.")) return;

const body = new FormData();
body.append('id', id);

try {
const res = await fetch('backend/delete_movie.php', { method: 'POST', body });
const data = await res.json();
if (data.status === 'success') {
loadStats();
} else {
alert("Error: " + data.message);
}
} catch (e) {
alert("Error de conexión al intentar borrar.");
}
}
async function manualIndex() {
const archivo = document.getElementById('manualFile').value;
const tmdbId = document.getElementById('manualId').value;
const tipo = document.getElementById('manualTipo').value;
const isManual = document.getElementById('manualCheckbox').checked;
const s2 = document.getElementById('manualS2').value;
const s3 = document.getElementById('manualS3').value;
const s4 = document.getElementById('manualS4').value;
const s5 = document.getElementById('manualS5').value;
const subs = document.getElementById('manualSubs').value;
const temp = document.getElementById('manualTemp') ? document.getElementById('manualTemp').value : 1;
const ep = document.getElementById('manualEpisodio') ? document.getElementById('manualEpisodio').value : 1;
const posterManual = document.getElementById('manualPoster') ? document.getElementById('manualPoster').value : '';
const backdropManual = document.getElementById('manualBackdrop') ? document.getElementById('manualBackdrop').value : '';
const result = document.getElementById('manualResult');

console.log("🎯 Sonda Telemetría: Iniciando inyección...", { isManual, tipo });

if (!tmdbId || (!archivo && !s2 && !s3 && !s4 && !s5)) {
console.warn("⚠️ Telemetría: Abortado por campos incompletos.");
const idLabel = isManual ? 'Título' : 'ID de TMDB';
result.innerHTML = `<p style="color:#f87171;">⚠️ Debes proporcionar el ${idLabel} y al menos un servidor.</p>`;
return;
}
result.innerHTML = '<p style="color:#94a3b8;">⏳ Indexando...</p>';

const body = new FormData();
body.append('archivo', archivo);
body.append('tmdb_id', tmdbId);
body.append('tipo', tipo);
body.append('server2', s2);
body.append('server3', s3);
body.append('server4', s4);
body.append('server5', s5);
body.append('subtitles', subs);
body.append('temporada', temp);
body.append('episodio', ep);
if (isManual) {
body.append('manual_insert', '1');
body.append('titulo_manual', tmdbId);
body.append('poster_manual', posterManual);
body.append('backdrop_manual', backdropManual);
}

try {
const res = await fetch('backend/manual_index.php', { method: 'POST', body });
const data = await res.json();
console.log("📡 Telemetría Backend:", data);

if (data.status === 'success') {
const renameMsg = data.renamed
? `<p style="margin:0;color:#fbbf24;font-size:0.8rem;">📁 Archivo renombrado → <strong>${data.nuevo_nombre}</strong></p>`
: `<p style="margin:0;color:#64748b;font-size:0.8rem;">📁 ${data.nuevo_nombre}</p>`;

result.innerHTML = `
<div style="display:flex;align-items:center;gap:1rem;margin-top:0.5rem;">
<img src="${data.poster}" style="width:60px;border-radius:8px; object-fit:cover;">
<div>
<p style="margin:0;font-weight:700;color:#10b981;">✅ Indexado: ${data.titulo}</p>
<p style="margin:0;color:#94a3b8;font-size:0.85rem;">Rating: ★ ${data.rating} | DB ID: ${data.id}</p>
${renameMsg}
</div>
</div>`;
console.log("✅ Telemetría: Inyección completada exitosamente.");
loadStats();
} else {
console.error("❌ Telemetría Error:", data.message);
result.innerHTML = '<p style="color:#f87171;">❌ Error: ' + data.message + '</p>';
}
} catch (e) {
console.error("❌ Telemetría: Error crítico de red.", e);
result.innerHTML = '<p style="color:#f87171;">❌ Error de conexión.</p>';
}
}

function clearManualFields() {
document.getElementById('manualFile').value = '';
document.getElementById('manualId').value = '';
document.getElementById('manualS2').value = '';
document.getElementById('manualS3').value = '';
document.getElementById('manualS4').value = '';
document.getElementById('manualS5').value = '';
document.getElementById('manualSubs').value = '';
if (document.getElementById('manualTemp')) document.getElementById('manualTemp').value = '1';
if (document.getElementById('manualEpisodio')) document.getElementById('manualEpisodio').value = '1';
if (document.getElementById('manualPoster')) document.getElementById('manualPoster').value = '';
if (document.getElementById('manualBackdrop')) document.getElementById('manualBackdrop').value = '';
const checkbox = document.getElementById('manualCheckbox');
if (checkbox && checkbox.checked) {
checkbox.checked = false;
document.getElementById('manualMetaFields').style.display = 'none';
document.getElementById('manualIdLabel').textContent = 'TMDB ID / Nombre';
document.getElementById('manualId').placeholder = "Ej: 754 o 'The Matrix'";
}

const manualInputs = [
document.getElementById('manualFile'),
document.getElementById('manualS2'),
document.getElementById('manualS3'),
document.getElementById('manualS4'),
document.getElementById('manualS5')
];
manualInputs.forEach(input => {
if (input) {
input.style.border = '';
input.style.background = '';
const helper = document.getElementById(input.id + '_dup_helper');
if (helper) helper.remove();
}
});

const result = document.getElementById('manualResult');
if (result) result.innerHTML = '';
console.log("🧹 Sonda Telemetría: Campos manuales limpiados con éxito.");
}

function bindManualDuplicateChecker() {
const manualInputs = [
document.getElementById('manualFile'),
document.getElementById('manualS2'),
document.getElementById('manualS3'),
document.getElementById('manualS4'),
document.getElementById('manualS5')
];

const checkManualDuplicates = () => {
const localValues = manualInputs.map(i => i ? getUrlBase(i.value.trim()) : '');
manualInputs.forEach(input => {
if (!input) return;
const val = input.value.trim();
const baseVal = getUrlBase(val);
const isDuplicateLocally = baseVal !== '' && localValues.indexOf(baseVal) !== localValues.lastIndexOf(baseVal);
const isDuplicateInDB = baseVal !== '' && existingUrls.has(baseVal);

if (isDuplicateLocally || isDuplicateInDB) {
input.classList.add('duplicate-error');
input.title = isDuplicateInDB ? "⚠️ Este link ya existe en la biblioteca" : "⚠️ Link repetido en este formulario";
} else {
input.classList.remove('duplicate-error');
input.title = "";
}
});
};

manualInputs.forEach(input => {
if (input) {
const newInput = input.cloneNode(true);
input.parentNode.replaceChild(newInput, input);
newInput.addEventListener('input', () => {
autoPrefixUrl(newInput);
checkManualDuplicates();
});
}
});
}

document.addEventListener('DOMContentLoaded', () => {
document.getElementById('manualTipo').addEventListener('change', (e) => {
document.getElementById('seriesFields').style.display = (e.target.value === 'series') ? 'grid' : 'none';
});

document.getElementById('manualCheckbox').addEventListener('change', (e) => {
const isManual = e.target.checked;
document.getElementById('manualMetaFields').style.display = isManual ? 'grid' : 'none';

const label = document.getElementById('manualIdLabel');
const input = document.getElementById('manualId');
const result = document.getElementById('manualResult');
if (result) result.innerHTML = '';

if (isManual) {
label.textContent = "Título (Manual)";
input.placeholder = "Ej: Canal 10 México o Forrest Gump";
} else {
label.textContent = "TMDB ID / Nombre";
input.placeholder = "Ej: 754 o 'The Matrix'";
}
});
});

window.addEventListener('load', () => {
const params = new URLSearchParams(window.location.search);
if (params.has('inject_url')) {
document.getElementById('manualFile').value = params.get('inject_url');
if (params.has('tmdb_id')) document.getElementById('manualId').value = params.get('tmdb_id');
if (params.has('type')) document.getElementById('manualTipo').value = params.get('type');

const result = document.getElementById('manualResult');
result.innerHTML = '<p style="color:#fbbf24; animation: pulse 2s infinite;">✨ Datos recibidos desde Galix Sniffer. Haz clic en "Inyectar" para confirmar.</p>';
}
});

document.getElementById('manualFile').addEventListener('input', async (e) => {
const val = e.target.value.trim();
if (val.length < 5) return;

const manualId = document.getElementById('manualId');
const result = document.getElementById('manualResult');

if (manualId.value) return;

const cleanUrl = val.split('?')[0];
let segments = cleanUrl.split('/');
let cleanTitle = "";

for (let i = segments.length - 1; i >= 0; i--) {
let s = segments[i].replace(/\.(mp4|mkv|m3u8|avi|mov)$/i, '').replace(/(_|-|\.)/g, ' ').trim();
if (s.length > 2 && !/^(master|playlist|video|index|hls|ts|m3u8|mp4|engine|hls2|urlset)$/i.test(s)) {
cleanTitle = s;
break;
}
}

if (cleanTitle.length < 2) return;

result.innerHTML = `<p style="color:#94a3b8; font-size:0.8rem;">🔍 Buscando ID para: "<strong>${cleanTitle}</strong>"...</p>`;

try {
const TMDB_KEY = 'aa99c189865340e6421390ff192384b6';
const type = document.getElementById('manualTipo').value;
const res = await fetch(`https://api.themoviedb.org/3/search/${type}?api_key=${TMDB_KEY}&query=${encodeURIComponent(cleanTitle)}&language=es-MX`);
const data = await res.json();

if (data.results && data.results.length > 0) {
const match = data.results[0];
manualId.value = match.id;
result.innerHTML = `
<div style="display:flex; align-items:center; gap:10px; background:rgba(16,185,129,0.1); padding:10px; border-radius:10px; border:1px solid #10b981; margin-top:10px;">
<img src="https://image.tmdb.org/t/p/w92${match.poster_path}" style="width:40px; border-radius:5px;">
<div>
<p style="margin:0; font-weight:700; color:#10b981; font-size:0.85rem;">✨ ¡Coincidencia encontrada!</p>
<p style="margin:0; color:white; font-size:0.8rem;">${match.title || match.name} (${(match.release_date || match.first_air_date || '').split('-')[0]})</p>
</div>
</div>`;
} else {
result.innerHTML = `<p style="color:#fbbf24; font-size:0.8rem;">⚠️ No se halló ID automático para "${cleanTitle}". Búscalo manualmente.</p>`;
}
} catch (err) {
console.error("Error en búsqueda automática:", err);
}
});

document.getElementById('manualId').addEventListener('blur', async (e) => {
const val = e.target.value.trim();
if (!val || !isNaN(val)) return;

const result = document.getElementById('manualResult');
const type = document.getElementById('manualTipo').value;
result.innerHTML = `<p style="color:#94a3b8; font-size:0.8rem;">🔎 Buscando ID para el nombre: "<strong>${val}</strong>"...</p>`;

try {
const TMDB_KEY = 'aa99c189865340e6421390ff192384b6';
const res = await fetch(`https://api.themoviedb.org/3/search/${type}?api_key=${TMDB_KEY}&query=${encodeURIComponent(val)}&language=es-MX`);
const data = await res.json();

if (data.results && data.results.length > 0) {
const match = data.results[0];
e.target.value = match.id;
result.innerHTML = `
<div style="display:flex; align-items:center; gap:10px; background:rgba(16,185,129,0.1); padding:10px; border-radius:10px; border:1px solid #10b981; margin-top:10px;">
<img src="https://image.tmdb.org/t/p/w92${match.poster_path}" style="width:40px; border-radius:5px;">
<div>
<p style="margin:0; font-weight:700; color:#10b981; font-size:0.85rem;">✅ ID Obtenido: ${match.id}</p>
<p style="margin:0; color:white; font-size:0.8rem;">${match.title || match.name} (${(match.release_date || match.first_air_date || '').split('-')[0]})</p>
</div>
</div>`;
} else {
result.innerHTML = `<p style="color:#f87171; font-size:0.8rem;">❌ No se encontró nada con el nombre "${val}".</p>`;
}
} catch (err) {
console.error("Error buscando por nombre:", err);
}
});

loadStats();
setTimeout(() => window.checkRepairProgress && window.checkRepairProgress(), 100);
document.getElementById('loadingOverlay').classList.add('hidden');

document.getElementById('librarySearch').addEventListener('input', function() {
    window.applyFilter(this.value);
});

let mp4RepairTimer = null;

window.checkRepairProgress = async () => {
try {
const res = await fetch('backend/mp4_scanner.php?action=progress&t=' + Date.now());
const data = await res.json();
if (!data || data.status === 'idle' || data.status === undefined) return;
if (data.status === 'running' && (!data.updated || Date.now() - new Date(data.updated).getTime() > 300000)) {
fetch('backend/mp4_scanner.php?action=reset&t=' + Date.now()).catch(()=>{});
return;
}
const toast = document.getElementById('mp4RepairToast');
if (!toast) return;
toast.style.display = 'block';
window.updateMP4RepairToast(data);
if (data.status === 'running' && !mp4RepairTimer) {
mp4RepairTimer = setInterval(async () => {
try {
const pr = await fetch('backend/mp4_scanner.php?action=progress&t=' + Date.now());
const pd = await pr.json();
window.updateMP4RepairToast(pd);
} catch (e) {}
}, 2000);
}
} catch (e) {}
};

let scanMP4sCachedFiles = [];
let scanMP4sRunning = false;

window.scanMP4s = async (detectAudio) => {
if (scanMP4sRunning) return;
scanMP4sRunning = true;
try {
if (detectAudio === undefined) detectAudio = false;
if (detectAudio) {
        // Check/trigger background audio cache
        const sr = await fetch('backend/mp4_scanner.php?action=audio_scan_start&t=' + Date.now());
        const sd = await sr.json();

        if (sd.status === 'scanning') {
            Swal.fire({
                title: 'Escaneando audio...',
                html: '<div style="text-align:center;padding:20px;">' +
                    '<div id="audioScanPct" style="font-size:1.5rem;font-weight:700;margin-bottom:10px;color:#f59e0b;">0%</div>' +
                    '<div style="background:#334155;border-radius:8px;height:8px;overflow:hidden;">' +
                    '<div id="audioScanBar" style="height:100%;width:0%;background:linear-gradient(90deg,#f59e0b,#ef4444);transition:width .3s;"></div></div>' +
                    '<div id="audioScanLabel" style="margin-top:8px;color:#94a3b8;font-size:0.85rem;">Iniciando escaneo...</div>' +
                    '<p style="margin-top:12px;font-size:0.75rem;color:#64748b;">Primera vez: ~2-3 min. Próximas veces: instantáneo</p></div>',
                background: '#1e293b',
                color: '#fff',
                showConfirmButton: false,
                allowOutsideClick: false,
                allowEscapeKey: false
            });

            const poll = setInterval(async () => {
                try {
                    const pr = await fetch('backend/mp4_scanner.php?action=audio_scan_status&t=' + Date.now());
                    const pd = await pr.json();

                    if (pd.status === 'scanning' && pd.progress) {
                        const p = pd.progress;
                        const pct = p.pct || 0;
                        document.getElementById('audioScanPct').textContent = pct + '%';
                        document.getElementById('audioScanBar').style.width = pct + '%';
                        document.getElementById('audioScanLabel').textContent =
                            'Escaneados ' + (p.scanned || 0) + ' de ' + (p.total || 0) + ' archivos';
                    } else if (pd.status === 'cached') {
                        clearInterval(poll);
                        scanMP4sRunning = false;
                        Swal.close();
                        // Proceed with fast scan (cache is ready)
                        Swal.fire({
                            title: 'Cargando resultados...',
                            html: '<div style="text-align:center;padding:20px;"><div style="width:30px;height:30px;border:3px solid rgba(16,185,129,0.2);border-top-color:#10b981;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto;"></div></div>',
                            background: '#1e293b',
                            color: '#fff',
                            showConfirmButton: false,
                            allowOutsideClick: false,
                            allowEscapeKey: false
                        });
                        const scanRes = await fetch('backend/mp4_scanner.php?action=scan&audio=1&t=' + Date.now());
                        const scanData = await scanRes.json();
                        Swal.close();
                        processScanResults(scanData, true);
                        return;
                    } else if (pd.status === 'idle') {
                        // Worker might still be starting up, wait for next poll
                        return;
                    }
                } catch (e) {}
            }, 1500);
            scanMP4sRunning = false;
            return;
        }
        // sd.status === 'cached' → proceed to fast scan (cache will be used)
        Swal.fire({
            title: 'Cargando resultados...',
            html: '<div style="text-align:center;padding:20px;"><div style="width:30px;height:30px;border:3px solid rgba(16,185,129,0.2);border-top-color:#10b981;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto;"></div></div>',
            background: '#1e293b',
            color: '#fff',
            showConfirmButton: false,
            allowOutsideClick: false,
            allowEscapeKey: false
        });
}

const audioParam = detectAudio ? '&audio=1' : '';
const result = await fetch('backend/mp4_scanner.php?action=scan&t=' + Date.now() + audioParam);
const data = await result.json();

Swal.close();

processScanResults(data, detectAudio);
} catch (err) {
console.error('[MP4 Scanner] Error:', err);
Swal.fire('Error de Red', 'No se pudo conectar con el esc\u00E1ner.', 'error');
}
scanMP4sRunning = false;
};

window.processScanResults = async (data, detectAudio) => {
const files = data.files || [];
scanMP4sCachedFiles = files;
const needsRepair = files.filter(f => f.needs_repair);
const hasAudioScan = files.some(f => f.audio_codec && f.audio_codec !== '');
const audioOnlyCount = files.filter(f => f.needs_repair && f.repair_reason && f.repair_reason.startsWith('Audio:')).length;
const containerCount = needsRepair.length - audioOnlyCount;

if (needsRepair.length === 0) {
    const totalScanned = data._total || files.length;
    const msg = detectAudio
        ? '<p>No se encontraron archivos con formato incorrecto ni con audio no AAC.</p>'
        : '<p>No se encontraron archivos con formato incorrecto.</p>';
    const extra = !detectAudio
        ? '<p style="margin-top:12px;"><label style="cursor:pointer;display:inline-flex;align-items:center;gap:8px;color:#f59e0b;font-weight:600;"><input type="checkbox" id="mp4ScanAudioToggle" style="width:18px;height:18px;accent-color:#f59e0b;"> 🔊 Detectar también archivos con audio AC3 (lento)</label></p>'
        : '';
    Swal.fire({
        title: 'Todos los videos est\u00E1n bien',
        html: msg + '<p style="font-size:0.75rem;color:#64748b;margin-top:10px;">' + totalScanned + ' archivos escaneados</p>' + extra,
    icon: 'success',
    background: '#1e293b',
    color: '#fff',
    confirmButtonColor: '#10b981',
    didRender: () => {
        const cb = document.getElementById('mp4ScanAudioToggle');
        if (cb) cb.onchange = () => { scanMP4s(true); };
    }
});
return;
}

let html = '<div style="text-align:left; max-height:400px; overflow-y:auto;">';

if (detectAudio) {
    html += '<p style="color:#22c55e; margin-bottom:10px;">🔊 Detección de audio completada. <strong>' + needsRepair.length + '</strong> archivo(s) detectados:</p>';
} else {
    html += '<p style="color:#f59e0b; margin-bottom:10px;">Se detectaron <strong>' + needsRepair.length + '</strong> archivo(s) con problemas de contenedor:</p>';
    if (!detectAudio) {
        html += '<label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;padding:8px 12px;background:rgba(245,158,11,0.1);border-radius:8px;cursor:pointer;font-size:0.85rem;color:#f59e0b;font-weight:600;">' +
            '<input type="checkbox" id="mp4ScanAudioToggle" style="width:18px;height:18px;accent-color:#f59e0b;"> 🔊 Detectar también archivos con audio AC3 (lento)</label>';
    }
}

html += '<div style="display:flex; flex-direction:column; gap:8px;">' +
    '<div style="display:flex; justify-content:space-between; padding:0 4px 4px 4px; font-size:0.7rem; color:#94a3b8; text-transform:uppercase;">' +
    '<span>Archivo</span><span style="margin-right:4px;">Modo</span></div>';

needsRepair.forEach((f, i) => {
    const isAudio = f.repair_reason && f.repair_reason.startsWith('Audio:');
    const bgColor = isAudio ? 'rgba(245,158,11,0.08)' : 'rgba(239,68,68,0.05)';
    const borderColor = isAudio ? 'rgba(245,158,11,0.3)' : 'rgba(239,68,68,0.2)';
    const badgeColor = isAudio ? '#f59e0b' : '#ef4444';
    html += '<div style="display:flex; align-items:center; gap:12px; padding:10px 14px; background:' + bgColor + '; border:1px solid ' + borderColor + '; border-radius:8px;">' +
        '<input type="checkbox" id="mp4ck_' + i + '" value="' + f.name.replace(/"/g, '&quot;') + '" checked style="width:18px; height:18px; accent-color:' + badgeColor + ';">' +
        '<div style="flex:1; min-width:0;">' +
        '<div style="font-weight:600; font-size:0.85rem;">' + f.name.replace(/"/g, '&quot;') + '</div>' +
        '<div style="display:flex; gap:8px; align-items:center; margin-top:4px;">' +
        '<span style="font-size:0.7rem; background:' + badgeColor + '20; color:' + badgeColor + '; padding:2px 8px; border-radius:4px; font-weight:600;">' + (f.repair_reason || 'Reparar') + '</span>' +
        '<span style="font-size:0.75rem; color:#94a3b8;">' + f.size_human + '</span>' +
        '</div></div>' +
        '<select id="mp4mode_' + i + '" style="padding:4px 8px; border-radius:6px; border:1px solid #475569; background:#334155; color:#fff; font-size:0.75rem; cursor:pointer;">' +
'<option value="container"' + (isAudio ? '' : ' selected') + '>Rápido</option>' +
                        '<option value="audio"' + (isAudio ? ' selected' : '') + '>Completo</option>' +
                        '</select></div>';
});

html += '</div></div>';

const { value: selectedFiles } = await Swal.fire({
    title: 'Reparar MP4s',
html: html,
background: '#1e293b',
color: '#fff',
showCancelButton: true,
confirmButtonText: 'Convertir seleccionados',
cancelButtonText: 'Cancelar',
confirmButtonColor: '#ef4444',
cancelButtonColor: '#64748b',
showDenyButton: true,
denyButtonText: 'Deseleccionar todos',
denyButtonColor: '#334155',
preDeny: () => {
    const firstCb = document.getElementById('mp4ck_0');
    const currentlyAllSelected = firstCb ? firstCb.checked : false;
    const newState = !currentlyAllSelected;
    for (let i = 0; i < needsRepair.length; i++) {
        const cb = document.getElementById('mp4ck_' + i);
        if (cb) cb.checked = newState;
    }
    const denyBtn = Swal.getDenyButton();
    if (denyBtn) denyBtn.innerHTML = newState ? 'Deseleccionar todos' : 'Seleccionar todos';
    return false;
},
    preConfirm: () => {
const sel = [];
const modes = [];
for (let i = 0; i < needsRepair.length; i++) {
const cb = document.getElementById('mp4ck_' + i);
if (cb && cb.checked) {
sel.push(cb.value);
const modeSel = document.getElementById('mp4mode_' + i);
modes.push(modeSel ? modeSel.value : 'container');
}
}
if (sel.length === 0) {
Swal.showValidationMessage('Selecciona al menos un archivo');
return false;
}
return { files: sel, modes: modes };
},
didRender: () => {
    const cb = document.getElementById('mp4ScanAudioToggle');
    if (cb) cb.onchange = () => {
        const label = cb.parentElement;
        if (cb.checked) {
            label.innerHTML = '⏳ Escaneando audio (puede tardar varios minutos)...';
            cb.disabled = true;
            setTimeout(() => { scanMP4s(true); }, 100);
        }
    };
}
});

if (selectedFiles && selectedFiles.files && selectedFiles.files.length > 0) {
convertSelectedMP4s(selectedFiles.files, selectedFiles.modes);
}
};

window.convertSelectedMP4s = async (files, modes) => {
const toast = document.getElementById('mp4RepairToast');
if (!toast) return;
toast.style.display = 'block';

document.getElementById('mp4RepairTitle').innerHTML = 'Reparando videos \u2014 0%';
document.getElementById('mp4RepairProgress').innerHTML = 'Iniciando...';
document.getElementById('mp4RepairBarFill').style.width = '0%';
document.getElementById('mp4RepairDetails').style.display = 'none';
document.getElementById('mp4RepairViewReport').style.display = 'none';
document.getElementById('mp4RepairDismiss').style.display = 'none';

try {
const params = files.map((f, i) => 'files[]=' + encodeURIComponent(f) + '&modes[]=' + encodeURIComponent(modes && modes[i] ? modes[i] : 'container')).join('&');
const res = await fetch('backend/mp4_scanner.php?action=convert&' + params + '&t=' + Date.now());
const data = await res.json();

if (data.status !== 'started') {
Swal.fire('Error', data.message || 'Error al iniciar la conversi\u00F3n.', 'error');
toast.style.display = 'none';
return;
}

if (mp4RepairTimer) clearInterval(mp4RepairTimer);
mp4RepairTimer = setInterval(async () => {
try {
const pr = await fetch('backend/mp4_scanner.php?action=progress&t=' + Date.now());
const pd = await pr.json();
updateMP4RepairToast(pd);
} catch (e) {}
}, 2000);
} catch (err) {
Swal.fire('Error de Red', 'No se pudo conectar con el reparador.', 'error');
toast.style.display = 'none';
}
};

window.updateMP4RepairToast = (data) => {
const toast = document.getElementById('mp4RepairToast');
const title = document.getElementById('mp4RepairTitle');
const progress = document.getElementById('mp4RepairProgress');
const barFill = document.getElementById('mp4RepairBarFill');
const details = document.getElementById('mp4RepairDetails');
const etaEl = document.getElementById('mp4RepairEta');
const dismissBtn = document.getElementById('mp4RepairDismiss');
const viewBtn = document.getElementById('mp4RepairViewReport');
const resetBtn = document.getElementById('mp4RepairReset');
if (!toast) return;

if (data.status === 'running') {
            title.innerHTML = 'Reparando videos \u2014 <span style="color:#ef4444;">' + data.pct + '%</span>';
progress.innerHTML = data.done + ' / ' + data.total + ' archivos';
barFill.style.width = data.pct + '%';
barFill.style.background = 'linear-gradient(90deg,#ef4444,#f87171)';
details.style.display = 'block';
details.innerHTML = data.current || 'Procesando...';
if (dismissBtn) dismissBtn.style.display = 'none';
if (viewBtn) viewBtn.style.display = 'none';
if (resetBtn) resetBtn.style.display = 'inline-flex';
if (etaEl) {
    if (data.done > 0 && data.total > 0 && data.start_time) {
        const elapsed = (Date.now() / 1000) - data.start_time;
        const rate = elapsed / data.done;
        const remaining = Math.round(rate * (data.total - data.done));
        if (remaining > 0) {
            const mins = Math.floor(remaining / 60);
            const secs = remaining % 60;
            etaEl.textContent = '\u23F1 Tiempo restante: ' + mins + ':' + String(secs).padStart(2, '0');
            etaEl.style.display = 'block';
        } else {
            etaEl.style.display = 'none';
        }
    } else {
        etaEl.style.display = 'none';
    }
}
} else if (data.status === 'completed') {
const ok = data.results ? data.results.filter(r => r.status === 'ok').length : 0;
const err = data.results ? data.results.filter(r => r.status === 'error').length : 0;
title.innerHTML = 'Reparaci\u00F3n completada';
progress.innerHTML = ok + ' convertidos' + (err > 0 ? ' \u00B7 ' + err + ' fallaron' : '');
barFill.style.width = '100%';
barFill.style.background = err > 0 ? 'linear-gradient(90deg,#f59e0b,#ef4444)' : 'linear-gradient(90deg,#10b981,#38bdf8)';
details.style.display = 'none';
if (etaEl) etaEl.style.display = 'none';
if (dismissBtn) dismissBtn.style.display = 'inline-flex';
if (viewBtn) {
viewBtn.style.display = 'inline-flex';
viewBtn.onclick = () => showMP4RepairReport(data);
}
if (mp4RepairTimer) { clearInterval(mp4RepairTimer); mp4RepairTimer = null; }
if (resetBtn) resetBtn.style.display = 'none';
} else if (data.status === 'failed') {
title.innerHTML = 'Reparaci\u00F3n fall\u00F3';
progress.innerHTML = data.error || 'Error desconocido';
barFill.style.width = '100%';
barFill.style.background = '#ef4444';
if (etaEl) etaEl.style.display = 'none';
if (dismissBtn) dismissBtn.style.display = 'inline-flex';
if (mp4RepairTimer) { clearInterval(mp4RepairTimer); mp4RepairTimer = null; }
if (resetBtn) resetBtn.style.display = 'none';
setTimeout(() => { if (toast.style.display !== 'none') toast.style.display = 'none'; }, 10000);
} else if (data.status === 'idle') {
title.innerHTML = 'Sin actividad';
progress.innerHTML = 'No hay reparaci\u00F3n en curso.';
barFill.style.width = '0%';
barFill.style.background = '#f59e0b';
if (etaEl) etaEl.style.display = 'none';
if (mp4RepairTimer) { clearInterval(mp4RepairTimer); mp4RepairTimer = null; }
if (resetBtn) resetBtn.style.display = 'none';
setTimeout(() => { if (toast.style.display !== 'none') toast.style.display = 'none'; }, 5000);
}
};

window.resetMP4Repair = async () => {
const { isConfirmed } = await Swal.fire({
title: '\u00BFCancelar reparaci\u00F3n?',
text: 'El progreso actual se perder\u00E1 y podr\u00E1s empezar de nuevo.',
icon: 'warning',
showCancelButton: true,
confirmButtonText: 'S\u00ED, cancelar',
cancelButtonText: 'No',
confirmButtonColor: '#ef4444',
cancelButtonColor: '#64748b',
background: '#1e293b',
color: '#fff'
});
if (!isConfirmed) return;

await fetch('backend/mp4_scanner.php?action=reset&t=' + Date.now());
const toast = document.getElementById('mp4RepairToast');
if (toast) toast.style.display = 'none';
if (mp4RepairTimer) { clearInterval(mp4RepairTimer); mp4RepairTimer = null; }
};

window.dismissMP4RepairToast = () => {
const t = document.getElementById('mp4RepairToast');
if (t) t.style.display = 'none';
if (mp4RepairTimer) { clearInterval(mp4RepairTimer); mp4RepairTimer = null; }
};

window.showMP4RepairReport = (data) => {
if (!data) {
try {
fetch('backend/mp4_scanner.php?action=progress&t=' + Date.now())
.then(r => r.json())
.then(d => showMP4RepairReport(d));
} catch(e) {}
return;
}
const results = data.results || [];
let html = '<div style="text-align:left; max-height:300px; overflow-y:auto;">';
results.forEach(r => {
var icon = 'OK';
var color = '#10b981';
var suffix = '';
var errMsg = '';
                if (r.status === 'ok') {
                    if (r.mode === 'audio_encoded') {
                        suffix = ' audio AAC';
                    } else {
                        suffix = ' contenedor';
                    }
} else {
icon = 'ERR';
color = '#ef4444';
if (r.error) {
errMsg = r.error.substring(0, 500);
}
}
html += '<div style="display:flex; align-items:center; gap:10px; padding:6px 0; border-bottom:1px solid rgba(255,255,255,0.05);">' +
'<span style="color:' + color + '; font-weight:700; font-size:0.75rem;">' + icon + '</span>' +
'<span style="font-size:0.85rem;">' + r.file + '</span>';
if (suffix) {
html += '<span style="margin-left:auto; font-size:0.7rem; color:#94a3b8;">' + suffix + '</span>';
}
html += '</div>';
if (errMsg) {
html += '<div style="font-size:0.7rem; color:#f87171; padding:0 0 6px 24px; word-break:break-all; margin-top:-2px;">' + escHtml(errMsg) + '</div>';
}
});
html += '</div>';
Swal.fire({
title: 'Reporte de Reparaci\u00F3n',
icon: 'info',
background: '#1e293b',
color: '#fff',
html: html,
confirmButtonText: 'Excelente',
confirmButtonColor: '#10b981'
});
};



window.toggleRoku = async (id, currentVal) => {
    const newVal = currentVal ? 0 : 1;
    const form = new FormData();
    form.append('id', id);
    form.append('visible_roku', newVal);
    try {
        const res = await fetch('backend/toggle_roku.php', { method: 'POST', body: form });
        const data = await res.json();
        if (data.status === 'success') {
            loadStats();
        } else {
            console.error('Error toggle Roku:', data.message);
        }
    } catch (e) {
        console.error('Error de red:', e);
    }
};

window.cleanGarbageFiles = async () => {
const { value: confirm } = await Swal.fire({
title: 'Limpiar archivos basura',
html: '<p>Se eliminar\u00E1n todos los archivos <code>._*.mp4</code> generados por macOS (Apple Double).</p><p style="color:#f87171; font-size:0.85rem;">Esta acci\u00F3n no se puede deshacer.</p>',
icon: 'warning',
background: '#1e293b',
color: '#fff',
showCancelButton: true,
confirmButtonText: 'S\u00ED, limpiar',
cancelButtonText: 'Cancelar',
confirmButtonColor: '#ef4444',
cancelButtonColor: '#64748b'
});
if (!confirm) return;

try {
const res = await fetch('backend/mp4_scanner.php?action=clean&t=' + Date.now());
const data = await res.json();

if (data.status === 'ok') {
const count = data.deleted_count || 0;
if (count > 0) {
const list = data.deleted_files.map(f => '<div style="font-size:0.8rem; padding:3px 0;">' + f + '</div>').join('');
Swal.fire({
title: 'Limpieza completada',
html: '<p style="color:#10b981; font-weight:700;">' + count + ' archivo(s) eliminado(s).</p>' + list,
icon: 'success',
background: '#1e293b',
color: '#fff',
confirmButtonColor: '#10b981'
});
} else {
Swal.fire({
title: 'Sin basura',
text: 'No se encontraron archivos ._*.mp4 para eliminar.',
icon: 'info',
background: '#1e293b',
color: '#fff',
confirmButtonColor: '#10b981'
});
}
} else {
Swal.fire('Error', 'No se pudo completar la limpieza.', 'error');
}
} catch (err) {
                Swal.fire('Error de Red', 'No se pudo conectar con el servidor.', 'error');
            }
        };

        window.backfillEpisodes = async () => {
            const { value: confirm } = await Swal.fire({
                title: '📺 Backfill Episodios',
                html: '<p>Actualizar\u00E1 t\u00EDtulos reales, im\u00E1genes y sinopsis de episodios existentes desde TMDB.</p><p style="color:#fbbf24; font-size:0.85rem;">1 llamada API por temporada. Puede tomar varios minutos.</p>',
                icon: 'question',
                background: '#1e293b',
                color: '#fff',
                showCancelButton: true,
                confirmButtonText: 'S\u00ED, actualizar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#fbbf24',
                cancelButtonColor: '#64748b'
            });
            if (!confirm) return;

            try {
                Swal.fire({ title: 'Procesando...', html: 'Consultando TMDB por temporada...', allowOutsideClick: false, didOpen: () => Swal.showLoading(), background: '#1e293b', color: '#fff' });
                const res = await fetch('backend/backfill_episodes.php?t=' + Date.now());
                const data = await res.json();

                if (data.status === 'success') {
                    let html = `<p style="color:#10b981;">✅ Actualizados: ${data.updated} episodios</p>`;
                    html += `<p style="color:#94a3b8;font-size:0.85rem;">Llamadas API: ${data.api_calls} | Grupos: ${data.groups} | Total procesados: ${data.total}</p>`;
                    if (data.errors && data.errors.length > 0) {
                        html += `<div style="max-height:150px;overflow-y:auto;margin-top:8px;font-size:0.8rem;color:#f87171;">`;
                        data.errors.forEach(e => { html += `<div>⚠️ ${e}</div>`; });
                        html += `</div>`;
                    }
                    Swal.fire({ icon: 'success', title: 'Backfill completado', html, background: '#1e293b', color: '#fff', confirmButtonColor: '#10b981' });
                } else {
                    Swal.fire('Error', data.message || 'Error al ejecutar backfill', 'error');
                }
            } catch (err) {
                Swal.fire('Error de Red', 'No se pudo conectar con el servidor.', 'error');
            }
        };
