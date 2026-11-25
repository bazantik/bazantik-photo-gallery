jQuery(document).ready(function ($) {
    // Hláška jádra o selhání zpracování obrázku radí nahrát menší (max. 2560 px); pro tento plugin to neplatí, použije se vlastní text
    if (window.pluploadL10n && window.pluploadL10n.http_error_image) {
        window.pluploadL10n.http_error_image = t('imageError', window.pluploadL10n.http_error_image);
    }
    // Přeložené texty dodává PHP (bazantikAdmin.strings); anglický záložní text se použije, když klíč chybí.
    // Zástupné znaky %s, %d, %1$s se nahrazují postupně podle pořadí argumentů.
    function t(key, fallback, ...args) {
        const strings = window.bazantikAdmin && window.bazantikAdmin.strings;
        const text = (strings && strings[key]) || fallback;
        let next = 0;
        return text.replace(/%(?:(\d+)\$)?[sd]/g, function (match, pos) {
            const value = pos ? args[parseInt(pos, 10) - 1] : args[next++];
            return value === undefined ? match : String(value);
        });
    }
    
    // Adresy ikon jádra WordPressu (PHP je dodává přes includes_url(), funguje i při WordPressu v podsložce)
    function bazantikIcon(key, fallback) {
        return (window.bazantikAdmin && window.bazantikAdmin[key]) || fallback;
    }

    const $mediaIds = $('#bazantik_media_ids');
    const $coverId  = $('#bazantik_cover_id');
    const $mediaList = $('#bazantik-media-list');
    let frame;
    
    $('#bazantik-add-media-btn').on('click', function (e) {
        e.preventDefault();
        if (frame) {
            frame.open();
            return;
        }
        
        frame = wp.media({
            title: t('selectMedia', 'Select photos and videos for the album'),
            button: { text: t('addToAlbum', 'Add to album') },
            multiple: true,
            library: { type: ['image', 'video'] }
        });
        
        frame.on('select', function () {
            const selection = frame.state().get('selection');
            let currentIds = $mediaIds.val() ? $mediaIds.val().split(',').map(Number) : [];
            
            selection.each(function (attachment) {
                const id = attachment.id;
                if (!currentIds.includes(id)) {
                    currentIds.push(id);
                    const isVideo = attachment.attributes.type === 'video';
                    const bazantikStatus = attachment.attributes.bazantikStatus || 'ready';
                    const thumb = isVideo ? bazantikIcon('videoIcon', '/wp-includes/images/media/video.png') : (attachment.attributes.sizes && attachment.attributes.sizes.thumbnail ? attachment.attributes.sizes.thumbnail.url : attachment.attributes.url);
                    
                    $mediaList.append(`
                        <li class="bazantik-media-item${bazantikStatus !== 'ready' ? ' is-' + bazantikStatus : ''}" data-id="${id}" data-status="${bazantikStatus}">
                            <div class="bazantik-media-preview">
                                <img src="${thumb}" alt="">
                                ${isVideo ? '<span class="dashicons dashicons-video-alt3 bazantik-badge-video"></span>' : ''}
                            </div>
                            <button type="button" class="bazantik-cover-btn dashicons dashicons-star-filled" title="${t('setCover', 'Set as album cover')}"></button>
                            <button type="button" class="bazantik-remove-item dashicons dashicons-no-alt" title="${t('remove', 'Remove')}"></button>
                        </li>
                    `);
                }
            });
            
            $mediaIds.val(currentIds.join(','));
            bazantikStartIfNeeded();
            selection.each(bazantikRegisterSortData);
            bazantikRenderTitles();
            bazantikScheduleDetect();
            if (!$coverId.val() && currentIds.length > 0) {
                setCover(currentIds[0]);
            }
        });
        
        frame.open();
    });
    
    function setCover(id) {
        $coverId.val(id);
        $mediaList.find('.bazantik-media-item').each(function () {
            $(this).toggleClass('is-cover', $(this).data('id') === Number(id));
        });
    }
    
    // Kliknutí na hvězdičku -> Nastavit jako obálku
    $mediaList.on('click', '.bazantik-cover-btn', function (e) {
        e.stopPropagation();
        const id = $(this).closest('.bazantik-media-item').data('id');
        setCover(id);
    });
    
    // Drag & Drop řazení
    $mediaList.sortable({
        items: '.bazantik-media-item',
        cursor: 'move',
        tolerance: 'pointer',
        forcePlaceholderSize: true,
        placeholder: 'bazantik-sort-placeholder',
        distance: 4,
        update: function () {
            const sortedIds = [];
            $mediaList.find('.bazantik-media-item').each(function () {
                sortedIds.push($(this).data('id'));
            });
            $mediaIds.val(sortedIds.join(','));
            batchSortMode = ''; // ruční přesun: nově nahrané fotky se budou přidávat na konec
            bazantikScheduleDetect();
        }
    });
    
    // Dlaždice jsou v mřížce (flex): vlastní rozhodování jQuery UI podle směru tažení v ní na dotyku selhávalo.
    // Vypneme ho a mezeru přesouvá obsluha události sort: z polohy kurzoru se spočítá, které místo mřížky (řádek, sloupec)
    // je pod ním, a mezera se vloží přesně na toto místo. Výpočet závisí jen na poloze kurzoru, ne na tom, kde mezera právě je,
    // takže se rozložení nemůže přepínat sem a tam (poskakování) a začátek řádku funguje stejně jako kterékoli jiné místo.
    const bazantikSortable = $mediaList.sortable('instance');
    if (bazantikSortable) {
        bazantikSortable._isFloating = function () { return true; };
        bazantikSortable._intersectsWithPointer = function () { return false; };
        $mediaList.sortable('option', 'sort', function (e, ui) {
            if (e.clientX == null || !ui.placeholder || !ui.placeholder[0]) return;
            const list = $mediaList[0];
            const gap = ui.placeholder[0];
            const flow = [];
            const others = [];
            $mediaList.children('.bazantik-media-item, .bazantik-sort-placeholder').each(function () {
                if (this === ui.item[0]) return;
                flow.push(this);
                if (this !== gap) others.push(this);
            });
            if (!others.length || gap.parentNode !== list) return;
            const box = list.getBoundingClientRect();
            if (e.clientX < box.left || e.clientX > box.right || e.clientY < box.top || e.clientY > box.bottom) return;
            const first = flow[0].getBoundingClientRect();
            const style = window.getComputedStyle(list);
            const stepX = first.width + (parseFloat(style.columnGap) || 0);
            const stepY = first.height + (parseFloat(style.rowGap) || 0);
            if (stepX <= 0 || stepY <= 0) return;
            const innerWidth = list.clientWidth - (parseFloat(style.paddingLeft) || 0) - (parseFloat(style.paddingRight) || 0);
            const cols = Math.max(1, Math.floor((innerWidth + (parseFloat(style.columnGap) || 0)) / stepX));
            const col = Math.min(cols - 1, Math.max(0, Math.floor((e.clientX - first.left) / stepX)));
            const row = Math.max(0, Math.floor((e.clientY - first.top) / stepY));
            const slot = Math.min(row * cols + col, others.length);
            if (slot === flow.indexOf(gap)) return;
            list.insertBefore(gap, slot < others.length ? others[slot] : others[others.length - 1].nextSibling);
        });
        $mediaList.sortable('refresh');
    }
    
    // Smazání položky
    // WordPress na této obrazovce načítá jquery-touch-punch: ten při dotyku zruší nativní klepnutí a „klik“ vyvolá jen tehdy,
    // když se prst nepohnul ani o pixel (a obchází volbu cancel). Dotyky na tlačítkách tedy touch-punch nedostane a projde nativní klik.
    ['touchstart', 'touchmove', 'touchend'].forEach(function (type) {
        $mediaList[0].addEventListener(type, function (e) {
            if (e.target && e.target.closest && e.target.closest('.bazantik-cover-btn, .bazantik-remove-item')) {
                e.stopPropagation();
            }
        }, { capture: true, passive: true });
    });

    $mediaList.on('click', '.bazantik-remove-item', function () {
        const $item = $(this).closest('.bazantik-media-item');
        const id = $item.data('id');
        let currentIds = $mediaIds.val().split(',').map(Number);
        currentIds = currentIds.filter(itemId => itemId !== id);
        $mediaIds.val(currentIds.join(','));
        
        if (Number($coverId.val()) === id) {
            setCover(currentIds[0] || '');
        }
        $item.remove();
        bazantikUpdateProgress();
    });
    
    // ==========================================
    // Zpracování fotek na pozadí (automatická smyčka + živý stav)
    // ==========================================
    const $procPanel = $('#bazantik-processing-panel');
    const $procFill  = $('#bazantik-processing-bar-fill');
    const $procText  = $('#bazantik-processing-text');
    const $procRetry = $('#bazantik-processing-retry');
    let procLoopRunning = false;
    
    function bazantikSleep(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }
    
    function bazantikAlbumIds() {
        const raw = $mediaIds.val();
        return raw ? raw.split(',').map(Number).filter(Boolean) : [];
    }
    
    function bazantikApi(path, data) {
        return wp.apiFetch({ path: '/bazantik-photo-gallery/v1/' + path, method: 'POST', data: data || {} });
    }
    
    function bazantikCountStates() {
        const states = { total: 0, waiting: 0, failed: 0 };
        $mediaList.find('.bazantik-media-item').each(function () {
            const status = $(this).attr('data-status') || 'ready';
            states.total++;
            if (status === 'pending' || status === 'processing') {
                states.waiting++;
            } else if (status === 'failed') {
                states.failed++;
            }
        });
        return states;
    }
    
    function bazantikUpdateProgress() {
        const s = bazantikCountStates();
        if (s.waiting === 0 && s.failed === 0) {
            $procPanel.hide();
            return;
        }
        
        const done = s.total - s.waiting - s.failed;
        $procFill.css('width', (s.total ? Math.round(done / s.total * 100) : 0) + '%');
        
        let text = s.total === 1
            ? t('procProgressOne', 'Processed %1$d of %2$d photo', done, s.total)
            : t('procProgress', 'Processed %1$d of %2$d photos', done, s.total);
        if (s.waiting > 0) text += ' · ' + t('procBackground', 'processing continues in the background');
        if (s.failed > 0) text += ' · ' + t('failedCount', 'failed: %d', s.failed);
        $procText.text(text);
        $procRetry.toggle(s.failed > 0);
        $procPanel.show();
    }
    
    function bazantikApplyStatuses(statuses, errors) {
        $mediaList.find('.bazantik-media-item').each(function () {
            const $li = $(this);
            const id = String($li.data('id'));
            const status = statuses ? statuses[id] : null;
            if (!status) return;
            
            if (status === 'missing') {
                // Fotka byla mezitím smazána z knihovny médií: odebereme ji ze seznamu alba
                $li.remove();
                $mediaIds.val(bazantikAlbumIds().filter(function (x) { return String(x) !== id; }).join(','));
                if (String($coverId.val()) === id) {
                    setCover(bazantikAlbumIds()[0] || '');
                }
                return;
            }
            
            $li.removeClass('is-pending is-processing is-failed');
            if (status !== 'ready') $li.addClass('is-' + status);
            $li.attr('data-status', status);
            
            if (status === 'failed' && errors && errors[id]) {
                $li.attr('title', errors[id]);
            } else {
                $li.removeAttr('title');
            }
        });
        bazantikUpdateProgress();
    }
    
    async function bazantikRunLoop() {
        if (procLoopRunning) return;
        procLoopRunning = true;
        
        try {
            let idleRounds = 0;
            while (true) {
                const res = await bazantikApi('process', { ids: bazantikAlbumIds() });
                bazantikApplyStatuses(res.statuses, res.errors);
                
                const counts = res.counts || {};
                const remaining = (counts.pending || 0) + (counts.processing || 0);
                if (res.paused) {
                    $procText.text(t('procPaused', 'Processing is paused (resume it on the Photo processing page).'));
                    break;
                }
                if (remaining === 0) break;
                
                if (res.busy) {
                    // Zpracování běží jinde (jiný panel, ruční dávka) – jen čekáme a obnovujeme stav
                    await bazantikSleep(4000);
                    continue;
                }
                
                if (res.done === 0 && res.failed === 0) {
                    idleRounds++;
                    if (idleRounds >= 3) break;
                    await bazantikSleep(2000);
                } else {
                    idleRounds = 0;
                    await bazantikSleep(500);
                }
            }
        } catch (e) {
            console.error('Bazantik Photo Gallery: could not connect to the server for processing', e);
            $procText.text(t('procConnFail', 'Connection to the server failed. Try reloading the page.'));
        } finally {
            procLoopRunning = false;
        }
    }
    
    function bazantikStartIfNeeded() {
        bazantikUpdateProgress();
        if (bazantikCountStates().waiting > 0) {
            bazantikRunLoop();
        }
    }
    
    $procRetry.on('click', function () {
        $procRetry.prop('disabled', true);
        bazantikApi('retry')
        .then(function () { return bazantikApi('status', { ids: bazantikAlbumIds() }); })
        .then(function (res) {
            bazantikApplyStatuses(res.statuses, res.errors);
            bazantikRunLoop();
        })
        .finally(function () { $procRetry.prop('disabled', false); });
    });
    
    // Hlídač: každých 15 s zkontroluje frontu a pokud na něco čeká, spustí zpracování.
    // Pokryje zpracování už během nahrávání i pokračování po znovuotevření editoru.
    // Skrytá záložka se nedotazuje; bez čekajících fotek stačí kontrola jednou za minutu.
    let bazantikWatchTick = 0;
    function bazantikWatchdog(force) {
        if (procLoopRunning || document.hidden) return;
        if (!force && bazantikCountStates().waiting === 0 && (++bazantikWatchTick % 4) !== 0) return;
        
        const waitingIds = $mediaList.find('.bazantik-media-item').filter(function () {
            return true; // kontrolujeme všechny fotky alba (i hotové): editor se tak dozví o smazaných a selhaných
        }).map(function () {
            return Number($(this).data('id'));
        }).get();
        
        bazantikApi('status', { ids: waitingIds })
        .then(function (res) {
            bazantikApplyStatuses(res.statuses, res.errors);
            if (((res.counts || {}).pending || 0) > 0) {
                bazantikRunLoop();
            }
        })
        .catch(function () {});
    }
    setInterval(bazantikWatchdog, 15000);
    // Po návratu na záložku se stav zkontroluje hned
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) bazantikWatchdog(true);
    });
    
    bazantikStartIfNeeded();
    
    // Varování při opuštění stránky s neuloženým seznamem fotek
    let bazantikInitialIds = $mediaIds.val();
    let bazantikSubmitting = false;
    
    $('#post').on('submit', function () {
        bazantikSubmitting = true;
    });
    
    // Update/Publikovat během nahrávání by nahrávání přerušilo. Odeslání se zablokuje dřív, než WordPress zneaktivní tlačítka
    // (po odmítnutém opuštění stránky by zůstala zneaktivněná se zatočeným kolečkem); uživatel dostane hlášku v ukazateli nahrávání.
    let bazantikSubmitBlocked = false;
    function bazantikUploadRunning() {
        return uploadTotal > 0 && (uploadDone + uploadFailed) < uploadTotal;
    }
    function bazantikRestoreSubmitButtons() {
        $('#publish, #save-post, #post-preview, #major-publishing-actions :submit').prop('disabled', false).removeClass('disabled button-disabled');
        $('#major-publishing-actions .spinner, #minor-publishing .spinner').removeClass('is-active').hide();
        $('#post').removeData('submitted');
        bazantikSubmitting = false;
    }
    document.addEventListener('submit', function (e) {
        if (!e.target || e.target.id !== 'post' || !bazantikUploadRunning()) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        bazantikSubmitBlocked = true;
        bazantikUploadRender();
        bazantikRestoreSubmitButtons();
    }, true);
    
    window.addEventListener('beforeunload', function (e) {
        if (!bazantikSubmitting && $mediaIds.val() !== bazantikInitialIds) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
    
    // Automatické ukládání seznamu fotek u konceptů (aby se při zavření stránky neztratil)
    let bazantikLastSavedState = $mediaIds.val() + '|' + $coverId.val();
    let bazantikAutosaveBusy = false;
    let bazantikSaveSeq = 0; // pořadí uložení: starší odpověď nesmí přepsat stav novějšího uložení
    
    setInterval(function () {
        const postStatus = $('#original_post_status').val();
        if (bazantikAutosaveBusy || ['auto-draft', 'draft', 'pending'].indexOf(postStatus) === -1) return;
        
        const state = $mediaIds.val() + '|' + $coverId.val();
        if (state === bazantikLastSavedState) return;
        
        const savedIds = $mediaIds.val();
        const saveSeq = ++bazantikSaveSeq;
        bazantikAutosaveBusy = true;
        
        bazantikApi('album-media', {
            post_id: Number($('#post_ID').val()),
            ids: bazantikAlbumIds(),
            cover: Number($coverId.val()) || 0
        }).then(function (res) {
            if (res && res.saved && saveSeq === bazantikSaveSeq) {
                bazantikLastSavedState = state;
                bazantikInitialIds = savedIds;
            }
        }).catch(function () {}).finally(function () {
            bazantikAutosaveBusy = false;
        });
    }, 3000);
    
    // ==========================================
    // Nahrávání přímo do alba (zóna pro přetažení souborů)
    // ==========================================
    const $dropzone    = $('#bazantik-dropzone');
    const $uploadBox   = $('#bazantik-upload-status');
    const $uploadFill  = $('#bazantik-upload-bar-fill');
    const $uploadText  = $('#bazantik-upload-text');
    const $uploadErrs  = $('#bazantik-upload-errors');
    const countedFiles = new Set();
    const retriedFiles = new Map(); // soubory, které se už po chybě zkoušely nahrát znovu (počet opakování)
    const uploadKeys = new Map(); // klíč nahrání podle souboru; zůstává do úspěšného nahrání (opakování = stejný klíč)
    function bazantikFileKey(native) { return native.name + '|' + native.size + '|' + native.lastModified; }
    const failedFiles = new Map(); // soubory, které se nepodařilo nahrát (zůstávají v paměti stránky, dokud se nenahrají nebo je neodstraníš)
    const $uploadRetry = $('#bazantik-upload-retry');
    let batchEpoch = 0; // číslo dávky: po vynulování počítadel se stará selhání už do nich nezapočítávají
    const posterJobs = new WeakMap(); // rozpracované náhledy videí (podle položky nahrávání)
    let bazantikUploader = null;
    let uploadTotal  = 0;
    let uploadDone   = 0;
    let uploadFailed = 0;
    let retryPending = 0; // opakování čekající na opětovné zařazení: soubor zůstává započítaný, dávka se tím nevynuluje
    let batchSortMode = '';
    let uploadStartedAt = 0; // způsob řazení alba před dávkou (pokud bylo seřazené)
    
    function bazantikRetryButton() {
        $uploadRetry.text(t('retryUploads', 'Retry uploading the failed files (%d)', failedFiles.size)).toggle(failedFiles.size > 0);
    }
    
    function bazantikUploadRender() {
        if (uploadTotal === 0) {
            $uploadBox.hide();
            return;
        }
        
        const finished = uploadDone + uploadFailed;
        $uploadFill.css('width', Math.min(100, Math.round(finished / uploadTotal * 100)) + '%');
        
        if (!uploadStartedAt) uploadStartedAt = Date.now();
        const elapsedSec = Math.round((Date.now() - uploadStartedAt) / 1000);
        let text = t('uploadProgress', 'Uploaded %1$d of %2$d files (%3$s)', uploadDone, uploadTotal, Math.floor(elapsedSec / 60) + ':' + String(elapsedSec % 60).padStart(2, '0'));
        if (uploadFailed > 0) text += ' · ' + t('failedCount', 'failed: %d', uploadFailed);
        if (finished < uploadTotal) text += ' · ' + t('uploadStay', 'do not leave the page until the upload finishes');
        if (finished < uploadTotal && bazantikSubmitBlocked) text += ' · ' + t('uploadSaveBlocked', 'SAVING WILL BE POSSIBLE AFTER THE UPLOAD FINISHES');
        if (finished >= uploadTotal) bazantikSubmitBlocked = false;
        $uploadText.text(text);
        $uploadBox.show();
        
        if (finished >= uploadTotal) {
            bazantikKickChainSoon(); // zpracování se spustí i na serveru (poběží, i když potom zavřeš panely)
            // Dávka je dokončena: pokud bylo album před ní seřazené, nově přidané fotky se zařadí podle stejného řazení
            if (batchSortMode && uploadDone > 0) {
                bazantikSortAlbum(batchSortMode);
                batchSortMode = '';
                bazantikScheduleDetect();
            }
            // Dávka je hotová: po chvíli ukazatel schováme (pokud mezitím nepřibyly další soubory)
            setTimeout(function () {
                // Dokud čekají selhané soubory na opakování, ukazatel zůstává (po zavření upozornění se znovu naplánuje skrytí)
                if (failedFiles.size > 0) return;
                if (uploadDone + uploadFailed >= uploadTotal) {
                    uploadTotal = 0;
                    uploadDone = 0;
                    uploadFailed = 0;
                    batchEpoch++;
                    countedFiles.clear();
                    uploadStartedAt = 0;
                    bazantikUploadRender();
                }
            }, 15000);
        }
    }
    
    // Po nahrání přidá soubor do seznamu alba (stejně jako výběr v okně médií)
    function bazantikAppendAttachment(attachment) {
        const attrs = attachment.attributes || {};
        const type = attrs.type;
        
        if (type !== 'image' && type !== 'video') {
            $uploadErrs.append($('<li>').text(t('badFileType', '%s: this file type cannot be added to the album (only photos and videos).', attrs.filename || t('fileFallback', 'File'))));
            return;
        }
        
        const id = Number(attachment.id);
        const currentIds = bazantikAlbumIds();
        if (!id || currentIds.includes(id)) return;
        currentIds.push(id);
        
        const isVideo = type === 'video';
        const thumb = isVideo
        ? bazantikIcon('videoIcon', '/wp-includes/images/media/video.png')
        : (attrs.sizes && attrs.sizes.thumbnail ? attrs.sizes.thumbnail.url : bazantikIcon('defaultIcon', '/wp-includes/images/media/default.png'));
        const status = attrs.bazantikStatus || 'ready';
        
        const $li = $(`
                <li class="bazantik-media-item${status !== 'ready' ? ' is-' + status : ''}" data-id="${id}" data-status="${status}">
                    <div class="bazantik-media-preview">
                        <img src="" alt="">
                        ${isVideo ? '<span class="dashicons dashicons-video-alt3 bazantik-badge-video"></span>' : ''}
                    </div>
                    <button type="button" class="bazantik-cover-btn dashicons dashicons-star-filled" title="${t('setCover', 'Set as album cover')}"></button>
                    <button type="button" class="bazantik-remove-item dashicons dashicons-no-alt" title="${t('remove', 'Remove')}"></button>
                </li>
            `);
        $li.find('img').attr('src', thumb || '');
        $mediaList.append($li);
        bazantikRegisterSortData(attachment);
        bazantikRenderTitles();
        bazantikScheduleDetect();
        
        $mediaIds.val(currentIds.join(','));
        if (!$coverId.val()) setCover(id);
        
        // Pokud bylo album před dávkou seřazené, fotka se zařadí na své místo hned (ne až po celé dávce)
        if (batchSortMode) {
            bazantikInsertSorted($li, batchSortMode);
        }
        bazantikStartIfNeeded();
    }
    
    if (window.wp && wp.Uploader && wp.Uploader.browser && wp.Uploader.browser.supported && $dropzone.length) {
        try {
            bazantikUploader = new wp.Uploader({
                dropzone: $dropzone,
                browser: $('#bazantik-upload-browse'),
                params: { post_id: Number($('#post_ID').val()) },
                plupload: {
                    filters: {
                        mime_types: [{
                            title: t('photosAndVideos', 'Photos and videos'),
                            extensions: 'jpg,jpeg,png,gif,webp,avif,heic,heif,mp4,webm,mov,m4v'
                        }]
                    }
                },
                
                added: function (attachment) {
                    const isRetry = retryPending > 0;
                    if (isRetry) retryPending--;
                    if (uploadTotal === 0) {
                        $uploadErrs.empty();
                        batchSortMode = bazantikDetectSortMode(); // pokud je album seřazené, po dokončení dávky se seřadí znovu
                    }
                    const file = attachment.get('file');
                    if (file && file.id) countedFiles.add(file.id);
                    
                    // Video: první snímek a skutečné rozměry zachytí prohlížeč z místního souboru (na serveru to nejde)
                    const nativeFile = file && typeof file.getNative === 'function' ? file.getNative() : null;
                    if (nativeFile && (/^video\//.test(nativeFile.type || '') || /\.(mov|mp4|m4v|webm)$/i.test(nativeFile.name || ''))) {
                        posterJobs.set(attachment, { promise: bazantikCapturePoster(nativeFile), lastModified: nativeFile.lastModified || 0 });
                    }
                    if (!isRetry) uploadTotal++;
                    bazantikUploadRender();
                },
                
                success: function (attachment) {
                    uploadDone++;
                    bazantikUploadPoster(attachment);
                    bazantikAppendAttachment(attachment);
                    bazantikUploadRender();
                },
                
                error: function (message, data, file) {
                    if (uploadTotal === 0) {
                        $uploadErrs.empty(); // nová dávka: stará upozornění zmizí
                        failedFiles.clear();
                        bazantikRetryButton();
                        batchSortMode = bazantikDetectSortMode();
                    }
                    // Soubor odmítnutý ještě před zařazením (např. příliš velký) se do celkového počtu doplní zde
                    if (file && file.id && !countedFiles.has(file.id)) {
                        countedFiles.add(file.id);
                        if (retryPending > 0) retryPending--; else uploadTotal++;
                    }
                    // Přechodná chyba serveru nebo spojení (HTTP/IO): soubor se po chvíli zkusí nahrát ještě jednou
                    const retryNative = file && typeof file.getNative === 'function' ? file.getNative() : null;
                    const retryKey = retryNative ? retryNative.name + '|' + retryNative.size + '|' + retryNative.lastModified : '';
                    const retryable = data && typeof plupload !== 'undefined' && (data.code === plupload.HTTP_ERROR || data.code === plupload.IO_ERROR);
                    
                    if (retryable && retryNative && bazantikUploader && bazantikUploader.uploader && (retriedFiles.get(retryKey) || 0) < 2) {
                        const attempt = (retriedFiles.get(retryKey) || 0) + 1;
                        retriedFiles.set(retryKey, attempt);
                        retryPending++; // soubor zůstává v celkovém počtu, aby ukazatel během čekání nezmizel
                        bazantikUploadRender();
                        // První opakování po 3 s, druhé po 10 s (server mohl být jen krátce zaneprázdněn)
                        setTimeout(function () {
                            try {
                                bazantikUploader.uploader.addFile(retryNative);
                            } catch (e) {
                                retryPending = Math.max(0, retryPending - 1);
                                uploadFailed++;
                                bazantikUploadRender();
                            }
                        }, attempt === 1 ? 3000 : 10000);
                        return;
                    }
                    
                    uploadFailed++;
                    const name = (file && file.name) ? file.name : t('fileFallback', 'File');
                    const $failedLi = $('<li>').text(name + ': ' + (message || t('uploadFailed', 'upload failed')));
                    if (retryable && retryNative) {
                        $failedLi.data('retryKey', retryKey);
                        failedFiles.set(retryKey, { file: retryNative, epoch: batchEpoch, $li: $failedLi });
                        bazantikRetryButton();
                    }
                    $uploadErrs.append($failedLi);
                    bazantikUploadRender();
                }
            });

            // Každý soubor má klíč nahrání, který se při opakování po chybě nemění; server podle něj nevytvoří druhou kopii
            if (bazantikUploader.uploader) {
                bazantikUploader.uploader.bind('BeforeUpload', function (up, f) {
                    const native = f && typeof f.getNative === 'function' ? f.getNative() : null;
                    if (!native) return;
                    const k = bazantikFileKey(native);
                    if (!uploadKeys.has(k)) {
                        uploadKeys.set(k, (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (Date.now().toString(36) + Math.random().toString(36).slice(2, 12)));
                    }
                    up.settings.multipart_params = Object.assign({}, up.settings.multipart_params, { bazantik_upload_key: uploadKeys.get(k) });
                });
                bazantikUploader.uploader.bind('FileUploaded', function (up, f) {
                    const native = f && typeof f.getNative === 'function' ? f.getNative() : null;
                    if (native) uploadKeys.delete(bazantikFileKey(native));
                });
            }
        } catch (e) {
            console.error('Bazantik Photo Gallery: could not start the uploader', e);
            $dropzone.hide();
        }
    } else {
        $dropzone.hide();
    }
    
    // ==========================================
    // Řazení fotek v albu
    // ==========================================
    function bazantikRegisterSortData(attachment) {
        const attrs = attachment.attributes || {};
        $mediaList.find('.bazantik-media-item[data-id="' + attachment.id + '"]').attr({
            'data-title': attrs.title || attrs.filename || '',
            'data-captured': attrs.bazantikCaptured || ''
        });
    }
    
    function bazantikCompareTitle(a, b) {
        const result = a.title.localeCompare(b.title, undefined, { numeric: true, sensitivity: 'base' });
        if (result !== 0) return result;
        // Při shodě (např. rozdíl jen v pomlčce, nebo 2 a 02) rozhodne přesné porovnání textu a nakonec ID:
        // pořadí je pak vždy jednoznačné a při obrácení se skutečně obrátí
        if (a.title !== b.title) return a.title < b.title ? -1 : 1;
        return a.id - b.id;
    }
    
    function bazantikSortAlbum(mode, dryRun) {
        const entries = $mediaList.children('.bazantik-media-item').get().map(function (el, index) {
            return {
                el: el,
                index: index,
                id: Number(el.getAttribute('data-id')),
                title: el.getAttribute('data-title') || '',
                captured: el.getAttribute('data-captured') || ''
            };
        });
        
        let sorted;
        
        if (mode === 'captured-asc' || mode === 'captured-desc') {
            const dir = mode === 'captured-asc' ? 1 : -1;
            const dated = entries.filter(function (e) { return e.captured !== ''; });
            const undated = entries.filter(function (e) { return e.captured === ''; });
            dated.sort(function (a, b) {
                if (a.captured !== b.captured) return a.captured < b.captured ? -dir : dir;
                return bazantikCompareTitle(a, b) * dir;
            });
            sorted = dated.concat(undated); // fotky bez data pořízení zůstanou na konci ve stávajícím pořadí
        } else if (mode === 'title-asc') {
            sorted = entries.slice().sort(bazantikCompareTitle);
        } else if (mode === 'title-desc') {
            sorted = entries.slice().sort(function (a, b) { return bazantikCompareTitle(b, a); });
        } else if (mode === 'uploaded-desc') {
            sorted = entries.slice().sort(function (a, b) { return b.id - a.id; });
        } else if (mode === 'uploaded-asc') {
            sorted = entries.slice().sort(function (a, b) { return a.id - b.id; });
        } else if (mode === 'reverse') {
            sorted = entries.slice().reverse();
        } else if (mode === 'shuffle') {
            sorted = entries.slice();
            for (let i = sorted.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                const tmp = sorted[i];
                sorted[i] = sorted[j];
                sorted[j] = tmp;
            }
        } else {
            return;
        }
        
        // Zkušební průchod (rozpoznání aktuálního řazení): vrátí jen pořadí ID, nic nepřesouvá
        if (dryRun) {
            return sorted.map(function (e) { return e.id; }).join(',');
        }
        
        // Jediné přeskládání DOM najednou (bez zasekávání i u stovek fotek)
        const fragment = document.createDocumentFragment();
        sorted.forEach(function (e) { fragment.appendChild(e.el); });
        $mediaList[0].appendChild(fragment);
        
        $mediaIds.val(sorted.map(function (e) { return e.id; }).join(','));
        if ($mediaList.data('ui-sortable')) {
            $mediaList.sortable('refresh');
        }
    }
    
    $('#bazantik-sort-mode').on('change', function () {
        const mode = $('#bazantik-sort-mode').val();
        if (!mode) return;
        bazantikSortAlbum(mode);
        
        // Pokud právě probíhá nahrávání, nové fotky se mají zařazovat podle PRÁVĚ zvoleného způsobu
        // (u „obrátit“ a „náhodně“ se jen přidávají na konec)
        if (uploadTotal > 0 && (uploadDone + uploadFailed) < uploadTotal) {
            batchSortMode = ['reverse', 'shuffle'].indexOf(mode) === -1 ? mode : '';
        }
        
        // Pamatujeme si naposledy zvolený stabilní způsob řazení (po „obrátit“ a „náhodně“ ne)
        if (['reverse', 'shuffle'].indexOf(mode) === -1) {
            bazantikLastSortMode = mode;
            try {
                localStorage.setItem('bazantik_sort_' + $('#post_ID').val(), mode);
            } catch (e) {}
        }
        bazantikScheduleDetect();
    });
    
    // ==========================================
    // Názvy na náhledech, rozpoznání řazení a zavírání upozornění
    // ==========================================
    function bazantikRenderTitles() {
        $mediaList.children('.bazantik-media-item').each(function () {
            const $li = $(this);
            let $bar = $li.children('.bazantik-media-title');
            if (!$bar.length) {
                $bar = $('<div class="bazantik-media-title"></div>').appendTo($li);
            }
            const t = $li.attr('data-title') || '';
            if ($bar.text() !== t) {
                $bar.text(t);
                $li.children('.bazantik-media-preview').attr('title', t.length > 16 ? t : null);
            }
        });
    }
    
    // Menu řazení ukazuje skutečný stav: pokud pořadí odpovídá některému řazení, vybere se, jinak zůstane výzva
    function bazantikDetectSortMode() {
        const $items = $mediaList.children('.bazantik-media-item');
        if ($items.length < 2) return '';
        
        const datedCount = $items.filter(function () {
            return ($(this).attr('data-captured') || '') !== '';
        }).length;
        
        const current = bazantikAlbumIds().join(',');
        const modes = ['captured-asc', 'captured-desc', 'title-asc', 'title-desc', 'uploaded-asc', 'uploaded-desc'];
        
        // Pořadí může odpovídat víc způsobům řazení najednou (např. názvy ve stejném pořadí jako čas pořízení):
        // nejdřív zkusíme naposledy zvolený způsob
        if (bazantikLastSortMode && modes.indexOf(bazantikLastSortMode) !== -1) {
            modes.splice(modes.indexOf(bazantikLastSortMode), 1);
            modes.unshift(bazantikLastSortMode);
        }
        
        for (let i = 0; i < modes.length; i++) {
            if (modes[i].indexOf('captured') === 0 && datedCount < 2 && modes[i] !== bazantikLastSortMode) continue;
            if (bazantikSortAlbum(modes[i], true) === current) return modes[i];
        }
        return '';
    }
    
    let bazantikLastSortMode = '';
    try {
        bazantikLastSortMode = localStorage.getItem('bazantik_sort_' + $('#post_ID').val()) || '';
    } catch (e) {}
    
    let bazantikDetectTimer = null;
    function bazantikScheduleDetect() {
        clearTimeout(bazantikDetectTimer);
        bazantikDetectTimer = setTimeout(function () {
            $('#bazantik-sort-mode').val(bazantikDetectSortMode());
        }, 400);
    }
    
    // Zavírání upozornění o nahrávání: jednotlivě křížkem, nebo všechna najednou
    $uploadErrs.on('click', '.bazantik-err-close', function () {
        const $li = $(this).closest('li');
        failedFiles.delete($li.data('retryKey'));
        $li.remove();
        bazantikRetryButton();
        bazantikUploadRender();
    });
    $('#bazantik-upload-errors-clear').on('click', function () {
        $uploadErrs.empty();
        failedFiles.clear();
        bazantikRetryButton();
        bazantikUploadRender();
    });
    
    // Ruční opakování nahrání souborů, které se nepodařilo nahrát (např. po výpadku spojení)
    $uploadRetry.on('click', function () {
        if (!bazantikUploader || !bazantikUploader.uploader) return;
        const entries = Array.from(failedFiles.entries());
        failedFiles.clear();
        bazantikRetryButton();
        entries.forEach(function (entry) {
            const info = entry[1];
            info.$li.remove();
            retriedFiles.delete(entry[0]);
            if (info.epoch === batchEpoch) {
                uploadFailed = Math.max(0, uploadFailed - 1);
                retryPending++; // soubor zůstává započítaný, po opětovném zařazení se nepřičte znovu
            }
            bazantikUploader.uploader.addFile(info.file);
        });
        bazantikUploadRender();
    });
    if ($uploadErrs.length && window.MutationObserver) {
        new MutationObserver(function () {
            $uploadErrs.children('li').each(function () {
                if (!$(this).children('.bazantik-err-close').length) {
                    $(this).append($('<button type="button" class="bazantik-err-close">×</button>').attr('title', t('close', 'Close')));
                }
            });
            $('#bazantik-upload-errors-clear').toggle($uploadErrs.children().length > 1);
        }).observe($uploadErrs[0], { childList: true });
    }
    
    bazantikRenderTitles();
    $('#bazantik-sort-mode').val(bazantikDetectSortMode());
    
    // ==========================================
    // Uložení změn při zavření stránky a zařazení nových fotek podle řazení alba
    // ==========================================
    
    // Poslední záchrana: při zavření stránky nebo přepnutí panelu se u konceptu tiše uloží aktuální stav seznamu
    function bazantikFlushAutosave() {
        const postStatus = $('#original_post_status').val();
        if (['auto-draft', 'draft', 'pending'].indexOf(postStatus) === -1) return;
        
        const state = $mediaIds.val() + '|' + $coverId.val();
        if (state === bazantikLastSavedState) return;
        
        bazantikLastSavedState = state;
        bazantikSaveSeq++;
        wp.apiFetch({
            path: '/bazantik-photo-gallery/v1/album-media',
            method: 'POST',
            keepalive: true,
            data: {
                post_id: Number($('#post_ID').val()),
                ids: bazantikAlbumIds(),
                cover: Number($coverId.val()) || 0
            }
        }).catch(function () {});
    }
    
    window.addEventListener('pagehide', bazantikFlushAutosave);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') bazantikFlushAutosave();
    });
    
    function bazantikEntryOf(el) {
        return {
            el: el,
            id: Number(el.getAttribute('data-id')),
            title: el.getAttribute('data-title') || '',
            captured: el.getAttribute('data-captured') || ''
        };
    }
    
    // Porovnávací funkce pro jednotlivé způsoby řazení (stejná pravidla jako při tlačítku „Seřadit“)
    function bazantikComparator(mode) {
        if (mode === 'title-asc') return bazantikCompareTitle;
        if (mode === 'title-desc') return function (a, b) { return bazantikCompareTitle(b, a); };
        if (mode === 'uploaded-asc') return function (a, b) { return a.id - b.id; };
        if (mode === 'uploaded-desc') return function (a, b) { return b.id - a.id; };
        
        if (mode === 'captured-asc' || mode === 'captured-desc') {
            const dir = mode === 'captured-asc' ? 1 : -1;
            return function (a, b) {
                const aEmpty = a.captured === '';
                const bEmpty = b.captured === '';
                if (aEmpty !== bEmpty) return aEmpty ? 1 : -1; // fotky bez data pořízení jsou na konci
                if (!aEmpty && a.captured !== b.captured) return a.captured < b.captured ? -dir : dir;
                return bazantikCompareTitle(a, b) * dir;
            };
        }
        return null;
    }
    
    // Vloží jednu fotku na správné místo v seřazeném albu (půlením intervalu, bez řazení celého alba)
    function bazantikInsertSorted($li, mode) {
        const cmp = bazantikComparator(mode);
        const el = $li[0];
        if (!cmp || !el) return;
        
        const entry = bazantikEntryOf(el);
        if (mode.indexOf('captured') === 0 && entry.captured === '') return; // bez data zůstane na konci
        
        const others = $mediaList.children('.bazantik-media-item').get()
        .filter(function (node) { return node !== el; })
        .map(bazantikEntryOf);
        
        let lo = 0;
        let hi = others.length;
        while (lo < hi) {
            const mid = (lo + hi) >> 1;
            if (cmp(others[mid], entry) <= 0) {
                lo = mid + 1;
            } else {
                hi = mid;
            }
        }
        
        if (lo < others.length) {
            others[lo].el.parentNode.insertBefore(el, others[lo].el);
            $mediaIds.val($mediaList.children('.bazantik-media-item').map(function () {
                return Number(this.getAttribute('data-id'));
            }).get().join(','));
        }
    }
    
    // ==========================================
    // Doplnění fotek nahraných do alba, které v seznamu chybí
    // ==========================================
    $('#bazantik-missing-dismiss').on('click', function () {
        const $notice = $('#bazantik-missing-notice');
        let items = [];
        try {
            items = JSON.parse($notice.attr('data-missing') || '[]');
        } catch (e) {}
        
        // Nabídka se zavře trvale (fotky se označí jako viděné)
        bazantikApi('seen', {
            post_id: Number($('#post_ID').val()),
            ids: items.map(function (item) { return item.id; })
        }).catch(function () {});
        $notice.remove();
    });
    
    $('#bazantik-missing-add').on('click', function () {
        const $notice = $('#bazantik-missing-notice');
        let items = [];
        try {
            items = JSON.parse($notice.attr('data-missing') || '[]');
        } catch (e) {}
        
        items.forEach(function (item) {
            bazantikAppendAttachment({ id: item.id, attributes: item.attributes });
        });
        $notice.remove();
    });
    
    // ==========================================
    // Náhled videa zachycený v prohlížeči + dokončení zpracování při zavření stránky
    // ==========================================
    function bazantikCapturePoster(file) {
        return new Promise(function (resolve) {
            const url = URL.createObjectURL(file);
            const video = document.createElement('video');
            let finished = false;
            let dims = null;
            let seekRequested = false;
            let playTried = false;
            let ticks = 0;
            let poll = null;
            let timer = null;
            
            // Prvek musí být v dokumentu (iOS jinak často nevykreslí snímek), ale mimo obrazovku
            video.muted = true;
            video.defaultMuted = true;
            video.setAttribute('muted', '');
            video.setAttribute('playsinline', '');
            video.playsInline = true;
            video.preload = 'auto';
            video.style.cssText = 'position:fixed;left:-9999px;top:0;width:2px;height:2px;opacity:0;pointer-events:none;';
            document.body.appendChild(video);
            
            const done = function (result) {
                if (finished) return;
                finished = true;
                clearInterval(poll);
                clearTimeout(timer);
                try { video.pause(); } catch (e) {}
                video.removeAttribute('src');
                video.load();
                video.remove();
                URL.revokeObjectURL(url);
                resolve(result);
            };
            
            const grab = function () {
                const w = video.videoWidth;
                const h = video.videoHeight;
                if (!w || !h) return false;
                
                try {
                    const scale = Math.min(1, 1280 / Math.max(w, h));
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.round(w * scale);
                    canvas.height = Math.round(h * scale);
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    
                    // Nedekódovaný snímek je průhledný: počkáme na další pokus
                    if (ctx.getImageData(canvas.width >> 1, canvas.height >> 1, 1, 1).data[3] === 0) return false;
                    
                    canvas.toBlob(function (blob) {
                        done({ blob: blob, width: w, height: h });
                    }, 'image/jpeg', 0.82);
                    return true;
                } catch (e) {
                    return false;
                }
            };
            
            poll = setInterval(function () {
                ticks++;
                
                if (video.videoWidth && video.videoHeight) {
                    dims = { width: video.videoWidth, height: video.videoHeight };
                }
                
                if (video.readyState >= 2 && dims) {
                    if (!seekRequested) {
                        seekRequested = true;
                        try { video.currentTime = Math.min(1, (video.duration || 2) / 2); } catch (e) {}
                        return;
                    }
                    if (!video.seeking && grab()) return;
                }
                
                // iOS bez gesta data nenačte: krátké ztlumené přehrání to obvykle odblokuje
                if (ticks >= 8 && !playTried) {
                    playTried = true;
                    const p = video.play();
                    if (p && typeof p.then === 'function') {
                        p.then(function () { video.pause(); }).catch(function () {});
                    }
                }
            }, 300);
            
            timer = setTimeout(function () {
                // Snímek se nepodařilo zachytit, ale rozměry známe: pošleme aspoň ty
                done(dims ? { blob: null, width: dims.width, height: dims.height } : null);
            }, 15000);
            
            video.addEventListener('error', function () {
                done(dims ? { blob: null, width: dims.width, height: dims.height } : null);
            });
            
            video.src = url;
        });
    }
    
    function bazantikUploadPoster(attachment) {
        const job = posterJobs.get(attachment);
        if (!job) return;
        posterJobs.delete(attachment);
        
        job.promise.then(function (result) {
            if (!result) return null;
            
            // Náhled se nepodařilo zachytit (typicky iOS), ale rozměry známe: pošleme aspoň ty (správná orientace dlaždice)
            if (!result.blob) {
                return bazantikApi('video-info', {
                    attachment_id: attachment.id,
                    width: result.width,
                    height: result.height,
                    last_modified: job.lastModified
                }).then(function (res) {
                    if (res && res.captured_sort) {
                        $mediaList.find('.bazantik-media-item[data-id="' + attachment.id + '"]').attr('data-captured', res.captured_sort);
                        bazantikScheduleDetect();
                    }
                });
            }
            
            const body = new FormData();
            body.append('attachment_id', attachment.id);
            body.append('width', result.width);
            body.append('height', result.height);
            body.append('last_modified', job.lastModified);
            body.append('poster', result.blob, 'poster.jpg');
            
            return wp.apiFetch({ path: '/bazantik-photo-gallery/v1/video-poster', method: 'POST', body: body }).then(function (res) {
                const $li = $mediaList.find('.bazantik-media-item[data-id="' + attachment.id + '"]');
                if (res && res.url) {
                    $li.find('.bazantik-media-preview img').attr('src', res.url);
                }
                if (res && res.captured_sort) {
                    $li.attr('data-captured', res.captured_sort);
                    bazantikScheduleDetect();
                }
            });
        }).catch(function () {});
    }
    
    // Zavření stránky při nezpracovaných fotkách: server dokončí zpracování sám, bez čekání na hodinový cron
    function bazantikKickChain() {
        if (bazantikCountStates().waiting === 0) return;
        
        wp.apiFetch({
            path: '/bazantik-photo-gallery/v1/chain',
            method: 'POST',
            keepalive: true,
            data: {}
        }).catch(function () {});
    }
    
    window.addEventListener('pagehide', bazantikKickChain);
    
    // Probíhající nahrávání: při opuštění stránky se prohlížeč zeptá (nahrávání by se přerušilo)
    window.addEventListener('beforeunload', function (e) {
        if (uploadTotal > 0 && (uploadDone + uploadFailed) < uploadTotal) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
    
    // Po dokončení nahrávání se zpracování spustí na serveru (nejvýše jednou za 30 s)
    let bazantikLastChainKick = 0;
    function bazantikKickChainSoon() {
        if (Date.now() - bazantikLastChainKick < 30000) return;
        bazantikLastChainKick = Date.now();
        wp.apiFetch({ path: '/bazantik-photo-gallery/v1/chain', method: 'POST', data: {} }).catch(function () {});
    }
    
    // Přepínání boxů pro tajný odkaz a heslo
    $('#bazantik_visibility').on('change', function () {
        const val = $(this).val();
        $('#bazantik-unlisted-box').toggle(val === 'unlisted');
        $('#bazantik-password-box').toggle(val === 'password');
    });

    // Album s ochranou heslem musí mít heslo: upozornění u pole a album se neuloží (bez dosavadního hesla a bez nového)
    const bazantikPostForm = document.getElementById('post');
    if (bazantikPostForm) {
        bazantikPostForm.addEventListener('submit', function (e) {
            const field = document.getElementById('bazantik_password');
            if (!field || $('#bazantik_visibility').val() !== 'password' || field.value !== '') return;
            const flag = field.closest('.bazantik-pw-wrap').nextElementSibling;
            const keepsExisting = flag && flag.classList.contains('bazantik-pw-changed') && flag.value === '0';
            if (keepsExisting || !window.bazantikRequirePassword || !window.bazantikPwReveal) return;
            e.preventDefault();
            e.stopPropagation();
            window.bazantikRequirePassword(field, window.bazantikPwReveal.albumRequired);
        }, true);
    }
});
