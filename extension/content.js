/**
 * PIXN Sync Pro - Content Script (Manifest V3)
 * Runs on https://pixn-retro.dedyn.io/*
 * Provides floating 1-click sync button and direct communication with PIXN Manager Pro.
 */

(function() {
    'use strict';

    if (window.__PIXN_EXTENSION_INITIALIZED__) return;
    window.__PIXN_EXTENSION_INITIALIZED__ = true;

    let targetServer = 'http://127.0.0.1:8989';

    // Retrieve initial server configuration from background service worker
    chrome.runtime.sendMessage({ action: 'get_target_server' }, (resp) => {
        if (resp && resp.server) {
            targetServer = resp.server.replace(/\/+$/, '');
        }

        // Check if URL hash requested auto-sync
        const hash = window.location.hash || '';
        if (hash.includes('autosync')) {
            const match = hash.match(/server=([^&]+)/);
            if (match && match[1]) {
                const s = decodeURIComponent(match[1]).trim().replace(/\/+$/, '');
                if (s && !/jsdelivr\.net|github\.io/i.test(s)) {
                    targetServer = s;
                    chrome.runtime.sendMessage({ action: 'set_target_server', server: s });
                }
            }
            setTimeout(() => launchSync(), 800);
        }
    });

    // Create and inject the floating sync button
    function injectFloatingButton() {
        if (document.getElementById('pixn-ext-sync-btn')) return;

        const btn = document.createElement('button');
        btn.id = 'pixn-ext-sync-btn';
        btn.innerHTML = `
            <svg style="width: 17px; height: 17px; fill: currentColor; filter: drop-shadow(0 0 4px rgba(255,255,255,0.4));" viewBox="0 0 24 24">
                <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
            </svg>
            <span>Sync to PIXN Pro</span>
        `;

        Object.assign(btn.style, {
            position: 'fixed',
            bottom: '24px',
            right: '24px',
            zIndex: '999999',
            display: 'flex',
            alignItems: 'center',
            gap: '8px',
            padding: '10px 18px',
            backgroundColor: '#0284c7',
            backgroundImage: 'linear-gradient(135deg, #0284c7 0%, #0369a1 100%)',
            color: '#ffffff',
            border: '1px solid rgba(56, 189, 248, 0.4)',
            borderRadius: '50px',
            boxShadow: '0 8px 24px rgba(0, 0, 0, 0.4), 0 0 15px rgba(2, 132, 199, 0.4)',
            fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
            fontSize: '13px',
            fontWeight: '600',
            letterSpacing: '0.3px',
            cursor: 'pointer',
            transition: 'all 0.25s cubic-bezier(0.16, 1, 0.3, 1)',
            backdropFilter: 'blur(8px)',
            outline: 'none'
        });

        btn.title = `Click to synchronize Pix'n Retro catalog to PIXN (${targetServer})`;

        btn.onmouseenter = () => {
            btn.style.transform = 'translateY(-3px) scale(1.03)';
            btn.style.boxShadow = '0 12px 28px rgba(0, 0, 0, 0.5), 0 0 20px rgba(56, 189, 248, 0.6)';
            btn.style.backgroundImage = 'linear-gradient(135deg, #0369a1 0%, #0284c7 100%)';
        };
        btn.onmouseleave = () => {
            btn.style.transform = 'translateY(0) scale(1)';
            btn.style.boxShadow = '0 8px 24px rgba(0, 0, 0, 0.4), 0 0 15px rgba(2, 132, 199, 0.4)';
            btn.style.backgroundImage = 'linear-gradient(135deg, #0284c7 0%, #0369a1 100%)';
        };

        btn.onclick = () => launchSync();

        document.body.appendChild(btn);
    }

    // Launch synchronization
    async function launchSync() {
        const btn = document.getElementById('pixn-ext-sync-btn');
        if (btn) {
            btn.disabled = true;
            btn.style.opacity = '0.7';
            btn.innerHTML = `
                <svg style="width: 16px; height: 16px; animation: pixn-spin 1s linear infinite;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle>
                    <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"></path>
                </svg>
                <span>Synchronizing...</span>
            `;
        }

        // Add spinning animation if not exists
        if (!document.getElementById('pixn-spin-style')) {
            const st = document.createElement('style');
            st.id = 'pixn-spin-style';
            st.textContent = '@keyframes pixn-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
            document.head.appendChild(st);
        }

        // 1. Fetch Discord Session Cookie via background service worker
        let capturedCookie = null;
        try {
            const cookieResp = await new Promise(resolve => {
                chrome.runtime.sendMessage({ action: 'get_session_cookie' }, resolve);
            });
            if (cookieResp && cookieResp.cookie) {
                capturedCookie = cookieResp.cookie;
            }
        } catch(e) {
            console.warn('Could not read session cookie via extension:', e);
        }

        // Fallback to document.cookie if extension cookie was empty
        if (!capturedCookie && document.cookie.includes('session=')) {
            const m = document.cookie.match(/session=([^;]+)/);
            if (m) capturedCookie = 'session=' + m[1];
        }

        // 2. Fetch authoritative target server from storage
        try {
            const srvResp = await new Promise(resolve => {
                chrome.runtime.sendMessage({ action: 'get_target_server' }, resolve);
            });
            if (srvResp && srvResp.server) {
                targetServer = srvResp.server.replace(/\/+$/, '');
            }
        } catch(e) {}

        window.__PIXN_SERVER__ = targetServer;
        if (capturedCookie) {
            window.__PIXN_CAPTURED_COOKIE__ = capturedCookie;
        }

        // 3. Inject and run sync engine
        const existingScript = document.getElementById('pixn-sync-engine-script');
        if (existingScript) existingScript.remove();

        window.__PIXN_SYNCING__ = true;

        // Auto-recovery timeout: never allow button to remain permanently stuck on "Synchronizing"
        clearTimeout(window.__PIXN_SYNC_TIMEOUT__);
        window.__PIXN_SYNC_TIMEOUT__ = setTimeout(() => {
            if (window.__PIXN_SYNCING__) {
                window.__PIXN_SYNCING__ = false;
                resetButton(true);
            }
        }, 30000);

        const s = document.createElement('script');
        s.id = 'pixn-sync-engine-script';
        s.src = `https://cdn.jsdelivr.net/gh/UncleSlim/pixn-manager-pro@main/sync_engine.js?server=${encodeURIComponent(targetServer)}&t=${Date.now()}`;

        s.onerror = () => {
            // Fallback to local server if CDN is unreachable
            const fallbackScript = document.createElement('script');
            fallbackScript.id = 'pixn-sync-engine-script-fallback';
            fallbackScript.src = `${targetServer}/sync_engine.js?server=${encodeURIComponent(targetServer)}&t=${Date.now()}`;
            fallbackScript.onerror = () => {
                window.__PIXN_SYNCING__ = false;
                resetButton(false, 'Script Blocked');
            };
            document.body.appendChild(fallbackScript);
        };

        document.body.appendChild(s);
    }

    // Reset button back to idle or completed state
    function resetButton(success = true, message = '') {
        const btn = document.getElementById('pixn-ext-sync-btn');
        if (!btn) return;
        btn.disabled = false;
        btn.style.opacity = '1';
        if (success) {
            btn.style.backgroundImage = 'linear-gradient(135deg, #059669 0%, #10b981 100%)';
            btn.style.borderColor = 'rgba(16, 185, 129, 0.6)';
            btn.innerHTML = `
                <svg style="width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 2.5;" viewBox="0 0 24 24">
                    <path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span>${message || 'Sync Complete!'}</span>
            `;
            setTimeout(() => {
                btn.style.backgroundImage = 'linear-gradient(135deg, #0284c7 0%, #0369a1 100%)';
                btn.style.borderColor = 'rgba(56, 189, 248, 0.4)';
                btn.innerHTML = `
                    <svg style="width: 17px; height: 17px; fill: currentColor;" viewBox="0 0 24 24">
                        <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
                    </svg>
                    <span>Sync to PIXN Pro</span>
                `;
            }, 4000);
        } else {
            btn.style.backgroundImage = 'linear-gradient(135deg, #dc2626 0%, #b91c1c 100%)';
            btn.innerHTML = `
                <svg style="width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 2.5;" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <span>${message || 'Sync Failed'}</span>
            `;
            setTimeout(() => {
                btn.style.backgroundImage = 'linear-gradient(135deg, #0284c7 0%, #0369a1 100%)';
                btn.innerHTML = `
                    <svg style="width: 17px; height: 17px; fill: currentColor;" viewBox="0 0 24 24">
                        <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
                    </svg>
                    <span>Sync to PIXN Pro</span>
                `;
            }, 4000);
        }
    }

    // Listen for completion events dispatched by sync_engine.js
    window.addEventListener('pixn_sync_done', (e) => {
        window.__PIXN_SYNCING__ = false;
        clearTimeout(window.__PIXN_SYNC_TIMEOUT__);
        const isOk = !e.detail || e.detail.status !== 'error';
        resetButton(isOk, isOk ? 'Sync Complete!' : (e.detail && e.detail.error ? 'Sync Failed' : 'Sync Error'));
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectFloatingButton);
    } else {
        injectFloatingButton();
    }
})();
