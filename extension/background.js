/**
 * PIXN Sync Pro - Background Service Worker (Manifest V3)
 * Bridges Pix'n Retro Discord session authentication and communicates with PIXN Manager Pro.
 */

const DEFAULT_SERVER = 'http://127.0.0.1:8989';

// Retrieve session cookie from pixn-retro.dedyn.io
async function getPixnSessionCookie() {
    try {
        const cookie = await chrome.cookies.get({
            url: 'https://pixn-retro.dedyn.io',
            name: 'session'
        });
        if (cookie && cookie.value) {
            return 'session=' + cookie.value;
        }
    } catch (e) {
        console.warn('Error reading cookie via chrome.cookies:', e);
    }
    return null;
}

// Get configured target server from extension storage
async function getTargetServer() {
    return new Promise((resolve) => {
        chrome.storage.local.get(['pixn_target_server'], (result) => {
            let srv = result.pixn_target_server || DEFAULT_SERVER;
            resolve(srv.replace(/\/+$/, ''));
        });
    });
}

// Set configured target server
async function setTargetServer(serverUrl) {
    const clean = (serverUrl || DEFAULT_SERVER).trim().replace(/\/+$/, '');
    await chrome.storage.local.set({ pixn_target_server: clean });
    return clean;
}

// Message handler
chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (message.action === 'get_session_cookie') {
        getPixnSessionCookie().then(cookie => {
            sendResponse({ success: Boolean(cookie), cookie: cookie });
        });
        return true; // Keep message channel open for async response
    }

    if (message.action === 'get_target_server') {
        getTargetServer().then(server => {
            sendResponse({ server: server });
        });
        return true;
    }

    if (message.action === 'set_target_server') {
        setTargetServer(message.server).then(saved => {
            sendResponse({ success: true, server: saved });
        });
        return true;
    }

    if (message.action === 'check_server_health') {
        const srv = (message.server || DEFAULT_SERVER).replace(/\/+$/, '');
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 3500);

        fetch(`${srv}/save.php?action=get_config&t=${Date.now()}`, {
            method: 'GET',
            signal: controller.signal
        })
        .then(res => res.json())
        .then(data => {
            clearTimeout(timeoutId);
            sendResponse({ online: true, data: data });
        })
        .catch(err => {
            clearTimeout(timeoutId);
            sendResponse({ online: false, error: err.message });
        });
        return true;
    }

    if (message.action === 'forward_sync_data') {
        const srv = (message.server || DEFAULT_SERVER).replace(/\/+$/, '');
        fetch(`${srv}/save.php?action=save_systems`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(message.payload)
        })
        .then(res => res.json())
        .then(resData => {
            sendResponse({ success: true, response: resData });
        })
        .catch(err => {
            sendResponse({ success: false, error: err.message });
        });
        return true;
    }
});
