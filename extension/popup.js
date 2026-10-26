document.addEventListener('DOMContentLoaded', () => {
    const targetServerInput = document.getElementById('targetServerInput');
    const btnSaveServer = document.getElementById('btnSaveServer');
    const serverStatus = document.getElementById('serverStatus');
    const cookieStatus = document.getElementById('cookieStatus');
    const btnOpenAndSync = document.getElementById('btnOpenAndSync');
    const btnOpenPixnManager = document.getElementById('btnOpenPixnManager');

    let currentServer = 'http://127.0.0.1:8989';

    // 1. Load configured server
    chrome.runtime.sendMessage({ action: 'get_target_server' }, (resp) => {
        if (resp && resp.server) {
            currentServer = resp.server;
            targetServerInput.value = currentServer;
        }
        checkServer(currentServer);
    });

    // 2. Check Pix'n Retro session cookie
    chrome.runtime.sendMessage({ action: 'get_session_cookie' }, (resp) => {
        if (resp && resp.success && resp.cookie) {
            cookieStatus.innerHTML = '<span class="dot dot-green"></span> Active Token';
            cookieStatus.style.color = '#34d399';
        } else {
            cookieStatus.innerHTML = '<span class="dot dot-yellow"></span> Not Logged In';
            cookieStatus.style.color = '#fbbf24';
        }
    });

    // Check server health
    function checkServer(url) {
        serverStatus.innerHTML = '<span class="dot dot-yellow"></span> Connecting...';
        serverStatus.style.color = '#94a3b8';

        chrome.runtime.sendMessage({ action: 'check_server_health', server: url }, (resp) => {
            if (resp && resp.online) {
                serverStatus.innerHTML = '<span class="dot dot-green"></span> Online';
                serverStatus.style.color = '#34d399';
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
