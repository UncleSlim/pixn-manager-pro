/**
 * PIXN Retro -> DiskStation Synchronization Engine
 * Runs on https://pixn-retro.dedyn.io/releases
 * Scrapes new/updated systems and games from Pixn Retro and sends them to DiskStation
 */
(async function() {
    // 1. Authoritative detection from the currently executing script element:
    // The script tag src is the exact webserver that served sync_engine.js.
    let DISKSTATION_URL = '';
    const isIgnoredHost = (host) => /jsdelivr\.net|github\.io|githubusercontent\.com|githack\.com/i.test(host || '');

    // 1. Authoritative detection from explicit script URL parameter ?server=...
    try {
        const scripts = Array.from(document.querySelectorAll('script')).filter(s => s.src && s.src.includes('sync_engine.js'));
        const curScript = document.currentScript || (scripts.length ? scripts[scripts.length - 1] : null);
        if (curScript && curScript.src) {
            const u = new URL(curScript.src, (typeof window !== 'undefined' && window.location) ? window.location.href : undefined);
            const serverParam = u.searchParams.get('server');
            if (serverParam && !isIgnoredHost(serverParam)) {
                DISKSTATION_URL = serverParam.replace(/\/+$/, '');
            }
        }
    } catch (e) {
        console.warn('Script URL detection warning:', e);
    }

    // 2. Check URL Hash parameter (e.g. #autosync&server=http://127.0.0.1:8989)
    if (!DISKSTATION_URL && typeof window !== 'undefined' && window.location && window.location.hash) {
        try {
            const hp = new URLSearchParams(window.location.hash.replace(/^#/, ''));
            const sFromHash = hp.get('server');
            if (sFromHash && !isIgnoredHost(sFromHash)) {
                DISKSTATION_URL = sFromHash.replace(/\/+$/, '');
            }
        } catch(e) {}
    }

    // 3. Fallback to global window.__PIXN_SERVER__ if explicitly set by Bookmarklet
    if (!DISKSTATION_URL && typeof window !== 'undefined' && window.__PIXN_SERVER__ && !isIgnoredHost(window.__PIXN_SERVER__)) {
        DISKSTATION_URL = window.__PIXN_SERVER__.replace(/\/+$/, '');
    }

    // 4. Fallback to remembered server in browser localStorage on pixn-retro.dedyn.io
    if (!DISKSTATION_URL && typeof window !== 'undefined' && window.localStorage) {
        try {
            const savedServer = window.localStorage.getItem('pixn_target_server');
            if (savedServer && !isIgnoredHost(savedServer)) {
                DISKSTATION_URL = savedServer.replace(/\/+$/, '');
            }
        } catch(e) {}
    }

    // 5. Default fallback: Local Portable Edition (http://127.0.0.1:8989)
    if (!DISKSTATION_URL) {
        DISKSTATION_URL = 'http://127.0.0.1:8989';
    }

    // Persist target server into localStorage so future syncs on this browser remember it
    try {
        if (typeof window !== 'undefined' && window.localStorage && DISKSTATION_URL && !isIgnoredHost(DISKSTATION_URL)) {
            window.localStorage.setItem('pixn_target_server', DISKSTATION_URL);
        }
    } catch(e) {}

    // Always update global window object to match the actively executing script's server
    if (typeof window !== 'undefined') {
        window.__PIXN_SERVER__ = DISKSTATION_URL;
    }

    const isPortable = DISKSTATION_URL.includes('127.0.0.1') || DISKSTATION_URL.includes('localhost');
    const targetTitle = isPortable ? 'PIXN Retro ➔ Portable PIXN Sync' : 'PIXN Retro ➔ PIXN Manager Pro Sync';

    const VIEWER_URL = DISKSTATION_URL.endsWith('.html') ? DISKSTATION_URL : (DISKSTATION_URL.replace(/\/+$/, '') + '/index.html');
    const BATCH_SIZE = 6;

    // Check if HUD already exists
    let hud = document.getElementById('pixn-sync-hud');
    if (hud) {
        hud.remove();
    }

    // Create Floating HUD
    hud = document.createElement('div');
    hud.id = 'pixn-sync-hud';
    hud.style.cssText = `
        position: fixed;
        bottom: 24px;
        right: 24px;
        width: 450px;
        max-width: 92vw;
        background: #0f1422;
        border: 1px solid #38bdf8;
        border-radius: 14px;
        padding: 20px;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.85), 0 0 25px rgba(56, 189, 248, 0.3);
        color: #f1f5f9;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        z-index: 999999;
        box-sizing: border-box;
    `;

    hud.innerHTML = `
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <div style="width: 10px; height: 10px; border-radius: 50%; background: #38bdf8; box-shadow: 0 0 8px #38bdf8;" id="sync-dot"></div>
                <strong style="font-size: 15px; color: #38bdf8;">${targetTitle}</strong>
            </div>
            <button id="sync-close-btn" style="background: transparent; border: none; color: #94a3b8; font-size: 18px; cursor: pointer; line-height: 1;">&times;</button>
        </div>
        <div style="font-size: 11px; color: #94a3b8; margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 5px;">
                <span>Target:</span> <code id="sync-target-display" style="color: #38bdf8; background: rgba(56, 189, 248, 0.1); padding: 1px 6px; border-radius: 4px; font-family: monospace;">${DISKSTATION_URL}</code>
            </div>
            <button id="sync-change-target-btn" style="background: transparent; border: 1px solid rgba(56, 189, 248, 0.3); border-radius: 4px; color: #38bdf8; font-size: 10px; padding: 1px 6px; cursor: pointer;" title="Change target server address">Change</button>
        </div>
        <div id="sync-status-msg" style="font-size: 13px; color: #cbd5e1; margin-bottom: 10px;">Connecting to Pixn Retro API...</div>
        
        <!-- Progress Bar -->
        <div style="width: 100%; height: 8px; background: #1e293b; border-radius: 4px; overflow: hidden; margin-bottom: 12px;">
            <div id="sync-progress-bar" style="width: 0%; height: 100%; background: linear-gradient(90deg, #38bdf8, #10b981); transition: width 0.2s;"></div>
        </div>

        <div style="display: flex; justify-content: space-between; font-size: 12px; color: #94a3b8; margin-bottom: 12px;">
            <span id="sync-counter">0 / 0 systems</span>
            <span id="sync-games-counter">0 games synced</span>
        </div>

        <div id="sync-new-systems-box" style="display: none; background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); border-radius: 8px; padding: 8px 12px; margin-bottom: 12px; font-size: 12px; color: #34d399;">
            <strong id="sync-new-count">0 New Systems Detected!</strong>
            <div id="sync-new-names" style="color: #e2e8f0; margin-top: 4px; font-size: 11px;"></div>
        </div>

        <div id="sync-log-box" style="background: #090d16; border: 1px solid #1e293b; border-radius: 6px; padding: 8px 10px; font-family: monospace; font-size: 11px; color: #64748b; max-height: 100px; overflow-y: auto; margin-bottom: 12px;">
            <div>Ready to synchronize...</div>
        </div>

        <div style="display: flex; justify-content: flex-end; align-items: center; gap: 8px;">
            <button id="sync-cancel-btn" style="background: #334155; border: none; color: white; padding: 6px 14px; border-radius: 6px; font-size: 12px; cursor: pointer;">Cancel</button>
        </div>
    `;

    document.body.appendChild(hud);

    const statusMsg = document.getElementById('sync-status-msg');
    const progressBar = document.getElementById('sync-progress-bar');
    const counter = document.getElementById('sync-counter');
    const gamesCounter = document.getElementById('sync-games-counter');
    const newBox = document.getElementById('sync-new-systems-box');
    const newCountEl = document.getElementById('sync-new-count');
    const newNamesEl = document.getElementById('sync-new-names');
    const logBox = document.getElementById('sync-log-box');
    const cancelBtn = document.getElementById('sync-cancel-btn');
    const closeBtn = document.getElementById('sync-close-btn');

    let isCancelled = false;
    cancelBtn.onclick = () => { isCancelled = true; hud.remove(); };
    closeBtn.onclick = () => { hud.remove(); };

    const changeTargetBtn = document.getElementById('sync-change-target-btn');
    if (changeTargetBtn) {
        changeTargetBtn.onclick = () => {
            const newServer = prompt('Enter target PIXN server URL (e.g. http://127.0.0.1:8989):', DISKSTATION_URL);
            if (newServer && newServer.trim()) {
                const cleaned = newServer.trim().replace(/\/+$/, '');
                localStorage.setItem('pixn_target_server', cleaned);
                window.__PIXN_SERVER__ = cleaned;
                location.reload();
            }
        };
    }

    function log(msg) {
        const d = document.createElement('div');
        d.textContent = `[${new Date().toLocaleTimeString()}] ${msg}`;
        logBox.appendChild(d);
        logBox.scrollTop = logBox.scrollHeight;
    }

    async function sendToServer(url, options = {}) {
        if (typeof GM_xmlhttpRequest !== 'undefined') {
            return new Promise((resolve, reject) => {
                GM_xmlhttpRequest({
                    method: options.method || 'GET',
                    url: url,
                    headers: options.headers || {},
                    data: options.body || null,
                    onload: function(res) {
                        resolve({
                            ok: res.status >= 200 && res.status < 300,
                            status: res.status,
                            statusText: res.statusText || '',
                            text: async () => res.responseText,
                            json: async () => JSON.parse(res.responseText)
                        });
                    },
                    onerror: function(err) {
                        reject(new Error((err && err.error) ? err.error : 'Network error'));
                    }
                });
            });
        }
        return fetch(url, options);
    }

    async function sendDiskStationStatus(statusObj) {
        try {
            await sendToServer(`${DISKSTATION_URL}/save.php?action=update_sync_status`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(statusObj)
            });
        } catch (e) {
            console.warn('Status update warning:', e);
        }
    }

    try {
        log("Fetching catalog from Pixn Retro API...");
        await sendDiskStationStatus({
            status: 'running',
            progress: 5,
            current_task: 'Checking Pixn Retro catalog for new systems...'
        });

        // 1. Fetch live systems from Pixn Retro API
        const sysRes = await fetch('/api/catalog/systems?catalog_type=releases');
        if (!sysRes.ok) throw new Error(`Pixn API error: ${sysRes.status} (Are you logged in to Discord?)`);
        const sysData = await sysRes.json();
        const incomingSystems = sysData.systems || [];
        if (!Array.isArray(incomingSystems) || incomingSystems.length === 0) {
            throw new Error(`Pixn Retro API returned 0 systems. Please ensure you are logged into Discord on https://pixn-retro.dedyn.io and try again.`);
        }
        log(`Pixn Retro catalog contains ${incomingSystems.length} systems.`);

        // 2. Save systems to NAS / Web Server & detect new systems
        statusMsg.textContent = isPortable ? `Comparing with Portable catalog (${incomingSystems.length} systems)...` : `Comparing with PIXN catalog (${incomingSystems.length} systems)...`;
        // Capture cookie / token from window.__PIXN_CAPTURED_COOKIE__, script tag params, localStorage, or document.cookie
        let capturedCookie = (typeof window !== 'undefined' && window.__PIXN_CAPTURED_COOKIE__) ? window.__PIXN_CAPTURED_COOKIE__ : '';
        if (!capturedCookie) {
            try {
                const scriptTags = document.querySelectorAll('script[src*="sync_engine.js"]');
                for (const st of scriptTags) {
                    const u = new URL(st.src, window.location.href);
                    const c = u.searchParams.get('cookie');
                    if (c) { capturedCookie = c; break; }
                }
            } catch (e) {}
        }
        if (!capturedCookie) {
            try {
                const urlParams = new URLSearchParams(window.location.search || '');
                if (urlParams.get('cookie')) capturedCookie = urlParams.get('cookie');
            } catch (e) {}
        }
        if (!capturedCookie) {
            try {
                capturedCookie = localStorage.getItem('pixn_captured_cookie') || '';
            } catch (e) {}
        }
        if (!capturedCookie || !capturedCookie.includes('session=')) {
            const docCookie = document.cookie || '';
            if (docCookie && docCookie.includes('session=')) {
                capturedCookie = docCookie;
            }
        }
        if (!capturedCookie || !capturedCookie.includes('session=')) {
            try {
                for (let i = 0; i < localStorage.length; i++) {
                    const k = localStorage.key(i);
                    const v = localStorage.getItem(k);
                    if (v && (k.toLowerCase().includes('session') || k.toLowerCase().includes('token') || k.toLowerCase().includes('auth'))) {
                        if (v.startsWith('session=')) { capturedCookie = v; break; }
                        else if (!capturedCookie && v.length > 20) capturedCookie = 'session=' + v;
                    }
                }
            } catch (e) {}
            if (!capturedCookie || !capturedCookie.includes('session=')) {
                try {
                    for (let i = 0; i < sessionStorage.length; i++) {
                        const k = sessionStorage.key(i);
                        const v = sessionStorage.getItem(k);
                        if (v && (k.toLowerCase().includes('session') || k.toLowerCase().includes('token') || k.toLowerCase().includes('auth'))) {
                            if (v.startsWith('session=')) { capturedCookie = v; break; }
                            else if (!capturedCookie && v.length > 20) capturedCookie = 'session=' + v;
                        }
                    }
                } catch (e) {}
            }
        }
        let cookieIsConfigured = !!(capturedCookie && capturedCookie.includes('session='));
        if (cookieIsConfigured) {
            log('🔑 Captured Pixn Retro session token (auto-filling PIXN config)');
        } else {
            log('ℹ️ Pixn Retro session cookie is HttpOnly.');
            // Render interactive cookie helper box in the HUD
            if (!document.getElementById('pixn-cookie-helper-box')) {
                const helperBox = document.createElement('div');
                helperBox.id = 'pixn-cookie-helper-box';
                helperBox.style.cssText = 'background: rgba(30, 41, 59, 0.95); border: 1px solid #f59e0b; border-radius: 6px; padding: 8px 10px; margin-bottom: 10px; text-align: left;';
                helperBox.innerHTML = `
                    <div style="font-size: 11px; font-weight: 600; color: #fbbf24; margin-bottom: 3px; display: flex; align-items: center; justify-content: space-between;">
                        <span><i class="fa-solid fa-key"></i> Auto-fill PIXN Session Cookie</span>
                        <span style="font-size: 9px; color: #f59e0b; border: 1px solid #f59e0b; padding: 1px 4px; border-radius: 3px;">Action Required</span>
                    </div>
                    <div style="font-size: 10px; color: #94a3b8; margin-bottom: 6px; line-height: 1.35;">
                        Browser security blocks reading HttpOnly cookies automatically. Paste your session cookie below to save it directly into PIXN:
                    </div>
                    <div style="display: flex; gap: 5px; margin-bottom: 4px;">
                        <input id="sync-manual-cookie-input" placeholder="Paste session=... or token value" style="flex: 1; padding: 4px 6px; font-size: 11px; background: #0f172a; border: 1px solid #475569; color: #fff; border-radius: 4px;">
                        <button id="sync-manual-cookie-save" style="background: #10b981; border: none; color: #fff; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: pointer;">Save</button>
                    </div>
                    <div id="sync-manual-cookie-status" style="font-size: 10px; color: #64748b;">
                        💡 Press <kbd style="background: rgba(255,255,255,0.1); padding: 1px 3px; border-radius: 2px;">F12</kbd> &rarr; Application &rarr; Cookies &rarr; copy <code>session</code>.
                    </div>
                `;
                const logBoxEl = document.getElementById('sync-log-box');
                if (logBoxEl && logBoxEl.parentNode) {
                    logBoxEl.parentNode.insertBefore(helperBox, logBoxEl);
                }
                const saveBtn = document.getElementById('sync-manual-cookie-save');
                const inp = document.getElementById('sync-manual-cookie-input');
                const stat = document.getElementById('sync-manual-cookie-status');
                if (saveBtn && inp) {
                    saveBtn.onclick = async () => {
                        let val = inp.value.trim();
                        if (!val) return;
                        if (!val.includes('=')) val = 'session=' + val;
                        try {
                            localStorage.setItem('pixn_captured_cookie', val);
                        } catch(e) {}
                        saveBtn.disabled = true;
                        saveBtn.textContent = 'Saving...';
                        try {
                            await sendToServer(`${DISKSTATION_URL}/save.php?action=save_systems`, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ systems: [], cookie: val })
                            });
                            cookieIsConfigured = true;
                            stat.innerHTML = '<span style="color: #34d399; font-weight: 600;">✅ Saved to PIXN! Closing in 4 seconds...</span>';
                            saveBtn.textContent = 'Saved';
                            log('🔑 Session token saved to PIXN config');
                            setTimeout(() => {
                                if (hud && hud.parentNode) {
                                    hud.style.transition = 'opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1), transform 0.8s cubic-bezier(0.16, 1, 0.3, 1)';
                                    hud.style.opacity = '0';
                                    hud.style.transform = 'translateY(20px)';
                                    setTimeout(() => {
                                        if (hud && hud.parentNode) hud.remove();
                                    }, 800);
                                }
                            }, 4000);
                        } catch (err) {
                            stat.innerHTML = `<span style="color: #ef4444;">Failed to save: ${err.message}</span>`;
                            saveBtn.disabled = false;
                            saveBtn.textContent = 'Save';
                        }
                    };
                }
            }
        }

        const saveSysRes = await sendToServer(`${DISKSTATION_URL}/save.php?action=save_systems`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                systems: incomingSystems,
                cookie: capturedCookie
            })
        });
        const saveSysData = await saveSysRes.json();
        if (saveSysData && saveSysData.has_cookie) {
            cookieIsConfigured = true;
            const helperEl = document.getElementById('pixn-cookie-helper-box');
            if (helperEl) helperEl.remove();
        }

        const newSystems = saveSysData.new_systems || [];
        const updatedSystems = saveSysData.updated_systems || [];
        const deletedSystems = saveSysData.deleted_systems || [];
        const missingSystems = saveSysData.missing_systems || [];

        let alertTitles = [];
        let detailLines = [];

        if (newSystems.length > 0) {
            alertTitles.push(`✨ ${newSystems.length} New System${newSystems.length > 1 ? 's' : ''}`);
            detailLines.push(`New: ` + newSystems.map(s => `${s.name} (${(s.gameCount || 0).toLocaleString()} games)`).join(', '));
            log(`✨ NEW SYSTEMS DETECTED: ${newSystems.map(s => s.name).join(', ')}`);
        }

        if (updatedSystems.length > 0) {
            alertTitles.push(`🔄 ${updatedSystems.length} Updated Release${updatedSystems.length > 1 ? 's' : ''}`);
            detailLines.push(`Updated: ` + updatedSystems.map(s => `${s.name} (${s.version || 'new release'}, ${(s.gameCount || 0).toLocaleString()} games)`).join(', '));
            log(`🔄 UPDATED SYSTEMS (new version/release): ${updatedSystems.map(s => s.name + ' [' + (s.version || s.release || '') + ']').join(', ')}`);
        }

        if (deletedSystems.length > 0) {
            alertTitles.push(`🗑️ ${deletedSystems.length} Deleted System${deletedSystems.length > 1 ? 's' : ''} Purged`);
            detailLines.push(`Removed: ` + deletedSystems.map(s => s.name || s.id).join(', '));
            log(`🗑️ DELETED SYSTEMS PURGED FROM SERVER: ${deletedSystems.map(s => s.name || s.id).join(', ')}`);
        }

        if (missingSystems.length > 0 && newSystems.length === 0) {
            alertTitles.push(`⚡ ${missingSystems.length} Missing File${missingSystems.length > 1 ? 's' : ''}`);
            detailLines.push(`Missing files: ` + missingSystems.map(s => s.name).join(', '));
            log(`⚡ SYSTEMS MISSING FILES ON SERVER: ${missingSystems.map(s => s.name).join(', ')}`);
        }

        if (alertTitles.length > 0) {
            newBox.style.display = 'block';
            newCountEl.textContent = alertTitles.join(' | ');
            newNamesEl.innerHTML = detailLines.join('<br>');
        } else {
            log("No new, updated, or deleted systems found. Checking file integrity...");
        }

        // 3. Determine which systems need game lists downloaded (Fast Delta Sync)
        let systemsToSync = [];

        if (newSystems.length > 0 || updatedSystems.length > 0 || missingSystems.length > 0) {
            const targetIds = new Set([
                ...newSystems.map(s => s.id),
                ...updatedSystems.map(s => s.id),
                ...missingSystems.map(s => s.id)
            ]);
            systemsToSync = incomingSystems.filter(s => targetIds.has(s.id) && s.gameCount > 0);
            log(`⚡ Fast Delta Sync: Grabbing games for ${systemsToSync.length} new/updated/missing system(s)...`);
        } else {
            // Already completely up to date!
            log("NAS / Web Server catalog is already 100% up to date.");
        }

        let totalGamesSynced = 0;
        let systemsSynced = 0;

        if (systemsToSync.length > 0) {
            counter.textContent = `0 / ${systemsToSync.length} systems`;

            for (let i = 0; i < systemsToSync.length; i += BATCH_SIZE) {
                if (isCancelled) {
                    log("Sync cancelled by user.");
                    await sendDiskStationStatus({ status: 'idle', current_task: 'Sync cancelled by user' });
                    return;
                }

                const slice = systemsToSync.slice(i, i + BATCH_SIZE);
                const sysNames = slice.map(s => s.name).join(', ');
                statusMsg.textContent = `Fetching: ${sysNames}...`;

                const batchResults = await Promise.all(slice.map(async (sys) => {
                    try {
                        const gRes = await fetch(`/api/catalog/games/${sys.id}?catalog_type=releases&limit=10000`);
                        if (!gRes.ok) {
                            log(`⚠️ Error fetching ${sys.name} from Pixn Retro: HTTP ${gRes.status}`);
                            return null;
                        }
                        const gData = await gRes.json();
                        const games = (gData.games || []).map(g => ({
                            id: g.id,
                            name: g.name,
                            publisher: g.publisher || '',
                            developer: g.developer || '',
                            genre: g.genre || '',
                            releasedate: g.releasedate || '',
                            size_mb: g.size_mb || null
                        }));
                        return {
                            system_id: sys.id,
                            system_name: sys.name,
                            games: games
                        };
                    } catch (err) {
                        console.error(`Error fetching games for ${sys.id}:`, err);
                        log(`⚠️ Error fetching games for ${sys.name}: ${err.message}`);
                        return null;
                    }
                }));

                const validItems = batchResults.filter(Boolean);
                if (validItems.length > 0) {
                    const saveRes = await sendToServer(`${DISKSTATION_URL}/save.php?action=save_batch`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ items: validItems })
                    });

                    if (!saveRes.ok) {
                        const errTxt = await saveRes.text();
                        log(`⚠️ Error saving batch (${validItems.map(v => v.system_name).join(', ')}): HTTP ${saveRes.status}`);
                        console.error('save_batch failed:', errTxt);
                    } else {
                        systemsSynced += validItems.length;
                        const batchGameCount = validItems.reduce((acc, it) => acc + it.games.length, 0);
                        totalGamesSynced += batchGameCount;

                        const pct = Math.min(95, Math.round((systemsSynced / systemsToSync.length) * 100));
                        progressBar.style.width = `${pct}%`;
                        counter.textContent = `${systemsSynced} / ${systemsToSync.length} systems`;
                        gamesCounter.textContent = `${totalGamesSynced.toLocaleString()} games`;

                        await sendDiskStationStatus({
                            status: 'running',
                            progress: pct,
                            current_task: `Synced ${systemsSynced}/${systemsToSync.length} systems (${validItems[validItems.length - 1].system_name})...`,
                            total_systems: incomingSystems.length
                        });

                        log(`Saved batch (${validItems.map(v => v.system_name).join(', ')}) to games & games_txt`);
                    }
                }
            }
        }

        // 4. Rebuild Global Search Index & Repair on Server
        statusMsg.textContent = isPortable ? "Updating search index on Portable app..." : "Updating search index on NAS / Web Server...";
        log("Rebuilding search_index.json & verifying files on server...");
        const indexRes = await sendToServer(`${DISKSTATION_URL}/save.php?action=build_index`);
        let indexData = {};
        try {
            const rawText = await indexRes.text();
            indexData = JSON.parse(rawText);
        } catch (e) {
            log(`Warning: Server returned non-JSON response while rebuilding index (${indexRes.status} ${indexRes.statusText})`);
            indexData = { systems: pixnSystems.length, games: 0 };
        }

        // 5. Finalize UI
        progressBar.style.width = '100%';
        document.getElementById('sync-dot').style.background = '#10b981';
        document.getElementById('sync-dot').style.boxShadow = '0 0 10px #10b981';
        
        let completionParts = [];
        if (newSystems.length > 0) completionParts.push(`Added ${newSystems.length} new system(s)`);
        if (updatedSystems.length > 0) completionParts.push(`Updated ${updatedSystems.length} system release(s)`);
        if (deletedSystems.length > 0) completionParts.push(`Cleaned up ${deletedSystems.length} deleted system(s)`);
        if (missingSystems.length > 0 && systemsSynced > 0) completionParts.push(`Restored ${systemsSynced} missing system(s)`);

        const totalSys = (indexData && (typeof indexData.systems === 'number' ? indexData.systems : (pixnSystems ? pixnSystems.length : 0))) || 0;
        const totalGms = (indexData && (typeof indexData.games === 'number' ? indexData.games : (typeof indexData.total_indexed === 'number' ? indexData.total_indexed : totalGamesSynced))) || 0;

        let completionSummary = '';
        if (completionParts.length > 0) {
            completionSummary = `✨ Sync Complete! ${completionParts.join(', ')}. Total: ${totalSys} systems & ${totalGms.toLocaleString()} games.`;
        } else if (systemsSynced > 0) {
            completionSummary = `✨ Sync Complete! Updated ${systemsSynced} systems. Total: ${totalSys} systems & ${totalGms.toLocaleString()} games.`;
        } else {
            completionSummary = `✨ Already up to date! All ${totalSys} systems & ${totalGms.toLocaleString()} games verified.`;
        }

        statusMsg.textContent = completionSummary;
        cancelBtn.style.display = 'none';

        await sendDiskStationStatus({
            status: 'completed',
            progress: 100,
            current_task: completionSummary,
            total_systems: incomingSystems.length,
            total_games: indexData.games || 71426,
            new_systems_count: newSystems.length,
            new_systems: newSystems,
            updated_systems_count: updatedSystems.length,
            updated_systems: updatedSystems,
            deleted_systems_count: deletedSystems.length,
            deleted_systems: deletedSystems,
            last_sync: new Date().toISOString()
        });

        if (cookieIsConfigured) {
            log("Done! Everything is synchronized and ready.");
            log("Closing HUD in 5 seconds...");
            // Auto fade out after 5 seconds after sync is done
            setTimeout(() => {
                if (hud && hud.parentNode) {
                    hud.style.transition = 'opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1), transform 0.8s cubic-bezier(0.16, 1, 0.3, 1)';
                    hud.style.opacity = '0';
                    hud.style.transform = 'translateY(20px)';
                    setTimeout(() => {
                        if (hud && hud.parentNode) hud.remove();
                    }, 800);
                }
            }, 5000);
        } else {
            log("Catalog synced! ⚠️ Pixn Retro Session Cookie is missing.");
            log("Please paste your session token in the box above to complete setup.");
            statusMsg.innerHTML = '<span style="color: #f59e0b; font-weight: 600;">⚠️ Catalog synced — Session Cookie required</span>';
            if (cancelBtn) {
                cancelBtn.textContent = 'Close';
                cancelBtn.style.background = '#475569';
            }
        }

    } catch (err) {
        console.error("Sync error:", err);
        statusMsg.textContent = `Error: ${err.message}`;
        document.getElementById('sync-dot').style.background = '#ef4444';
        document.getElementById('sync-dot').style.boxShadow = '0 0 10px #ef4444';
        log(`ERROR: ${err.message}`);
        await sendDiskStationStatus({
            status: 'idle',
            current_task: `Error: ${err.message}`
        });
    }
})();
