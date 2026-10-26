// ==UserScript==
// @name         PIXN Retro -> PIXN Manager Pro 1-Click Sync
// @namespace    https://jimmy.langtoft.dk/pixn/
// @version      1.3
// @description  Adds a floating 1-click Sync button on Pixn Retro releases page to sync new systems and games directly to PIXN Manager Pro
// @match        https://pixn-retro.dedyn.io/*
// @run-at       document-idle
// @grant        none
// ==/UserScript==

(function() {
    'use strict';

    function launchSync() {
        if (document.getElementById('pixn-sync-hud')) return;
        const s = document.createElement('script');
        s.src = 'https://jimmy.langtoft.dk/pixn/sync_engine.js?t=' + Date.now();
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
