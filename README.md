# 🕹️ PIXN Manager Pro

> **The Ultimate Retro Game Catalog & Batocera Hub**

An interactive, high-performance web catalog and retro gaming collection manager designed for local deployment.

Browse over **331 gaming systems** (310 active console platforms with RetroBat ports filtered) and **82,800+ titles**, inspect high-resolution multi-media artwork (3D boxes, 2D covers, screenshots, marquees, cartridges), search globally across all platforms, and verify your actual ROM collection against an active **Batocera Linux PC** via SMB network shares.

---

## 🌟 Key Features

### 🎮 Dual Browsing Experience

* **Browse by System:** Filter by platform with dynamic console brand theming (Nintendo, Sega, Sony, Arcade, Atari, Commodore, SNK, etc.), ambient console glow auras, and official console logos.
* **Visual System Gallery (`S`):** Immersive 4-column showcase grid with larger responsive cards, brand heritage colors, dynamic halo hover glows, hardware category badges (*Console, Handheld, Arcade, Computer, Port, Pinball, Vintage*), live Batocera PC installation tags (*On PC*), single-line metadata tags, and hover quick actions (copy Batocera UNC ROM path, view `.txt` list, open system).
* **Global Search Across All Systems:** Instant, debounced search across all 82,800+ games with live status indicators and Batocera verification.
* **Hardware Category Filters:** Filter platforms by *Console, Portable, Computer, Arcade, Port, PC Gaming, Vintage, Pinball, Toys, and Virtual Console*.
* **Hide RetroBat / Batocera Toggles:** Quick header filters to isolate native console catalogs from PC front-end ports.

### 📥 qBittorrent Integration & Torrent Streaming Proxy

* **1-Click System Torrent Downloads:** Direct download buttons featuring the official qBittorrent emblem placed right next to the system name on the main Info Bar and Visual Gallery cards, as well as on every row in the Batocera Overview table and Catalog Synchronization dialog.
* **NAS Torrent Streaming Proxy (`save.php`):** Proxies authenticated torrent requests directly from Pix'n Retro with `ETag` validation and streams in-memory RFC 5987 content-disposition attachments for instant opening in qBittorrent / BitTorrent clients without cluttering disk storage.

### 🖼️ Dual View Modes

* **Text / Notepad View (`L`):** Retro text editor styling with line numbers, custom formatting (*Titles Only, Numbered, Filename, Details*), copy-to-clipboard, and direct `.txt` download.
* **3D Cover Art Grid View (`G`):**
  * Three density options: **S** (Compact), **M** (Normal), and **L** (Showcase).
  * Interactive **3D Card Tilt** with dynamic specular holographic sheen on mouse hover.
  * **Floating Quick Preview Card:** Hover over any card for instant in-game screenshots, synopsis, developer, and release metadata.
  * High-performance lazy-loading with prioritized candidate fallback tiers and persistent IndexedDB art caching.

### 🎨 Deep Multi-Media Hierarchy & Lightbox

* **Multi-Tier Media Switcher:**
  * **Box Art:** 3D Perspective Box, Front 2D Cover, Back 2D Cover.
  * **In-Game:** Gameplay Screenshots, Title Screens.
  * **Other:** Marquee / Wheel Logos, Cartridge Scans.
* **Full-Resolution Lightbox:** Click any artwork to open an immersive fanart backdrop lightbox with zoom, download, and arrow-key cycling.

### 🔌 Batocera PC & Network ROM Manager

* **Live SMB Integration:** Direct network connection to your Batocera PC ROM share (`\\BATOCERA\share\roms`).
* **Multi-Tier Fast Scanner:**
  * **Tier 1 (Fast REST API — Port 1234):** Turbo scan via Batocera's built-in web service. Instantly pulls titles, synopsis, developer, publisher, ratings, and video preview streams in ~100ms with automatic HTML5 video MIME normalization (`video/mp4`).
  * **Tier 2 (Direct `gamelist.xml` via SMB — Port 445):** Seamless fallback if web services are disabled or offline, reading system metadata directly from the share.
  * **Tier 3 (Filesystem Directory Walk):** Direct folder scan ensuring 100% accurate file existence and size accounting, automatically filtering out deleted games.
* **Multi-Tier Matching Engine:**
  * **Direct Match:** Instant match by filename and system ID.
  * **Smart Fuzzy Matching:** Intelligently matches alternative naming conventions (No-Intro, GoodSet, region codes like `(USA)` / `(Europe)`, Roman numerals, punctuation). Fuzzy-matched games are seamlessly marked as **Installed** in your catalog with a distinct purple indicator.
* **"Other" Non-Catalog ROM Discovery:**
  * If you have ROMs installed on Batocera that are **not** in the official [Pix'n Retro](https://pixn-retro.dedyn.io/) catalog (e.g., fan translations, ROM hacks, homebrew, prototypes, or custom dumps), the system detects them automatically and enables the **📁 Other** filter pill.
  * You can filter, search, view filenames, file sizes, and inspect emulator settings for all extra games outside the official catalog.
* **Batocera Quick HUD Popover:** Clicking the header `Batocera Online` emblem opens a sleek, glassmorphic popover card right under the emblem:
  * **Live Network Status & Host:** Live online status indicator, detected hostname with clickable LAN IP address badge (`192.168.0.190`), and 1-click copy for the active SMB ROMs path (`\\BATOCERA\share\roms`).
  * **Installed Systems Only Toggle:** Quick switch to filter the entire UI and system selector down to only the systems currently installed on your Batocera PC (e.g. 178 systems out of 310), with unified filter indicators displayed on the header emblem, system dropdown selector, and visual gallery toolbar.
  * **1-Click Actions:** Fast re-scan refresh button and quick link to full configuration in Setup.
* **Full Batocera Health & Overview Modal:** Searchable system-by-system completion metrics, installed vs catalog counts, completion badges, and 1-click jump into any platform catalog.
* **Configuration Overrides Inspector:** View and filter custom game and emulator settings directly from `batocera.conf`.
* **One-Click UNC Path Copy:** Copy the exact Windows network path to open the current system's ROM folder straight in File Explorer (`Win + R`), available directly from the Visual Gallery hover card, HUD popover, and header controls.

> [!TIP]
> **Do I need to enable the Web Server on my Batocera PC?**
>
> * **Samba File Sharing (Port 445) — Required:** The only mandatory requirement for Batocera verification is standard Samba/Windows file sharing (`\\BATOCERA\share\roms`). PIXN uses native file access to verify actual ROM existence and calculate exact file sizes.
> * **Batocera Web Services / REST API (Port 1234) — Highly Recommended:**
>   * Enabled by default in modern Batocera releases (v33+).
>   * Enables near-instantaneous catalog loading (~100ms instead of parsing large XML/directories over the network).
>   * Enables seamless in-browser **Dual-Engine Video Previews** streamed directly from Batocera's media server (Port 1234) or streamed natively via Windows UNC network share with full HTTP 206 Partial Content scrubbing.
>   * **How to verify/enable:** On your Batocera PC, open **Main Menu** &rarr; **System Settings** &rarr; **Developer** &rarr; toggle **Web Services (API)** to **ON** (or verify `system.api.enabled=1` in `batocera.conf`).

### 🔄 Catalog Synchronization & Self-Healing

* **1-Click Browser Sync:** Includes the native **PIXN Sync Pro** browser extension (`extension/`) to pull updates directly from [pixn-retro.dedyn.io](https://pixn-retro.dedyn.io/releases) and auto-capture session cookies.
* **Self-Repair Engine:** Checks catalog integrity, rebuilds `search_index.json`, and restores missing `.txt` list files.

---

## 📁 Repository Structure

```text
pixn/
├── index.html                           # Main frontend application (Single-Page App)
├── save.php                             # Backend API engine (API sync, Batocera proxy, video streaming, repair)
├── sync_engine.js                       # Client-side sync script for Pixn Retro bookmarklet
├── pixn_sync.user.js                    # Tampermonkey/Violentmonkey auto-sync user script
├── extension/                           # [Chrome/Edge/Brave] Dedicated Manifest V3 browser extension
├── systems.json                         # Primary metadata index for all 331 gaming systems
├── search_index.json                    # Compact lookup index for instant global searching
├── config.sample.json                   # Configuration template for first-time setups
├── batocera_config.json                 # Batocera connection configuration (legacy compatibility)
├── README.md                            # Documentation and setup guide
├── sync_status.json                     # Synchronization status and update change log
├── data/                                # Backend cache and state directory
│   ├── cache_batocera/                  # Cached Batocera scan results (auto-generated)
│   ├── cache_gamelist/                  # Cached system gamelist XML/JSON (auto-generated)
│   ├── cache_releases_check.json        # Release metadata cache
│   ├── pixn_releases_meta.json          # Release version and ETag tracking metadata
│   ├── pixn_media_timestamps.json       # Media update cache timestamps
│   └── batocera_folder_map.json         # System folder alias overrides
├── games/                               # Detailed individual {system}.json metadata files (331 systems)
├── games_txt/                           # Plain-text {system}.txt export lists
├── system-logos/                        # Official system logos (SVG/PNG) & application icons
└── portable/                            # [Portable Edition] Standalone zero-install Windows desktop package
    ├── PIXN.exe                         # Windows desktop launcher & tray watchdog app
    ├── php/                             # Embedded zero-install PHP 8 runtime
    ├── index.html                       # Portable frontend application
    ├── save.php                         # Portable backend engine
    ├── README-PORTABLE.txt              # Portable edition technical guide
    └── PORTABLE_DISTRIBUTION_CLEANUP_GUIDE.md # Distribution packaging guide
```

> [!TIP]
> **Can I use the Portable release files for NAS / Web Server hosting?**  
> **Yes!** The Portable package contains the exact same web application files. If you copy files to a NAS or web server (Method 2), your NAS uses its own built-in web server (Synology Web Station, Apache, Nginx) and native PHP engine, so you do not need `PIXN.exe` or the `php/` folder.

## 🔄 Browser Synchronization (PIXN Sync Pro Extension)

Because **Pix'n Retro** releases require Discord authentication and use secure `HttpOnly` session cookies that cannot be read by standard scripts, the included **PIXN Sync Pro** browser extension provides the seamless, zero-maintenance synchronization workflow directly inside your browser.

---

### PIXN Sync Pro Browser Extension (Chrome, Edge, Brave, Opera)

A native Manifest V3 extension included directly in the `extension/` folder. It auto-captures your Discord session cookie and adds a floating 1-click sync button on Pix'n Retro.

#### How to Load in Chrome / Edge / Brave:

1. Open your browser and navigate to:
   * **Chrome / Brave:** `chrome://extensions`
   * **Edge:** `edge://extensions`
2. In the top-right corner, toggle **Developer mode** to **ON**.
3. Click **Load unpacked** (top-left) and select the `extension` folder included in your PIXN directory.
4. Visit [https://pixn-retro.dedyn.io/releases](https://pixn-retro.dedyn.io/releases) — a floating **⚡ Sync to PIXN Pro** button will automatically appear to synchronize catalogs and auto-import your session cookie!

---

### How the Sync Engine Works Behind the Scenes

1. **Authenticated In-Browser Requests:** Runs inside your already authenticated Pix'n Retro browser session.
2. **Automatic Cookie Capture:** Captures the `HttpOnly` session cookie via the browser extension and saves it automatically to your server's `config.json`.
3. **Delta Comparison:** Queries `/api/catalog/systems?catalog_type=releases` directly from Pix'n Retro and compares systems against your catalog.
4. **Smart Batch Download:** Only systems that have new versions, updated release dates, or missing local files are fetched (`/api/catalog/games/{sysId}?catalog_type=releases`).
5. **Server Storage & Rebuild:** Scraped catalog data is posted to `save.php`, saving `{system}.json` and `{system}.txt` files, and automatically triggers an instant rebuild of `search_index.json`.
6. **Direct Viewer Access:** Once complete, the HUD shows a summary of new/updated systems with an **Open PIXN Manager Pro** button linking directly to your PIXN instance.

### Server Auto-Updater vs. Browser Sync Extension

* **Browser Sync Extension [Recommended & Automatic]:** Zero complex setup! The very first time you click "Sync to PIXN" on Pix'n Retro, the extension automatically captures your active login session and saves your session cookie directly to your server's `config.json`. You never need to use browser developer tools or manually copy cookies.
* **Server Background Auto-Updater:** Uses the automatically saved cookie to check for newly published rom sets every few minutes and enables direct `.torrent` downloads for qBittorrent. Whenever your Discord session naturally expires, simply clicking the Sync button on Pix'n Retro refreshes your server cookie seamlessly.
* **Manual Paste (Fallback only):** If you wish to pre-configure the cookie before running your first browser sync, you can paste it manually under **Setup** &rarr; **Pix'n Retro Session Cookie**.

---

## ⌨️ Keyboard Shortcuts

| Key | Action |
| :--- | :--- |
| `/` | Focus search input (Filter or Global Search) |
| `S` | Open Visual System Gallery modal |
| `G` | Switch to Cover Art Grid View |
| `L` | Switch to Text / Notepad View |
| `↑` / `↓` or `J` / `K` | Select next / previous game in list |
| `Enter` | Open detail modal for selected game |
| `←` / `→` or `H` / `L` | Previous / Next game inside detail modal |
| `1` / `2` / `3` | Switch media tabs inside modal (Box / In-Game / Other) |
| `Esc` | Close any open modal or lightbox |

---

## 🚀 Installation & Setup

PIXN Manager Pro can be run in two ways: as a **zero-install Windows Portable application** on your PC, or hosted 24/7 on a **NAS / Web Server** (Synology, QNAP, Linux).

---

### Method 1: Windows Portable Edition (Zero-Install — Recommended for PC)

The portable edition is completely self-contained. It requires **no web server** (Apache/Nginx/IIS), **no PHP installation**, and **no administrative rights**.

#### Requirements:
* **Operating System:** Windows 10 or Windows 11 (64-bit).
* **Network Access to Batocera PC:**
  * **Samba File Sharing (Port 445) — Required:** Access to your Batocera ROMs network share (e.g. `\\BATOCERA\share\roms`).
  * **Batocera Web Services (Port 1234) — Recommended:** Enabled by default on Batocera v33+ for 10x faster scans and in-browser video streaming.

#### Quick Start:
1. **Download & Extract:** Download the latest `PIXN-Portable-v*.zip` from [GitHub Releases](https://github.com/UncleSlim/pixn-manager-pro/releases) and extract it to any folder on your PC (e.g., `C:\PIXN\` or your desktop).
2. **Launch:** Double-click **`PIXN.exe`**.
   * Starts the built-in, lightweight background PHP engine on port `8989`.
   * Adds a discreet icon to your Windows Notification Tray.
   * Automatically opens your default web browser to [http://127.0.0.1:8989/](http://127.0.0.1:8989/).
3. **First-Time Setup Wizard:**
   * Enter your Batocera PC's network path (e.g. `\\BATOCERA\share\roms`) and click **Test Connection**.
   * Click **Save & Apply**.
4. **Closing & Exiting:**
   * When you finish, simply close your browser tab. PIXN automatically detects the tab close and safely shuts down the background engine within 3 seconds.
   * You can also right-click the **PIXN tray icon** in the taskbar and select **Exit PIXN**.

---

### Method 2: NAS & Dedicated Web Server Deployment (Synology, QNAP, Linux)

For users who want to host PIXN Manager Pro 24/7 on a home server or NAS accessible from any device on their local network.

#### Requirements:
* **Web Server:** Synology Web Station, QNAP, Apache, or Nginx.
* **PHP Runtime:** **PHP 7.4+** or **PHP 8.x** with extensions: `curl`, `json`, `mbstring`.
* **Network Access to Batocera:** Read access via SMB (`\\BATOCERA\share\roms` or mounted share).

#### Deployment Steps:
1. **Upload Files:** Copy the contents of the repository into your web server's document root (e.g., the `web/pixn/` shared folder on Synology/QNAP, or `/var/www/html/pixn/` on Linux).
2. **Set Permissions:** Ensure the web server process (`http`, `www-data`, or `nobody`) has write permissions to the `pixn/` folder so it can create `config.json`, store caches, and sync game catalogs.
3. **Open in Browser:** Navigate to `http://<your-nas-ip>/pixn/index.html`.
4. **Configuration Wizard:**
   * **Web Server Address:** Auto-detected from your browser address bar.
   * **Batocera ROMs UNC Path:** Enter `\\BATOCERA\share\roms` (or your local mount path).
   * Click **Save & Apply** to generate your `config.json`.
5. **Load Sync Extension:** Load the `extension/` folder in Chrome/Edge Developer Mode to sync releases from Pix'n Retro with 1-click and auto-capture your session cookie.

---

## 🔒 Security & Privacy Notice

> [!IMPORTANT]
> If committing this repository to GitHub or any public version control:
>
> * **Do not publish `config.json` or `data/pixn_auth.json`**: These files contain your private session cookie and local network machine names.
> * A clean template [`config.sample.json`](config.sample.json) is provided for distribution.
> * Add `config.json` and `data/pixn_auth.json` to your `.gitignore`.

---

## 📄 License & Credits

* Catalog data and media CDN provided by [Pixel Nostalgia (Pixn Retro)](https://pixn-retro.dedyn.io/).
* System branding, logos, and game trademarks are property of their respective manufacturers and publishers.
* Built with modern Vanilla JavaScript, CSS custom properties, and FontAwesome icons.
