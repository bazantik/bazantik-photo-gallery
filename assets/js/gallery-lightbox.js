/**
 * Bazantik Photo Gallery - Produkční Lightbox Engine
 * Optimalizováno pro WEDOS NoLimit a Apple Safari (macOS M4 + iOS Retina XDR)
 *
 * Funkce:
 * - 100% stabilní Pixel-to-Cursor Zoom (kolečko myši s rAF dojezdem, Apple pinch-to-zoom)
 * - Plynulý filmový Cross-Dissolve bez černých záblesků
 * - Prediktivní GPU přednačítání 2 snímky dopředu (+1, +2, -1)
 * - Striktní pravidlo "Never Downgrade" pro plnou ostrost originálu
 */
(function () {
    'use strict';
    
    // Přeložené texty dodává PHP (bazantikConfig.strings); anglický záložní text se použije, když klíč chybí
    function t(key, fallback) {
        const strings = window.bazantikConfig && window.bazantikConfig.strings;
        return (strings && strings[key]) || fallback;
    }
    
    // Obrana do hloubky: adresy z data-media se používají jen s bezpečným schématem (http, https, relativní, blob, data:image/video)
    function safeUrl(url) {
        if (typeof url !== 'string') return '';
        const u = url.trim();
        const scheme = /^([a-z][a-z0-9+.\-]*):/i.exec(u);
        if (!scheme) return u;
        const name = scheme[1].toLowerCase();
        if (name === 'http' || name === 'https' || name === 'blob') return u;
        if (name === 'data' && /^data:(image|video)\//i.test(u)) return u;
        return '';
    }
    
    function sanitizeMedia(media) {
        if (!media || typeof media !== 'object') return {};
        if ('original' in media) media.original = safeUrl(media.original);
        if ('poster' in media) media.poster = safeUrl(media.poster);
        if (media.sources && typeof media.sources === 'object') {
            Object.keys(media.sources).forEach(k => {
                if (media.sources[k] && typeof media.sources[k] === 'object') media.sources[k].url = safeUrl(media.sources[k].url);
            });
        }
        return media;
    }
    
    function initBazantikGallery() {
        const grid = document.querySelector('.bazantik-justified-grid');
        if (!grid) return;
        grid.classList.remove('bazantik-nojs'); // záložní rozložení bez JavaScriptu už není potřeba
        
        const allItems = Array.from(grid.querySelectorAll('.bazantik-grid-item')).map(item => {
            try {
                return sanitizeMedia(JSON.parse(item.getAttribute('data-media')));
            } catch (e) {
                return {};
            }
        });
        
        // ==========================================
        // 1. JUSTIFIED GRID ALGORITHM
        // ==========================================
        // Lightbox pracuje jen s hotovými fotkami; nehotové (rozostřené) dlaždice jsou pouze v mřížce
        const items = allItems.filter(m => m && m.type !== 'pending');
        
        function layoutJustifiedGrid() {
            const containerWidth = Math.floor(grid.getBoundingClientRect().width) || grid.clientWidth;
            if (containerWidth <= 0) return;
            
            const isMobile = window.innerWidth <= 768;
            const gap = 8;
            
            let targetHeight = 180;
            if (isMobile) {
                targetHeight = 150;
            } else if (containerWidth > 1550) {
                targetHeight = 220;
            } else {
                targetHeight = Math.round((containerWidth - 24) / 6.2);
                if (targetHeight < 160) targetHeight = 160;
                if (targetHeight > 200) targetHeight = 200;
            }
            
            const gridItems = Array.from(grid.querySelectorAll('.bazantik-grid-item'));
            if (gridItems.length === 0) return;
            
            let currentRow = [];
            let currentRowWidth = 0;
            
            gridItems.forEach((item, index) => {
                const data = allItems[index] || {};
                const w = data.width || 3;
                const h = data.height || 2;
                const aspect = w / h;
                
                const itemWidthAtTarget = targetHeight * aspect;
                currentRow.push({ item, aspect, width: itemWidthAtTarget });
                currentRowWidth += itemWidthAtTarget;
                
                const totalGaps = (currentRow.length - 1) * gap;
                
                if (currentRowWidth + totalGaps >= containerWidth) {
                    const totalAspect = currentRow.reduce((sum, r) => sum + r.aspect, 0);
                    const rowHeight = (containerWidth - totalGaps) / totalAspect;
                    
                    // Zbytek po zaokrouhlení se rozdělí po pixelu mezi dlaždice, aby řádek vyplnil šířku přesně.
                    // Jinak je obsah o pár pixelů užší než kontejner a kontejner, jehož šířka se řídí obsahem
                    // (např. v Avadě), by se při každém přepočtu dál zužoval (poskakování dlaždic).
                    const widths = currentRow.map(r => Math.floor(rowHeight * r.aspect));
                    let remainder = (containerWidth - totalGaps) - widths.reduce((sum, v) => sum + v, 0);
                    for (let k = 0; remainder > 0 && k < widths.length; k++, remainder--) widths[k]++;
                    currentRow.forEach((r, k) => {
                        r.item.style.width = `${widths[k]}px`;
                        r.item.style.height = `${Math.floor(rowHeight)}px`;
                    });
                    
                    currentRow = [];
                    currentRowWidth = 0;
                }
            });
            
            if (currentRow.length > 0) {
                currentRow.forEach(r => {
                    const finalW = Math.floor(targetHeight * r.aspect);
                    r.item.style.width = `${finalW}px`;
                    r.item.style.height = `${targetHeight}px`;
                });
            }
        }
        
        layoutJustifiedGrid();
        window.addEventListener('load', layoutJustifiedGrid);
        
        // ==========================================
        // 2. PREFERENCE DIVÁKA (localStorage)
        // ==========================================
        // Zařízení ovládané jen dotykem (telefon, tablet): šipky se ve výchozím stavu skryjí, listuje se swipem
        const isTouchOnly = !!(window.matchMedia && window.matchMedia('(pointer: coarse) and (hover: none)').matches);
        
        // Záloha pro prohlížeče, které dotykové zařízení nepoznají z (pointer: coarse) a (hover: none): dotyk bez jemného ukazovátka
        const hasFinePointer = !!(window.matchMedia && window.matchMedia('(any-pointer: fine)').matches);
        const touchFirst = isTouchOnly || ((navigator.maxTouchPoints || 0) > 0 && !hasFinePointer);
        
        const DEFAULT_SETTINGS = {
            speed: 4,
            loop: true,
            arrows: !isTouchOnly,
            doubleTapZoom: touchFirst,
            autoHide: false,
            autoHideDelay: 3,
            translucent: true,
            backdropOpacity: 95,
            blur: false,
            blurPx: 5,
            toolbarCollapsed: false,
            sidebarOpen: false,
        };
        
        function loadSettings() {
            try {
                const raw = localStorage.getItem('bazantik_viewer_settings');
                if (raw) {
                    return Object.assign({}, DEFAULT_SETTINGS, JSON.parse(raw));
                }
            } catch (e) {}
            return Object.assign({}, DEFAULT_SETTINGS);
        }
        
        function saveSettings(settings) {
            // Ukládají se jen hodnoty odlišné od výchozích, aby se nezmrazily výchozí hodnoty podle typu zařízení
            const changed = {};
            Object.keys(settings).forEach((key) => {
                if (!(key in DEFAULT_SETTINGS) || settings[key] !== DEFAULT_SETTINGS[key]) {
                    changed[key] = settings[key];
                }
            });
            try {
                localStorage.setItem('bazantik_viewer_settings', JSON.stringify(changed));
            } catch (e) {}
        }
        
        const viewerSettings = loadSettings();
        
        // ==========================================
        // 3. LIGHTBOX ENGINE & ELEMENTY
        // ==========================================
        let currentIndex = 0;
        let slideshowTimer = null;
        let isSlideshowActive = false;
        let leafletMap = null;
        let leafletMarker = null;
        let mapToken = 0; // zneplatní opožděnou inicializaci mapy po přepnutí snímku nebo zavření lightboxu
        
        const domCache = new Map();
        
        // Dynamický limit LRU cache: na iPhonech šetříme GPU RAM (max 2 textury), na PC držíme 6
        function getMaxCacheSize() {
            return isMobileDevice() ? 4 : 6;
        }
        
        const TIER_WEIGHTS = {
            'medium': 1,
            'medium_large': 2,
            'large': 3,
            '1536x1536': 4,
            '2048x2048': 5,
            'bazantik_4k': 6,
            'full': 7
        };
        
        function putInLruCache(key, element) {
            const limit = getMaxCacheSize();
            while (domCache.size >= limit && !domCache.has(key)) {
                const oldestKey = domCache.keys().next().value;
                const oldEl = domCache.get(oldestKey);
                if (oldEl && oldEl.parentNode !== holder) {
                    oldEl.src = '';
                }
                domCache.delete(oldestKey);
            }
            domCache.set(key, element);
        }
        
        let isZoomed = false;
        let currentScale = 1;
        let translateX = 0;
        let translateY = 0;
        let currentRenderWidth = 0;
        let currentRenderHeight = 0;
        let currentLoadedTier = '';
        
        let wheelAnimId = null;
        let animTargetScale = 1;
        let animTargetX = 0;
        let animTargetY = 0;
        
        let isPointerDown = false;
        let startPointerX = 0;
        let startPointerY = 0;
        let initialTranslateX = 0;
        let initialTranslateY = 0;
        let totalDragDistance = 0;
        let startPointerTime = 0;
        let lastTapTime = 0;
        let lastTapX = 0;
        let lastTapY = 0;
        let isPullingDown = false;
        
        let pinchActive = false;
        let pinchDidHappen = false;
        let pinchStartDist = 0;
        let pinchStartScale = 1;
        let pinchPointX = 0;
        let pinchPointY = 0;
        
        let activeLoadToken = 0;
        let activeTransitionToken = 0;
        let spinnerTimeout = null;
        
        const modal = document.getElementById('bazantik-lightbox');
        const backdrop = modal ? modal.querySelector('.bazantik-lightbox-backdrop') : null;
        const holder = document.getElementById('bazantik-media-holder');
        const viewport = document.getElementById('bazantik-viewport');
        const spinner = document.getElementById('bazantik-spinner');
        const sidebar = document.getElementById('bazantik-sidebar');
        const sidebarTitle = document.getElementById('bazantik-sidebar-title');
        
        const panelInfo = document.getElementById('bazantik-panel-info');
        const panelSettings = document.getElementById('bazantik-panel-settings');
        const exifContainer = document.getElementById('bazantik-exif-container');
        const mapContainer = document.getElementById('bazantik-map-container');
        const mapConsentBox = document.getElementById('bazantik-map-consent');
        const mapLoadButton = document.getElementById('bazantik-map-load');
        const mapElement = document.getElementById('bazantik-leaflet-map');
        const mapRevokeButton = document.getElementById('bazantik-map-revoke');
        // Souhlas s načtením podkladu z třetí strany se pamatuje v prohlížeči (localStorage, na server se neodesílá)
        let mapConsent = false;
        try {
            mapConsent = localStorage.getItem('bazantik_map_consent') === '1';
        } catch (e) { /* úložiště nemusí být dostupné (soukromý režim) */ }
        let pendingGps = null;
        
        const speedInput = document.getElementById('bazantik-setting-speed');
        const speedVal = document.getElementById('bazantik-speed-val');
        const loopInput = document.getElementById('bazantik-setting-loop');
        const arrowsInput = document.getElementById('bazantik-setting-arrows');
        const autoHideInput = document.getElementById('bazantik-setting-autohide');
        const autoHideDelayInput = document.getElementById('bazantik-setting-autohide-delay');
        const autoHideVal = document.getElementById('bazantik-autohide-val');
        const rowAutoHideDelay = document.getElementById('bazantik-row-autohide-delay');
        const translucentInput = document.getElementById('bazantik-setting-translucent');
        const backdropInput = document.getElementById('bazantik-setting-backdrop');
        const backdropVal = document.getElementById('bazantik-backdrop-val');
        const rowBackdrop = document.getElementById('bazantik-row-backdrop');
        const blurInput = document.getElementById('bazantik-setting-blur');
        const blurAmountInput = document.getElementById('bazantik-setting-blur-amount');
        const blurVal = document.getElementById('bazantik-blur-val');
        const rowBlurToggle = document.getElementById('bazantik-row-blur-toggle');
        const rowBlurAmount = document.getElementById('bazantik-row-blur-amount');
        const zoomOriginalInput = document.getElementById('bazantik-setting-zoom-original');
        const doubleTapInput = document.getElementById('bazantik-setting-double-tap');
        const rowZoomSource = document.getElementById('bazantik-row-zoom-source');
        
        const btnClose = document.getElementById('bazantik-btn-close');
        let lastFocusedElement = null; // prvek, který měl fokus před otevřením lightboxu
        const btnPrev = document.getElementById('bazantik-btn-prev');
        const btnNext = document.getElementById('bazantik-btn-next');
        const btnPlay = document.getElementById('bazantik-btn-play');
        const btnInfo = document.getElementById('bazantik-btn-info');
        const btnFullscreen = document.getElementById('bazantik-btn-fullscreen');
        const iconFs = document.getElementById('bazantik-icon-fs');
        const btnDownload = document.getElementById('bazantik-btn-download');
        
        // Detekce podpory Fullscreen API (na iPhonech v Safari se tlačítko automaticky skryje)
        const isFullscreenSupported = Boolean(
                                              document.fullscreenEnabled ||
                                              document.webkitFullscreenEnabled ||
                                              (document.documentElement && (document.documentElement.requestFullscreen || document.documentElement.webkitRequestFullscreen))
                                              );
        
        if (btnFullscreen && !isFullscreenSupported) {
            btnFullscreen.style.setProperty('display', 'none', 'important');
            if (modal) modal.classList.add('bazantik-no-fullscreen');
        }
        
        const tabInfo = document.getElementById('bazantik-tab-info');
        const tabSettings = document.getElementById('bazantik-tab-settings');
        
        const topToolbar = document.getElementById('bazantik-top-toolbar');
        const btnCollapse = document.getElementById('bazantik-btn-collapse');
        const iconCollapse = document.getElementById('bazantik-icon-collapse');
        
        function isMobileDevice() {
            return window.innerWidth <= 768;
        }
        
        // Zdroj pro zoom na mobilu: výchozí hodnotu určuje album (data-mobile-zoom), Šetřič dat v prohlížeči ji sníží na 4K
        // a vlastní volba návštěvníka (localStorage) má přednost před oběma. Na počítači se vždy používá originál.
        function mobileZoomOriginal() {
            if (typeof viewerSettings.zoomOriginal === 'boolean') {
                return viewerSettings.zoomOriginal;
            }
            const conn = navigator.connection;
            if (conn && conn.saveData) {
                return false;
            }
            return grid && grid.dataset.mobileZoom === 'original';
        }
        
        // Přepnutí na originál při už přiblížené fotce: kvalitnější zdroj se načte až po zavření panelu, aby se nestahoval zbytečně při zkoušení volby
        let pendingZoomUpgrade = false;
        
        function applyPendingZoomUpgrade() {
            if (!pendingZoomUpgrade) return;
            pendingZoomUpgrade = false;
            const media = items[currentIndex];
            const img = holder ? holder.querySelector('img') : null;
            if (!isZoomed || !media || media.type === 'video' || !img) return;
            const target = getZoomTarget(media);
            if (target.key === 'full' && currentLoadedTier !== 'full' && target.url && img.src !== target.url) {
                smoothSwapImageSource(img, target.url, target.key);
            }
        }
        
        function getZoomTarget(media) {
            if (isMobileDevice() && !mobileZoomOriginal()) {
                const s4k = media && media.sources && media.sources.bazantik_4k;
                if (s4k && s4k.url) {
                    return { url: s4k.url, key: 'bazantik_4k' };
                }
            }
            return { url: (media && media.original) || '', key: 'full' };
        }
        
        function getTargetViewportDimensions(overrideSidebarOpen = null) {
            const isMob = isMobileDevice();
            const totalW = (modal && modal.clientWidth > 0) ? modal.clientWidth : window.innerWidth;
            const totalH = (modal && modal.clientHeight > 0) ? modal.clientHeight : window.innerHeight;
            
            const isSbOpen = overrideSidebarOpen !== null
            ? Boolean(overrideSidebarOpen)
            : Boolean(sidebar && sidebar.classList.contains('is-open'));
            
            let effectiveW = totalW;
            if (!isMob && isSbOpen) {
                effectiveW = Math.max(100, totalW - 364);
            }
            
            return {
                w: effectiveW,
                h: totalH,
            };
        }
        
        function getViewportPadding() {
            const isMob = isMobileDevice();
            const isFs = Boolean(document.fullscreenElement || document.webkitFullscreenElement);
            
            // Okraj 176 px je rezerva pro šipky; bez šipek na dotykovém zařízení (tablet) fotka využije celou šířku
            if (isMob || isFs || (isTouchOnly && !viewerSettings.arrows)) {
                return { x: 0, y: 0 };
            }
            return { x: 176, y: 40 };
        }
        
        // ==========================================
        // 4. AUTO-HIDE PŘI NEČINNOSTI
        // ==========================================
        let idleTimer = null;
        
        function resetIdleTimer() {
            if (modal) modal.classList.remove('bazantik-ui-idle');
            clearTimeout(idleTimer);
            
            const isSidebarOpen = sidebar && sidebar.classList.contains('is-open');
            if (viewerSettings.autoHide && !isSidebarOpen && modal && modal.classList.contains('is-active')) {
                idleTimer = setTimeout(() => {
                    if (modal && modal.classList.contains('is-active') && (!sidebar || !sidebar.classList.contains('is-open'))) {
                        modal.classList.add('bazantik-ui-idle');
                    }
                }, Math.max(1, viewerSettings.autoHideDelay) * 1000);
            }
        }
        
        ['pointermove', 'pointerdown', 'keydown', 'touchstart'].forEach(evt => {
            window.addEventListener(evt, resetIdleTimer, { passive: true });
        });
        
        // Jakmile uživatel pohne myší na novém snímku, zoom kolečkem se ihned aktivuje
        window.addEventListener('pointermove', () => {
            if (ignoreInertiaWheel) {
                ignoreInertiaWheel = false;
                clearTimeout(wheelIdleTimer);
            }
        }, { passive: true });
        
        // ==========================================
        // 5. ZASOUVACÍ CHEVRON LIŠTY
        // ==========================================
        function updateToolbarCollapseUI() {
            if (!topToolbar || !btnCollapse) return;
            const isCollapsed = Boolean(viewerSettings.toolbarCollapsed);
            topToolbar.classList.toggle('is-collapsed', isCollapsed);
            btnCollapse.classList.toggle('is-active', isCollapsed);
            btnCollapse.title = isCollapsed ? t('expandToolbar', 'Expand toolbar') : t('collapseToolbar', 'Collapse toolbar');
            btnCollapse.setAttribute('aria-label', btnCollapse.title);
            btnCollapse.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
            if (iconCollapse) {
                iconCollapse.innerHTML = isCollapsed
                ? '<polyline points="11 17 6 12 11 7"></polyline><polyline points="18 17 13 12 18 7"></polyline>'
                : '<polyline points="13 17 18 12 13 7"></polyline><polyline points="6 17 11 12 6 7"></polyline>';
            }
        }
        
        if (btnCollapse) {
            btnCollapse.addEventListener('click', () => {
                viewerSettings.toolbarCollapsed = !viewerSettings.toolbarCollapsed;
                saveSettings(viewerSettings);
                updateToolbarCollapseUI();
            });
        }
        updateToolbarCollapseUI();
        
        function showSpinnerDelayed() {
            clearTimeout(spinnerTimeout);
            spinnerTimeout = setTimeout(() => {
                if (spinner) spinner.classList.add('is-active');
            }, 120);
        }
        
        function hideSpinner() {
            clearTimeout(spinnerTimeout);
            if (spinner) spinner.classList.remove('is-active');
        }
        
        let hqSpinnerTimeout = null;
        let currentSwappingUrl = '';
        const failedSwapUrls = new Set(); // adresy, které se nepodařilo načíst nebo dekódovat (neopakujeme je)
        let activeSwapToken = 0;
        let activeSwapImg = null;
        let settleSwapTimer = null;
        let ignoreInertiaWheel = false;
        let wheelIdleTimer = null;
        
        function showHqSpinner() {
            clearTimeout(hqSpinnerTimeout);
            hqSpinnerTimeout = setTimeout(() => {
                const hqSpinner = document.getElementById('bazantik-hq-spinner');
                if (hqSpinner && isZoomed) {
                    hqSpinner.classList.add('is-active');
                }
            }, 30);
        }
        
        function hideHqSpinner() {
            clearTimeout(hqSpinnerTimeout);
            const hqSpinner = document.getElementById('bazantik-hq-spinner');
            if (hqSpinner) {
                hqSpinner.classList.remove('is-active');
            }
        }
        
        function cancelActiveSwap() {
            activeSwapToken++;
            clearTimeout(settleSwapTimer);
            if (activeSwapImg) {
                activeSwapImg.onload = null;
                activeSwapImg.onerror = null;
                activeSwapImg.src = '';
                activeSwapImg = null;
            }
            currentSwappingUrl = '';
            hideHqSpinner();
        }
        
        function getBestScreenSource(media, targetW, targetH) {
            if (!media || !media.sources) {
                return { url: media.original || '', key: 'full' };
            }
            
            const dpr = Math.min(window.devicePixelRatio || 1, 3);
            const isFullscreen = Boolean(document.fullscreenElement || document.webkitFullscreenElement);
            
            const screenW = isFullscreen ? Math.max(window.innerWidth, screen.width || 0) : (targetW || window.innerWidth);
            const screenH = isFullscreen ? Math.max(window.innerHeight, screen.height || 0) : (targetH || window.innerHeight);
            
            const reqW = screenW * dpr;
            const reqH = screenH * dpr;
            
            const candidateKeys = ['medium', 'medium_large', 'large', '1536x1536', '2048x2048', 'bazantik_4k'];
            const available = [];
            
            candidateKeys.forEach(k => {
                if (media.sources[k] && media.sources[k].url) {
                    available.push({
                        key: k,
                        url: media.sources[k].url,
                        w: media.sources[k].w || 0,
                        h: media.sources[k].h || 0,
                    });
                }
            });
            
            available.sort((a, b) => a.w - b.w);
            
            for (const item of available) {
                if (item.w >= reqW || item.h >= reqH) {
                    return item;
                }
            }
            
            const item4k = available.find(i => i.key === 'bazantik_4k');
            if (item4k) {
                return item4k;
            }
            
            if ((reqW > 2048 || reqH > 1400 || isFullscreen) && media.original) {
                return { url: media.original, key: 'full' };
            }
            
            if (available.length > 0) {
                return available[available.length - 1];
            }
            
            return { url: media.original || '', key: 'full' };
        }
        
        function getMaxScale(media, img) {
            const nativeW = media.width || img.naturalWidth || 3840;
            const baseW   = currentRenderWidth || img.clientWidth || 370;
            return Math.max(1.2, nativeW / baseW);
        }
        
        function backdropPercent() {
            const v = parseInt(viewerSettings.backdropOpacity, 10);
            return Math.min(95, Math.max(75, Number.isNaN(v) ? 95 : v));
        }
        
        function blurPixels() {
            const v = parseInt(viewerSettings.blurPx, 10);
            return Math.min(10, Math.max(1, Number.isNaN(v) ? 5 : v));
        }
        
        function applyBackdropRows() {
            const on = Boolean(viewerSettings.translucent);
            if (rowBackdrop) {
                rowBackdrop.style.display = on ? 'block' : 'none';
            }
            if (rowBlurToggle) {
                rowBlurToggle.style.display = on ? 'flex' : 'none';
            }
            if (rowBlurAmount) {
                rowBlurAmount.style.display = on && viewerSettings.blur ? 'block' : 'none';
            }
        }
        
        function applyBackdrop() {
            if (modal) {
                modal.style.setProperty('--bazantik-backdrop-alpha', viewerSettings.translucent ? (backdropPercent() / 100).toString() : '1');
                modal.style.setProperty('--bazantik-backdrop-blur', `${blurPixels()}px`);
                modal.classList.toggle('bazantik-blur-on', Boolean(viewerSettings.translucent && viewerSettings.blur));
            }
        }
        
        function syncSettingsInputs() {
            if (speedInput && speedVal) {
                speedInput.value = viewerSettings.speed.toString();
                speedVal.textContent = `${viewerSettings.speed} s`;
            }
            if (loopInput) {
                loopInput.checked = Boolean(viewerSettings.loop);
            }
            if (arrowsInput) {
                arrowsInput.checked = Boolean(viewerSettings.arrows);
            }
            if (doubleTapInput) {
                doubleTapInput.checked = Boolean(viewerSettings.doubleTapZoom);
            }
            if (autoHideInput) {
                autoHideInput.checked = Boolean(viewerSettings.autoHide);
            }
            if (autoHideDelayInput && autoHideVal) {
                autoHideDelayInput.value = viewerSettings.autoHideDelay.toString();
                autoHideVal.textContent = `${viewerSettings.autoHideDelay} s`;
            }
            if (rowAutoHideDelay) {
                rowAutoHideDelay.style.display = viewerSettings.autoHide ? 'block' : 'none';
            }
            if (translucentInput) {
                translucentInput.checked = Boolean(viewerSettings.translucent);
            }
            if (backdropInput && backdropVal) {
                backdropInput.value = backdropPercent().toString();
                backdropVal.textContent = `${backdropPercent()} %`;
            }
            if (blurInput) {
                blurInput.checked = Boolean(viewerSettings.blur);
            }
            if (blurAmountInput && blurVal) {
                blurAmountInput.value = blurPixels().toString();
                blurVal.textContent = `${blurPixels()} px`;
            }
            applyBackdropRows();
            applyBackdrop();
            if (zoomOriginalInput) {
                zoomOriginalInput.checked = mobileZoomOriginal();
            }
            if (rowZoomSource) {
                rowZoomSource.style.display = isMobileDevice() ? '' : 'none';
            }
            if (modal) {
                modal.classList.toggle('bazantik-hide-arrows', !viewerSettings.arrows);
            }
        }
        syncSettingsInputs();
        if (modal) modal.classList.toggle('bazantik-touch-only', isTouchOnly);
        
        // Zamknutí posunu stránky odstraní klasický posuvník (myš na Windows a macOS), stránka by se rozšířila a mřížka
        // by se pod lightboxem přepočítala. Šířku posuvníku proto nahradíme odsazením zprava.
        let scrollLockPadding = null;
        
        function lockPageScroll() {
            if (scrollLockPadding === null) {
                const barWidth = window.innerWidth - document.documentElement.clientWidth;
                scrollLockPadding = document.body.style.paddingRight;
                if (barWidth > 0) {
                    const current = parseFloat(window.getComputedStyle(document.body).paddingRight) || 0;
                    document.body.style.paddingRight = `${current + barWidth}px`;
                }
            }
            document.body.style.overflow = 'hidden';
        }
        
        function unlockPageScroll() {
            document.body.style.overflow = '';
            if (scrollLockPadding !== null) {
                document.body.style.paddingRight = scrollLockPadding;
                scrollLockPadding = null;
            }
        }
        
        function openLightbox(index) {
            if (!modal) return;
            currentIndex = index;
            if (!modal.classList.contains('is-active')) lastFocusedElement = document.activeElement;
            modal.classList.add('is-active');
            modal.setAttribute('aria-hidden', 'false');
            modal.focus({ preventScroll: true }); // fokus do dialogu bez viditelného rámečku na tlačítku
            lockPageScroll();
            if (backdrop) backdrop.style.opacity = '1';
            
            modal.classList.toggle('bazantik-hide-arrows', !viewerSettings.arrows);
            if (rowZoomSource) rowZoomSource.style.display = isMobileDevice() ? '' : 'none';
            
            if (isMobileDevice() && sidebar) {
                sidebar.classList.remove('is-open');
                if (btnInfo) btnInfo.classList.remove('is-active');
            }
            
            // Na počítači se obnoví stav infopanelu z minula (na mobilu se sám neotvírá)
            if (!isMobileDevice() && sidebar && viewerSettings.sidebarOpen) {
                sidebar.classList.add('is-open');
                if (btnInfo) btnInfo.classList.add('is-active');
            }
            
            const isSidebarOpen = !isMobileDevice() && sidebar && sidebar.classList.contains('is-open');
            modal.classList.toggle('has-sidebar', Boolean(isSidebarOpen));
            
            updateToolbarCollapseUI();
            resetIdleTimer();
            navigateTo(currentIndex);
        }
        
        function destroyMap() {
            mapToken++;
            if (leafletMap) {
                try { leafletMap.remove(); } catch (e) {}
            }
            leafletMap = null;
            leafletMarker = null;
        }
        
        function closeLightbox() {
            if (!modal) return;
            destroyMap();
            stopSlideshow();
            resetZoom();
            hideSpinner();
            currentSwappingUrl = '';
            hideHqSpinner();
            clearTimeout(idleTimer);
            clearTimeout(settleSwapTimer);
            clearTimeout(wheelIdleTimer);
            ignoreInertiaWheel = false;
            modal.classList.remove('is-active', 'has-sidebar', 'bazantik-ui-idle');
            modal.setAttribute('aria-hidden', 'true');
            if (lastFocusedElement && document.contains(lastFocusedElement)) {
                lastFocusedElement.focus({ preventScroll: true });
            }
            lastFocusedElement = null;
            setTimeout(layoutJustifiedGrid, 80); // po zavření lightboxu mřížku znovu přepočítáme
            unlockPageScroll();
            if (holder) {
                holder.querySelectorAll('video').forEach(v => { try { v.pause(); } catch (err) {} });
                // Prvek, na kterém dotyk právě skončil, neodstraňujeme uprostřed gesta (Chrome na Androidu pak ztrácel další klepnutí)
                setTimeout(() => {
                    if (holder && modal && !modal.classList.contains('is-active')) holder.innerHTML = '';
                }, 350);
            }
            if (backdrop) backdrop.style.opacity = '1';
            
            if (sidebar) {
                sidebar.classList.remove('is-open');
                if (btnInfo) btnInfo.classList.remove('is-active');
            }
            
            exitFullscreenSafe();
        }
        
        // Fullscreen API se liší podle prohlížeče (prefix WebKit, někde bez Promise): volání nikdy nesmí vyhodit chybu
        function exitFullscreenSafe() {
            try {
                if (document.fullscreenElement && document.exitFullscreen) {
                    const r = document.exitFullscreen();
                    if (r && typeof r.catch === 'function') r.catch(() => {});
                } else if (document.webkitFullscreenElement && document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                }
            } catch (err) { /* nepodporováno */ }
        }
        
        function navigateTo(newIndex) {
            // Ochrana před přenosem setrvačnosti kolečka z předchozího snímku
            ignoreInertiaWheel = true;
            clearTimeout(wheelIdleTimer);
            wheelIdleTimer = setTimeout(() => {
                ignoreInertiaWheel = false;
            }, 180);
            
            cancelActiveSwap();
            resetZoom();
            
            if (slideshowTimer) {
                clearTimeout(slideshowTimer);
                slideshowTimer = null;
            }
            
            if (newIndex >= items.length) {
                if (isSlideshowActive && !viewerSettings.loop) {
                    stopSlideshow();
                    return;
                }
                currentIndex = 0;
            } else if (newIndex < 0) {
                currentIndex = items.length - 1;
            } else {
                currentIndex = newIndex;
            }
            
            const thisToken = ++activeLoadToken;
            const media = items[currentIndex];
            if (!media) return;
            // Sousedy se přednačítají až po zobrazení aktuální fotky, aby si s ní nekonkurovaly o pásmo (hlavně na mobilu)
            const thisIndexForPreload = currentIndex;
            
            renderSidebarData(media);
            
            if (btnDownload) {
                let downloadHref = media.download || media.original || '';
                // Tajný klíč skrytého alba se v HTML nevypisuje, doplní se z adresy stránky
                const pageKey = new URLSearchParams(window.location.search).get('key');
                if (media.download && pageKey) {
                    downloadHref += '&key=' + encodeURIComponent(pageKey);
                }
                btnDownload.href = downloadHref;
                btnDownload.setAttribute('download', media.exif?.filename || 'photo.jpg');
            }
            
            if (media.type === 'video') {
                hideSpinner();
                performCrossDissolve(media, media.original, 'video');
                preloadAdjacentImages(thisIndexForPreload);
                return;
            }
            
            const dims = getTargetViewportDimensions();
            const pad = getViewportPadding();
            const vW = dims.w - pad.x;
            const vH = dims.h - pad.y;
            const bestSource = getBestScreenSource(media, vW, vH);
            
            const cacheKey = `${currentIndex}_${bestSource.key}`;
            
            if (domCache.has(cacheKey)) {
                const cachedEl = domCache.get(cacheKey);
                if (cachedEl.complete && cachedEl.naturalWidth > 0) {
                    hideSpinner();
                    performCrossDissolve(media, bestSource.url, bestSource.key, cachedEl);
                    preloadAdjacentImages(thisIndexForPreload);
                    return;
                }
            }
            
            showSpinnerDelayed();
            
            const loaderImg = new Image();
            loaderImg.fetchPriority = 'high';
            loaderImg.src = bestSource.url;
            loaderImg.alt = media.caption || '';
            loaderImg.decoding = 'async';
            
            const onImageLoaded = () => {
                if (thisToken !== activeLoadToken) return;
                hideSpinner();
                putInLruCache(cacheKey, loaderImg);
                performCrossDissolve(media, bestSource.url, bestSource.key, loaderImg);
                preloadAdjacentImages(thisIndexForPreload);
            };
            
            // Selhání načtení nebo dekódování zmenšeniny: místo rozbitého obrázku se zobrazí originál
            let failedHandled = false;
            const onImageFailed = () => {
                loaderImg.onerror = null;
                if (failedHandled || thisToken !== activeLoadToken) return;
                failedHandled = true;
                hideSpinner();
                performCrossDissolve(media, media.original, 'full');
                preloadAdjacentImages(thisIndexForPreload);
            };
            
            if (loaderImg.decode) {
                loaderImg.decode().then(onImageLoaded, onImageFailed);
            } else {
                loaderImg.onload = onImageLoaded;
            }
            
            loaderImg.onerror = onImageFailed;
        }
        
        // Prvek z cache nebo po zrušeném přechodu nesmí zdědit vypnuté dotyky ani absolutní pozici
        function resetMediaElInteractivity(el) {
            if (!el) return;
            el.style.pointerEvents = '';
            el.style.position = '';
            el.style.zIndex = '';
        }
        
        // Pojistka: viditelná fotka, která by přes přechod zůstala bez dotyků, se uvolní
        function healMediaPointerEvents() {
            if (!holder) return;
            const els = holder.querySelectorAll('img, video');
            const top = els.length ? els[els.length - 1] : null;
            if (top && top.style.pointerEvents === 'none') top.style.pointerEvents = '';
        }
        
        /**
         * Filmový Cross-Dissolve s garancí stability centrování ve Flexboxu
         */
        function performCrossDissolve(media, srcUrl, tierKey, readyMediaEl = null) {
            if (!holder) return;
            
            const thisTransitionToken = ++activeTransitionToken;
            holder.querySelectorAll('.bazantik-video-error').forEach(n => n.remove());
            
            const oldElements = Array.from(holder.querySelectorAll('img, video'));
            const oldEl = oldElements.length > 0 ? oldElements[oldElements.length - 1] : null;
            
            let newEl = readyMediaEl || document.createElement(media.type === 'video' ? 'video' : 'img');
            if (newEl.src !== srcUrl) {
                newEl.src = srcUrl;
            }
            if (media.type === 'video') {
                newEl.controls = true;
                // Safari bez tabindexu video do pořadí klávesy Tab ani na fokus nepustí
                newEl.tabIndex = 0;
                newEl.preload = 'metadata';
                if (media.poster) newEl.poster = media.poster;
                newEl.playsInline = true;
                if (!newEl.dataset.bazantikErrBound) {
                    newEl.dataset.bazantikErrBound = '1';
                    // Nepodporovaný kodek (typicky HEVC mimo Apple): srozumitelná zpráva místo prázdného přehrávače
                    newEl.addEventListener('error', () => {
                        if (!holder || holder.querySelector('.bazantik-video-error')) return;
                        const msg = document.createElement('div');
                        msg.className = 'bazantik-video-error';
                        msg.textContent = t('videoError', 'This video could not be played in this browser.');
                        holder.appendChild(msg);
                    });
                }
            } else {
                newEl.alt = media.caption || '';
            }
            
            currentLoadedTier = tierKey;
            if (modal) modal.classList.toggle('bazantik-is-video', media.type === 'video');
            fitImageToViewport(newEl, media);
            
            if (isSlideshowActive) {
                scheduleNextSlideshowStep();
            }
            
            if (!oldEl) {
                holder.innerHTML = '';
                resetMediaElInteractivity(newEl);
                newEl.style.opacity = '0';
                newEl.style.transform = 'none';
                holder.appendChild(newEl);
                void newEl.offsetWidth;
                requestAnimationFrame(() => {
                    newEl.style.transition = 'opacity 0.28s cubic-bezier(0, 0, 0.2, 1)';
                    newEl.style.opacity = '1';
                });
                return;
            }
            
            if (oldEl === newEl) {
                // Zrušený přechod mohl na prvku nechat pointer-events: none / position: absolute
                holder.querySelectorAll('img, video').forEach(el => { if (el !== newEl) el.remove(); });
                resetMediaElInteractivity(newEl);
                newEl.style.opacity = '1';
                return;
            }
            
            // Překrytí vrstev během animace
            oldEl.style.position = 'absolute';
            oldEl.style.pointerEvents = 'none';
            oldEl.style.zIndex = '1';
            
            // Nová fotka je nahoře (z-index 2) a dotyky přijímá hned; pointer-events: none dostává jen odcházející vrstva
            newEl.style.position = 'absolute';
            newEl.style.pointerEvents = '';
            newEl.style.zIndex = '2';
            newEl.style.opacity = '0';
            newEl.style.transform = 'none';
            newEl.style.transition = 'none';
            
            holder.appendChild(newEl);
            void newEl.offsetWidth;
            
            requestAnimationFrame(() => {
                if (thisTransitionToken !== activeTransitionToken) return;
                
                newEl.style.transition = 'opacity 0.28s cubic-bezier(0, 0, 0.2, 1)';
                newEl.style.opacity = '1';
                
                oldEl.style.transition = 'opacity 0.28s cubic-bezier(0.4, 0, 1, 1)';
                oldEl.style.opacity = '0';
            });
            
            setTimeout(() => {
                if (thisTransitionToken !== activeTransitionToken) return;
                
                oldElements.forEach(el => {
                    if (el !== newEl && el.parentNode === holder) {
                        el.remove();
                    }
                });
                
                // Návrat k přirozenému flexboxovému centrování
                newEl.style.position = '';
                newEl.style.zIndex = '';
                newEl.style.pointerEvents = '';
                newEl.style.transition = '';
            }, 300);
        }
        
        function showPrev() {
            navigateTo(currentIndex - 1);
        }
        
        function showNext() {
            navigateTo(currentIndex + 1);
        }
        
        function fitImageToViewport(mediaEl, media, overrideSidebarOpen = null) {
            if (!mediaEl || !media) return;
            
            const nativeW = media.width || mediaEl.videoWidth || mediaEl.naturalWidth || 1920;
            const nativeH = media.height || mediaEl.videoHeight || mediaEl.naturalHeight || 1080;
            const aspect = nativeW / nativeH;
            
            const dims = getTargetViewportDimensions(overrideSidebarOpen);
            const pad = getViewportPadding();
            
            const maxW = Math.max(100, dims.w - pad.x);
            const maxH = Math.max(100, dims.h - pad.y);
            
            let targetW = maxW;
            let targetH = maxW / aspect;
            
            if (targetH > maxH) {
                targetH = maxH;
                targetW = maxH * aspect;
            }
            
            currentRenderWidth = Math.round(targetW);
            currentRenderHeight = Math.round(targetH);
            
            mediaEl.style.width = `${currentRenderWidth}px`;
            mediaEl.style.height = `${currentRenderHeight}px`;
            mediaEl.style.maxWidth = 'none';
            mediaEl.style.maxHeight = 'none';
        }
        
        function preloadAdjacentImages(index) {
            if (index !== currentIndex || !items.length) return;
            const nextIdx1 = (index + 1) % items.length;
            const nextIdx2 = (index + 2) % items.length;
            const prevIdx  = (index - 1 + items.length) % items.length;
            const toPreload = isMobileDevice() ? [nextIdx1, prevIdx] : [nextIdx1, nextIdx2, prevIdx];
            
            const dims = getTargetViewportDimensions();
            const pad = getViewportPadding();
            const vW = dims.w - pad.x;
            const vH = dims.h - pad.y;
            
            toPreload.forEach(idx => {
                const m = items[idx];
                if (m && m.type !== 'video') {
                    // Na mobilních zařízeních nikdy nepřednačítáme 4K/full do pozadí (ochrana před OOM pádem)
                    let reqW = vW;
                    let reqH = vH;
                    if (isMobileDevice()) {
                        reqW = Math.min(vW, 1200);
                        reqH = Math.min(vH, 1200);
                    }
                    const bestSource = getBestScreenSource(m, reqW, reqH);
                    if (isMobileDevice() && (bestSource.key === 'full' || bestSource.key === 'bazantik_4k')) {
                        return;
                    }
                    const cacheKey = `${idx}_${bestSource.key}`;
                    if (!domCache.has(cacheKey)) {
                        const preloadImg = new Image();
                        preloadImg.fetchPriority = 'low';
                        preloadImg.src = bestSource.url;
                        preloadImg.decoding = 'async';
                        
                        const onDecoded = () => {
                            putInLruCache(cacheKey, preloadImg);
                        };
                        
                        if (preloadImg.decode) {
                            preloadImg.decode().then(onDecoded).catch(() => {});
                        } else {
                            preloadImg.onload = onDecoded;
                        }
                    }
                }
            });
        }
        
        function checkAndUpgradeResolution(mediaEl, media, overrideSidebarOpen = null) {
            if (!mediaEl || !media || media.type === 'video' || isZoomed) return;
            
            const dims = getTargetViewportDimensions(overrideSidebarOpen);
            const pad = getViewportPadding();
            const vW = dims.w - pad.x;
            const vH = dims.h - pad.y;
            const neededSource = getBestScreenSource(media, vW, vH);
            
            const currentWeight = TIER_WEIGHTS[currentLoadedTier] || 0;
            const neededWeight  = TIER_WEIGHTS[neededSource.key] || 0;
            
            if (neededWeight > currentWeight && neededSource.url !== mediaEl.src) {
                smoothSwapImageSource(mediaEl, neededSource.url, neededSource.key);
            }
        }
        
        function smoothSwapImageSource(img, newUrl, newTier) {
            if (!img || img.src === newUrl || currentSwappingUrl === newUrl || failedSwapUrls.has(newUrl)) return;
            
            const thisSwapToken = ++activeSwapToken;
            
            // Stornujeme předchozí běžící stahování
            if (activeSwapImg) {
                activeSwapImg.onload = null;
                activeSwapImg.onerror = null;
                activeSwapImg.src = '';
                activeSwapImg = null;
            }
            
            // Zobrazení indikátoru, pokud se stahuje plný originál a není již v paměti
            if (newTier === 'full' || newTier === 'bazantik_4k') {
                currentSwappingUrl = newUrl;
                showHqSpinner();
            }
            
            const temp = new Image();
            activeSwapImg = temp;
            temp.src = newUrl;
            temp.decoding = 'async';
            
            const applySwap = () => {
                if (thisSwapToken !== activeSwapToken) return;
                activeSwapImg = null;
                
                const liveImg = holder ? holder.querySelector('img') : img;
                if (liveImg && liveImg.parentNode) {
                    currentLoadedTier = newTier;
                    
                    // Přenese aktuální styl (transformaci a rozměry), třídy i popisek
                    temp.style.cssText = liveImg.style.cssText;
                    if (isZoomed) {
                        temp.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentScale})`;
                    }
                    temp.style.pointerEvents = '';
                    temp.className = liveImg.className;
                    temp.alt = liveImg.alt || '';
                    
                    // Asynchronní dekódování již doběhlo, uzel prohodíme bez zablokování hlavního vlákna
                    temp.style.transition = 'none';
                    
                    // Nahrazení uzlu v DOMu
                    liveImg.replaceWith(temp);
                    
                    // Invalidační trik pro WebKit: změna vizuálních hranic vrstvy (outline)
                    // a syntetický pohyb myši probudí spící kompozitor Safari i bez dotyku ruky
                    temp.style.outline = '1px solid transparent';
                    void temp.offsetHeight;
                    
                    requestAnimationFrame(() => {
                        temp.style.outline = 'none';
                        try {
                            const rect = temp.getBoundingClientRect();
                            temp.dispatchEvent(new MouseEvent('mousemove', {
                                bubbles: true,
                                cancelable: true,
                                view: window,
                                clientX: rect.left + rect.width / 2,
                                clientY: rect.top + rect.height / 2
                            }));
                        } catch (err) {}
                        
                        requestAnimationFrame(() => {
                            if (thisSwapToken === activeSwapToken) {
                                if (currentSwappingUrl === newUrl) {
                                    currentSwappingUrl = '';
                                }
                                hideHqSpinner();
                            }
                        });
                    });
                } else {
                    if (currentSwappingUrl === newUrl) {
                        currentSwappingUrl = '';
                    }
                    hideHqSpinner();
                }
            };
            
            const onSwapError = () => {
                if (thisSwapToken === activeSwapToken) {
                    failedSwapUrls.add(newUrl);
                }
                if (thisSwapToken !== activeSwapToken) return;
                activeSwapImg = null;
                if (currentSwappingUrl === newUrl) {
                    currentSwappingUrl = '';
                }
                hideHqSpinner();
            };
            
            if (temp.decode) {
                temp.decode().then(applySwap).catch(onSwapError);
            } else {
                temp.onload = applySwap;
                temp.onerror = onSwapError;
            }
        }
        
        /**
         * Přesný doraz hran (Math.abs) zaručuje držení fotky v okně bez odskoku
         */
        function clampTranslation(x, y, scale, overrideSidebarOpen = null) {
            const dims = getTargetViewportDimensions(overrideSidebarOpen);
            // Přiblížená fotka smí dojet až k okraji okna (rezerva pro šipky platí jen pro vyfocení celé fotky),
            // jinak by zoom na okraji fotky ujížděl od kurzoru
            const allowedW = dims.w;
            const allowedH = dims.h;
            
            const scaledW = currentRenderWidth * scale;
            const scaledH = currentRenderHeight * scale;
            
            let clampedX = 0;
            if (scaledW > allowedW) {
                const maxPanX = (scaledW - allowedW) / 2;
                clampedX = Math.max(-maxPanX, Math.min(maxPanX, x));
            }
            
            let clampedY = 0;
            if (scaledH > allowedH) {
                const maxPanY = (scaledH - allowedH) / 2;
                clampedY = Math.max(-maxPanY, Math.min(maxPanY, y));
            }
            
            return { x: clampedX, y: clampedY };
        }
        
        /**
         * Precizní Zoom-to-Click: míří přesně na bod pod kurzorem myši
         */
        function toggleZoom(clickX, clickY) {
            const media = items[currentIndex];
            if (!media || media.type === 'video' || !holder) return;
            
            const img = holder.querySelector('img');
            if (!img) return;
            
            if (!isZoomed) {
                const targetScale = getMaxScale(media, img);
                currentScale = targetScale;
                animTargetScale = targetScale;
                isZoomed = true;
                holder.classList.add('is-zoomed');
                
                let targetX = 0;
                let targetY = 0;
                
                if (typeof clickX === 'number' && typeof clickY === 'number') {
                    const rect = img.getBoundingClientRect();
                    const imgCenterX = rect.left + rect.width / 2;
                    const imgCenterY = rect.top + rect.height / 2;
                    
                    const offsetX = clickX - imgCenterX;
                    const offsetY = clickY - imgCenterY;
                    
                    targetX = -offsetX * (currentScale - 1);
                    targetY = -offsetY * (currentScale - 1);
                }
                
                const clamped = clampTranslation(targetX, targetY, currentScale);
                translateX = clamped.x;
                translateY = clamped.y;
                animTargetX = clamped.x;
                animTargetY = clamped.y;
                
                img.style.transition = 'transform 0.22s cubic-bezier(0.25, 1, 0.5, 1)';
                img.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentScale})`;
                
                const zoomTarget = getZoomTarget(media);
                if (zoomTarget.url && img.src !== zoomTarget.url) {
                    smoothSwapImageSource(img, zoomTarget.url, zoomTarget.key);
                }
                
                // Po doběhnutí 220ms animace odstraníme CSS transition zámek.
                // Safari tím okamžitě uvolní GPU vrstvu a vykreslí 24Mpx originál v plné ostrosti.
                setTimeout(() => {
                    const liveImg = holder ? holder.querySelector('img') : img;
                    if (liveImg && isZoomed) {
                        liveImg.style.transition = 'none';
                    }
                }, 240);
            } else {
                resetZoom();
            }
        }
        
        function resetZoom() {
            if (!holder) return;
            cancelActiveSwap();
            if (wheelAnimId) {
                cancelAnimationFrame(wheelAnimId);
                wheelAnimId = null;
            }
            const img = holder.querySelector('img');
            isZoomed = false;
            isPullingDown = false;
            pinchActive = false;
            pinchDidHappen = false;
            currentScale = 1;
            translateX = 0;
            translateY = 0;
            animTargetScale = 1;
            animTargetX = 0;
            animTargetY = 0;
            holder.classList.remove('is-zoomed', 'is-dragging');
            if (backdrop) backdrop.style.opacity = '1';
            if (img) {
                img.style.transition = 'transform 0.22s cubic-bezier(0.25, 1, 0.5, 1)';
                img.style.transform = 'none';
                const media = items[currentIndex];
                if (media) fitImageToViewport(img, media);
            }
        }
        
        // ==========================================
        // 6. GESTA, WHEEL ZOOM & APPLE PINCH-TO-ZOOM
        // ==========================================
        if (holder) {
            // Plynulý dojezd zoomu s normalizací času (stejná rychlost na 60Hz i 120Hz ProMotion)
            function startWheelAnimation() {
                if (wheelAnimId) return;
                const img = holder.querySelector('img');
                if (!img) return;
                img.style.transition = 'none';
                let lastFrameTime = performance.now();
                
                const animateWheelStep = (now) => {
                    const dt = Math.min(32, Math.max(8, now - lastFrameTime)) / 16.667;
                    lastFrameTime = now;
                    const factor = 1 - Math.pow(1 - 0.25, dt);
                    // Po výměně zdroje (náhled → originál) je původní uzel odpojen, animace musí běžet na aktuálním obrázku
                    const liveImg = holder.querySelector('img') || img;
                    
                    const scaleDiff = animTargetScale - currentScale;
                    const xDiff = animTargetX - translateX;
                    const yDiff = animTargetY - translateY;
                    
                    // Dosednutí do cíle s přirozeným GPU vyhlazením
                    if (Math.abs(scaleDiff) < 0.002 && Math.abs(xDiff) < 0.2 && Math.abs(yDiff) < 0.2) {
                        currentScale = animTargetScale;
                        translateX = animTargetX;
                        translateY = animTargetY;
                        
                        if (currentScale <= 1.01) {
                            resetZoom();
                        } else {
                            liveImg.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentScale})`;
                        }
                        
                        wheelAnimId = null;
                        return;
                    }
                    
                    // Sametové tlumení přepočtené podle skutečného času snímku
                    currentScale += scaleDiff * factor;
                    translateX += xDiff * factor;
                    translateY += yDiff * factor;
                    
                    liveImg.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentScale})`;
                    wheelAnimId = requestAnimationFrame(animateWheelStep);
                };
                
                wheelAnimId = requestAnimationFrame(animateWheelStep);
            }
            
            // Plynulý Wheel zoom přes requestAnimationFrame (odladěno pro Logitech G502 i macOS Safari)
            holder.addEventListener('wheel', (e) => {
                // Doznívající impulzy z předchozí fotky zahodíme a prodloužíme klidový stav o 100 ms
                if (ignoreInertiaWheel) {
                    e.preventDefault();
                    clearTimeout(wheelIdleTimer);
                    wheelIdleTimer = setTimeout(() => {
                        ignoreInertiaWheel = false;
                    }, 100);
                    return;
                }
                
                const media = items[currentIndex];
                if (!media || media.type === 'video') return;
                
                e.preventDefault();
                const img = holder.querySelector('img');
                if (!img) return;
                
                const maxScale = getMaxScale(media, img);
                // Firefox hlásí řádky (1) nebo stránky (2): přepočet na pixely, aby krok odpovídal Chrome a Safari
                const wheelDelta = e.deltaMode === 1 ? e.deltaY * 33 : (e.deltaMode === 2 ? e.deltaY * 100 : e.deltaY);
                const isZoomIn = wheelDelta < 0;
                const absDelta = Math.abs(wheelDelta);
                
                if (absDelta < 0.1) return;
                
                // Univerzální krok měřítka – jemný kultivovaný krok pro Logitech G502 i Magic Mouse
                let stepRatio = 1.14;
                if (absDelta > 60) {
                    // Krokové herní kolečko (Logitech G502 s deltaY >= 100)
                    stepRatio = 1.14;
                } else if (absDelta < 3) {
                    // Plynulý dotykový scroll (Apple Magic Mouse / Mac trackpad)
                    stepRatio = 1 + Math.max(0.015, absDelta * 0.02);
                }
                
                // Pokud animace neběží, synchronizujeme cíl s aktuální polohou
                if (!wheelAnimId) {
                    animTargetScale = currentScale;
                    animTargetX = translateX;
                    animTargetY = translateY;
                }
                
                let nextTargetScale = isZoomIn
                ? animTargetScale * stepRatio
                : animTargetScale / stepRatio;
                
                // Doraz na maximální zoom
                if (nextTargetScale > maxScale) {
                    nextTargetScale = maxScale;
                }
                
                // Při odzoomování zpět k 1.0 plynule srovnáme na výchozí velikost
                if (!isZoomIn && nextTargetScale <= 1.02) {
                    animTargetScale = 1;
                    animTargetX = 0;
                    animTargetY = 0;
                    isZoomed = false;
                    holder.classList.remove('is-zoomed');
                } else {
                    // Výpočet bodu pod kurzorem myši
                    const hRect = holder.getBoundingClientRect();
                    const centerX = hRect.left + hRect.width / 2;
                    const centerY = hRect.top + hRect.height / 2;
                    
                    const pointX = (e.clientX - centerX - animTargetX) / animTargetScale;
                    const pointY = (e.clientY - centerY - animTargetY) / animTargetScale;
                    
                    const nextTargetX = e.clientX - centerX - pointX * nextTargetScale;
                    const nextTargetY = e.clientY - centerY - pointY * nextTargetScale;
                    
                    // Poloha se při zoomu kolečkem neomezuje: bod pod kurzorem se nehýbe a fotka nikdy neskáče
                    animTargetScale = nextTargetScale;
                    animTargetX = nextTargetX;
                    animTargetY = nextTargetY;
                    isZoomed = true;
                    holder.classList.add('is-zoomed');
                }
                
                // Vyžádání plného originálu po ustálení zoomu (svižných 90 ms klidu a reálné zvětšení >= 1.15x)
                clearTimeout(settleSwapTimer);
                const settleTarget = getZoomTarget(media);
                if (settleTarget.url && img.src !== settleTarget.url && isZoomed && animTargetScale >= 1.15) {
                    settleSwapTimer = setTimeout(() => {
                        const liveImg = holder ? holder.querySelector('img') : img;
                        if (isZoomed && currentLoadedTier !== 'full' && liveImg) {
                            smoothSwapImageSource(liveImg, settleTarget.url, settleTarget.key);
                        }
                    }, 90);
                }
                
                startWheelAnimation();
            }, { passive: false });
            
            // Samoúzdrava: nový dotyk jedním prstem ukončí zapomenutý stav pinche (např. po výměně fotky během gesta)
            holder.addEventListener('touchstart', (e) => {
                if (e.touches.length === 1 && pinchActive) {
                    pinchActive = false;
                    pinchDidHappen = false;
                }
            }, { passive: true, capture: true });
            holder.addEventListener('touchcancel', () => {
                pinchActive = false;
            }, { passive: true });
            
            // Stabilní Apple Pinch-to-zoom mezi 2 prsty
            holder.addEventListener('touchstart', (e) => {
                if (e.target.tagName !== 'IMG') return;
                
                if (e.touches.length === 2) {
                    pinchActive = true;
                    pinchDidHappen = true;
                    
                    const t1 = e.touches[0];
                    const t2 = e.touches[1];
                    pinchStartDist = Math.hypot(t1.clientX - t2.clientX, t1.clientY - t2.clientY);
                    pinchStartScale = currentScale;
                    
                    const vRect = viewport ? viewport.getBoundingClientRect() : holder.getBoundingClientRect();
                    const centerX = vRect.left + vRect.width / 2;
                    const centerY = vRect.top + vRect.height / 2;
                    
                    const startMidX = (t1.clientX + t2.clientX) / 2;
                    const startMidY = (t1.clientY + t2.clientY) / 2;
                    
                    pinchPointX = (startMidX - centerX - translateX) / currentScale;
                    pinchPointY = (startMidY - centerY - translateY) / currentScale;
                }
            }, { passive: true });
            
            holder.addEventListener('touchmove', (e) => {
                if (!pinchActive || e.touches.length !== 2) return;
                
                e.preventDefault();
                
                const img = holder.querySelector('img');
                const media = items[currentIndex];
                if (!img || !media) return;
                
                const t1 = e.touches[0];
                const t2 = e.touches[1];
                const currentDist = Math.hypot(t1.clientX - t2.clientX, t1.clientY - t2.clientY);
                const currentMidX = (t1.clientX + t2.clientX) / 2;
                const currentMidY = (t1.clientY + t2.clientY) / 2;
                
                if (pinchStartDist > 10) {
                    const maxScale = getMaxScale(media, img);
                    const factor = currentDist / pinchStartDist;
                    let nextScale = pinchStartScale * factor;
                    
                    if (nextScale > maxScale) nextScale = maxScale;
                    if (nextScale < 0.95) nextScale = 0.95;
                    
                    const vRect = viewport ? viewport.getBoundingClientRect() : holder.getBoundingClientRect();
                    const centerX = vRect.left + vRect.width / 2;
                    const centerY = vRect.top + vRect.height / 2;
                    
                    let targetX = currentMidX - centerX - pinchPointX * nextScale;
                    let targetY = currentMidY - centerY - pinchPointY * nextScale;
                    
                    currentScale = nextScale;
                    isZoomed = currentScale > 1.05;
                    
                    if (isZoomed) {
                        holder.classList.add('is-zoomed');
                    } else {
                        holder.classList.remove('is-zoomed');
                    }
                    
                    const clamped = clampTranslation(targetX, targetY, currentScale);
                    translateX = clamped.x;
                    translateY = clamped.y;
                    
                    img.style.transition = 'none';
                    img.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentScale})`;
                }
            }, { passive: false });
            
            holder.addEventListener('touchend', (e) => {
                if (pinchActive && e.touches.length === 0) {
                    pinchActive = false;
                    setTimeout(() => { pinchDidHappen = false; }, 400);
                    
                    const img = holder.querySelector('img');
                    if (img) {
                        img.style.transition = 'transform 0.22s cubic-bezier(0.25, 1, 0.5, 1)';
                    }
                    
                    if (currentScale <= 1.05) {
                        resetZoom();
                    } else {
                        const clamped = clampTranslation(translateX, translateY, currentScale);
                        translateX = clamped.x;
                        translateY = clamped.y;
                        if (img) {
                            img.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentScale})`;
                        }
                        const media = items[currentIndex];
                        const pinchTarget = getZoomTarget(media);
                        if (media && pinchTarget.url && img && img.src !== pinchTarget.url) {
                            smoothSwapImageSource(img, pinchTarget.url, pinchTarget.key);
                        }
                    }
                }
            }, { passive: true });
            
            // Samoúzdrava: dotyk, který skončil na holderu místo na fotce, uvolní fotku pro další dotyky
            holder.addEventListener('pointerdown', (e) => {
                if (e.target === holder || e.target.tagName !== 'IMG') healMediaPointerEvents();
            }, true);
            
            // Posun (Pan) tažením myší / jedním prstem
            holder.addEventListener('pointerdown', (e) => {
                if (e.target.tagName !== 'IMG' || pinchActive || pinchDidHappen) return;
                
                if (wheelAnimId) {
                    cancelAnimationFrame(wheelAnimId);
                    wheelAnimId = null;
                    animTargetScale = currentScale;
                    animTargetX = translateX;
                    animTargetY = translateY;
                }
                
                isPointerDown = true;
                isPullingDown = false;
                startPointerX = e.clientX;
                startPointerY = e.clientY;
                initialTranslateX = translateX;
                initialTranslateY = translateY;
                totalDragDistance = 0;
                startPointerTime = performance.now();
                
                // Dotyk má implicitní zachycení a `touch-action: none`; explicitní zachycení a preventDefault
                // zde na Androidu Chrome ztrácely `click` následujícího klepnutí na tlačítko
                if (e.pointerType !== 'touch') {
                    try {
                        holder.setPointerCapture(e.pointerId);
                    } catch (err) {}
                    e.preventDefault();
                }
            });
            
            holder.addEventListener('pointermove', (e) => {
                if (!isPointerDown || pinchActive) return;
                
                const dx = e.clientX - startPointerX;
                const dy = e.clientY - startPointerY;
                totalDragDistance = Math.hypot(dx, dy);
                
                const img = holder.querySelector('img');
                if (!img) return;
                
                if (isZoomed) {
                    if (totalDragDistance > 5) {
                        holder.classList.add('is-dragging');
                    }
                    // Fotka, která po zoomu kolečkem stojí za mezí (pruh u okraje), se při tažení neskřípne skokem:
                    // smí se jen pohybovat směrem k mezi, od ní ven ne
                    const maxPos = clampTranslation(1e9, 1e9, currentScale);
                    const minPos = clampTranslation(-1e9, -1e9, currentScale);
                    translateX = Math.max(Math.min(minPos.x, initialTranslateX), Math.min(Math.max(maxPos.x, initialTranslateX), initialTranslateX + dx));
                    translateY = Math.max(Math.min(minPos.y, initialTranslateY), Math.min(Math.max(maxPos.y, initialTranslateY), initialTranslateY + dy));
                    img.style.transition = 'none';
                    img.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentScale})`;
                    return;
                }
                
                const isSidebarOpenOnMobile = isMobileDevice() && sidebar && sidebar.classList.contains('is-open');
                
                if (!isSidebarOpenOnMobile && dy > 0 && Math.abs(dy) > Math.abs(dx) * 1.2) {
                    isPullingDown = true;
                    holder.classList.add('is-dragging');
                    img.style.transform = `translate(0px, ${dy}px) scale(1)`;
                    
                    if (backdrop) {
                        const opacity = Math.max(0.35, 1 - (dy / 380));
                        backdrop.style.opacity = opacity.toString();
                    }
                }
            });
            
            function handlePointerEnd(e) {
                if (!isPointerDown) return;
                isPointerDown = false;
                holder.classList.remove('is-dragging');
                
                try {
                    holder.releasePointerCapture(e.pointerId);
                } catch (err) {}
                
                if (pinchDidHappen) return;
                
                // Zrušený dotyk (systém převzal gesto) nemá spolehlivé souřadnice: jen vrátíme stav
                if (e.type === 'pointercancel') {
                    isPullingDown = false;
                    const cancelImg = holder.querySelector('img');
                    if (cancelImg && !isZoomed) cancelImg.style.transform = 'none';
                    if (backdrop) backdrop.style.opacity = '1';
                    return;
                }
                
                const diffX = e.clientX - startPointerX;
                const diffY = e.clientY - startPointerY;
                const img = holder.querySelector('img');
                
                // Rychlé švihnutí nemusí stihnout vyvolat pointermove: vzdálenost a směr bereme z koncové polohy
                totalDragDistance = Math.max(totalDragDistance, Math.hypot(diffX, diffY));
                if (!isPullingDown && !isZoomed && diffY > 70 && diffY > Math.abs(diffX) * 1.2) {
                    const sidebarOpen = isMobileDevice() && sidebar && sidebar.classList.contains('is-open');
                    if (!sidebarOpen) isPullingDown = true;
                }
                
                if (isPullingDown && !isZoomed) {
                    isPullingDown = false;
                    if (diffY > 70) {
                        closeLightbox();
                        return;
                    } else {
                        if (img) img.style.transform = 'none';
                        if (backdrop) backdrop.style.opacity = '1';
                        return;
                    }
                }
                
                if (totalDragDistance < 6) {
                    if (!viewerSettings.doubleTapZoom) {
                        toggleZoom(startPointerX, startPointerY);
                        return;
                    }
                    // Zoom až druhým klepnutím: do 300 ms a do 30 px od prvního (první klepnutí nic nezdržuje)
                    const now = performance.now();
                    if (now - lastTapTime <= 300 && Math.hypot(startPointerX - lastTapX, startPointerY - lastTapY) <= 30) {
                        lastTapTime = 0;
                        toggleZoom(startPointerX, startPointerY);
                    } else {
                        lastTapTime = now;
                        lastTapX = startPointerX;
                        lastTapY = startPointerY;
                    }
                    return;
                }
                
                if (!isZoomed) {
                    // Pomalé tažení musí mít aspoň 24 px, rychlé švihnutí stačí od 12 px (rychlost aspoň 0,3 px/ms)
                    const elapsed = Math.max(1, performance.now() - startPointerTime);
                    const distance = Math.abs(diffX);
                    const isSwipe = distance > 24 || (distance > 12 && distance / elapsed >= 0.3);
                    if (isSwipe && distance > Math.abs(diffY)) {
                        stopSlideshow();
                        if (diffX > 0) showPrev();
                        else showNext();
                    }
                }
            }
            
            holder.addEventListener('pointerup', handlePointerEnd);
            holder.addEventListener('pointercancel', handlePointerEnd);
        }
        
        // ==========================================
        // 7. PLYNULÝ RESIZE (60 FPS)
        // ==========================================
        let resizeDebounceTimer = null;
        let isResizeTicking = false;
        
        function onWindowResize() {
            if (!isResizeTicking) {
                isResizeTicking = true;
                requestAnimationFrame(() => {
                    layoutJustifiedGrid();
                    if (holder) {
                        const mediaEl = holder.querySelector('img') || holder.querySelector('video');
                        const media = items[currentIndex];
                        if (mediaEl && media) {
                            if (!isZoomed) {
                                fitImageToViewport(mediaEl, media);
                            } else if (media.type !== 'video') {
                                currentScale = getMaxScale(media, mediaEl);
                                const clamped = clampTranslation(translateX, translateY, currentScale);
                                translateX = clamped.x;
                                translateY = clamped.y;
                                mediaEl.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentScale})`;
                            }
                        }
                    }
                    isResizeTicking = false;
                });
            }
            
            clearTimeout(resizeDebounceTimer);
            resizeDebounceTimer = setTimeout(() => {
                const mediaEl = holder ? (holder.querySelector('img') || holder.querySelector('video')) : null;
                const media = items[currentIndex];
                if (mediaEl && media) {
                    checkAndUpgradeResolution(mediaEl, media);
                }
            }, 250);
        }
        
        window.addEventListener('resize', onWindowResize);
        window.addEventListener('orientationchange', () => {
            setTimeout(onWindowResize, 150);
            setTimeout(onWindowResize, 350);
        });
        
        if (sidebar) {
            sidebar.addEventListener('pointerdown', (e) => e.stopPropagation());
            sidebar.addEventListener('pointermove', (e) => e.stopPropagation());
            sidebar.addEventListener('pointerup', (e) => e.stopPropagation());
        }
        
        // ==========================================
        // 8. EXIF DATA & CLONA (IRIS)
        // ==========================================
        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
        }
        
        function renderSidebarData(media) {
            if (sidebarTitle) {
                sidebarTitle.textContent = media.exif?.filename_clean || media.caption || t('mediaDetail', 'Media detail');
            }
            if (!exifContainer) return;
            exifContainer.innerHTML = '';
            
            if (!media.exif) return;
            const e = Object.assign({}, media.exif);
            
            const isoVal = escapeHtml(e.iso) || '—';
            // Zlomky z Androidu (např. 30000030/1000000000 s) se převedou na tvar 1/33 s, i u už uložených fotek
            const fixShutter = (v) => {
                const m = /^(\d+)\/(\d+)\s*s$/.exec(String(v || ''));
                if (!m || m[1] === '1' || Number(m[2]) <= 0) return v;
                const sec = Number(m[1]) / Number(m[2]);
                return sec > 0 && sec < 1 ? '1/' + Math.round(1 / sec) + ' s' : (Math.round(sec * 10) / 10) + ' s';
            };
            e.shutter_speed = fixShutter(e.shutter_speed);
            const shutterVal = escapeHtml(e.shutter_speed) || '—';
            const apertureVal = escapeHtml(e.aperture) || '—';
            const focalVal = escapeHtml(e.focal_35mm || e.focal_length) || '—';
            const focalTitle = e.focal_35mm ? t('focalTitle35', 'Focal length (35mm equivalent)') : t('focalTitle', 'Focal length (lens)');
            
            const badgesHtml = `
                                    <div class="bazantik-exif-badges">
                                        <div class="bazantik-exif-badge">
                                            <div class="bazantik-exif-badge-icon" title="${escapeHtml(t('isoTitle', 'ISO sensitivity'))}">
                                                <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2">
                                                    <rect x="2" y="4" width="20" height="16" rx="3.5"/>
                                                    <text x="12.25" y="14.7" font-size="7.5" font-weight="800" fill="currentColor" stroke="none" text-anchor="middle" font-family="-apple-system, BlinkMacSystemFont, sans-serif" letter-spacing="0.5">ISO</text>
                                                </svg>
                                            </div>
                                            <div class="bazantik-exif-badge-val" title="${isoVal}">${isoVal}</div>
                                        </div>
                                        <div class="bazantik-exif-badge">
                                            <div class="bazantik-exif-badge-icon" title="${escapeHtml(t('shutterTitle', 'Shutter speed'))}">
                                                <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                    <circle cx="12" cy="13" r="8"/>
                                                    <path d="M12 9v4l2.5 2"/>
                                                    <path d="M12 2v3"/>
                                                    <path d="M9.5 2h5"/>
                                                </svg>
                                            </div>
                                            <div class="bazantik-exif-badge-val" title="${shutterVal}">${shutterVal}</div>
                                        </div>
                                        <div class="bazantik-exif-badge">
                                            <div class="bazantik-exif-badge-icon" title="${escapeHtml(t('apertureTitle', 'Aperture (iris)'))}">
                                                <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                    <circle cx="12" cy="12" r="10"></circle>
                                                    <line x1="14.31" y1="8" x2="19.48" y2="16.95"></line>
                                                    <line x1="9.69" y1="8" x2="20.02" y2="8"></line>
                                                    <line x1="7.38" y1="12" x2="12.55" y2="3.05"></line>
                                                    <line x1="9.69" y1="16" x2="4.52" y2="7.05"></line>
                                                    <line x1="14.31" y1="16" x2="3.98" y2="16"></line>
                                                    <line x1="16.62" y1="12" x2="11.45" y2="20.95"></line>
                                                </svg>
                                            </div>
                                            <div class="bazantik-exif-badge-val" title="${apertureVal}">${apertureVal}</div>
                                        </div>
                                        <div class="bazantik-exif-badge">
                                            <div class="bazantik-exif-badge-icon" title="${escapeHtml(focalTitle)}">
                                                <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                                    <ellipse cx="12" cy="5.8" rx="7.2" ry="2.6"/>
                                                    <ellipse cx="12" cy="5.8" rx="4.4" ry="1.5" stroke-width="1.2" opacity="0.6"/>
                                                    <path d="M4.8 5.8v11.6c0 2 3.2 3.6 7.2 3.6s7.2-1.6 7.2-3.6V5.8"/>
                                                    <line x1="8" y1="9" x2="8" y2="17" stroke-width="1.2" opacity="0.35"/>
                                                </svg>
                                            </div>
                                            <div class="bazantik-exif-badge-val" title="${focalVal}">${focalVal}</div>
                                        </div>
                                    </div>
                                `;
            
            let detailsHtml = '';
            
            const fileRows = [
                { label: t('lblFile', 'File:'), val: e.filename },
                { label: t('lblSize', 'Size:'), val: e.filesize },
                { label: t('lblDimensions', 'Dimensions:'), val: e.dimensions },
                { label: t('lblCreated', 'Date:'), val: e.captured_at },
            ].filter(r => r.val);
            
            if (fileRows.length > 0) {
                detailsHtml += '<div class="bazantik-exif-group-title">' + escapeHtml(t('grpFile', 'File')) + '</div>';
                fileRows.forEach(r => {
                    detailsHtml += `<div class="bazantik-exif-row"><span class="bazantik-exif-label">${escapeHtml(r.label)}</span><span class="bazantik-exif-val">${escapeHtml(r.val)}</span></div>`;
                });
            }
            
            const expoRows = [
                { label: t('lblIso', 'ISO:'), val: e.iso },
                { label: t('lblExposureTime', 'Exposure time:'), val: e.shutter_speed },
                { label: t('lblAperture', 'Aperture:'), val: e.aperture },
                { label: t('lblFocal', 'Focal length:'), val: e.focal_length },
                { label: t('lblFocal35', 'Focal length (35mm equiv.):'), val: e.focal_35mm },
                { label: t('lblBias', 'Exposure compensation:'), val: e.exposure_bias },
            ].filter(r => r.val);
            
            if (expoRows.length > 0) {
                detailsHtml += '<div class="bazantik-exif-group-title">' + escapeHtml(t('grpExposure', 'Exposure settings')) + '</div>';
                expoRows.forEach(r => {
                    detailsHtml += `<div class="bazantik-exif-row"><span class="bazantik-exif-label">${escapeHtml(r.label)}</span><span class="bazantik-exif-val">${escapeHtml(r.val)}</span></div>`;
                });
            }
            
            const gearRows = [
                { label: t('lblCamera', 'Camera:'), val: e.camera },
                { label: t('lblLens', 'Lens:'), val: e.lens },
                { label: t('lblSoftware', 'Software:'), val: e.software },
            ].filter(r => r.val);
            
            if (gearRows.length > 0) {
                detailsHtml += '<div class="bazantik-exif-group-title">' + escapeHtml(t('grpGear', 'Gear & software')) + '</div>';
                gearRows.forEach(r => {
                    detailsHtml += `<div class="bazantik-exif-row"><span class="bazantik-exif-label">${escapeHtml(r.label)}</span><span class="bazantik-exif-val">${escapeHtml(r.val)}</span></div>`;
                });
            }
            
            exifContainer.innerHTML = badgesHtml + detailsHtml;
            
            if (mapContainer) {
                if (media.gps && typeof L !== 'undefined') {
                    mapContainer.style.display = 'block';
                    pendingGps = media.gps;
                    updateMapVisibility();
                } else {
                    pendingGps = null;
                    mapToken++;
                    mapContainer.style.display = 'none';
                }
            }
        }

        // Podklad mapy se stahuje z třetí strany, proto až po kliknutí návštěvníka (souhlas platí v tomto prohlížeči pro celý web)
        function updateMapVisibility() {
            if (!pendingGps) return;
            if (mapConsent) {
                if (mapConsentBox) mapConsentBox.style.display = 'none';
                if (mapElement) mapElement.style.display = 'block';
                if (mapRevokeButton) mapRevokeButton.style.display = 'block';
                initOrUpdateMap(pendingGps.lat, pendingGps.lng);
            } else {
                mapToken++;
                if (mapConsentBox) mapConsentBox.style.display = 'block';
                if (mapElement) mapElement.style.display = 'none';
                if (mapRevokeButton) mapRevokeButton.style.display = 'none';
            }
        }

        if (mapRevokeButton) {
            mapRevokeButton.addEventListener('click', () => {
                mapConsent = false;
                try {
                    localStorage.removeItem('bazantik_map_consent');
                } catch (e) { /* nic k odebrání */ }
                destroyMap();
                updateMapVisibility();
            });
        }

        if (mapLoadButton) {
            mapLoadButton.addEventListener('click', () => {
                mapConsent = true;
                try {
                    localStorage.setItem('bazantik_map_consent', '1');
                } catch (e) { /* souhlas pak platí jen do opuštění stránky */ }
                updateMapVisibility();
            });
        }
        
        // Podklad mapy. Když poskytovatel dlaždic nedodává (výpadek, vyčerpaný limit), nabídne se návštěvníkovi přepnutí na OpenStreetMap.
        function addBaseLayer(map) {
            const cfg = bazantikConfig;
            const useOsm = cfg.mapProvider === 'osm' || mapOsmChosen();
            const layerOptions = {
                maxZoom: 18,
                updateWhenZooming: false, // dlaždice nového přiblížení se načtou až po skončení zoomu (méně současných požadavků)
                keepBuffer: 4,
            };
            if (useOsm) {
                L.tileLayer(cfg.osmTileLayer, Object.assign({ attribution: cfg.osmAttribution }, layerOptions)).addTo(map);
                return;
            }

            const defs = (cfg.mapSwitch && Array.isArray(cfg.mapLayers) && cfg.mapLayers.length > 1)
                ? cfg.mapLayers
                : [{ id: '', label: '', url: cfg.tileLayer, attr: cfg.attribution }];
            const storageKey = 'bazantik_map_layer_' + cfg.mapProvider;
            let activeId = defs[0].id;
            if (defs.length > 1) {
                try {
                    const saved = localStorage.getItem(storageKey);
                    if (defs.some((d) => d.id === saved)) activeId = saved;
                } catch (e) { /* bez uložené volby se použije výchozí vrstva */ }
            }

            const layers = [];
            let loaded = 0;
            let failed = 0;
            let noticeShown = false;
            let switcher = null;
            let logoControl = null;
            let activeLayer = null;

            defs.forEach((def) => {
                const layer = L.tileLayer(def.url, Object.assign({ attribution: def.attr }, layerOptions));
                layer.on('tileload', () => { loaded++; });
                layer.on('tileerror', () => {
                    failed++;
                    if (noticeShown || failed < 4 || failed < loaded) return;
                    noticeShown = true;
                    showNotice();
                });
                layers.push({ def, layer });
                if (def.id === activeId) activeLayer = layer;
            });
            activeLayer.addTo(map);

            if (defs.length > 1) {
                const baseLayers = {};
                layers.forEach((l) => { baseLayers[l.def.label] = l.layer; });
                switcher = L.control.layers(baseLayers, null, { position: 'topright', collapsed: true }).addTo(map);
                map.on('baselayerchange', (e) => {
                    // Nová vrstva začíná s čistými počty, aby chyba jedné vrstvy (např. mimo tarif poskytovatele) nezablokovala ostatní.
                    loaded = 0;
                    failed = 0;
                    noticeShown = false;
                    const hit = layers.find((l) => l.layer === e.layer);
                    if (!hit) return;
                    try { localStorage.setItem(storageKey, hit.def.id); } catch (err) { /* volba pak platí jen do zavření stránky */ }
                });
            }

            if (cfg.mapLogo && cfg.mapLogo.src) {
                logoControl = L.control({ position: 'bottomleft' });
                logoControl.onAdd = function () {
                    const box = L.DomUtil.create('div', 'bazantik-map-logo bazantik-map-logo--' + cfg.mapProvider);
                    const link = document.createElement('a');
                    link.href = cfg.mapLogo.href;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    const img = document.createElement('img');
                    img.src = cfg.mapLogo.src;
                    img.alt = cfg.mapProviderName;
                    link.appendChild(img);
                    box.appendChild(link);
                    return box;
                };
                logoControl.addTo(map);
            }

            function showNotice() {
                const box = document.createElement('div');
                box.className = 'bazantik-map-notice';
                const text = document.createElement('p');
                text.textContent = t('mapProblem', 'The map from %s is not loading.').replace('%s', cfg.mapProviderName);
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = t('mapUseOsm', 'Switch to OpenStreetMap');
                btn.addEventListener('click', () => {
                    try { localStorage.setItem('bazantik_map_osm', cfg.tileLayer); } catch (e) { /* přepnutí pak platí jen do zavření stránky */ }
                    mapOsmSession = true;
                    layers.forEach((l) => { if (map.hasLayer(l.layer)) map.removeLayer(l.layer); });
                    if (logoControl) map.removeControl(logoControl);
                    if (switcher) map.removeControl(switcher);
                    box.remove();
                    L.tileLayer(cfg.osmTileLayer, Object.assign({ attribution: cfg.osmAttribution }, layerOptions)).addTo(map);
                });
                box.appendChild(text);
                box.appendChild(btn);
                map.getContainer().appendChild(box);
            }
        }

        let mapOsmSession = false;
        // Volba návštěvníka platí jen pro tutéž adresu dlaždic (po změně poskytovatele správcem se zkusí znovu)
        function mapOsmChosen() {
            if (mapOsmSession) return true;
            try { return localStorage.getItem('bazantik_map_osm') === bazantikConfig.tileLayer; } catch (e) { return false; }
        }

        function initOrUpdateMap(lat, lng) {
            const thisMapToken = ++mapToken;
            setTimeout(() => {
                if (thisMapToken !== mapToken || !modal || !modal.classList.contains('is-active')) return;
                if (!leafletMap) {
                    leafletMap = L.map('bazantik-leaflet-map').setView([lat, lng], 13);
                    addBaseLayer(leafletMap);
                    const pinIcon = L.divIcon({
                        className: 'bazantik-map-pin',
                        html: '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="42" viewBox="0 0 30 42"><path d="M15 0C6.7 0 0 6.7 0 15c0 11.2 15 27 15 27s15-15.8 15-27C30 6.7 23.3 0 15 0z" fill="#e5484d" stroke="#ffffff" stroke-width="2"/><circle cx="15" cy="15" r="5.5" fill="#ffffff"/></svg>',
                        iconSize: [30, 42],
                        iconAnchor: [15, 42]
                    });
                    leafletMarker = L.marker([lat, lng], { icon: pinIcon }).addTo(leafletMap);
                } else {
                    leafletMap.invalidateSize();
                    leafletMap.setView([lat, lng], 13);
                    leafletMarker.setLatLng([lat, lng]);
                }
            }, 200);
        }
        
        // ==========================================
        // 9. PREZENTACE & OVLÁDÁNÍ
        // ==========================================
        function scheduleNextSlideshowStep() {
            if (slideshowTimer) {
                clearTimeout(slideshowTimer);
                slideshowTimer = null;
            }
            if (!isSlideshowActive) return;
            
            const intervalMs = Math.max(1, viewerSettings.speed) * 1000;
            slideshowTimer = setTimeout(() => {
                if (isSlideshowActive) {
                    showNext();
                }
            }, intervalMs);
        }
        
        function startSlideshow() {
            stopSlideshow();
            resetZoom();
            const playIcon = document.getElementById('bazantik-icon-play');
            if (playIcon) {
                playIcon.innerHTML = '<rect x="6" y="4" width="4" height="16"></rect><rect x="14" y="4" width="4" height="16"></rect>';
            }
            if (btnPlay) {
                btnPlay.title = t('slideshowStop', 'Stop slideshow');
                btnPlay.setAttribute('aria-label', btnPlay.title);
            }
            
            isSlideshowActive = true;
            scheduleNextSlideshowStep();
        }
        
        function stopSlideshow() {
            isSlideshowActive = false;
            if (slideshowTimer) {
                clearTimeout(slideshowTimer);
                slideshowTimer = null;
            }
            const playIcon = document.getElementById('bazantik-icon-play');
            if (playIcon) {
                playIcon.innerHTML = '<polygon points="5 3 19 12 5 21 5 3"></polygon>';
            }
            if (btnPlay) {
                btnPlay.title = t('slideshowStart', 'Start slideshow');
                btnPlay.setAttribute('aria-label', btnPlay.title);
            }
        }
        
        function toggleSlideshow() {
            if (isSlideshowActive) {
                stopSlideshow();
            } else {
                startSlideshow();
            }
        }
        
        function toggleSidebar(forcedState = null) {
            if (!sidebar) return;
            
            const isCurrentlyOpen = sidebar.classList.contains('is-open');
            const willBeOpen = forcedState !== null ? forcedState : !isCurrentlyOpen;
            
            sidebar.classList.toggle('is-open', willBeOpen);
            if (modal) modal.classList.toggle('has-sidebar', willBeOpen);
            if (btnInfo) btnInfo.classList.toggle('is-active', willBeOpen);
            
            // Stav panelu si na počítači pamatujeme (na mobilu ne)
            if (!isMobileDevice()) {
                viewerSettings.sidebarOpen = willBeOpen;
                saveSettings(viewerSettings);
            }
            
            if (willBeOpen && panelInfo && panelInfo.classList.contains('is-active') && leafletMap) {
                setTimeout(() => { if (leafletMap) leafletMap.invalidateSize(); }, 300);
            }
            
            resetIdleTimer();
            if (!willBeOpen) applyPendingZoomUpgrade();
            
            const mediaEl = holder ? (holder.querySelector('img') || holder.querySelector('video')) : null;
            const media = items[currentIndex];
            if (mediaEl && media) {
                if (!isZoomed) {
                    fitImageToViewport(mediaEl, media, willBeOpen);
                    if (!willBeOpen) {
                        checkAndUpgradeResolution(mediaEl, media, false);
                    }
                } else {
                    const clamped = clampTranslation(translateX, translateY, currentScale, willBeOpen);
                    translateX = clamped.x;
                    translateY = clamped.y;
                    mediaEl.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentScale})`;
                }
            }
        }
        
        function setSidebarTab(tabName) {
            const isInfo = (tabName === 'info');
            
            if (tabInfo) {
                tabInfo.classList.toggle('is-active', isInfo);
                tabInfo.setAttribute('aria-selected', isInfo ? 'true' : 'false');
            }
            if (tabSettings) {
                tabSettings.classList.toggle('is-active', !isInfo);
                tabSettings.setAttribute('aria-selected', !isInfo ? 'true' : 'false');
            }
            
            if (panelInfo) panelInfo.classList.toggle('is-active', isInfo);
            if (panelSettings) panelSettings.classList.toggle('is-active', !isInfo);
            
            if (isInfo && leafletMap) {
                setTimeout(() => { if (leafletMap) leafletMap.invalidateSize(); }, 200);
            }
            resetIdleTimer();
        }
        
        function toggleFullscreen() {
            if (!document.fullscreenElement && !document.webkitFullscreenElement) {
                try {
                    if (modal && modal.requestFullscreen) {
                        const r = modal.requestFullscreen();
                        if (r && typeof r.catch === 'function') r.catch(() => {});
                    } else if (modal && modal.webkitRequestFullscreen) {
                        modal.webkitRequestFullscreen();
                    }
                } catch (err) { /* nepodporováno */ }
            } else {
                exitFullscreenSafe();
            }
        }
        
        if (speedInput && speedVal) {
            speedInput.addEventListener('input', (e) => {
                const val = parseInt(e.target.value, 10) || 4;
                viewerSettings.speed = val;
                speedVal.textContent = `${val} s`;
                saveSettings(viewerSettings);
                
                if (isSlideshowActive) {
                    scheduleNextSlideshowStep();
                }
            });
        }
        
        if (doubleTapInput) {
            doubleTapInput.addEventListener('change', (e) => {
                viewerSettings.doubleTapZoom = e.target.checked;
                saveSettings(viewerSettings);
            });
        }
        
        if (zoomOriginalInput) {
            zoomOriginalInput.addEventListener('change', (e) => {
                viewerSettings.zoomOriginal = e.target.checked;
                saveSettings(viewerSettings);
                pendingZoomUpgrade = e.target.checked;
            });
        }
        
        if (loopInput) {
            loopInput.addEventListener('change', (e) => {
                viewerSettings.loop = e.target.checked;
                saveSettings(viewerSettings);
            });
        }
        
        if (arrowsInput) {
            arrowsInput.addEventListener('change', (e) => {
                viewerSettings.arrows = e.target.checked;
                saveSettings(viewerSettings);
                if (modal) {
                    modal.classList.toggle('bazantik-hide-arrows', !viewerSettings.arrows);
                }
                onWindowResize();
            });
        }
        
        if (autoHideInput) {
            autoHideInput.addEventListener('change', (e) => {
                viewerSettings.autoHide = e.target.checked;
                saveSettings(viewerSettings);
                if (rowAutoHideDelay) {
                    rowAutoHideDelay.style.display = viewerSettings.autoHide ? 'block' : 'none';
                }
                resetIdleTimer();
            });
        }
        
        if (autoHideDelayInput && autoHideVal) {
            autoHideDelayInput.addEventListener('input', (e) => {
                const val = parseInt(e.target.value, 10) || 3;
                viewerSettings.autoHideDelay = val;
                autoHideVal.textContent = `${val} s`;
                saveSettings(viewerSettings);
                resetIdleTimer();
            });
        }
        
        if (translucentInput) {
            translucentInput.addEventListener('change', (e) => {
                viewerSettings.translucent = e.target.checked;
                saveSettings(viewerSettings);
                applyBackdropRows();
                applyBackdrop();
            });
        }
        
        if (blurInput) {
            blurInput.addEventListener('change', (e) => {
                viewerSettings.blur = e.target.checked;
                saveSettings(viewerSettings);
                applyBackdropRows();
                applyBackdrop();
            });
        }
        
        if (blurAmountInput && blurVal) {
            blurAmountInput.addEventListener('input', (e) => {
                viewerSettings.blurPx = parseInt(e.target.value, 10) || 5;
                blurVal.textContent = `${blurPixels()} px`;
                saveSettings(viewerSettings);
                applyBackdrop();
            });
        }
        
        if (backdropInput && backdropVal) {
            backdropInput.addEventListener('input', (e) => {
                viewerSettings.backdropOpacity = parseInt(e.target.value, 10) || 95;
                backdropVal.textContent = `${backdropPercent()} %`;
                saveSettings(viewerSettings);
                applyBackdrop();
            });
        }
        
        const onFullscreenChange = () => {
            const isFs = Boolean(document.fullscreenElement || document.webkitFullscreenElement);
            if (btnFullscreen) {
                btnFullscreen.title = isFs ? t('fullscreenExit', 'Exit full screen') : t('fullscreenEnter', 'Full screen');
                btnFullscreen.setAttribute('aria-label', btnFullscreen.title);
            }
            if (iconFs) {
                iconFs.innerHTML = isFs
                ? '<path d="M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3m0 18v-3a2 2 0 0 1 2-2h3M3 16h3a2 2 0 0 1 2 2v3"/>'
                : '<path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>';
            }
            
            const mediaEl = holder ? (holder.querySelector('img') || holder.querySelector('video')) : null;
            const media = items[currentIndex];
            if (mediaEl && media) {
                if (!isZoomed) {
                    fitImageToViewport(mediaEl, media);
                    if (media.type !== 'video') {
                        checkAndUpgradeResolution(mediaEl, media);
                    }
                } else if (media.type !== 'video') {
                    currentScale = getMaxScale(media, mediaEl);
                    const clamped = clampTranslation(translateX, translateY, currentScale);
                    translateX = clamped.x;
                    translateY = clamped.y;
                    mediaEl.style.transform = `translate(${translateX}px, ${translateY}px) scale(${currentScale})`;
                }
            }
        };
        
        document.addEventListener('fullscreenchange', onFullscreenChange);
        document.addEventListener('webkitfullscreenchange', onFullscreenChange);
        
        let lastTileOpenAt = 0;
        function openTile(index) {
            if (allItems[index] && allItems[index].type === 'pending') return;
            lastTileOpenAt = performance.now();
            openLightbox(allItems.slice(0, index).filter(m => m && m.type !== 'pending').length);
        }
        grid.querySelectorAll('.bazantik-grid-item').forEach((elem, index) => {
            elem.addEventListener('click', (e) => {
                e.preventDefault();
                if (performance.now() - lastTileOpenAt < 400) return;
                openTile(index);
            });
        });
        
        // Chrome / Opera na Androidu občas po zavření lightboxu pro krátké klepnutí `click` nevyvolají:
        // když ke klepnutí na dlaždici `click` do 80 ms nepřijde, lightbox otevřeme z pointerup
        (function () {
            let tap = null;
            grid.addEventListener('pointerdown', (e) => {
                if (e.pointerType !== 'touch' || !e.isPrimary) { tap = null; return; }
                const tile = e.target.closest ? e.target.closest('.bazantik-grid-item') : null;
                tap = tile ? { tile: tile, x: e.clientX, y: e.clientY, t: performance.now() } : null;
            });
            grid.addEventListener('pointerup', (e) => {
                if (!tap || e.pointerType !== 'touch') return;
                const t = tap;
                tap = null;
                if (Math.hypot(e.clientX - t.x, e.clientY - t.y) > 10 || performance.now() - t.t > 500) return;
                const downAt = performance.now();
                setTimeout(() => {
                    if (lastTileOpenAt >= downAt - 5 || (modal && modal.classList.contains('is-active'))) return;
                    const tiles = Array.prototype.slice.call(grid.querySelectorAll('.bazantik-grid-item'));
                    const idx = tiles.indexOf(t.tile);
                    if (idx >= 0) openTile(idx);
                }, 80);
            });
            grid.addEventListener('pointercancel', () => { tap = null; });
        })();
        
        // Ovládání mřížky klávesnicí: hotové dlaždice jsou fokusovatelné a Enter / mezerník otevře lightbox
        function makeTileAccessible(tile) {
            if (!tile || tile.classList.contains('bazantik-grid-item--pending')) return;
            let caption = '';
            try { caption = (JSON.parse(tile.getAttribute('data-media')) || {}).caption || ''; } catch (err) {}
            tile.setAttribute('tabindex', '0');
            tile.setAttribute('role', 'button');
            if (caption) tile.setAttribute('aria-label', caption);
        }
        grid.querySelectorAll('.bazantik-grid-item').forEach(makeTileAccessible);
        grid.addEventListener('keydown', (e) => {
            if ((e.key !== 'Enter' && e.key !== ' ') || e.target === null || !e.target.classList) return;
            if (!e.target.classList.contains('bazantik-grid-item')) return;
            e.preventDefault();
            e.target.click();
        });
        
        if (btnClose) btnClose.addEventListener('click', closeLightbox);
        if (btnPrev) btnPrev.addEventListener('click', () => { stopSlideshow(); showPrev(); });
        if (btnNext) btnNext.addEventListener('click', () => { stopSlideshow(); showNext(); });
        if (btnPlay) btnPlay.addEventListener('click', toggleSlideshow);
        if (btnFullscreen) btnFullscreen.addEventListener('click', toggleFullscreen);
        if (btnInfo) btnInfo.addEventListener('click', () => toggleSidebar());
        if (btnInfo && window.MutationObserver) {
            // aria-expanded sleduje stav tlačítka (třída is-active se mění na několika místech)
            const syncInfoExpanded = () => btnInfo.setAttribute('aria-expanded', btnInfo.classList.contains('is-active') ? 'true' : 'false');
            new MutationObserver(syncInfoExpanded).observe(btnInfo, { attributes: true, attributeFilter: ['class'] });
            syncInfoExpanded();
        }
        
        if (tabInfo) tabInfo.addEventListener('click', () => setSidebarTab('info'));
        if (tabSettings) tabSettings.addEventListener('click', () => setSidebarTab('settings'));
        
        // Ochrana před systémovým zoomem rozhraní v iOS Safari
        ['gesturestart', 'gesturechange'].forEach(evt => {
            document.addEventListener(evt, (e) => {
                if (modal && modal.classList.contains('is-active')) {
                    e.preventDefault();
                }
            }, { passive: false });
        });
        
        // ==========================================
        // PRŮBĚŽNÉ DOPLŇOVÁNÍ NEHOTOVÝCH DLAŽDIC (bez obnovení stránky)
        // ==========================================
        function parseTileData(tile) {
            try {
                return sanitizeMedia(JSON.parse(tile.getAttribute('data-media')));
            } catch (e) {
                return {};
            }
        }
        
        function openLightboxForTile(tile) {
            const domIndex = Array.from(grid.querySelectorAll('.bazantik-grid-item')).indexOf(tile);
            if (domIndex < 0 || !allItems[domIndex] || allItems[domIndex].type === 'pending') return;
            openLightbox(allItems.slice(0, domIndex).filter(m => m && m.type !== 'pending').length);
        }
        
        // Po výměně dlaždic přepočítá seznamy; otevřený lightbox zůstane na stejné fotce
        function rebuildItemLists() {
            const current = items[currentIndex];
            const fresh = Array.from(grid.querySelectorAll('.bazantik-grid-item')).map(parseTileData);
            
            allItems.length = 0;
            fresh.forEach(m => allItems.push(m));
            
            items.length = 0;
            fresh.filter(m => m && m.type !== 'pending').forEach(m => items.push(m));
            
            domCache.clear(); // klíče cache jsou podle pořadí, po změně seznamu by neseděly
            
            if (current && modal && modal.classList.contains('is-active')) {
                const idx = items.findIndex(m => m && m.original === current.original);
                if (idx >= 0) currentIndex = idx;
            }
        }
        
        function applyFragment(html) {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            let changed = false;
            
            grid.querySelectorAll('.bazantik-grid-item--pending').forEach(tile => {
                const id = tile.getAttribute('data-attachment-id');
                if (!id) return;
                
                const ready = doc.querySelector('.bazantik-grid-item[data-attachment-id="' + id + '"]:not(.bazantik-grid-item--pending)');
                const any   = doc.querySelector('.bazantik-grid-item[data-attachment-id="' + id + '"]');
                
                if (ready) {
                    const fresh = document.importNode(ready, true);
                    fresh.classList.add('bazantik-grid-item--just-ready');
                    makeTileAccessible(fresh);
                    fresh.addEventListener('click', (e) => {
                        e.preventDefault();
                        openLightboxForTile(fresh);
                    });
                    tile.replaceWith(fresh);
                    changed = true;
                } else if (!any) {
                    // Fotka mezitím selhala nebo byla odstraněna z alba: dlaždici odebereme
                    tile.remove();
                    changed = true;
                }
            });
            
            // Fotky přidané do alba po načtení stránky (např. průběžné nahrávání do konceptu) se doplní na své místo
            let anchor = null;
            doc.querySelectorAll('.bazantik-justified-grid .bazantik-grid-item').forEach(src => {
                const id = src.getAttribute('data-attachment-id');
                if (!id) return;
                let existing = null;
                grid.querySelectorAll('.bazantik-grid-item').forEach(t => { if (t.getAttribute('data-attachment-id') === id) existing = t; });
                if (existing) {
                    anchor = existing;
                    return;
                }
                const added = document.importNode(src, true);
                if (!added.classList.contains('bazantik-grid-item--pending')) {
                    makeTileAccessible(added);
                    added.addEventListener('click', (e) => {
                        e.preventDefault();
                        openLightboxForTile(added);
                    });
                }
                if (anchor) {
                    anchor.after(added);
                } else {
                    grid.insertBefore(added, grid.firstElementChild);
                }
                anchor = added;
                changed = true;
            });
            
            // Aktualizace upozornění nad mřížkou
            const prev = grid.previousElementSibling;
            const pageNotice = prev && prev.classList.contains('bazantik-processing-notice') ? prev : null;
            const newNotice = doc.querySelector('.bazantik-processing-notice');
            if (pageNotice) {
                if (newNotice) {
                    pageNotice.textContent = newNotice.textContent;
                } else {
                    pageNotice.remove();
                }
            } else if (newNotice) {
                grid.parentNode.insertBefore(document.importNode(newNotice, true), grid);
            }
            
            if (changed) {
                rebuildItemLists();
                layoutJustifiedGrid();
            }
        }
        
        function startPendingWatcher() {
            const albumId = grid.getAttribute('data-album-id');
            if (!albumId || !window.bazantikConfig || !bazantikConfig.homeUrl) return;
            const hasNotice = () => {
                const prev = grid.previousElementSibling;
                return !!(prev && prev.classList.contains('bazantik-processing-notice'));
            };
            if (!grid.querySelector('.bazantik-grid-item--pending') && !hasNotice()) return;
            
            // Autor alba (koncept, náhled) vidí aktuální stav bez mezipaměti, proto se ptáme častěji
            const isEditor = grid.hasAttribute('data-bazantik-editor');
            const accessKey = new URLSearchParams(window.location.search).get('key');
            const startedAt = Date.now();
            const MAX_MS = 30 * 60 * 1000; // po 30 minutách se přestaneme dotazovat
            
            function schedule() {
                setTimeout(check, isEditor ? 6000 + Math.random() * 3000 : 20000 + Math.random() * 10000);
            }
            
            async function check() {
                if (Date.now() - startedAt > MAX_MS) return;
                
                if (document.hidden) { // záložka na pozadí: nedotazujeme se
                    schedule();
                    return;
                }
                
                try {
                    const url = new URL(bazantikConfig.homeUrl, window.location.href);
                    url.searchParams.set('bazantik_fragment', albumId);
                    if (accessKey) url.searchParams.set('key', accessKey);
                    url.searchParams.set('_', String(Date.now()));
                    
                    const res = await fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' });
                    if (res.ok) {
                        applyFragment(await res.text());
                    }
                } catch (err) {}
                
                // Dotazujeme se, dokud je v mřížce nehotová dlaždice nebo server hlásí, že album ještě není kompletní
                if (grid.querySelector('.bazantik-grid-item--pending') || hasNotice()) {
                    schedule();
                }
            }
            
            schedule();
        }
        
        // ==========================================
        // OCHRANA PŘED CHYBĚJÍCÍMI SOUBORY: dlaždice, jejíž náhled se nenačte, se po jednom opakování odebere
        // ==========================================
        function handleBrokenTile(img) {
            const tile = img.closest('.bazantik-grid-item');
            if (!tile || !tile.parentNode) return;
            
            if (!img.dataset.bazantikRetried) {
                // První selhání: zkusíme načíst znovu (může jít o výpadek sítě)
                img.dataset.bazantikRetried = '1';
                const base = (img.currentSrc || img.src || '').split('?')[0];
                if (!base) return;
                img.removeAttribute('srcset');
                img.src = base + '?r=' + Date.now();
                return;
            }
            
            // Podruhé selhalo: dlaždici odebereme jen tehdy, když server potvrdí, že soubor neexistuje (404/410).
            // Při výpadku sítě nebo chybě serveru dlaždici ponecháme, aby fotka z alba nezmizela jen kvůli přechodné chybě.
            const removeTile = () => {
                if (!tile.parentNode) return;
                tile.remove();
                rebuildItemLists();
                layoutJustifiedGrid();
            };
            const probeUrl = (img.currentSrc || img.src || '').split('?')[0];
            if (!probeUrl || !window.fetch) { removeTile(); return; }
            fetch(probeUrl, { method: 'HEAD', cache: 'no-store' })
                .then((res) => { if (res.status === 404 || res.status === 410) removeTile(); })
                .catch(() => {});
        }
        
        grid.addEventListener('error', (e) => {
            if (e.target && e.target.tagName === 'IMG') handleBrokenTile(e.target);
        }, true);
        
        window.addEventListener('load', () => {
            grid.querySelectorAll('.bazantik-grid-item img').forEach((img) => {
                if (img.complete && img.currentSrc && img.naturalWidth === 0) handleBrokenTile(img);
            });
        });
        
        // Pojistka proti rozházené mřížce: kdykoli se změní šířka kontejneru (např. přepnutí rozvržení Avady
        // mezi počítačem a tabletem, i když je otevřený lightbox), mřížka se přepočítá znovu
        if (window.ResizeObserver) {
            let observedWidth = grid.clientWidth;
            let recentLayouts = [];
            new ResizeObserver(() => {
                if (Math.abs(grid.clientWidth - observedWidth) > 1) {
                    observedWidth = grid.clientWidth;
                    // Pojistka proti zacyklení: když přepočet mřížky sám mění šířku kontejneru a znovu se spouští,
                    // po čtyřech přepočtech za 1,5 s se přestane reagovat (změnu okna zachytí resize a orientationchange)
                    const now = Date.now();
                    recentLayouts = recentLayouts.filter(t => now - t < 1500);
                    if (recentLayouts.length >= 4) {
                        return;
                    }
                    recentLayouts.push(now);
                    layoutJustifiedGrid();
                }
            }).observe(grid);
        }
        
        startPendingWatcher();
        
        // Skrytý boční panel a sbalená lišta nesmí být dostupné čtečkám ani klávesnici (aria-hidden + inert)
        function setRegionHidden(el, hidden) {
            if (!el) return;
            el.setAttribute('aria-hidden', hidden ? 'true' : 'false');
            if (hidden) { el.setAttribute('inert', ''); } else { el.removeAttribute('inert'); }
        }
        function syncHiddenRegions() {
            setRegionHidden(sidebar, !sidebar || !sidebar.classList.contains('is-open'));
            setRegionHidden(topToolbar ? topToolbar.querySelector('.bazantik-toolbar-actions') : null, !!(topToolbar && topToolbar.classList.contains('is-collapsed')));
        }
        if (window.MutationObserver) {
            const regionObserver = new MutationObserver(syncHiddenRegions);
            if (sidebar) regionObserver.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
            if (topToolbar) regionObserver.observe(topToolbar, { attributes: true, attributeFilter: ['class'] });
        }
        syncHiddenRegions();
        
        // Obrys fokusu se ukazuje jen při ovládání klávesnicí (Tab); po kliknutí myší ho Chrome a Edge při dalším stisku klávesy jinak vykreslí
        if (modal) {
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Tab') modal.classList.add('bazantik-kbd-nav');
            }, true);
            modal.addEventListener('pointerdown', () => {
                modal.classList.remove('bazantik-kbd-nav');
            }, true);
            // Po kliknutí myší tlačítko fokus nedrží (jinak by se po stisku šipky na klávesnici obkreslilo); klávesnice (detail 0) beze změny
            modal.addEventListener('click', (e) => {
                if (e.detail === 0 || !e.target || !e.target.closest) return;
                const btn = e.target.closest('button, a');
                if (!btn) return;
                setTimeout(() => {
                    if (document.activeElement === btn) btn.blur();
                }, 0);
            }, true);
        }
        
        document.addEventListener('keydown', (e) => {
            if (!modal || !modal.classList.contains('is-active')) return;
            if (e.key === 'Escape') closeLightbox();
            // Šipky na posuvníku, v seznamu nebo na záložce patří těmto prvkům; fotku přepínají jen mimo ně
            const arrowsOwned = e.target && e.target.closest && e.target.closest('input[type="range"], select, [role="tab"]');
            if (e.key === 'ArrowLeft' && !arrowsOwned) { stopSlideshow(); showPrev(); }
            if (e.key === 'ArrowRight' && !arrowsOwned) { stopSlideshow(); showNext(); }
            if (e.key === ' ' && e.target && e.target.id === 'bazantik-btn-download') {
                // Odkaz na stažení reaguje nativně jen na Enter; mezerník ho má aktivovat jako ostatní tlačítka
                e.preventDefault();
                e.target.click();
                return;
            }
            if (e.key === ' ' && e.target && e.target.tagName === 'VIDEO') {
                // Mezerník na přehrávači videa spouští a zastavuje přehrávání (ne prezentaci)
                e.preventDefault();
                if (e.target.paused) { e.target.play().catch(() => {}); } else { e.target.pause(); }
                return;
            }
            if (e.key === ' ') {
                // Mezerník na tlačítku nebo posuvníku patří jim (klávesnicové ovládání), prezentaci přepíná jen mimo ně
                if (e.target && e.target.closest && e.target.closest('button, input, select, a, [role="tab"]')) return;
                e.preventDefault();
                toggleSlideshow();
            }
            if (e.key === 'Tab') {
                // Fokus zůstává uvnitř lightboxu (focus trap)
                const toolbarCollapsed = !!modal.querySelector('.bazantik-top-toolbar.is-collapsed');
                const sidebarOpen = modal.classList.contains('has-sidebar');
                const focusable = Array.from(modal.querySelectorAll('button, [href], input, select, video[controls], [tabindex]:not([tabindex="-1"])'))
                    .filter(el => {
                        if (el.disabled || el.getClientRects().length === 0) return false;
                        if (!sidebarOpen && el.closest('#bazantik-sidebar')) return false;
                        if (toolbarCollapsed && el.closest('.bazantik-toolbar-actions')) return false;
                        return true;
                    });
                // Přehrávač videa je vizuálně mezi šipkami, proto je mezi nimi i v pořadí klávesy Tab
                const videoEl = focusable.find(el => el.tagName === 'VIDEO');
                if (videoEl) {
                    focusable.splice(focusable.indexOf(videoEl), 1);
                    const prevAt = btnPrev ? focusable.indexOf(btnPrev) : -1;
                    const nextAt = btnNext ? focusable.indexOf(btnNext) : -1;
                    focusable.splice(prevAt >= 0 ? prevAt + 1 : (nextAt >= 0 ? nextAt : 0), 0, videoEl);
                }
                if (!focusable.length) return;
                // Pořadí řídíme sami, aby se fokus nikdy nedostal na skrytý prvek
                const at = focusable.indexOf(document.activeElement);
                let target;
                if (at < 0) {
                    target = e.shiftKey ? focusable[focusable.length - 1] : focusable[0];
                } else {
                    target = focusable[(at + (e.shiftKey ? -1 : 1) + focusable.length) % focusable.length];
                }
                e.preventDefault();
                // Prvek, který fokus nepřijme, se přeskočí (jinak by mezerník jednal naslepo)
                for (let i = 0; i < focusable.length && document.activeElement !== target; i++) {
                    target.focus();
                    if (document.activeElement === target) break;
                    const idx = focusable.indexOf(target);
                    target = focusable[(idx + (e.shiftKey ? -1 : 1) + focusable.length) % focusable.length];
                }
            }
        });
    }
    
    // Android Chrome po klepnutí na obrázek někdy nevyvolá `click` na následně stisknutém tlačítku
    // (pointerdown/pointerup/touchend dorazí, `click` ne). Když `click` do 60 ms nepřijde, spustí se tlačítko samo.
    (function () {
        let downButton = null;
        let downX = 0;
        let downY = 0;
        let lastTap = null;
        let rescued = null;
        const lightboxButton = (el) => {
            const btn = el && el.closest ? el.closest('button') : null;
            return btn && btn.closest('#bazantik-lightbox') ? btn : null;
        };
        window.addEventListener('pointerdown', (e) => {
            downButton = e.pointerType === 'touch' ? lightboxButton(e.target) : null;
            downX = e.clientX;
            downY = e.clientY;
            // Nové klepnutí je vždy skutečný záměr uživatele, nikdy se nezahazuje jako pozdní `click` předchozího
            rescued = null;
        }, true);
        window.addEventListener('click', (e) => {
            if (lastTap) lastTap.clicked = true;
            if (rescued && lightboxButton(e.target) === rescued) {
                rescued = null;
                e.stopImmediatePropagation();
                e.preventDefault();
            }
        }, true);
        window.addEventListener('pointerup', (e) => {
            const btn = downButton;
            downButton = null;
            if (!btn || e.pointerType !== 'touch' || lightboxButton(e.target) !== btn || btn.disabled) return;
            if (Math.hypot(e.clientX - downX, e.clientY - downY) > 10) return;
            const tap = { clicked: false };
            lastTap = tap;
            setTimeout(() => {
                if (tap.clicked) return;
                btn.click();
                rescued = btn;
                setTimeout(() => { if (rescued === btn) rescued = null; }, 600);
            }, 60);
        }, true);
    })();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBazantikGallery);
    } else {
        initBazantikGallery();
    }
})();
