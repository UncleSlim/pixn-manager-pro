document.addEventListener('DOMContentLoaded', () => {
    const targetServerInput = document.getElementById('targetServerInput');
    const btnSaveServer = document.getElementById('btnSaveServer');
    const serverStatus = document.getElementById('serverStatus');
    const cookieStatus = document.getElementById('cookieStatus');
    const btnSyncCookie = document.getElementById('btnSyncCookie');
    const btnOpenAndSync = document.getElementById('btnOpenAndSync');
    const btnOpenPixnManager = document.getElementById('btnOpenPixnManager');

    let currentServer = 'http://127.0.0.1:8989';
    let currentCookie = null;

    // 1. Load configured server
    chrome.runtime.sendMessage({ action: 'get_target_server' }, (resp) => {
        if (resp && resp.server) {
            currentServer = resp.server;
            targetServerInput.value = currentServer;
            const badge = document.getElementById('serverImportBadge');
            if (badge) {
                if (resp.bundled_server && resp.bundled_server === currentServer) {
                    badge.style.display = 'inline-block';
                    badge.title = `Pre-configured from PIXN setup (${resp.bundled_server})`;
                } else {
                    badge.style.display = 'none';
                }
            }
        }
        checkServer(currentServer);
    });

    // 2. Check Pix'n Retro session cookie & auto-forward
    function checkCookie() {
        chrome.runtime.sendMessage({ action: 'get_session_cookie' }, (resp) => {
            if (resp && resp.success && resp.cookie) {
                currentCookie = resp.cookie;
                cookieStatus.innerHTML = '<span class="dot dot-green"></span> Active Token';
                cookieStatus.style.color = '#34d399';
                if (btnSyncCookie) btnSyncCookie.style.display = 'flex';

                // Automatically forward cookie to target server if online
                chrome.runtime.sendMessage({
                    action: 'save_session_cookie',
                    server: currentServer,
                    cookie: currentCookie
                }, (saveResp) => {
                    if (saveResp && saveResp.success) {
                        cookieStatus.innerHTML = '<span class="dot dot-green"></span> Active &amp; Synced to PIXN';
                    }
                });
            } else {
                currentCookie = null;
                cookieStatus.innerHTML = '<span class="dot dot-yellow"></span> Not Logged In';
                cookieStatus.style.color = '#fbbf24';
                if (btnSyncCookie) btnSyncCookie.style.display = 'none';
            }
        });
    }
    checkCookie();

    // Check server health
    function checkServer(url) {
        serverStatus.innerHTML = '<span class="dot dot-yellow"></span> Connecting...';
        serverStatus.style.color = '#94a3b8';

        chrome.runtime.sendMessage({ action: 'check_server_health', server: url }, (resp) => {
            if (resp && resp.online) {
                serverStatus.innerHTML = '<span class="dot dot-green"></span> Online';
                serverStatus.style.color = '#34d399';
                if (currentCookie) {
                    chrome.runtime.sendMessage({
                        action: 'save_session_cookie',
                        server: url,
                        cookie: currentCookie
                    }, (saveResp) => {
                        if (saveResp && saveResp.success) {
                            cookieStatus.innerHTML = '<span class="dot dot-green"></span> Active &amp; Synced to PIXN';
                        }
                    });
                }
            } else {
                serverStatus.innerHTML = '<span class="dot dot-red"></span> Offline';
                serverStatus.style.color = '#f87171';
            }
        });
    }

    // Save server button
    btnSaveServer.addEventListener('click', () => {
        const val = (targetServerInput.value || '').trim().replace(/\/+$/, '');
        if (!val) return;
        btnSaveServer.disabled = true;
        btnSaveServer.textContent = 'Saving...';

        chrome.runtime.sendMessage({ action: 'set_target_server', server: val }, (resp) => {
            btnSaveServer.disabled = false;
            btnSaveServer.textContent = 'Saved!';
            currentServer = val;
            setTimeout(() => { btnSaveServer.textContent = 'Save'; }, 1500);
            checkServer(currentServer);
        });
    });

    // Manual sync cookie button
    if (btnSyncCookie) {
        btnSyncCookie.addEventListener('click', () => {
            if (!currentCookie) return;
            btnSyncCookie.disabled = true;
            btnSyncCookie.innerHTML = '<span>Saving Cookie...</span>';

            chrome.runtime.sendMessage({
                action: 'save_session_cookie',
                server: currentServer,
                cookie: currentCookie
            }, (resp) => {
                btnSyncCookie.disabled = false;
                if (resp && resp.success) {
                    btnSyncCookie.innerHTML = '<span>✅ Cookie Saved to PIXN!</span>';
                    cookieStatus.innerHTML = '<span class="dot dot-green"></span> Active &amp; Synced to PIXN';
                    setTimeout(() => {
                        btnSyncCookie.innerHTML = '<span>🔑 Sync Cookie to PIXN</span>';
                    }, 2500);
                } else {
                    btnSyncCookie.innerHTML = '<span>❌ Failed to save</span>';
                    setTimeout(() => {
                        btnSyncCookie.innerHTML = '<span>🔑 Sync Cookie to PIXN</span>';
                    }, 2500);
                }
            });
        });
    }

    // Open Pix'n Retro & Sync
    btnOpenAndSync.addEventListener('click', () => {
        const url = `https://pixn-retro.dedyn.io/releases#autosync&server=${encodeURIComponent(currentServer)}`;
        chrome.tabs.create({ url: url });
        window.close();
    });

    // Open PIXN Web Manager
    btnOpenPixnManager.addEventListener('click', () => {
        chrome.tabs.create({ url: currentServer });
        window.close();
    });
});
