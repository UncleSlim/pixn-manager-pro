# PIXN Portable — Distribution & Cleanup Guide

This guide details what must be deleted, what is safe to clean up, and what must be kept before packaging and distributing **PIXN Portable** to other users.

---

## 1. ⚠️ CRITICAL: Must Delete Before Sharing (Privacy & Security)

These files contain **your personal login credentials** and **private local network hostnames/paths**. If you distribute them, other users will automatically be logged into PIXN under your personal Discord account and will see your internal network addresses.

| Target File | Why it MUST be removed |
| :--- | :--- |
| **`data/pixn_auth.json`** | **CRITICAL:** Contains your raw Discord session cookie (`session=...`). Anyone possessing this file has full authenticated access as your account on PIXN. **Delete this file.** |
| **`config.json`** | Contains your private `pixn_session_cookie`, your private NAS URL, and your Batocera UNC path (`\\\\BATOCERA-RGS\\...`). **Delete this file** or replace it with a clean copy of `config.sample.json`. |
| **`batocera_config.json`** | Contains your private local network Batocera host and paths (`\\\\BATOCERA-RGS\\share\\roms`). **Delete this file.** |

---

## 2. 🧹 SAFE TO DELETE: Caches & Temporary Runtime Files

Over time, PIXN accumulates cached scans and temporary runtime lock files. Deleting these significantly reduces package size and ensures the new user starts with a clean slate:

| Folder / File | Action & Notes |
| :--- | :--- |
| **`data/cache_batocera/`** | **Safe to delete all files inside.** Contains hundreds of `_bato_http_*.json` and UNC cached scan results from your personal Batocera machine. PIXN will automatically regenerate clean cache files when the new user rescans their own system. |
| **`data/cache_gamelist/`** | **Safe to delete all files inside.** Contains cached Batocera `gamelist.xml` files. |
| **`data/cache_torrents/`** & **`data/torrents/`** | **Safe to delete all files inside.** Contains cached `.torrent` files. |
| **`data/active_port.txt`** | **Safe to delete.** Temporary port number recorded by `PIXN.exe` during startup. |
| **`data/app_heartbeat.txt`** | **Safe to delete.** Heartbeat file created by active browser tabs. |
| **`data/tab_closed.txt`** | **Safe to delete.** Temporary shutdown signal file. |
| **`data/pixn_releases_meta.json`** | *(Optional)* Safe to delete. Re-downloads automatically from PIXN API on first sync. Keeping it allows the new user to load release data slightly faster. |
| **`data/pixn_media_timestamps.json`**| *(Optional)* Safe to delete. Re-downloads automatically on media sync. |

---

## 3. 🛡️ DO NOT DELETE: Core Application Files

The portable edition requires all of the following components to launch and function:

* **`PIXN.exe`** — The portable desktop launcher.
* **`php/`** — The standalone PHP 8 runtime environment bundled for Windows.
* **`index.html`** — The main frontend application interface.
* **`save.php`** — The backend API engine handling scanning, torrents, downloads, and config.
* **`sync_engine.js`** — The browser sync engine script.
* **`pixn_sync.user.js`** — The Tampermonkey / Greasemonkey helper script.
* **`systems.json`** — Master list of supported gaming systems and emulators.
* **`search_index.json`** — Search index for fast game lookups.
* **`config.sample.json`** — Default configuration template for new installations.
* **`games/`** — All 329 system catalog JSON database files (the core library).
* **`games_txt/`** — Text format game catalogs.
* **`system-logos/`** — Logos and icons for gaming consoles and systems.

---

## 4. 🌐 Regarding `jimmy.langtoft.dk` inside `pixn_sync.user.js`

In `pixn_sync.user.js`, `jimmy.langtoft.dk` appears twice:
1. Line 3: `// @namespace    https://jimmy.langtoft.dk/pixn/`
2. Line 17: `s.src = 'https://jimmy.langtoft.dk/pixn/sync_engine.js?t=' + Date.now();`

### Why it is there:
* **Browser Mixed-Content Protection**:
  When a user visits `https://pixn-retro.dedyn.io/` (an HTTPS website), modern web browsers **block** loading scripts directly from plain HTTP servers (e.g. `http://127.0.0.1:8989/sync_engine.js`).
* Because `jimmy.langtoft.dk` has a valid SSL certificate (HTTPS), it acts as a public HTTPS host that injects `sync_engine.js` without triggering browser security blocks.
* Once loaded, `sync_engine.js` talks directly to whatever local server address is provided in the configuration or query parameter (`?server=http://127.0.0.1:8989`).
* When users click **Settings → Download UserScript** in the web UI, `save.php` (`action=get_userscript`) dynamically updates `https://jimmy.langtoft.dk/pixn` with their detected local server address.

### Options before publishing:
1. **Option A (Leave as-is):** If you are comfortable hosting `sync_engine.js` as an HTTPS relay for everyone, keeping it ensures out-of-the-box compatibility on `https://pixn-retro.dedyn.io/`.
2. **Option B (Use GitHub Pages / Public Repo):** Point `s.src` to a public GitHub Pages URL (e.g. `https://<your-username>.github.io/<repo>/sync_engine.js`).
3. **Option C (Local Download Only):** Set `s.src` to a placeholder URL and instruct users to download their custom script directly from **Settings → UserScript** within the running PIXN interface.

---

## 5. ⚡ Quick Cleanup Script (PowerShell)

To automatically clean the portable folder before zipping, you can run this PowerShell command inside the `portable` directory:

```powershell
# Remove private credentials and configs
Remove-Item -Path "data\pixn_auth.json" -ErrorAction SilentlyContinue
Remove-Item -Path "config.json" -ErrorAction SilentlyContinue
Remove-Item -Path "batocera_config.json" -ErrorAction SilentlyContinue

# Remove temporary runtime state files
Remove-Item -Path "data\active_port.txt" -ErrorAction SilentlyContinue
Remove-Item -Path "data\app_heartbeat.txt" -ErrorAction SilentlyContinue
Remove-Item -Path "data\tab_closed.txt" -ErrorAction SilentlyContinue

# Clear Batocera scans and torrent caches
Remove-Item -Path "data\cache_batocera\*" -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item -Path "data\cache_gamelist\*" -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item -Path "data\cache_torrents\*" -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item -Path "data\torrents\*" -Recurse -Force -ErrorAction SilentlyContinue

# Re-create clean empty cache folders
New-Item -ItemType Directory -Path "data\cache_batocera" -Force | Out-Null
New-Item -ItemType Directory -Path "data\cache_gamelist" -Force | Out-Null
New-Item -ItemType Directory -Path "data\cache_torrents" -Force | Out-Null
New-Item -ItemType Directory -Path "data\torrents" -Force | Out-Null

Write-Host "PIXN Portable cleaned successfully and ready for distribution!" -ForegroundColor Green
```
