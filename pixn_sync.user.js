// ==UserScript==
// @name         PIXN Retro -> PIXN Manager Pro 1-Click Sync
// @namespace    https://github.com/UncleSlim/pixn-manager-pro
// @version      1.6
// @description  Adds a floating 1-click Sync button on Pixn Retro releases page to sync new systems and games directly to PIXN Manager Pro
// @match        https://pixn-retro.dedyn.io/*
// @connect      127.0.0.1
// @connect      localhost
// @connect      *
// @grant        GM_cookie
// @grant        GM.cookie
// @grant        GM_xmlhttpRequest
// @run-at       document-idle
// ==/UserScript==

(function() {
    'use strict';

    // Target PIXN Server (Auto-configured: Local Portable default)
    let TARGET_SERVER = 'http://127.0.0.1:8989';

    // Check if server was passed via URL hash (e.g. from Bookmarklet or PIXN Manager Pro)
    if (window.location.hash) {
        try {
            const hashParams = new URLSearchParams(window.location.hash.replace(/^#/, ''));
            const hashServer = hashParams.get('server');
            if (hashServer) {
                TARGET_SERVER = hashServer.replace(/\/+$/, '');
                try { localStorage.setItem('pixn_target_server', TARGET_SERVER); } catch(e) {}
            }
        } catch(e) {}
    }

    // Check localStorage fallback (filtering out legacy script host domain)
    try {
        const savedServer = localStorage.getItem('pixn_target_server');
        if (savedServer && !/jimmy\.langtoft\.dk|jsdelivr\.net/i.test(savedServer)) {
            TARGET_SERVER = savedServer.replace(/\/+$/, '');
        }
    } catch(e) {}

    // Extract Pixn Retro session cookie (supports HttpOnly cookies via privileged Tampermonkey/Violentmonkey APIs)
    function getSessionCookie() {
        return new Promise(function(resolve) {
            let done = false;
            function finish(val) {
                if (!done) {
                    done = true;
                    resolve(val || '');
                }
            }
            setTimeout(function() { finish(''); }, 1500);

            // 1. Try GM.cookie (Violentmonkey & modern Tampermonkey Promise API)
            if (typeof GM !== 'undefined' && GM.cookie && typeof GM.cookie.list === 'function') {
                try {
                    GM.cookie.list({}).then(function(cookies) {
                        if (Array.isArray(cookies)) {
                            let c = cookies.find(function(x) { return x.name === 'session'; });
                            if (c && c.value) return finish('session=' + c.value);
                        }
                        return GM.cookie.list({ domain: 'pixn-retro.dedyn.io' });
                    }).then(function(cookies) {
                        if (Array.isArray(cookies)) {
                            let c = cookies.find(function(x) { return x.name === 'session'; });
                            if (c && c.value) return finish('session=' + c.value);
                        }
                    }).catch(function() {});
                } catch(e) {}
            }

            // 2. Try GM_cookie (Tampermonkey callback API)
            if (typeof GM_cookie !== 'undefined' && typeof GM_cookie.list === 'function') {
                try {
                    GM_cookie.list({}, function(cookies, error) {
                        if (!error && Array.isArray(cookies)) {
                            let c = cookies.find(function(x) { return x.name === 'session'; });
                            if (c && c.value) return finish('session=' + c.value);
                        }
                        GM_cookie.list({ domain: 'pixn-retro.dedyn.io' }, function(cookies2, error2) {
                            if (!error2 && Array.isArray(cookies2)) {
                                let c2 = cookies2.find(function(x) { return x.name === 'session'; });
                                if (c2 && c2.value) return finish('session=' + c2.value);
                            }
                        });
                    });
                } catch(e) {}
            }

            // 3. Fallback: document.cookie (if not HttpOnly)
            if (document.cookie && document.cookie.includes('session=')) {
                const m = document.cookie.match(/session=([^;]+)/);
                if (m) return finish('session=' + m[1]);
            }
        });
    }

    async function launchSync() {
        if (document.getElementById('pixn-sync-hud')) return;

        // Auto-capture session cookie for seamless 1-click PIXN configuration
        let capturedCookie = '';
        try {
            capturedCookie = await getSessionCookie();
            if (capturedCookie) {
                window.__PIXN_CAPTURED_COOKIE__ = capturedCookie;
                try { localStorage.setItem('pixn_captured_cookie', capturedCookie); } catch(e) {}
            }
        } catch(e) {}

        const s = document.createElement('script');
        let baseUrl = (TARGET_SERVER && !/jsdelivr\.net/i.test(TARGET_SERVER)) ? TARGET_SERVER : 'https://cdn.jsdelivr.net/gh/UncleSlim/pixn-manager-pro@main';
        let srcUrl = baseUrl + '/sync_engine.js?server=' + encodeURIComponent(TARGET_SERVER) + '&t=' + Date.now();
        if (capturedCookie) {
            srcUrl += '&cookie=' + encodeURIComponent(capturedCookie);
        }
        s.src = srcUrl;
        document.body.appendChild(s);
    }

    if (document.getElementById('pixn-ds-sync-btn')) return;

    const btn = document.createElement('button');
    btn.id = 'pixn-ds-sync-btn';
    btn.innerHTML = '🔄 Sync to PIXN Manager Pro';
    btn.title = 'Sync new systems and games directly to PIXN Manager Pro';
    btn.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        z-index: 99999;
        background: linear-gradient(135deg, #0284c7, #0ea5e9);
        color: #ffffff;
        border: 1px solid rgba(255, 255, 255, 0.25);
        border-radius: 24px;
        padding: 10px 18px;
        font-size: 13px;
        font-weight: 600;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        box-shadow: 0 10px 25px rgba(2, 132, 199, 0.45), 0 0 15px rgba(56, 189, 248, 0.3);
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        gap: 8px;
    `;

    btn.onmouseover = () => {
        btn.style.transform = 'translateY(-2px) scale(1.02)';
        btn.style.boxShadow = '0 14px 30px rgba(2, 132, 199, 0.6), 0 0 20px rgba(56, 189, 248, 0.45)';
    };
    btn.onmouseout = () => {
        btn.style.transform = 'none';
        btn.style.boxShadow = '0 10px 25px rgba(2, 132, 199, 0.45), 0 0 15px rgba(56, 189, 248, 0.3)';
    };

    btn.onclick = launchSync;

    document.body.appendChild(btn);

    // Auto-launch if URL contains #autosync
    if (window.location.hash.includes('autosync')) {
        setTimeout(launchSync, 800);
    }
})();
