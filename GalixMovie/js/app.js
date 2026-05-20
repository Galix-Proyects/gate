document.addEventListener('DOMContentLoaded', () => {
    // 🛡️ PROTOCOLO AD-SHIELD: Bloquear popups y escapes de sandbox
    window.open = function() { console.warn("🛡️ GalixMovie: Intento de Popup bloqueado."); return { focus: function() {} }; };
    
    console.log("🎬 GalixMovie PRO Inicializado");

    let currentMovie = null; 
    let currentActiveMedia = null; // Para almacenar el episodio o película actual con sus metadatos (incluyendo subs)
    let progressTimer = null;
    let hideHeaderTimer = null; // 🚀 Cronómetro de visibilidad v9.0
    let clientIp = ''; 

    // Obtener IP del cliente al iniciar (DHARMA #25 - Bypass cache 404)
    fetch('backend/get_client_ip.php?t=' + Date.now()).then(r => r.json()).then(d => { if(d.ip) clientIp = d.ip; });

    const navbar = document.getElementById('navbar');
    window.addEventListener('scroll', () => {
        navbar.classList.toggle('scrolled', window.scrollY > 50);
    });

    const searchInput = document.getElementById('searchInput');
    searchInput.addEventListener('input', (e) => {
        const q = e.target.value.toLowerCase().trim();
        document.querySelectorAll('.movie-card').forEach(card => {
            const title = card.querySelector('.movie-title')?.textContent.toLowerCase() || '';
            card.style.display = (!q || title.includes(q)) ? 'block' : 'none';
        });
    });

    // ─── ACTUALIZAR HERO DINÁMICO ──────────────────────────────────────────
    function updateHero(movie) {
        if (!movie) return;
        const heroImg     = document.getElementById('heroImg');
        const heroContent = document.getElementById('heroContent');
        const heroTitle   = document.getElementById('heroTitle');
        const heroDesc    = document.getElementById('heroDesc');
        const heroMeta    = document.getElementById('heroMeta');
        const heroPlayBtn = document.getElementById('heroPlayBtn');

        const isMobile = window.innerWidth <= 768;
        heroImg.src = isMobile ? (movie.poster_path || movie.backdrop_path) : (movie.backdrop_path || movie.poster_path);
        heroImg.style.display = 'block';
        heroTitle.textContent = movie.titulo.toUpperCase();
        heroDesc.textContent  = movie.sinopsis;
        heroMeta.innerHTML    = `<span>${movie.fecha_estreno.split('-')[0]}</span> <span>★ ${movie.puntuacion}</span> <span>4K Ultra HD</span>`;
        
        heroPlayBtn.onclick = () => openPlayer(movie);

        setTimeout(() => { heroContent.style.opacity = '1'; }, 500);
    }

    async function loadContinueWatching() {
        try {
            const res = await fetch('backend/get_progress.php');
            const data = await res.json();
            if (data.status === 'success' && data.historial.length > 0) {
                const section = document.getElementById('continueSection');
                const grid = document.getElementById('continueGrid');
                section.style.display = 'block';
                grid.innerHTML = '';
                // Mostrar solo un máximo de 8 elementos en Continuar Viendo
                const limitedHistorial = data.historial.slice(0, 8);
                limitedHistorial.forEach(item => {
                    const pct = Math.round((item.tiempo_visto / item.total_tiempo) * 100);
                    const card = document.createElement('div');
                    card.className = 'movie-card';
                    card.style.position = 'relative';
                    card.innerHTML = `
                        <img src="${item.poster_path || 'assets/no-poster.png'}" alt="${item.titulo}" loading="lazy">
                        <div class="movie-info">
                            <div class="movie-title">${item.titulo}</div>
                        </div>
                        <div class="movie-popup" style="background: rgba(255, 255, 255, 0.02);">
                            <div class="popup-title">${item.titulo}</div>
                            <div class="popup-meta">★ ${item.puntuacion || 'N/A'} • ${pct}% Completado</div>
                            <div class="popup-desc">${item.sinopsis || 'Sin descripción disponible.'}</div>
                        </div>
                        <div style="position:absolute; bottom:0; left:0; width:100%; height:3px; background:rgba(255,255,255,0.2); z-index: 30;">
                            <div style="width:${pct}%; height:100%; background:var(--accent);"></div>
                        </div>
                    `;
                    card.onclick = () => showMovieDetails(item);
                    grid.appendChild(card);
                });
            }
        } catch (err) { console.warn("Historial no disponible:", err); }
    }

    let allMovies = []; // Cache global de contenido

    // ─── FILTRADO DINÁMICO ──────────────────────────────────────────────────
    window.filterContent = async (type, el) => {
        // Actualizar UI
        document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
        el.classList.add('active');

        let filtered = [];
        if (type === 'all') {
            filtered = allMovies;
        } else if (type === 'fav') {
            try {
                const res = await fetch('backend/get_favorites.php');
                const data = await res.json();
                if (data.status === 'success') {
                    const favIds = data.favoritos.map(f => f.id);
                    filtered = allMovies.filter(m => favIds.includes(m.id));
                }
            } catch (err) {
                console.error("Error cargando favoritos:", err);
            }
        } else {
            filtered = allMovies.filter(m => m.tipo === type);
        }

        renderGrid(filtered);
    };

    function renderGrid(movies) {
        const container = document.getElementById('dynamicRowsContainer');
        if (!container) return;
        container.innerHTML = '';

        if (movies.length === 0) {
            const emptySec = document.createElement('section');
            emptySec.className = 'content-row';
            emptySec.innerHTML = `
                <h2 class="row-title">Tu Biblioteca</h2>
                <div style="color: var(--text-dim); padding: 20px 0; font-size: 0.9rem;">No hay películas o series disponibles.</div>
            `;
            container.appendChild(emptySec);
            return;
        }

        // Secciones de 10 en 10 películas por fila (tanto PC como móviles)
        const chunkSize = 10;
        for (let i = 0; i < movies.length; i += chunkSize) {
            const chunk = movies.slice(i, i + chunkSize);
            const sectionIndex = Math.floor(i / chunkSize) + 1;

            const section = document.createElement('section');
            section.className = 'content-row';
            
            // Nombre de la fila
            const rowTitle = sectionIndex === 1 ? 'Tu Biblioteca' : `Tu Biblioteca - Sec. ${sectionIndex}`;
            
            section.innerHTML = `
                <h2 class="row-title">${rowTitle}</h2>
                <div class="movie-grid"></div>
            `;

            const grid = section.querySelector('.movie-grid');

            chunk.forEach(movie => {
                const card = document.createElement('div');
                card.className = 'movie-card';
                card.innerHTML = `
                    <img src="${movie.poster_path}" alt="${movie.titulo}" loading="lazy">
                    <div class="movie-info">
                        <div class="movie-title">${movie.titulo}</div>
                    </div>
                    <div class="movie-popup">
                        <div class="popup-title">${movie.titulo}</div>
                        <div class="popup-meta">★ ${movie.puntuacion} • ${movie.fecha_estreno.split('-')[0]}</div>
                        <div class="popup-desc">${movie.sinopsis}</div>
                    </div>
                `;
                card.onclick = () => showMovieDetails(movie);
                grid.appendChild(card);
            });

            container.appendChild(section);

            // Iniciar listeners de scroll y arrastre de mouse por hardware
            if (window.initGridScrolling) {
                window.initGridScrolling(grid);
            }
        }
    }

    async function loadContent() {
        try {
            const res = await fetch('backend/get_content.php');
            const data = await res.json();
            if (data.status === 'success' && data.movies.length > 0) {
                allMovies = data.movies;
                const randomMovie = allMovies[Math.floor(Math.random() * allMovies.length)];
                updateHero(randomMovie);
                renderGrid(allMovies);
            }
        } catch (err) { console.error("Error cargando contenido:", err); }
    }

    loadContent();
    loadContinueWatching();

    let plyrPlayer = new Plyr('#videoPlayer', {
        controls: ['play-large', 'play', 'progress', 'current-time', 'duration', 'mute', 'volume', 'captions', 'settings', 'pip', 'airplay', 'fullscreen'],
        settings: ['quality', 'speed'],
        ratio: null, // 🛡️ LIBERACIÓN TOTAL: Permite que el video use todo el espacio sin forzar 16:9
        autoplay: true,
        muted: false // Tratar de reproducir con sonido, si el navegador lo bloquea, Plyr lo maneja.
    });

    // 📱 MEJORA: Ocultar header del player en fullscreen (móvil)
    const playerHeader = document.getElementById('playerHeader') || document.querySelector('.player-header');
    const hiddenOnFullscreen = [playerHeader, document.getElementById('progressBarContainer')];

    function onEnterFullscreen() {
        hiddenOnFullscreen.forEach(el => { if (el) el.style.display = 'none'; });
    }
    function onExitFullscreen() {
        hiddenOnFullscreen.forEach(el => { if (el) el.style.display = ''; });
    }

    plyrPlayer.on('enterfullscreen', onEnterFullscreen);
    plyrPlayer.on('exitfullscreen', onExitFullscreen);

    // Fallback: Escuchar el evento nativo de fullscreen para iOS Safari
    document.addEventListener('fullscreenchange', () => {
        document.fullscreenElement ? onEnterFullscreen() : onExitFullscreen();
    });
    document.addEventListener('webkitfullscreenchange', () => {
        document.webkitFullscreenElement ? onEnterFullscreen() : onExitFullscreen();
    });

    let sourceQueue = [];
    let currentSourceIndex = 0;
    let consecutiveFailuresCount = 0;

    async function showServerStatus() {
        const currentSource = sourceQueue[currentSourceIndex];
        const headerLabel = document.getElementById('currentServerLabel');
        const serverDot = document.getElementById('serverDot');
        if (!headerLabel || !serverDot) return;

        // Limpiar etiqueta y poner solo el número del servidor (ej: S1, S2)
        const serverNum = currentSource.label.replace('Servidor ', 'S');
        headerLabel.textContent = serverNum;
        
        const isLocal = !currentSource.url.startsWith('http');
        
        // Estado inicial
        serverDot.style.background = isLocal ? '#22c55e' : '#94a3b8'; // Verde si es local, gris si es remoto esperando sonda

        // Si es remoto, lanzar sonda real para confirmar estatus
        if (!isLocal) {
            try {
                const res = await fetch(`backend/check_status.php?url=${encodeURIComponent(currentSource.url)}`);
                const data = await res.json();
                
                if (data.status === 'online') {
                    serverDot.style.background = '#22c55e'; // Verde ONLINE
                } else {
                    serverDot.style.background = '#ef4444'; // Rojo OFFLINE
                }
            } catch (err) { 
                console.warn("Sonda de estatus fallida:", err);
                serverDot.style.background = '#ef4444'; // Rojo por error
            }
        }
    }

    // ⚡ PRE-CARGADOR ULTRA-VELOZ EN BACKGROUND (DHARMA Fix #50)
    // Comienza a resolver y precargar el stream en cuanto el usuario presiona la tarjeta (card click)
    window.activePreloadVideo = null;
    window.preloadMovieSources = async (movie) => {
        console.log("⚡ Pre-cargador Galix: Iniciando pre-resolución en background para:", movie.titulo);
        
        let targetLinks = movie;
        let isSeries = movie.tipo === 'series';
        let firstEp = null;

        // Si es serie, precargar el primer episodio
        if (isSeries && movie.episodes && movie.episodes.length > 0) {
            const sortedEps = movie.episodes.sort((a, b) => {
                if (a.temporada !== b.temporada) return a.temporada - b.temporada;
                return a.episodio - b.episodio;
            });
            firstEp = sortedEps[0];
            targetLinks = firstEp;
        }

        // Obtener el primer enlace no vacío disponible en orden de prioridad
        const seedUrl = (targetLinks.archivo_path || targetLinks.server2 || targetLinks.server3 || targetLinks.server4 || targetLinks.server5 || "").trim();
        if (!seedUrl) {
            console.log("⚡ Pre-cargador Galix: No hay enlaces disponibles para pre-cargar.");
            return;
        }

        const targetContenidoId = movie.id;
        const targetEpisodioId = isSeries ? (firstEp.id || firstEp.meta_id) : '';

        // Función para disparar precarga HTTP nativa en el navegador
        const triggerBrowserPreload = (url) => {
            if (!url) return;
            // Evitar pre-cargar iframes directos ya que se cargan en la pantalla
            if (url.includes('<iframe') || url.includes('/e/') || url.includes('embed') || url.includes('sharing/embed')) {
                console.log("⚡ Pre-cargador Galix: Es un iframe, omitiendo precarga multimedia de bytes.");
                return;
            }

            try {
                // Normalizar ruta local si aplica
                let finalUrl = url;
                if (finalUrl.includes(':\\') || finalUrl.includes('GalixMovie')) {
                    if (finalUrl.includes('media')) {
                        finalUrl = 'media' + finalUrl.split('media')[1].replace(/\\/g, '/');
                    }
                }

                // Aplicar proxy si aplica
                const forceProxy = true; // Por seguridad al precargar
                if (finalUrl.startsWith('http') && !finalUrl.includes(window.location.hostname) && !finalUrl.includes('cloudwindow-route.com')) {
                    const pageRef = document.referrer || window.location.href;
                    finalUrl = `backend/proxy.php?url=${encodeURIComponent(finalUrl)}&ref=${encodeURIComponent(pageRef)}&cip=${clientIp}`;
                } else if (!finalUrl.startsWith('http')) {
                    const isMKV = finalUrl.toLowerCase().includes('.mkv');
                    if (isMKV) {
                        finalUrl = `backend/mkv_stream.php?file=${encodeURIComponent(finalUrl)}`;
                    } else {
                        finalUrl = encodeURI(finalUrl);
                    }
                }

                // Destruir precarga anterior si existe
                if (window.activePreloadVideo) {
                    window.activePreloadVideo.src = "";
                    window.activePreloadVideo.load();
                }

                const tempVideo = document.createElement('video');
                tempVideo.preload = 'auto';
                tempVideo.muted = true;
                tempVideo.volume = 0;
                tempVideo.src = finalUrl;
                tempVideo.load();
                window.activePreloadVideo = tempVideo;
                console.log("⚡ Pre-cargador Galix: Pre-buffer HTTP nativo iniciado para:", finalUrl);
            } catch (err) {
                console.warn("⚡ Pre-cargador Galix: Error en triggerBrowserPreload:", err);
            }
        };

        // Escenarios de resolución
        if (seedUrl.startsWith('extract:') || seedUrl.startsWith('sniper:')) {
            // Sniper en móviles se convierte a extract
            let finalSeed = seedUrl;
            if (finalSeed.startsWith('sniper:')) {
                const isMobileOrTV = /iPhone|iPad|iPod|Android|Web0S|Tizen|SmartTV|Roku|AppleTV|NetCast|PlayStation|Xbox/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
                if (isMobileOrTV) {
                    finalSeed = finalSeed.replace('sniper:', 'extract:');
                }
            }

            console.log("⚡ Pre-cargador Galix: Evaluando semilla extract/sniper:", finalSeed);
            
            // 1. Consultar si ya está resuelto en la caché de Autopilot
            try {
                const cacheRes = await fetch(`backend/get_cached_mirrors.php?contenido_id=${targetContenidoId}&episodio_id=${targetEpisodioId}&seed_url=${encodeURIComponent(finalSeed)}&t=${Date.now()}`);
                const cacheData = await cacheRes.json();
                
                if (cacheData.status === 'success' && cacheData.mirrors && cacheData.mirrors.length > 0) {
                    console.log("⚡ Pre-cargador Galix: HIT de Cache! Pre-cargando stream resuelto:", cacheData.mirrors[0].resolved_url);
                    triggerBrowserPreload(cacheData.mirrors[0].resolved_url);
                } else {
                    // 2. MISS de Cache: Lanzar extracción silenciosa en background para que guarde en base de datos
                    if (finalSeed.startsWith('extract:')) {
                        const pageUrl = finalSeed.replace('extract:', '');
                        console.log("⚡ Pre-cargador Galix: MISS de Cache. Extrayendo silenciosamente en background para:", pageUrl);
                        
                        // Lanzar fetch silencioso
                        fetch(`backend/extract.php?url=${encodeURIComponent(pageUrl)}&contenido_id=${targetContenidoId}&episodio_id=${targetEpisodioId}&t=${Date.now()}`)
                            .then(res => res.json())
                            .then(data => {
                                if (data.status === 'success' && data.url) {
                                    console.log("⚡ Pre-cargador Galix: Extracción silenciosa completada y cacheada:", data.url);
                                    triggerBrowserPreload(data.url);
                                }
                            })
                            .catch(err => console.warn("⚡ Pre-cargador Galix: Fallo silencioso en extract:", err));
                    } else if (finalSeed.startsWith('sniper:')) {
                        // Si es sniper, no podemos hacerlo 100% silencioso porque requiere mensajería con la extensión,
                        // pero podemos dispararlo si la extensión está activa.
                        const embedUrl = finalSeed.replace('sniper:', '');
                        console.log("⚡ Pre-cargador Galix: Disparando Sniper silencioso en background para:", embedUrl);
                        
                        window.dispatchEvent(new CustomEvent('galix-sniper-request', {
                            detail: { embedUrl: embedUrl, requestId: Date.now() }
                        }));
                    }
                }
            } catch (err) {
                console.warn("⚡ Pre-cargador Galix: Error resolviendo caché en preloader:", err);
            }
        } else {
            // Enlace directo: MP4, M3U8 local o remoto
            console.log("⚡ Pre-cargador Galix: Enlace directo detectado, pre-cargando de inmediato:", seedUrl);
            triggerBrowserPreload(seedUrl);
        }
    };

    window.showMovieDetails = async (movie) => {
        console.log("🎬 Sonda Telemetría: Mostrando Detalles de la película", movie);
        
        // Disparar pre-cargador en background para aprovechar tiempos (DHARMA Ultra-Fast Preloader)
        window.preloadMovieSources(movie);
        const modal = document.getElementById('movieDetailsModal');
        if (!modal) return;

        // Limpiar/Cargar placeholders de carga
        document.getElementById('detailsPoster').src = movie.poster_path;
        document.getElementById('detailsTitle').textContent = movie.titulo;
        document.getElementById('detailsRating').textContent = `★ ${movie.puntuacion}`;
        document.getElementById('detailsYear').textContent = movie.fecha_estreno ? movie.fecha_estreno.split('-')[0] : 'N/A';
        document.getElementById('detailsType').textContent = movie.tipo === 'series' ? 'SERIE' : 'PELÍCULA';
        document.getElementById('detailsOverview').textContent = movie.sinopsis || 'Sin descripción disponible.';
        
        const castEl = document.getElementById('detailsCast');
        castEl.textContent = 'Cargando reparto...';

        // Configurar el botón de reproducción
        const playBtn = document.getElementById('detailsPlayBtn');
        playBtn.onclick = () => {
            window.closeMovieDetails();
            window.openPlayer(movie);
        };

        // Mostrar modal de detalles
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        // Intentar obtener actores/cast en tiempo real desde la API de TMDB
        if (movie.tmdb_id) {
            try {
                const apiType = movie.tipo === 'series' ? 'tv' : 'movie';
                const creditsUrl = `https://api.themoviedb.org/3/${apiType}/${movie.tmdb_id}/credits?api_key=aa99c189865340e6421390ff192384b6&language=es-MX`;
                const res = await fetch(creditsUrl);
                if (res.ok) {
                    const data = await res.json();
                    if (data.cast && data.cast.length > 0) {
                        castEl.innerHTML = ''; // Limpiar el cargando
                        // Plex style: Mostrar hasta 8 actores principales con foto
                        data.cast.slice(0, 8).forEach(actor => {
                            const initials = actor.name ? actor.name.split(' ').map(n => n[0]).slice(0, 2).join('').toUpperCase() : '?';
                            const svgString = `<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="#0f172a"/><text x="50" y="55" font-size="34" font-family="system-ui, sans-serif" font-weight="bold" fill="#a855f7" text-anchor="middle" dominant-baseline="middle">${initials}</text></svg>`;
                            const photoUrl = actor.profile_path 
                                ? `https://image.tmdb.org/t/p/w185${actor.profile_path}` 
                                : `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svgString)}`;

                            const safeName = actor.name ? actor.name.replace(/"/g, '&quot;') : '';
                            const safeChar = actor.character ? actor.character.replace(/"/g, '&quot;') : 'N/A';

                            const actorCard = document.createElement('div');
                            actorCard.className = 'actor-card';
                            actorCard.innerHTML = `
                                <div class="actor-img-wrapper">
                                    <img class="actor-img" src="${photoUrl}" alt="${safeName}" loading="lazy">
                                </div>
                                <div class="actor-name" title="${safeName}">${safeName}</div>
                                <div class="actor-character" title="${safeChar}">${safeChar}</div>
                            `;
                            castEl.appendChild(actorCard);
                        });
                        
                        // Aplicar desplazamiento suave con rueda de mouse y drag tactil al carrusel de actores
                        if (window.initGridScrolling) {
                            window.initGridScrolling(castEl);
                        }
                    } else {
                        castEl.innerHTML = '<span style="color:rgba(255,255,255,0.4); font-size:0.85rem;">Reparto no disponible</span>';
                    }
                } else {
                    castEl.innerHTML = '<span style="color:rgba(255,255,255,0.4); font-size:0.85rem;">Reparto no disponible</span>';
                }
            } catch (err) {
                console.warn("Error al obtener actores de TMDB:", err);
                castEl.innerHTML = '<span style="color:rgba(255,255,255,0.4); font-size:0.85rem;">Reparto no disponible</span>';
            }
        } else {
            castEl.innerHTML = '<span style="color:rgba(255,255,255,0.4); font-size:0.85rem;">Reparto no disponible</span>';
        }
    };

    window.closeMovieDetails = () => {
        const modal = document.getElementById('movieDetailsModal');
        if (modal) {
            modal.style.display = 'none';
            // Restaurar scroll solo si no está abierto el reproductor
            const playerModal = document.getElementById('playerModal');
            if (!playerModal || playerModal.style.display === 'none') {
                document.body.style.overflow = '';
            }
        }
    };

    window.currentIntroAudio = null;
    window.currentIntroTimeout = null;

    window.runCinematicIntro = (callback) => {
        console.log("🎬 Sonda Cinematic: Inicializando Splash Pre-Roll...");
        const introOverlay = document.getElementById('galix-intro');
        if (!introOverlay) {
            if (callback) callback();
            return;
        }

        if (window.currentIntroAudio) {
            try { window.currentIntroAudio.pause(); } catch(e){}
            window.currentIntroAudio = null;
        }
        if (window.currentIntroTimeout) {
            clearTimeout(window.currentIntroTimeout);
            window.currentIntroTimeout = null;
        }

        introOverlay.classList.remove('hidden');
        
        // Forzar reflow
        void introOverlay.offsetWidth;

        // Audio del Intro 8s
        const audio = new Audio('assets/intro_8s.mp3');
        audio.volume = 1.0;
        window.currentIntroAudio = audio;

        audio.play().catch(err => {
            console.warn("⚠️ Autoplay de audio bloqueado:", err.message);
        });

        window.currentIntroTimeout = setTimeout(() => {
            introOverlay.style.opacity = '0';
            
            setTimeout(() => {
                introOverlay.classList.add('hidden');
                introOverlay.style.opacity = '1'; // reset for next time
                
                const player = document.getElementById('videoPlayer');
                if (player) player.muted = false;
                if (plyrPlayer) plyrPlayer.muted = false;

                console.log("🎬 Sonda Cinematic: Splash finalizado.");
                if (callback) callback();
            }, 1000);
        }, 8000);
    };

    window.openPlayer = (movie) => {
        console.log("🎬 Sonda Telemetría: Abriendo Player");
        currentMovie = movie;
        const modal  = document.getElementById('playerModal');
        const player = document.getElementById('videoPlayer');
        const epSelector = document.getElementById('episodeSelector');
        
        let targetLinks = movie;
        
        // Configurar selector de episodios si es serie
        if (movie.tipo === 'series' && movie.episodes && movie.episodes.length > 0) {
            epSelector.style.display = 'block';
            epSelector.innerHTML = '';
            
            // Ordenar por temporada y episodio
            const sortedEps = movie.episodes.sort((a, b) => {
                if (a.temporada !== b.temporada) return a.temporada - b.temporada;
                return a.episodio - b.episodio;
            });

            sortedEps.forEach((ep, idx) => {
                const opt = document.createElement('option');
                opt.value = idx;
                opt.textContent = `T${ep.temporada} E${ep.episodio}`;
                epSelector.appendChild(opt);
            });
            
            targetLinks = sortedEps[0]; // Cargar el primer episodio por defecto
            document.getElementById('playerTitle').textContent = `${movie.titulo} - T${targetLinks.temporada}E${targetLinks.episodio}`;
            
            // Evento para cambiar de episodio
            epSelector.onchange = (e) => {
                const selectedEp = sortedEps[e.target.value];
                document.getElementById('playerTitle').textContent = `${movie.titulo} - T${selectedEp.temporada}E${selectedEp.episodio}`;
                loadQueueAndPlay(selectedEp);
            };
        } else {
            epSelector.style.display = 'none';
            document.getElementById('playerTitle').textContent = movie.titulo || '';
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        document.body.classList.add('immersive-active'); // 🚀 INMERSIÓN FORZADA v3.2
        document.body.style.overflow = 'hidden'; // 🛡️ Bloquear scroll lateral v10.0

        // Silenciar temporalmente en background durante la intro cinemática
        if (player) player.muted = true;
        if (plyrPlayer) plyrPlayer.muted = true;

        window.runCinematicIntro(() => {
            console.log("🎬 Sonda Cinematic: Intro terminada.");
        });

        loadQueueAndPlay(targetLinks);
        initHeaderAutoHide(); // 🛡️ Activar motor de visibilidad v9.0

        clearInterval(progressTimer);
        progressTimer = setInterval(async () => {
            if (!player.paused && player.currentTime > 5 && currentMovie?.id) {
                const body = new FormData();
                body.append('contenido_id', currentMovie.id);
                body.append('tiempo', Math.floor(player.currentTime));
                body.append('total',  Math.floor(player.duration || 0));
                await fetch('backend/save_progress.php', { method: 'POST', body });
            }
        }, 10000);
    };

    function loadQueueAndPlay(linksSource) {
        currentActiveMedia = linksSource; // Guardar referencia para subtítulos y otros metadatos
        
        // Crear cola original con filtrado
        const baseQueue = [
            { url: linksSource.archivo_path, label: "Servidor 1" },
            { url: linksSource.server2,      label: "Servidor 2" },
            { url: linksSource.server3,      label: "Servidor 3" },
            { url: linksSource.server4,      label: "Servidor 4" },
            { url: linksSource.server5,      label: "Servidor 5" }
        ].filter(s => s.url && s.url.trim() !== "");

        // DHARMA FIX #40: Auto-Expansión Dual de Semillas (Sniper + Fénix)
        // Si el usuario ingresó una sola semilla (sniper: o extract:), creamos automáticamente ambas alternativas
        const rawQueue = [];
        for (const item of baseQueue) {
            const url = item.url.trim();
            if (url.startsWith('sniper:') || url.startsWith('extract:')) {
                const cleanUrl = url.replace(/^(sniper:|extract:)/, '');
                
                // Opción 1: Sniper (Extracción ultrarrápida del cliente)
                rawQueue.push({
                    url: 'sniper:' + cleanUrl,
                    label: `${item.label} (Sniper)`
                });
                
                // Opción 2: Fénix / Extract (Extracción robusta del servidor)
                rawQueue.push({
                    url: 'extract:' + cleanUrl,
                    label: `${item.label} (Fénix)`
                });
            } else {
                rawQueue.push(item);
            }
        }

        const isMP4 = url => url.toLowerCase().includes('.mp4');
        const isM3U8 = url => (url.includes('.m3u8') || url.includes('.txt') || url.startsWith('extract:') || url.startsWith('sniper:') || url.includes('proxy.php') || url.includes('mkv_stream.php')) && !isMP4(url);
        const isEmbed = url => url.includes('http') && !isMP4(url) && !isM3U8(url) && !url.startsWith('/') && !url.match(/\.(mkv|avi|mov)$/i);

        const mp4Servers   = rawQueue.filter(s => isMP4(s.url));
        const m3u8Servers  = rawQueue.filter(s => isM3U8(s.url));
        const embedServers = rawQueue.filter(s => isEmbed(s.url));
        const otherServers = rawQueue.filter(s => !isMP4(s.url) && !isM3U8(s.url) && !isEmbed(s.url));

        // 🚀 DHARMA Fix #41: Orden de reproducción prioritario exacto: MP4 -> M3U8 -> Local Otros -> Reproductores Terceros (Iframes)
        sourceQueue = [...mp4Servers, ...m3u8Servers, ...otherServers, ...embedServers];
        
        if (sourceQueue.length === 0) {
            Swal.fire('Sin Enlaces', 'Este contenido aún no tiene enlaces cargados.', 'info');
            return;
        }

        currentSourceIndex = 0;
        consecutiveFailuresCount = 0; // Resetear fallos al iniciar reproducción nueva
        loadSource(sourceQueue[currentSourceIndex].url);
        showServerStatus();
    }

    let isSwitchingSource = false;
    window.tryNextSource = async (isManual = false) => {
        if (isSwitchingSource) return;
        isSwitchingSource = true;

        if (isManual) {
            consecutiveFailuresCount = 0; // El click manual rompe la cadena de fallos automáticos
        } else {
            consecutiveFailuresCount++; // Incrementar si es fallo automático
        }

        console.log("🛰️ Telemetría: Forzando cambio de servidor...");
        // Buscar el botón con un selector flexible
        const nextBtn = document.querySelector('button[onclick*="tryNextSource"]');
        const originalIcon = nextBtn ? nextBtn.innerHTML : '';
        if (nextBtn) {
            nextBtn.disabled = true;
            nextBtn.innerHTML = '<ion-icon name="sync-outline" class="spin-animation"></ion-icon>';
        }

        currentSourceIndex++;
        
        // Si llegamos al final de la cola, reiniciamos para ciclar o declaramos apagón
        if (currentSourceIndex >= sourceQueue.length) {
            console.warn("🔄 Sonda FENIX: Fin de cola alcanzado. Evaluando estado de fallos locales...");
            
            // Si el número de fallos consecutivos automáticos es igual o mayor a la longitud de la cola,
            // significa que absolutamente todos los servidores han intentado cargarse y han fallado en secuencia.
            if (consecutiveFailuresCount >= sourceQueue.length) {
                console.error("🛑 GalixMovie: Apagón total. Todos los servidores han fallado consecutivamente.");
                closePlayer();
                Swal.fire({
                    title: 'Apagón de Redundancia 🎬⚠️',
                    html: `
                        <div style="text-align:left; font-size:0.95rem; line-height:1.6; color:#cbd5e1;">
                            <p>Hemos agotado los <b>${sourceQueue.length} servidores</b> disponibles y ninguno responde.</p>
                            <div style="background:rgba(239,68,68,0.1); border-left:4px solid #ef4444; padding:10px; margin:15px 0; border-radius:4px;">
                                <strong>Diagnóstico del Sistema:</strong><br>
                                Todos los enlaces de origen están rechazando la conexión o han expirado simultáneamente.
                            </div>
                            <p style="color:#94a3b8; font-size:0.85rem;">
                                <b>Siguientes pasos:</b><br>
                                1. Recarga la página para refrescar tokens.<br>
                                2. Verifica si el archivo local no ha sido movido.<br>
                                3. Intenta con otra película mientras se restauran los mirrors.
                            </p>
                        </div>
                    `,
                    icon: 'error',
                    background: '#020617',
                    color: '#fff',
                    confirmButtonColor: '#ef4444',
                    confirmButtonText: 'Aceptar'
                });
                currentSourceIndex = 0;
                consecutiveFailuresCount = 0;
                isSwitchingSource = false;
                if (nextBtn) {
                    nextBtn.disabled = false;
                    nextBtn.innerHTML = originalIcon;
                }
                return;
            } else {
                // Ciclo instantáneo limpio (p. ej. si el usuario clickeó rápido o algunos están OK)
                console.log("♻️ Redundancia Activa: Ciclando limpiamente al inicio de la cola.");
                currentSourceIndex = 0;
            }
        }

        try {
            await loadSource(sourceQueue[currentSourceIndex].url);
            await showServerStatus();
        } finally {
            isSwitchingSource = false;
            nextBtn.disabled = false;
            nextBtn.innerHTML = originalIcon;
        }
    };

    async function loadSource(source) {
        window.isForcedEmbed = false; // DHARMA: Resetear estado global para evitar fugas entre servidores
        window.isFenixExtractedToken = false; // Flag para saber a qué IP pertenece el token (Server o Cliente)
        
        // 🧠 Galix Autopilot Engine v1.0 - Read-Through Cache
        if (source.startsWith('extract:') || source.startsWith('sniper:')) {
            if (!window.isCheckingAutopilotCache) {
                window.isCheckingAutopilotCache = true;
                const seedUrl = source;
                const targetContenidoId = currentMovie ? currentMovie.id : '';
                const targetEpisodioId = (currentMovie && currentMovie.tipo === 'series' && currentActiveMedia) ? (currentActiveMedia.id || currentActiveMedia.meta_id) : '';
                
                console.log("🔍 Autopilot Cache: Buscando streams cacheados para:", seedUrl);
                try {
                    const cacheRes = await fetch(`backend/get_cached_mirrors.php?contenido_id=${targetContenidoId}&episodio_id=${targetEpisodioId}&seed_url=${encodeURIComponent(seedUrl)}&t=${Date.now()}`);
                    const cacheData = await cacheRes.json();
                    window.isCheckingAutopilotCache = false;
                    
                    if (cacheData.status === 'success' && cacheData.mirrors && cacheData.mirrors.length > 0) {
                        console.log("🟢 Autopilot Cache HIT: Encontrados streams resueltos!", cacheData.mirrors);
                        
                        // Reconstruir los mirrors para la cola de reproducción
                        const cachedMirrors = cacheData.mirrors.map((m, idx) => {
                            let labelText = sourceQueue[currentSourceIndex].label;
                            if (idx > 0) {
                                labelText += ` (Opción ${idx + 1}: ${m.idioma} - ${m.servidor_nombre})`;
                            }
                            return {
                                url: m.resolved_url,
                                label: labelText,
                                isCached: true
                            };
                        });
                        
                        // Reemplazar la semilla en la cola de servidores con los mirrors cacheados
                        sourceQueue.splice(currentSourceIndex, 1, ...cachedMirrors);
                        
                        // Cargar el primer mirror directamente
                        loadSource(sourceQueue[currentSourceIndex].url);
                        return;
                    }
                } catch (err) {
                    console.warn("⚠️ Error consultando Autopilot Cache:", err);
                    window.isCheckingAutopilotCache = false;
                }
            }
        }

        const player = document.getElementById('videoPlayer');
        const embed  = document.getElementById('embedPlayer');
        const quarantineContainer = document.getElementById('iframePlayerContainer');

        // 🎯 DHARMA #31: GALIX SNIPER REFRESH BRIDGE
        // Cuando la URL usa el prefijo sniper:, le pedimos a la extensión GalixSniper
        // que abra el embed en un tab temporal, intercepte el m3u8 fresco via webRequest,
        // y nos lo devuelva para reproducirlo en el reproductor nativo de GalixMovie.
        
        // DHARMA Fix #34: PelisCalidad bloquea iframes y escuda su autoplay brutalmente.
        // Lo redirigimos a FÉNIX (extract.php) para que rompa el wrapper HTML y saque el iframe interno.
        if (source.startsWith('sniper:') && source.includes('peliscalidad.com')) {
            console.log("🔄 Redirigiendo PelisCalidad de SNIPER a FENIX para extracción profunda de su iframe...");
            source = source.replace('sniper:', 'extract:');
        }

        // Redirigir Sniper a Fénix en móviles ya que no soportan la extensión
        if (source.startsWith('sniper:')) {
            const isMobileOrTV = /iPhone|iPad|iPod|Android|Web0S|Tizen|SmartTV|Roku|AppleTV|NetCast|PlayStation|Xbox/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            if (isMobileOrTV) {
                console.log("📱 Dispositivo Móvil o Smart TV detectado. Redirigiendo Sniper a Fénix (servidor) para evitar timeout de Extensión.");
                source = source.replace('sniper:', 'extract:');
            }
        }

        if (source.startsWith('sniper:')) {
            const embedUrl = source.replace('sniper:', '');
            console.log("🎯 Sonda SNIPER: Solicitando token fresco para:", embedUrl);
            const headerLabel = document.getElementById('currentServerLabel');
            if (headerLabel) headerLabel.innerHTML += ' <span id="sniper-label" style="font-size:0.6rem; color:#f59e0b; animation:pulse 1s infinite;">⚡ SNIPER ACTIVO...</span>';

            try {
                // Intentar comunicación con la extensión GalixSniper via postMessage
                const sniperResult = await new Promise((resolve, reject) => {
                    const timeout = setTimeout(() => reject(new Error('Sniper timeout')), 10000); // 10s max (Optimizado y Quirúrgico)
                    // El Sniper Bridge escucha este evento desde background.js
                    window.addEventListener('galix-sniper-result', function handler(e) {
                        window.removeEventListener('galix-sniper-result', handler);
                        clearTimeout(timeout);
                        resolve(e.detail);
                    }, { once: true });
                    // Disparar la captura via GalixSniper (el content.js lo escucha)
                    window.dispatchEvent(new CustomEvent('galix-sniper-request', {
                        detail: { embedUrl: embedUrl, requestId: Date.now() }
                    }));
                });

                if (sniperResult && sniperResult.m3u8) {
                    console.log("✅ SNIPER: Token fresco obtenido:", sniperResult.m3u8);
                    
                    // Guardar en cache para autopiloto
                    const targetContenidoId = currentMovie ? currentMovie.id : '';
                    const targetEpisodioId = (currentMovie && currentMovie.tipo === 'series' && currentActiveMedia) ? (currentActiveMedia.id || currentActiveMedia.meta_id) : '';
                    const seedUrl = sourceQueue[currentSourceIndex].url;
                    
                    fetch('backend/save_cached_mirrors.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `contenido_id=${targetContenidoId}&episodio_id=${targetEpisodioId}&seed_url=${encodeURIComponent(seedUrl)}&resolved_url=${encodeURIComponent(sniperResult.m3u8)}&tipo_resolucion=hls&idioma=Latino&servidor_nombre=Directo%20(Sniper)&calidad=HD`
                    }).catch(err => console.warn("⚠️ Falló registrar cache de Sniper:", err));

                    source = sniperResult.m3u8;
                } else {
                    throw new Error('No m3u8 interceptado');
                }
            } catch (err) {
                console.warn("⚠️ SNIPER Bridge no disponible, fallback a FENIX (Server Sniper):", err.message);
                source = 'extract:' + embedUrl;
            } finally {
                const sl = document.getElementById('sniper-label');
                if (sl) sl.remove();
            }
        }

        // 🧠 DHARMA FIX #24: SOPORTE PARA EXTRACCIÓN DINÁMICA (yt-dlp)
        if (source.startsWith('extract:')) {

            const pageUrl = source.replace('extract:', '');
            console.log("🧬 Sonda FENIX: Iniciando extracción dinámica para:", pageUrl);
            
            // Mostrar indicador de "Extrayendo..."
            const headerLabel = document.getElementById('currentServerLabel');
            const originalLabel = headerLabel ? headerLabel.innerHTML : '';
            if (headerLabel) headerLabel.innerHTML += ' <span id="extracting-label" style="font-size:0.6rem; color:var(--accent); animation:pulse 1s infinite;">(RESOLVIENDO...)</span>';

            let forcedType = null;
            try {
                // Cache-busting para el extractor y paso de IDs para auto-cosecha (DHARMA #35)
                const targetContenidoId = currentMovie ? currentMovie.id : '';
                const targetEpisodioId = (currentMovie && currentMovie.tipo === 'series' && currentActiveMedia) ? (currentActiveMedia.id || currentActiveMedia.meta_id) : '';
                
                const res = await fetch(`backend/extract.php?url=${encodeURIComponent(pageUrl)}&contenido_id=${targetContenidoId}&episodio_id=${targetEpisodioId}&t=${Date.now()}`);
                const data = await res.json();
                if (data.status === 'success' && data.url) {
                    console.log("✅ Extracción exitosa:", data.url, "Tipo:", data.type);
                    
                    // Guardar el mirror resuelto en la cola para no re-extraerlo
                    sourceQueue[currentSourceIndex].url = data.url;
                    
                    source = data.url; // Reemplazar con el link real
                    forcedType = data.type; // Guardar el tipo (hls o embed)
                    window.isFenixExtractedToken = true; // 🚨 El token se generó en el Servidor (Fénix). Obliga al Proxy a actuar.

                    // 🧬 DHARMA Fix #35: Phoenix Multi-Mirror Redundancy
                    // Si el extractor devolvió mirrors adicionales (ej: Vimeus con opciones Latino/Castellano),
                    // los inyectamos dinámicamente en la cola justo después del servidor actual
                    // para que el usuario pueda saltar entre ellos usando el botón de redundancia (Siguiente Servidor)
                    if (data.mirrors && data.mirrors.length > 1) {
                        const currentLabel = sourceQueue[currentSourceIndex].label;
                        
                        // Filtrar mirrors para evitar duplicar bases conocidas
                        const existingBaseUrls = new Set(sourceQueue.map(s => s.url.split('?')[0].toLowerCase()));
                        
                        const newMirrors = [];
                        data.mirrors.forEach((m, idx) => {
                            if (idx === 0) return; // Omitir el primero ya que lo cargamos de inmediato
                            
                            const baseM = m.url.split('?')[0].toLowerCase();
                            if (!existingBaseUrls.has(baseM)) {
                                let mirrorUrl = m.url;
                                // 🧬 MEJORA MAESTRA M65: Anteponer 'extract:' a los mirrors escaneables para forzar su resolución a HLS nativo
                                if (mirrorUrl.includes('vimeos.zip') || mirrorUrl.includes('goodstream.one') || mirrorUrl.includes('vimeos.net')) {
                                    mirrorUrl = 'extract:' + mirrorUrl;
                                }
                                newMirrors.push({
                                    url: mirrorUrl,
                                    label: `${currentLabel} (Opción ${idx + 1}: ${m.lang} - ${m.server})`
                                });
                                existingBaseUrls.add(baseM);
                            }
                        });
                        
                        if (newMirrors.length > 0) {
                            sourceQueue.splice(currentSourceIndex + 1, 0, ...newMirrors);
                            console.log(`🧬 Sonda FENIX: Inyectados ${newMirrors.length} mirrors adicionales de Vimeus en la cola de redundancia.`);
                        }
                    }
                } else {
                    console.warn("⚠️ Fallo en extracción, servidor no soportado:", data.message);
                    setTimeout(() => window.tryNextSource(), 50);
                    return;
                }
            } catch (err) {
                console.error("❌ Error de red en extractor:", err);
                setTimeout(() => window.tryNextSource(), 50);
                return;
            } finally {
                // Limpiar label de cargando
                const label = document.getElementById('extracting-label');
                if (label) label.remove();
            }

            // Si el extractor determinó que es un Embed, nos saltamos la lógica de detección automática
            if (forcedType === 'embed') {
                window.isForcedEmbed = true;
            }
        }

        // Seguridad Extrema: Nunca dejar pasar el prefijo 'extract:' al resto del motor (DHARMA #25)
        if (typeof source === 'string' && source.indexOf('extract:') !== -1) {
            source = source.split('extract:').pop();
        }

        // DHARMA FIX #8: Purgar HLS antes de cualquier cambio
        // Si no se destruye, el motor viejo sigue cargando fragmentos, da error
        // y dispara el tryNextSource() saltándose el servidor actual.
        if (window.hls) {
            console.log("🧬 Telemetría: Purgando motor HLS previo para evitar saltos...");
            window.hls.destroy();
            window.hls = null;
        }

        // ── INYECCIÓN DE SUBTÍTULOS ─────────────────────────────────────────
        const tracks = player.querySelectorAll('track');
        tracks.forEach(t => t.remove());

        if (currentActiveMedia && currentActiveMedia.subtitulos_path) {
            let subUrl = currentActiveMedia.subtitulos_path;
            // Normalizar ruta si es Windows/Absoluta
            if (subUrl.includes(':\\') || subUrl.includes('GalixMovie')) {
                if (subUrl.includes('media')) {
                    subUrl = 'media' + subUrl.split('media')[1].replace(/\\/g, '/');
                }
            }
            
            const track = document.createElement('track');
            track.kind = 'captions';
            track.label = 'Español';
            track.srclang = 'es';
            track.src = `backend/subtitles.php?file=${encodeURIComponent(subUrl)}`;
            track.default = true;
            player.appendChild(track);
            console.log("📝 Sonda FENIX: Subtítulo inyectado vía conversor:", subUrl);
            
            // Forzar actualización en Plyr
            if (plyrPlayer) {
                setTimeout(() => {
                    plyrPlayer.source = plyrPlayer.source;
                }, 500);
            }
        }

        player.src = '';
        player.style.display = 'none';
        embed.src = '';
        embed.style.display = 'none';
        if(quarantineContainer) {
            quarantineContainer.innerHTML = '';
            quarantineContainer.style.display = 'none';
        }

        const isRawIframe = source.includes('<iframe');
        // 🎯 FIX: Excluir m3u8/.txt de seekplayer — son streams HLS, no embeds
        const isHLSFromEmbed = source.includes('.m3u8') || source.includes('.txt');
        // 🧬 DHARMA #30: Lista universal de proveedores de embed con auto-refresco de token
        const isEmbedLink = window.isForcedEmbed || (!isHLSFromEmbed && (
            source.includes('/e/')         ||
            source.includes('embed')       ||
            source.includes('megapelisenhd') ||
            source.includes('rpmstream')   ||
            source.includes('seekplayer')  ||
            source.includes('terabox')     ||
            source.includes('vimeos.net')  ||    // embed-XXXX.html → token auto-refresh
            source.includes('goodstream.one') || // embed-XXXX.html → token auto-refresh
            source.includes('hlswish.com') ||    // /e/ID → token auto-refresh
            source.includes('voe.sx')      ||    // /e/ID → token auto-refresh
            source.includes('vimeus.com')        // API source → resolved by extract.php
        ));
        const isStrictCDN = source.includes('vimeos') || source.includes('cloudwindow-route') || source.includes('hlswish');

        let finalUrl = source;

        
        // 📦 AUTODETECCIÓN Y CONVERSIÓN DE TERABOX
        if (finalUrl.includes('terabox') && finalUrl.includes('/s/')) {
            const parts = finalUrl.split('/s/');
            if (parts[1]) {
                const surl = parts[1].startsWith('1') ? parts[1].substring(1) : parts[1];
                finalUrl = `https://www.terabox.com/sharing/embed?surl=${surl.split('?')[0]}`;
                console.log("📦 Sonda FENIX: Terabox detectado. Convirtiendo a Embed:", finalUrl);
            }
        }

        // DHARMA FIX #20: Normalizar rutas absolutas de Windows a relativas Web
        if (finalUrl.includes(':\\') || finalUrl.includes('GalixMovie')) {
            const original = finalUrl;
            if (finalUrl.includes('media')) {
                finalUrl = 'media' + finalUrl.split('media')[1].replace(/\\/g, '/');
                console.log("📂 Sonda FENIX: Ruta Normalizada:", { original, final: finalUrl });
            }
        }

        const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        
        // 🚀 DHARMA #42: Proxy Inteligente basado en Pertenencia de Token
        // Si el token pertenece al CDN de Goodstream/Vimeos y fue extraído manualmente (Cliente) o por Sniper (Cliente),
        // DEBEMOS saltarnos el proxy para que el CDN vea la IP del cliente y no la del servidor.
        // Si fue extraído por Fénix, DEBEMOS usar el proxy para que vea la IP del servidor.
        const isIpBoundCDN = finalUrl.includes('goodstream.one') || finalUrl.includes('vimeos.') || finalUrl.includes('hlswish.com');
        let forceProxy = true;
        if (isIpBoundCDN && !window.isFenixExtractedToken) {
            forceProxy = false;
            console.log("🛡️ Bypass de Proxy Autorizado: El token pertenece a la IP del Cliente.");
        }

        if (finalUrl.startsWith('http') && !finalUrl.includes(window.location.hostname) && !isRawIframe && !isEmbedLink && !finalUrl.includes('cloudwindow-route.com') && !finalUrl.includes('medixiru.com') && !finalUrl.includes('callistanise.com') && forceProxy) {
            console.log("🛡️ Sonda Telemetría: Usando Proxy para:", finalUrl);
            // DHARMA FIX #22: Pasar el Referer de la página de origen para evadir hotlink protection
            const pageRef = document.referrer || window.location.href;
            finalUrl = `backend/proxy.php?url=${encodeURIComponent(finalUrl)}&ref=${encodeURIComponent(pageRef)}&cip=${clientIp}`;
        } else {
            // DHARMA FIX #17: Sanear rutas locales (Espacios, Comillas, etc)
            if (!finalUrl.startsWith('http')) {
                const isMKV = finalUrl.toLowerCase().includes('.mkv');
                
                if (isMKV) {
                    console.log("🧬 FENIX: Transmuxeando MKV al vuelo via FFmpeg...");
                    // Codificamos la ruta pero NO la URL completa para el script
                    finalUrl = `backend/mkv_stream.php?file=${encodeURIComponent(finalUrl)}`;
                } else {
                    finalUrl = encodeURI(finalUrl);
                }
            }
            console.log("🚀 Sonda Telemetría: Carga Directa para:", finalUrl);
        }

        const isObfuscatedHLS = finalUrl.includes('.txt') && (finalUrl.includes('master') || finalUrl.includes('playlist'));
        
        if (isRawIframe || isEmbedLink) {
            window.isIframeActive = true; // DHARMA: Flag para desactivar Phantom Mode en iframes
            // Ocultar Plyr para dejar espacio al Iframe
            if (plyrPlayer && plyrPlayer.elements && plyrPlayer.elements.container) {
                plyrPlayer.elements.container.style.display = 'none';
            }
            player.style.display = 'none';

            if (isRawIframe) {
                quarantineContainer.innerHTML = source;
                const injectedIframe = quarantineContainer.querySelector('iframe');
                if (injectedIframe) {
                    injectedIframe.style.width = '100%';
                    injectedIframe.style.height = '100%';
                    injectedIframe.referrerPolicy = 'no-referrer';
                }
                quarantineContainer.style.display = 'block';
            } else {
                var iframeSrc = finalUrl;
                console.log('🎬 GalixShield: Carga directa (SPA-safe):', iframeSrc);

                quarantineContainer.style.display = 'block';
                var iframeHtml  = '<iframe id="targetIframe" src="' + iframeSrc + '" ';
                iframeHtml += 'style="width:100%;height:100%;border:none;display:block;background:#000;" ';
                iframeHtml += 'allow="autoplay;fullscreen;encrypted-media;picture-in-picture;web-share" ';
                iframeHtml += 'allowfullscreen ';
                iframeHtml += 'referrerpolicy="no-referrer" loading="eager"></iframe>';
                
                quarantineContainer.innerHTML = iframeHtml;
            }
        } else {
            window.isIframeActive = false; // DHARMA: Reactivar Phantom Mode para videos nativos
            // Mostrar Plyr y ocultar Iframes
            if (plyrPlayer && plyrPlayer.elements && plyrPlayer.elements.container) {
                plyrPlayer.elements.container.style.display = 'block';
            }
            player.style.display = 'block';
            
            // 🛑 FIX: Evitar que MP4/WebM se envíen al motor HLS aunque pasen por proxy
            const isNativeVideo = source.toLowerCase().includes('.mp4') || source.toLowerCase().includes('.webm') || source.toLowerCase().includes('.mkv');
            
            if (Hls.isSupported() && !isNativeVideo && (finalUrl.includes('.m3u8') || finalUrl.includes('proxy.php') || isObfuscatedHLS)) {
                const hls = new Hls({
                        enableWorker: true,
                        lowLatencyMode: true,
                        backBufferLength: 90,
                        manifestLoadingMaxRetry: 5,
                        levelLoadingMaxRetry: 5
                    });
                    hls.loadSource(finalUrl);
                hls.attachMedia(player);
                window.hls = hls;

                hls.on(Hls.Events.AUDIO_TRACKS_UPDATED, function(event, data) {
                    const tracks = data.audioTracks;
                    if (tracks.length > 1) {
                        document.getElementById('btnAudio').style.display = 'block';
                        const preferred = tracks.findIndex(t => {
                            const name = (t.name || "").toLowerCase();
                            const lang = (t.lang || "").toLowerCase();
                            return name.includes('lat') || name.includes('spa') || lang.includes('es');
                        });
                        if (preferred !== -1) hls.audioTrack = preferred;
                    }
                });

                hls.on(Hls.Events.ERROR, function (event, data) {
                    if (data.fatal) {
                        console.warn("🛑 Sonda HLS - Error Crítico detectado:", data.details);
                        
                        // 🔄 FALLBACK INTELIGENTE v12.3: Si falló la Carga Directa (CORS/SSL/Red),
                        // re-intentamos enrutar la petición a través del proxy local antes de rendirnos.
                        const currentSource = sourceQueue[currentSourceIndex];
                        if (currentSource && currentSource.url === source && !finalUrl.includes('proxy.php')) {
                            console.log("🔄 Fallback Inteligente: Carga Directa falló. Re-intentando con Proxy para:", source);
                            const pageRef = document.referrer || window.location.href;
                            const proxiedUrl = `backend/proxy.php?url=${encodeURIComponent(source)}&ref=${encodeURIComponent(pageRef)}&cip=${clientIp}`;
                            
                            hls.destroy();
                            const fallbackHls = new Hls({
                                enableWorker: true,
                                lowLatencyMode: true,
                                backBufferLength: 90,
                                manifestLoadingMaxRetry: 5,
                                levelLoadingMaxRetry: 5
                             });
                             fallbackHls.on(Hls.Events.ERROR, function(e, d) {
                                 if (d.fatal) {
                                     console.error("🛑 Sonda HLS - Error Crítico en Proxy Fallback:", d.details);
                                     tryNextSource();
                                 }
                             });
                             fallbackHls.on(Hls.Events.AUDIO_TRACKS_UPDATED, function(e, d) {
                                 const tracks = d.audioTracks;
                                 if (tracks.length > 1) {
                                     document.getElementById('btnAudio').style.display = 'block';
                                     const preferred = tracks.findIndex(t => {
                                         const name = (t.name || "").toLowerCase();
                                         const lang = (t.lang || "").toLowerCase();
                                         return name.includes('lat') || name.includes('spa') || lang.includes('es');
                                     });
                                     if (preferred !== -1) fallbackHls.audioTrack = preferred;
                                 }
                             });
                             fallbackHls.on(Hls.Events.MANIFEST_PARSED, () => {
                                 consecutiveFailuresCount = 0; // Resetear fallos al reproducir
                                 if (plyrPlayer && !plyrPlayer.playing) plyrPlayer.play().catch(() => {});
                             });
                             
                             fallbackHls.loadSource(proxiedUrl);
                             fallbackHls.attachMedia(player);
                             window.hls = fallbackHls;
                             return;
                         }

                        const headerLabel = document.getElementById('currentServerLabel');
                        if (headerLabel) {
                            const currentSource = sourceQueue[currentSourceIndex];
                            const isLocal = currentSource && !currentSource.url.startsWith('http');
                            const icon = isLocal ? 'server-outline' : 'globe-outline';
                            const color = '#ef4444'; // Rojo para error
                            
                            headerLabel.innerHTML = `
                                <div style="display:flex; align-items:center; gap:6px; color:${color}; font-weight:700;">
                                    <ion-icon name="${icon}" style="font-size:0.9rem;"></ion-icon>
                                    <span>${currentSource?.label || 'Server'}</span>
                                    <span style="font-size:0.55rem; background:${color}22; padding:2px 6px; border-radius:4px; margin-left:4px;">REINTENTANDO...</span>
                                </div>
                            `;
                        }
                        tryNextSource();
                    }
                });

                hls.on(Hls.Events.MANIFEST_PARSED, () => {
                    consecutiveFailuresCount = 0; // Resetear fallos al reproducir
                    if (plyrPlayer && !plyrPlayer.playing) plyrPlayer.play().catch(() => {});
                });
            } else {
                // 🛑 iOS Safari/iPad Fallback: Capturar errores de reproducción nativa HLS (DHARMA #42)
                player.addEventListener('error', function nativeErrorHandler(e) {
                    player.removeEventListener('error', nativeErrorHandler);
                    console.warn("🛑 Sonda HLS - Error en Reproductor Nativo de iOS/iPadOS:", player.error ? player.error.message : "Error desconocido");
                    
                    // Si falló la carga directa nativa y no está usando proxy, reintentamos con proxy
                    const currentSource = sourceQueue[currentSourceIndex];
                    if (currentSource && currentSource.url === source && !finalUrl.includes('proxy.php')) {
                        console.log("🔄 Fallback con Proxy para iOS/iPadOS:", source);
                        const pageRef = document.referrer || window.location.href;
                        const proxiedUrl = `backend/proxy.php?url=${encodeURIComponent(source)}&ref=${encodeURIComponent(pageRef)}&cip=${clientIp}`;
                        player.src = proxiedUrl;
                        if (plyrPlayer && !plyrPlayer.playing) plyrPlayer.play().catch(() => {});
                    } else {
                        tryNextSource();
                    }
                }, { once: true });

                // Resetear fallos al iniciar reproducción de forma nativa en iOS/iPad
                player.addEventListener('playing', function resetFailuresOnPlay() {
                    consecutiveFailuresCount = 0;
                    player.removeEventListener('playing', resetFailuresOnPlay);
                });

                player.src = finalUrl;
                if (plyrPlayer && !plyrPlayer.playing) plyrPlayer.play().catch(() => {});
            }
        }
    }

    window.closePlayer = () => {
        if (document.activeElement) document.activeElement.blur(); 
        
        // Purgar y silenciar la intro cinemática
        if (window.currentIntroAudio) {
            try { window.currentIntroAudio.pause(); } catch(e){}
            window.currentIntroAudio = null;
        }
        if (window.currentIntroTimeout) {
            clearTimeout(window.currentIntroTimeout);
            window.currentIntroTimeout = null;
        }
        const introOverlay = document.getElementById('playerCinematicIntro');
        if (introOverlay) {
            introOverlay.classList.remove('active');
            introOverlay.style.display = 'none';
        }

        window.isIframeActive = false; // DHARMA: Resetear estado
        console.log("🛑 Sonda Telemetría: Cerrando reproductor y purgando fuentes...");
        
        clearInterval(progressTimer);
        
        // 1. Detener Plyr
        if (plyrPlayer) {
            plyrPlayer.pause();
            plyrPlayer.stop(); // Forzar parada total
            if (plyrPlayer.elements && plyrPlayer.elements.container) {
                plyrPlayer.elements.container.style.display = 'block';
            }
        }
        
        // 2. Destruir HLS (Unificado)
        if (window.hls) {
            console.log("🧬 Telemetría: Destruyendo motor HLS...");
            window.hls.detachMedia();
            window.hls.destroy();
            window.hls = null;
        }

        // 3. Limpieza física de elementos
        const videoPlayer = document.getElementById('videoPlayer');
        const embedPlayer = document.getElementById('embedPlayer');
        const quarantine  = document.getElementById('iframePlayerContainer');

        if (videoPlayer) {
            videoPlayer.pause();
            videoPlayer.removeAttribute('src'); // Eliminar atributo para detener carga
            videoPlayer.load(); // Forzar reset
        }
        if (embedPlayer) embedPlayer.src = '';
        if (quarantine)  quarantine.innerHTML = '';

        if (typeof Swal !== 'undefined' && Swal.isVisible()) Swal.close();
        
        document.getElementById('btnAudio').style.display = 'none';
        document.getElementById('playerModal').style.display = 'none';
        document.body.style.overflow = 'auto';
        document.body.classList.remove('immersive-active');
        document.body.style.overflow = ''; // 🛡️ Liberar scroll v10.0
        
        loadContinueWatching();
        console.log("✅ Telemetría: Reproductor purgado y silenciado al 100%.");
    };

    window.toggleFavorite = async () => {
        if (!currentMovie?.id) return;
        const icon = document.getElementById('heartIcon');
        try {
            const body = new FormData();
            body.append('contenido_id', currentMovie.id);
            const res  = await fetch('backend/toggle_favorite.php', { method: 'POST', body });
            const data = await res.json();
            if (data.action === 'added') {
                icon.setAttribute('name', 'heart');
                icon.style.color = 'var(--accent)';
            } else {
                icon.setAttribute('name', 'heart-outline');
                icon.style.color = '';
            }
        } catch (err) { console.error("Error toggling favorito:", err); }
    };

    window.downloadMovie = () => {
        if (!currentMovie) return;
        const source = currentMovie.archivo_path || '';
        
        if (source.includes('.m3u8') || source.includes('/e/') || source.includes('/embed/')) {
            Swal.fire({
                title: 'Descarga Restringida 🛡️',
                text: 'Esta película utiliza tecnología de Streaming Fragmentado (HLS/Embed). No es un archivo único (MP4), sino múltiples fragmentos de video encriptados. Para descargarla, se requiere una extensión de navegador como "Video DownloadHelper".',
                icon: 'warning',
                background: '#0f172a',
                color: '#f8fafc',
                confirmButtonColor: 'var(--accent)',
                confirmButtonText: 'Entendido',
                target: '.plyr' // Mantener dentro del fullscreen
            });
        } else {
            Swal.fire({
                title: 'Iniciando Descarga',
                text: 'Preparando archivo original...',
                icon: 'info',
                background: '#0f172a',
                color: '#f8fafc',
                timer: 2000,
                showConfirmButton: false,
                target: '.plyr'
            });
            const a = document.createElement('a');
            a.href = source || `backend/stream.php?id=${currentMovie.id}`;
            a.download = `${currentMovie.titulo}.mp4`;
            a.target = '_blank';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
        if (document.activeElement) document.activeElement.blur();
    };

    window.toggleAudioMenu = () => {
        if (!window.currentHls) return;
        const tracks = window.currentHls.audioTracks;
        const options = {};
        tracks.forEach((t, i) => {
            options[i] = t.name || `Pista ${i + 1}`;
        });

        // Eliminar foco del botón para evitar advertencias ARIA de SweetAlert2
        if (document.activeElement) document.activeElement.blur();

        setTimeout(() => {
            Swal.fire({
                title: '<span style="font-size:1rem; font-weight:600; color:#cbd5e1;">Pista de Audio</span>',
                input: 'select',
                inputOptions: options,
                inputValue: window.currentHls.audioTrack,
                showCancelButton: false,
                position: 'top-end',
                backdrop: false,
                target: '.plyr', // ¡VITAL! Adjuntar dentro de Plyr para sobrevivir al Fullscreen
                customClass: {
                    popup: 'netflix-audio-menu',
                    input: 'netflix-audio-select',
                    confirmButton: 'netflix-audio-btn'
                },
                background: 'rgba(2, 6, 23, 0.75)',
                color: '#fff',
                confirmButtonColor: 'var(--accent)',
                confirmButtonText: 'Aplicar',
                returnFocus: false
            }).then((result) => {
                if (result.isConfirmed && window.hls) {
                    window.hls.audioTrack = parseInt(result.value);
                }
            });
        }, 50);
    };

    // 🚀 MOTOR DE VISIBILIDAD INTELIGENTE (v9.0)
    function initHeaderAutoHide() {
        const header = document.getElementById('playerHeader');
        const modal = document.getElementById('playerModal');
        if (!header || !modal) return;

        const show = () => {
            header.style.opacity = '1';
            header.style.transform = 'translateY(0)';
            header.style.transition = 'all 0.4s ease';
            clearTimeout(hideHeaderTimer);
            hideHeaderTimer = setTimeout(hide, 4000); // Ocultar tras 4s
        };

        const hide = () => {
            if (window.isIframeActive) return; // DHARMA: Mantener botones visibles en iframes terceros
            if (header.matches(':hover')) return; // No ocultar si el puntero está encima
            header.style.opacity = '0';
            header.style.transform = 'translateY(-20px)';
        };

        // Detectar movimiento en el modal y toques en móviles (DHARMA #41)
        modal.addEventListener('mousemove', show);
        modal.addEventListener('touchstart', show, { passive: true });
        modal.addEventListener('click', show);
        
        header.addEventListener('mouseenter', () => {
            clearTimeout(hideHeaderTimer);
            header.style.opacity = '1';
            header.style.transform = 'translateY(0)';
        });

        show(); // Inicializar visible
    }

    // 🛰️ PUENTE DE COMUNICACIÓN (v9.1)
    // window.tryNextSource ya está definido arriba como global
    window.tryNextAudio = () => {
        if (typeof window.toggleAudioMenu === 'function') {
            window.toggleAudioMenu();
        }
    };

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closePlayer();
    });
});
