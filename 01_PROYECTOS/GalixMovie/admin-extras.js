        window.auditSeries = async (id) => {
            const resData = await fetch('backend/get_content.php?t=' + Date.now()).then(r => r.json());
            const movie = resData.movies ? resData.movies.find(m => m.id == id) : null;
            if (!movie || !movie.episodes || movie.episodes.length === 0) {
                Swal.fire('Sin Capítulos', 'Esta serie no tiene capítulos para analizar.', 'info');
                return;
            }

            const eps = movie.episodes.sort((a, b) => {
                if (parseInt(a.temporada) !== parseInt(b.temporada)) return parseInt(a.temporada) - parseInt(b.temporada);
                return parseInt(a.episodio) - parseInt(b.episodio);
            });

            let html = '<div style="max-height:400px; overflow-y:auto; text-align:left; background:rgba(0,0,0,0.3); padding:10px; border-radius:8px;">';
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
                confirmButtonText: 'Detener Auditoría',
                allowOutsideClick: false
            });

            for (let ep of eps) {
                if (!Swal.isVisible()) break;

                const icon = document.getElementById(`ep-icon-${ep.meta_id}`);
                if (!icon) continue;

                icon.innerHTML = '<b class="i-refresh spin"></b> Revisando...';
                icon.style.color = '#fbbf24';

                const urls = [ep.archivo_path, ep.server2, ep.server3, ep.server4, ep.server5].filter(u => u && u.trim() !== '');
                
                if (urls.length === 0) {
                    icon.innerHTML = '❌ Sin enlaces';
                    icon.style.color = '#ef4444';
                    continue;
                }

                let anyOnline = false;
                for (let url of urls) {
                    if (!url.startsWith('http')) {
                        anyOnline = true;
                        break;
                    }
                    try {
                        const res = await fetch(`backend/check_status.php?url=${encodeURIComponent(url)}`);
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
            
            if (Swal.isVisible()) {
                Swal.getConfirmButton().textContent = 'Cerrar';
                Swal.getConfirmButton().style.backgroundColor = '#64748b';
            }
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

        async function runAuditor() {
            const rows = document.querySelectorAll('#movieTableBody tr');
            const consoleBox = document.getElementById('consoleOutput');
            consoleBox.innerHTML += '<br>> 🔍 Iniciando Auditoría FENIX de Alta Precisión...<br>';
            
            Swal.fire({
                title: 'Auditando Biblioteca...',
                html: 'Analizando integridad de mirrors.<br><strong id="auditProgress">0</strong> de ' + rows.length,
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            let count = 0;
            let offlineCount = 0;
            let onlineCount = 0;
            let deadServersList = [];
            for (const row of rows) {
                const movieId = row.getAttribute('data-movie-id');
                const title = row.querySelector('td:nth-child(2)').innerText.replace('OFFLINE', '').trim();
                const mirrorDivs = row.querySelectorAll('td[id^="s"]');
                
                let anyOnline = false;
                let mirrorsChecked = 0;
                let mirrorsFoundDown = 0;
                
                console.log(`🎬 Auditing: ${title}`);

                for (const td of mirrorDivs) {
                    const url = td.getAttribute('data-url');
                    const isDirectStream = url => url.includes('.m3u8') || url.includes('.mp4') || url.includes('proxy.php') || url.includes('.txt') || url.startsWith('extract:');
                    
                    if (!url || url === 'null' || url === '' || url === 'undefined') continue;
                    
                    mirrorsChecked++;
                    const colName = td.id.split('-')[0]; // s1, s2, s3...
                    // 🛡️ MEJORA 1: extract: y sniper: son semillas de extracción en caliente — asumir online
                    const isSeed = url.startsWith('extract:') || url.startsWith('sniper:');
                    const isLocal = !url.startsWith('http') && !isSeed && !url.startsWith('backend/');
                    
                    if (isLocal || isSeed) {
                        console.log(`  ✅ Mirror Especial (${isSeed ? 'Semilla' : 'Local'}): ${url}`);
                        anyOnline = true;
                        continue; // No hacemos break para poder revisar los demás servidores de la fila
                    }

                    try {
                        const probeRes = await fetch(`backend/check_status.php?url=${encodeURIComponent(url)}`);
                        const probeData = await probeRes.json();
                        
                        if (probeData.status === 'online') {
                            console.log(`  ✅ Mirror Online: ${url}`);
                            anyOnline = true;
                            // quitamos el break para revisar TODOS los servidores y encontrar los caídos
                        } else {
                            console.log(`  ❌ Mirror Down: ${url} (Code: ${probeData.http_code})`);
                            mirrorsFoundDown++;
                            deadServersList.push({ id: movieId, column: colName, url: url, title: title });
                        }
                    } catch (e) {
                        console.error(`  ⚠️ Error probando: ${url}`, e);
                    }
                }

                // REGLA DE ORO: Solo marcar OFFLINE si tenemos mirrors y TODOS fallaron.
                // Si no hay mirrors definidos, la dejamos online por defecto (para no ocultar vacías accidentalmente)
                const finalStatus = (mirrorsChecked > 0 && !anyOnline) ? '0' : '1';

                const formData = new FormData();
                formData.append('id', movieId);
                formData.append('status', finalStatus);
                await fetch('backend/set_online_status.php', { method: 'POST', body: formData });

                count++;
                document.getElementById('auditProgress').innerText = count;
                
                if (finalStatus === '0') {
                    offlineCount++;
                    consoleBox.innerHTML += `<span style="color:#ef4444">> 🚨 OFFLINE: ${title} (${mirrorsFoundDown}/${mirrorsChecked} caídos)</span><br>`;
                    // Marcar fila visualmente como OFFLINE
                    row.style.border = '2px solid #ef4444';
                    row.style.background = 'rgba(239, 68, 68, 0.05)';
                } else {
                    onlineCount++;
                    if (count % 10 === 0) {
                        consoleBox.innerHTML += `> ✅ OK: ${title}<br>`;
                    }
                    row.style.border = '';
                    row.style.background = '';
                }
                consoleBox.scrollTop = consoleBox.scrollHeight;
            }

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
                    <div style="color:#ef4444;font-weight:bold;margin-bottom:8px;">¡Atención! Se detectaron ${deadServersList.length} servidores estáticos caídos:</div>
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
                    // LLamar al nuevo endpoint para eliminar solo los servidores caídos
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
                            Swal.fire('Error', 'El servidor devolvió un error inesperado (ver consola)', 'error');
                            return;
                        }
                        
                        if(data.status === 'success') {
                            Swal.fire('¡Limpieza Completada!', `Se eliminaron ${data.deleted} enlaces caídos.`, 'success');
                            loadStats();
                        } else {
                            Swal.fire('Error', 'No se pudieron eliminar', 'error');
                        }
                    } catch (e) {
                        console.error("Fallo de red real:", e);
                        Swal.fire('Error', 'Fallo de red: ' + e.message, 'error');
                    }
                }
            });
            loadStats();
        }

        async function checkAllServers() {
            const icons = document.querySelectorAll('[id^="s1-"], [id^="s2-"], [id^="s3-"], [id^="s4-"], [id^="s5-"]');
            for (const icon of icons) {
                const div = icon.querySelector('div');
                if (div && div.onclick) {
                    div.click();
                    await new Promise(r => setTimeout(r, 150));
                }
            }
        }

        async function updateMovie(id, currentTmdbId, tipo) {
            const resData = await fetch(`backend/get_content.php?admin=1&t=${Date.now()}`).then(r => r.json());
            const movie = resData.movies ? resData.movies.find(m => m.id == id) : null;
            
            let initialPath = '', initialS2 = '', initialS3 = '', initialS4 = '', initialS5 = '';
            let defaultTemp = 1, defaultEp = 1, currentMetaId = null;

            if (tipo === 'series' || tipo === 'tv') {
                // Find latest episode or default to T1E1
                const eps = movie && movie.episodes ? movie.episodes : [];
                if (eps.length > 0) {
                    const latest = eps[eps.length - 1]; // Just pick the last one added
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

            // Extraer nombre de archivo del path actual
            let currentFilename = '', currentDir = '';
            if (isLocalFilePath(initialPath)) {
                const idx = initialPath.lastIndexOf('/');
                currentFilename = idx >= 0 ? initialPath.substring(idx + 1) : initialPath;
                currentDir = idx > 0 ? initialPath.substring(0, idx) : '';
            }

            let modalHtml = `<label style="text-align:left; display:block; font-size:0.9rem; margin-bottom:5px;">ID TMDB:</label>` +
                            `<input id="swal-input1" class="swal2-input" value="${currentTmdbId}" style="margin:0 0 15px 0; width:100%; max-width:100%;">` +
                            `<div style="margin-bottom:10px;padding:10px;background:rgba(99,102,241,0.08);border-radius:8px;border:1px solid rgba(99,102,241,0.2);">` +
                            `<label style="text-align:left;display:block;font-size:0.85rem;margin-bottom:5px;color:#a5b4fc;"><b class="i-doc"></b> Nombre del archivo:</label>` +
                            `<input id="swal-filename" class="swal2-input" value="${currentFilename}" placeholder="con extensión" style="margin:0;width:100%;font-family:monospace;">` +
                            `<div id="swal-filename-dir" style="font-size:0.7rem;color:#64748b;margin-top:4px;">📁 ${currentDir}/</div></div>`;

            if (tipo === 'series' || tipo === 'tv') {
                modalHtml += `<div style="display:flex; gap:10px; margin-bottom:15px; background:rgba(255,255,255,0.05); padding:10px; border-radius:8px; flex-wrap:wrap;">` +
                             `<div style="flex:1;"><label style="text-align:left; display:block; font-size:0.8rem; margin-bottom:5px; color:#a78bfa;">Temporada:</label>` +
                             `<input id="swal-temp" type="number" min="1" class="swal2-input" value="${defaultTemp}" oninput="if(this.value < 1) this.value = 1;" style="margin:0; width:100%;"></div>` +
                             `<div style="flex:1;"><label style="text-align:left; display:block; font-size:0.8rem; margin-bottom:5px; color:#a78bfa;">Episodio:</label>` +
                             `<input id="swal-ep" type="number" min="1" class="swal2-input" value="${defaultEp}" oninput="if(this.value < 1) this.value = 1;" style="margin:0; width:100%;"></div>` +
                             `<div style="flex-basis: 100%; margin-top:5px;"><button type="button" onclick="checkModalServers()" style="width:100%; background:rgba(16,185,129,0.1); color:#10b981; border:1px solid #10b981; padding:8px; border-radius:5px; cursor:pointer; font-weight:bold;"><b class="i-pulse"></b> Probar Enlaces del Capítulo</button></div>` +
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
                        input.style.border = '2px solid #fbbf24'; // Amarillo (Checking)
                        input.style.backgroundColor = 'rgba(251,191,36,0.1)';
                        if (statusSpan) statusSpan.innerHTML = '⏳';
                        
                        try {
                            const url = input.value.trim();
                            if (!url.startsWith('http')) {
                                input.style.border = '2px solid #10b981';
                                input.style.backgroundColor = 'rgba(16,185,129,0.1)';
                                if (statusSpan) statusSpan.innerHTML = '✅';
                                continue;
                            }
                            const res = await fetch(`backend/check_status.php?url=${encodeURIComponent(url)}`);
                            const data = await res.json();
                            if (data.status === 'online') {
                                input.style.border = '2px solid #10b981'; // Verde
                                input.style.backgroundColor = 'rgba(16,185,129,0.1)';
                                if (statusSpan) statusSpan.innerHTML = '✅';
                            } else {
                                input.style.border = '2px solid #ef4444'; // Rojo
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

            // CATEGORIA selector
            const categoriaOpts = [
                { val: 'movie',  label: '🎬 Película' },
                { val: 'series', label: '📺 Serie' },
                { val: 'tv',     label: '📡 TV en Vivo' }
            ].map(o => `<option value="${o.val}" ${tipo === o.val ? 'selected' : ''}>${o.label}</option>`).join('');

            modalHtml += `<div style="margin-bottom:14px;padding:10px 12px;background:rgba(99,102,241,0.08);border-radius:8px;border:1px solid rgba(99,102,241,0.25);">` +
                         `<label style="text-align:left;display:block;font-size:0.8rem;margin-bottom:6px;color:#a5b4fc;">🗂️ Categoría:</label>` +
                         `<select id="swal-categoria" style="width:100%;padding:9px 12px;background:#0f172a;color:#f1f5f9;border:1px solid #334155;border-radius:6px;font-size:0.9rem;cursor:pointer;">` +
                         categoriaOpts +
                         `</select></div>` +
                         `<label style="text-align:left; display:block; font-size:0.8rem; margin-bottom:5px; color:var(--accent);">Servidor Principal (Auto-detección Iframe/m3u8): <span id="status-swal-input2"></span></label>` +
                         `<input id="swal-input2" class="swal2-input" value="${initialPath}" placeholder="Mirror 1" style="margin:0 0 10px 0; width:100%;">` +
                         `<label style="text-align:left; display:block; font-size:0.8rem; margin-bottom:5px;">Servidor Espejo 2: <span id="status-swal-input3"></span></label>` +
                         `<input id="swal-input3" class="swal2-input" value="${initialS2}" placeholder="Mirror 2" style="margin:0 0 10px 0; width:100%;">` +
                         `<label style="text-align:left; display:block; font-size:0.8rem; margin-bottom:5px;">Servidor Espejo 3: <span id="status-swal-input4"></span></label>` +
                         `<input id="swal-input4" class="swal2-input" value="${initialS3}" placeholder="Mirror 3" style="margin:0 0 10px 0; width:100%;">` +
                         `<label style="text-align:left; display:block; font-size:0.8rem; margin-bottom:5px;">Servidor Espejo 4: <span id="status-swal-input5"></span></label>` +
                         `<input id="swal-input5" class="swal2-input" value="${initialS4}" placeholder="Mirror 4" style="margin:0 0 10px 0; width:100%;">` +
                         `<label style="text-align:left; display:block; font-size:0.8rem; margin-bottom:5px;">Servidor Espejo 5: <span id="status-swal-input6"></span></label>` +
                         `<input id="swal-input6" class="swal2-input" value="${initialS5}" placeholder="Mirror 5" style="margin:0; width:100%;">`;

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
                        server5: document.getElementById('swal-input6') ? document.getElementById('swal-input6').value : ''
                    }
                },
                didOpen: () => {
                    if (tipo === 'series' || tipo === 'tv') {
                        const tempInput = document.getElementById('swal-temp');
                        const epInput = document.getElementById('swal-ep');
                        
                        // Hidden input to track current meta_id dynamically
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

                            // Actualizar campo de nombre de archivo según episodio
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
                            
                            // Limpiar iconos de estado al cambiar de episodio
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

                    // Pequeño delay para asegurar que Swal renderizó todo el DOM
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

                        // Fix: Re-calcular tempUrls si se cambia de episodio en Series
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

            try {
                const res = await fetch('backend/update_movie.php', { method: 'POST', body });
                const data = await res.json();
                if (data.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Actualizado',
                        text: `Ahora es: ${data.data.titulo}`,
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
            const tmdbId  = document.getElementById('manualId').value;
            const tipo    = document.getElementById('manualTipo').value;
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
            const result  = document.getElementById('manualResult');

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
                body.append('titulo_manual', tmdbId); // En modo manual el campo "ID" contiene el título
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
            // Limpiar campos manuales
            if (document.getElementById('manualPoster')) document.getElementById('manualPoster').value = '';
            if (document.getElementById('manualBackdrop')) document.getElementById('manualBackdrop').value = '';
            // Resetear checkbox manual
            const checkbox = document.getElementById('manualCheckbox');
            if (checkbox && checkbox.checked) {
                checkbox.checked = false;
                document.getElementById('manualMetaFields').style.display = 'none';
                document.getElementById('manualIdLabel').textContent = 'TMDB ID / Nombre';
                document.getElementById('manualId').placeholder = "Ej: 754 o 'The Matrix'";
            }
            
            // Limpiar estilos de duplicados si existen
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

        // Check duplicates for manual indexation form
        // NOTA: Esta función se llama DESDE loadStats() para garantizar que existingUrls ya está poblado.
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
                    // Remover listeners anteriores clonando el nodo
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

                // Si tenemos todo, podemos avisar al usuario
                const result = document.getElementById('manualResult');
                result.innerHTML = '<p style="color:#fbbf24; animation: pulse 2s infinite;">✨ Datos recibidos desde Galix Sniffer. Haz clic en "Inyectar" para confirmar.</p>';
            }
        });

        // 🧠 DETECTOR INTELIGENTE DE TMDB AL PEGAR URL
        document.getElementById('manualFile').addEventListener('input', async (e) => {
            const val = e.target.value.trim();
            if (val.length < 5) return;

            const manualId = document.getElementById('manualId');
            const result = document.getElementById('manualResult');

            // Si ya tiene un ID, no sobrescribir a menos que esté vacío
            if (manualId.value) return;

            // 1. Extraer posible título: Ignorar parámetros de búsqueda y escanear rutas
            const cleanUrl = val.split('?')[0];
            let segments = cleanUrl.split('/');
            let cleanTitle = "";

            // Buscar de atrás hacia adelante el primer segmento que parezca un nombre
            for (let i = segments.length - 1; i >= 0; i--) {
                let s = segments[i].replace(/\.(mp4|mkv|m3u8|avi|mov)$/i, '').replace(/(_|-|\.)/g, ' ').trim();
                // Ignorar palabras genéricas de sistema
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

        // 🔎 BUSCADOR POR NOMBRE EN EL CAMPO ID
        document.getElementById('manualId').addEventListener('blur', async (e) => {
            const val = e.target.value.trim();
            if (!val || !isNaN(val)) return; // Si es número o está vacío, no hacer nada

            const result = document.getElementById('manualResult');
            const type = document.getElementById('manualTipo').value;
            result.innerHTML = `<p style="color:#94a3b8; font-size:0.8rem;">🔎 Buscando ID para el nombre: "<strong>${val}</strong>"...</p>`;

            try {
                const TMDB_KEY = 'aa99c189865340e6421390ff192384b6';
                const res = await fetch(`https://api.themoviedb.org/3/search/${type}?api_key=${TMDB_KEY}&query=${encodeURIComponent(val)}&language=es-MX`);
                const data = await res.json();

                if (data.results && data.results.length > 0) {
                    const match = data.results[0];
                    e.target.value = match.id; // Reemplazar nombre por el ID real
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

        document.getElementById('librarySearch').addEventListener('keyup', function() {
            const q = this.value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            document.querySelectorAll('#movieTableBody tr').forEach(tr => {
                const title = (tr.querySelector('td[data-label="Título"]')?.textContent || '').toLowerCase();
                tr.style.display = title.includes(q) ? '' : 'none';
            });
        });

        // ── MP4 Scanner & Repair Tool ──
        let mp4RepairTimer = null;

        // Reanudar toast si hay reparación activa (desde cualquier dispositivo/sesión)
        window.checkRepairProgress = async () => {
            try {
                const res = await fetch('backend/mp4_scanner.php?action=progress&t=' + Date.now());
                const data = await res.json();
                if (!data || data.status === 'idle' || data.status === undefined) return;
                const toast = document.getElementById('mp4RepairToast');
                if (!toast) return;
                toast.style.display = 'block';
                updateMP4RepairToast(data);
                if (data.status === 'running' && !mp4RepairTimer) {
                    mp4RepairTimer = setInterval(async () => {
                        try {
                            const pr = await fetch('backend/mp4_scanner.php?action=progress&t=' + Date.now());
                            const pd = await pr.json();
                            updateMP4RepairToast(pd);
                        } catch (e) {}
                    }, 2000);
                }
            } catch (e) {}
        };

        window.scanMP4s = async () => {
            try {
                const result = await fetch('backend/mp4_scanner.php?action=scan&t=' + Date.now());
                const data = await result.json();

                const files = data.files || [];
                const needsRepair = files.filter(f => f.needs_repair);

                if (needsRepair.length === 0) {
                    const totalScanned = data._total || files.length;
                    Swal.fire({
                        title: 'Todos los videos est\u00E1n bien',
                        html: '<p>No se encontraron archivos con formato incorrecto.</p><p style="font-size:0.75rem;color:#64748b;margin-top:10px;">' + totalScanned + ' archivos escaneados en ' + (data._scanned_dirs || []).length + ' directorios</p>',
                        icon: 'success',
                        background: '#1e293b',
                        color: '#fff',
                        confirmButtonColor: '#10b981'
                    });
                    return;
                }

                let html = '<div style="text-align:left; max-height:400px; overflow-y:auto;">';
                html += '<p style="color:#f59e0b; margin-bottom:15px;">Se detectaron <strong>' + needsRepair.length + '</strong> archivo(s) con problemas:</p>';
                html += '<div style="display:flex; flex-direction:column; gap:8px;">';

                needsRepair.forEach((f, i) => {
                    const isAudio = f.repair_reason && f.repair_reason.startsWith('Audio:');
                    const bgColor = isAudio ? 'rgba(245,158,11,0.08)' : 'rgba(239,68,68,0.05)';
                    const borderColor = isAudio ? 'rgba(245,158,11,0.3)' : 'rgba(239,68,68,0.2)';
                    const badgeColor = isAudio ? '#f59e0b' : '#ef4444';
                    html += '<label style="display:flex; align-items:center; gap:12px; padding:10px 14px; background:' + bgColor + '; border:1px solid ' + borderColor + '; border-radius:8px; cursor:pointer;">' +
                        '<input type="checkbox" id="mp4ck_' + i + '" value="' + f.name.replace(/"/g, '&quot;') + '" checked style="width:18px; height:18px; accent-color:' + badgeColor + ';">' +
                        '<div style="flex:1; min-width:0;">' +
                        '<div style="font-weight:600;">' + f.name.replace(/"/g, '&quot;') + '</div>' +
                        '<div style="display:flex; gap:8px; align-items:center; margin-top:4px;">' +
                        '<span style="font-size:0.7rem; background:' + badgeColor + '20; color:' + badgeColor + '; padding:2px 8px; border-radius:4px; font-weight:600;">' + (f.repair_reason || 'Reparar') + '</span>' +
                        '<span style="font-size:0.75rem; color:#94a3b8;">' + f.size_human + '</span>' +
                        '</div></div></label>';
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
                    preConfirm: () => {
                        const sel = [];
                        for (let i = 0; i < needsRepair.length; i++) {
                            const cb = document.getElementById('mp4ck_' + i);
                            if (cb && cb.checked) sel.push(cb.value);
                        }
                        if (sel.length === 0) {
                            Swal.showValidationMessage('Selecciona al menos un archivo');
                            return false;
                        }
                        return sel;
                    },
                    didRender: () => {
                        const denyBtn = Swal.getDenyButton();
                        if (denyBtn) {
                            denyBtn.onclick = () => {
                                allSelected = !allSelected;
                                for (let i = 0; i < needsRepair.length; i++) {
                                    const cb = document.getElementById('mp4ck_' + i);
                                    if (cb) cb.checked = allSelected;
                                }
                                denyBtn.innerHTML = allSelected ? 'Deseleccionar todos' : 'Seleccionar todos';
                            };
                        }
                    }
                });

                if (selectedFiles && selectedFiles.length > 0) {
                    convertSelectedMP4s(selectedFiles);
                }
            } catch (err) {
                console.error('[MP4 Scanner] Error:', err);
                Swal.fire('Error de Red', 'No se pudo conectar con el esc\u00E1ner.', 'error');
            }
        };

        window.convertSelectedMP4s = async (files) => {
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
                const params = files.map(f => 'files[]=' + encodeURIComponent(f)).join('&');
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
            } else if (data.status === 'completed') {
                const ok = data.results ? data.results.filter(r => r.status === 'ok').length : 0;
                const err = data.results ? data.results.filter(r => r.status === 'error').length : 0;
                title.innerHTML = 'Reparaci\u00F3n completada';
                progress.innerHTML = ok + ' convertidos' + (err > 0 ? ' \u00B7 ' + err + ' fallaron' : '');
                barFill.style.width = '100%';
                barFill.style.background = err > 0 ? 'linear-gradient(90deg,#f59e0b,#ef4444)' : 'linear-gradient(90deg,#10b981,#38bdf8)';
                details.style.display = 'none';
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
                if (dismissBtn) dismissBtn.style.display = 'inline-flex';
                if (mp4RepairTimer) { clearInterval(mp4RepairTimer); mp4RepairTimer = null; }
                if (resetBtn) resetBtn.style.display = 'none';
                setTimeout(() => { if (toast.style.display !== 'none') toast.style.display = 'none'; }, 10000);
            } else if (data.status === 'idle') {
                title.innerHTML = 'Sin actividad';
                progress.innerHTML = 'No hay reparaci\u00F3n en curso.';
                barFill.style.width = '0%';
                barFill.style.background = '#f59e0b';
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
