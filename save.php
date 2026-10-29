<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Private-Network: true');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 0);
ini_set('memory_limit', '512M');
error_reporting(E_ALL);

$reqAction = $_GET['action'] ?? ($_POST['action'] ?? '');

$baseDir = __DIR__;
$dataDir = $baseDir . '/data';

// If running in local desktop environment, ensure active requests immediately clear any stale tab_closed signal
if ($reqAction !== 'tab_closed' && file_exists($dataDir . '/tab_closed.txt')) {
    @unlink($dataDir . '/tab_closed.txt');
}

if ($reqAction === 'heartbeat') {
    if (!is_dir($dataDir)) @mkdir($dataDir, 0777, true);
    @file_put_contents($dataDir . '/app_heartbeat.txt', (string)time());
    if (file_exists($dataDir . '/tab_closed.txt')) @unlink($dataDir . '/tab_closed.txt');
    echo json_encode(['status' => 'ok', 'time' => time()]);
    exit;
}

if ($reqAction === 'tab_closed') {
    if (!is_dir($dataDir)) @mkdir($dataDir, 0777, true);
    $tcFile = $dataDir . '/tab_closed.txt';
    @file_put_contents($tcFile, (string)time());
    // Give browser reload 3s grace buffer before watchdog treats it as permanent close
    @touch($tcFile, time() + 3);
    echo json_encode(['status' => 'ok', 'closing' => true]);
    exit;
}


if ($reqAction !== 'stream_batocera_video' && $reqAction !== 'download_torrent' && !ob_get_level() && function_exists('ob_gzhandler')) {
    @ob_start('ob_gzhandler');
}

// Polyfill filter_var and FILTER_VALIDATE_IP for minimalist/embedded PHP environments
if (!defined('FILTER_VALIDATE_IP')) define('FILTER_VALIDATE_IP', 275);
if (!defined('FILTER_FLAG_IPV4')) define('FILTER_FLAG_IPV4', 524288);
if (!defined('FILTER_FLAG_IPV6')) define('FILTER_FLAG_IPV6', 1048576);

function isIpAddress($host) {
    if (!is_string($host) && !is_numeric($host)) return false;
    $val = trim((string)$host);
    if ($val === '') return false;
    if (preg_match('/^(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(?:\.(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}$/', $val)) {
        return true;
    }
    if (strpos($val, ':') !== false && preg_match('/^[0-9a-fA-F:]+$/', $val)) {
        return true;
    }
    return false;
}

if (!function_exists('filter_var')) {
    function filter_var($value, $filter = FILTER_VALIDATE_IP, $options = 0) {
        if ($filter === FILTER_VALIDATE_IP) {
            return isIpAddress($value) ? (string)$value : false;
        }
        return !empty($value) ? $value : false;
    }
}

$gamesDir = $baseDir . '/games';
$txtDir = $baseDir . '/games_txt';
$statusFile = $baseDir . '/sync_status.json';

if (!is_dir($gamesDir)) {
    mkdir($gamesDir, 0777, true);
}
if (!is_dir($txtDir)) {
    mkdir($txtDir, 0777, true);
}

function readJsonFile($path) {
    if (!file_exists($path)) return null;
    $raw = file_get_contents($path);
    if ($raw === false) return null;
    // Strip UTF-8 BOM if present
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    return json_decode($raw, true);
}

function getSystemTxtFilename($sysId) {
    if ($sysId === 'lindbergh') return 'lindbergh_retrobat.txt';
    if ($sysId === 'namco2x6') return 'namco2x6_retrobat.txt';
    if ($sysId === 'chihiro') return 'chihiro_legacy.txt';
    if (substr($sysId, -9) === '_batocera') {
        return substr($sysId, 0, -9) . '.txt';
    }
    return $sysId . '.txt';
}

function getSystemTxtPath($txtDir, $sysId) {
    return $txtDir . '/' . getSystemTxtFilename($sysId);
}

function getPixnConfig($baseDir) {
    $configFile = $baseDir . '/config.json';
    if (!file_exists($configFile) && file_exists(dirname($baseDir) . '/config.json')) {
        $configFile = dirname($baseDir) . '/config.json';
    }
    $config = file_exists($configFile) ? readJsonFile($configFile) : [];
    if (!is_array($config)) $config = [];

    // Fallbacks for backwards compatibility:
    if (empty($config['batocera_unc_path'])) {
        $bFile = $baseDir . '/batocera_config.json';
        if (!file_exists($bFile) && file_exists(dirname($baseDir) . '/batocera_config.json')) {
            $bFile = dirname($baseDir) . '/batocera_config.json';
        }
        if (file_exists($bFile)) {
            $bData = readJsonFile($bFile);
            if (!empty($bData['unc_path'])) {
                $config['batocera_unc_path'] = $bData['unc_path'];
            }
        }
    }
    if (empty($config['pixn_session_cookie'])) {
        $aFile = $baseDir . '/data/pixn_auth.json';
        if (!file_exists($aFile) && file_exists(dirname($baseDir) . '/data/pixn_auth.json')) {
            $aFile = dirname($baseDir) . '/data/pixn_auth.json';
        }
        if (file_exists($aFile)) {
            $aData = readJsonFile($aFile);
            if (!empty($aData['cookie'])) {
                $config['pixn_session_cookie'] = $aData['cookie'];
            }
        }
    }
    if (empty($config['batocera_unc_path'])) {
        $config['batocera_unc_path'] = '\\\\BATOCERA\\share\\roms';
    }

    return $config;
}

function getPixnBatoceraUncPath($baseDir, $explicit = '') {
    $cfg = getPixnConfig($baseDir);
    $cfgPath = trim($cfg['batocera_unc_path'] ?? '');

    if (!empty($explicit) && is_string($explicit)) {
        $cleanExp = trim($explicit);
        $isGenericPlaceholder = (strcasecmp(rtrim(str_replace('/', '\\', $cleanExp), '\\/'), '\\\\batocera\\share\\roms') === 0);
        // If client passed the generic placeholder "\\\\BATOCERA\\share\\roms" but server has a specific configured path (e.g. \\batocera-rgs\share\roms), prefer server config!
        if ($isGenericPlaceholder && $cfgPath !== '' && strcasecmp(rtrim(str_replace('/', '\\', $cfgPath), '\\/'), '\\\\batocera\\share\\roms') !== 0) {
            return $cfgPath;
        }
        if (!$isGenericPlaceholder && $cleanExp !== '') {
            return $cleanExp;
        }
    }

    if ($cfgPath !== '' && strcasecmp(rtrim(str_replace('/', '\\', $cfgPath), '\\/'), '\\\\batocera\\share\\roms') !== 0) {
        return $cfgPath;
    }

    // Auto-detect fallback if generic default: check if batocera-rgs is alive on the LAN
    static $autoBatoPath = null;
    if ($autoBatoPath === null) {
        $autoBatoPath = '';
        if (resolveLanHostname('batocera-rgs')) {
            $autoBatoPath = '\\\\batocera-rgs\\share\\roms';
        }
    }
    if ($autoBatoPath !== '') {
        return $autoBatoPath;
    }

    return $cfgPath !== '' ? $cfgPath : '\\\\BATOCERA\\share\\roms';
}

function fetchPixnRetroApi($endpoint, $baseDir, $timeout = 10) {
    $cfg = getPixnConfig($baseDir);
    $cookie = $cfg['pixn_session_cookie'] ?? '';
    $url = 'https://pixn-retro.dedyn.io' . $endpoint;

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(4, $timeout));
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        if (!empty($cookie)) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res && $code >= 200 && $code < 300) {
            $parsed = json_decode($res, true);
            if (is_array($parsed)) return $parsed;
        }
        return null;
    }

    $curlBin = file_exists('/usr/bin/curl') ? '/usr/bin/curl' : (file_exists('/bin/curl') ? '/bin/curl' : 'curl');
    $escapedUrl = escapeshellarg($url);
    $escapedCookie = escapeshellarg($cookie);

    $out = [];
    $ret = -1;
    @exec("{$curlBin} -s -k --connect-timeout 4 --max-time {$timeout} -H \"User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\" -H \"Accept: application/json\" --cookie {$escapedCookie} {$escapedUrl} 2>&1", $out, $ret);

    if ($ret === 0 && !empty($out)) {
        $res = implode("\n", $out);
        return json_decode($res, true);
    }
    return null;
}

function getPixnSystemGamelist($baseDir, $sysId) {
    if (!$sysId) return [];

    $cacheDir = $baseDir . '/data/cache_gamelist';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $cleanSys = preg_replace('/[^a-zA-Z0-9_\-]/', '', $sysId);
    $cacheFile = $cacheDir . '/' . $cleanSys . '.json';
    $cacheTTL = 86400 * 7; // 7 days TTL

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTTL)) {
        $cached = readJsonFile($cacheFile);
        if (is_array($cached) && !empty($cached['__v']) && $cached['__v'] >= 2) {
            return $cached;
        }
    }

    // Try primary sysId, and if ends in _batocera/_retrobat also try stripped
    $candidateSysIds = [$cleanSys];
    $strippedSys = preg_replace('/(_batocera|_retrobat)$/', '', $cleanSys);
    if ($strippedSys !== $cleanSys) {
        $candidateSysIds[] = $strippedSys;
    }

    $rawXml = false;
    foreach ($candidateSysIds as $csId) {
        $xmlUrl = 'https://pixn-retro.dedyn.io/media/' . $csId . '/gamelist.xml';
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $xmlUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode === 200 && $res) {
                $rawXml = $res;
                break;
            }
        } else {
            $escapedUrl = escapeshellarg($xmlUrl);
            $out = [];
            $ret = -1;
            @exec("/usr/bin/curl -s -k -m 10 -H 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)' {$escapedUrl}", $out, $ret);
            if ($ret === 0 && !empty($out)) {
                $res = implode("\n", $out);
                if (stripos($res, '<gameList>') !== false || stripos($res, '<game>') !== false) {
                    $rawXml = $res;
                    break;
                }
            }
        }
    }

    if (!$rawXml) return [];

    $reader = new XMLReader();
    if (!$reader->XML($rawXml, 'UTF-8', LIBXML_NOERROR | LIBXML_NOWARNING)) return [];

    $metaMap = [];
    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'game') {
            try {
                $gameXml = new SimpleXMLElement($reader->readOuterXML());
                $path = (string)$gameXml->path;
                $name = (string)$gameXml->name;
                $dev = (string)$gameXml->developer;
                $pub = (string)$gameXml->publisher;
                $genre = (string)$gameXml->genre;
                $desc = (string)$gameXml->desc;
                $rating = (string)$gameXml->rating;
                $releasedate = (string)$gameXml->releasedate;
                $players = (string)$gameXml->players;
                $boxart = (string)($gameXml->boxart ?: ($gameXml->box2d ?: $gameXml->box3d));
                $boxback = (string)($gameXml->boxback ?: ($gameXml->back ?: ''));
                $cartridge = (string)($gameXml->cartridge ?: ($gameXml->cartridges ?: ''));
                $thumb = (string)$gameXml->thumbnail;
                $titleshot = (string)($gameXml->titleshot ?: ($gameXml->titlescreen ?: ''));
                $marquee = (string)$gameXml->marquee;
                $screenshot = (string)($gameXml->screenshot ?: ($gameXml->image ?: ''));
                $fanart = (string)$gameXml->fanart;
                $video = (string)$gameXml->video;

                $normPath = ltrim(str_replace('\\', '/', $path), './');
                $normName = mb_strtolower(trim($name));
                $baseFile = mb_strtolower(pathinfo($normPath, PATHINFO_BASENAME));
                $baseWithoutExt = mb_strtolower(pathinfo($normPath, PATHINFO_FILENAME));

                $info = [
                    'name' => trim($name),
                    'developer' => trim($dev),
                    'publisher' => trim($pub),
                    'genre' => trim($genre),
                    'desc' => trim($desc),
                    'rating' => trim($rating),
                    'releasedate' => trim($releasedate),
                    'players' => trim($players),
                    'boxart' => ltrim(str_replace('\\', '/', $boxart), './'),
                    'boxback' => ltrim(str_replace('\\', '/', $boxback), './'),
                    'cartridge' => ltrim(str_replace('\\', '/', $cartridge), './'),
                    'thumbnail' => ltrim(str_replace('\\', '/', $thumb), './'),
                    'titleshot' => ltrim(str_replace('\\', '/', $titleshot), './'),
                    'titlescreen' => ltrim(str_replace('\\', '/', ($titleshot ?: $marquee)), './'),
                    'marquee' => ltrim(str_replace('\\', '/', $marquee), './'),
                    'image' => ltrim(str_replace('\\', '/', $screenshot), './'),
                    'screenshot' => ltrim(str_replace('\\', '/', $screenshot), './'),
                    'fanart' => ltrim(str_replace('\\', '/', $fanart), './'),
                    'video' => ltrim(str_replace('\\', '/', $video), './')
                ];

                if ($normPath !== '') $metaMap['p:' . mb_strtolower($normPath)] = $info;
                if ($baseFile !== '') $metaMap['f:' . $baseFile] = $info;
                if ($baseWithoutExt !== '') $metaMap['b:' . $baseWithoutExt] = $info;
                if ($normName !== '') $metaMap['n:' . $normName] = $info;
            } catch (Exception $ex) {}
        }
    }
    $reader->close();

    $metaMap['__v'] = 2;
    @file_put_contents($cacheFile, json_encode($metaMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $metaMap;
}

function enrichSystemMetadata($sysId, &$games) {
    global $baseDir;
    $metaMap = getPixnSystemGamelist($baseDir, $sysId);
    if (empty($metaMap)) return 0;

    $enrichedCount = 0;
    foreach ($games as &$g) {
        $gid = $g['id'] ?? '';
        $gname = mb_strtolower(trim($g['name'] ?? ''));
        $lowerGid = mb_strtolower(str_replace('\\', '/', $gid));
        $baseFile = mb_strtolower(pathinfo($lowerGid, PATHINFO_BASENAME));
        $baseWithoutExt = mb_strtolower(pathinfo($lowerGid, PATHINFO_FILENAME));

        $match = $metaMap['p:' . $lowerGid] ?? ($metaMap['f:' . $baseFile] ?? ($metaMap['b:' . $baseWithoutExt] ?? ($metaMap['n:' . $gname] ?? null)));

        if ($match) {
            $devVal = $match['developer'] ?? '';
            $g['developer'] = $devVal;
            if ($devVal !== '') $enrichedCount++;

            if (empty($g['publisher']) && !empty($match['publisher'])) {
                $g['publisher'] = $match['publisher'];
            }
            if (!empty($match['genre'])) {
                $g['genre'] = $match['genre'];
            }
            if (!empty($match['desc'])) {
                $g['desc'] = $match['desc'];
            }
            if (!empty($match['rating'])) {
                $g['rating'] = $match['rating'];
            }
            if (!empty($match['players'])) {
                $g['players'] = $match['players'];
            }
            if (empty($g['releasedate']) && !empty($match['releasedate'])) {
                $g['releasedate'] = $match['releasedate'];
            }
            if (!empty($match['boxart'])) {
                $g['boxart'] = $match['boxart'];
            }
            if (!empty($match['boxback'])) {
                $g['boxback'] = $match['boxback'];
            }
            if (!empty($match['cartridge'])) {
                $g['cartridge'] = $match['cartridge'];
            }
            if (!empty($match['thumbnail'])) {
                $g['thumbnail'] = $match['thumbnail'];
            }
            if (!empty($match['titleshot'])) {
                $g['titleshot'] = $match['titleshot'];
                $g['titlescreen'] = $match['titleshot'];
            } elseif (!empty($match['titlescreen'])) {
                $g['titlescreen'] = $match['titlescreen'];
            }
            if (!empty($match['marquee'])) {
                $g['marquee'] = $match['marquee'];
            }
            if (!empty($match['image'])) {
                $g['image'] = $match['image'];
                $g['screenshot'] = $match['image'];
            }
        } else {
            if (!isset($g['developer'])) $g['developer'] = '';
        }
    }
    unset($g);
    return $enrichedCount;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$action = $_GET['action'] ?? ($data['action'] ?? 'status');

if (!function_exists('pixnHttpGet')) {
    function pixnHttpGet($url, $headers = [], $timeout = 15) {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) PIXN/2.0');
            if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($httpCode >= 200 && $httpCode < 400) ? $res : null;
        }

        $curlBin = file_exists('/usr/bin/curl') ? '/usr/bin/curl' : (file_exists('/bin/curl') ? '/bin/curl' : 'curl');
        $headerArgs = '';
        foreach ($headers as $h) {
            $headerArgs .= ' -H ' . escapeshellarg($h);
        }
        $escapedUrl = escapeshellarg($url);
        $out = [];
        $ret = -1;
        @exec("{$curlBin} -s -k --max-time {$timeout} -A 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) PIXN/2.0' {$headerArgs} {$escapedUrl} 2>&1", $out, $ret);
        if ($ret === 0 && !empty($out)) {
            $joined = implode("\n", $out);
            if (strlen($joined) > 0) return $joined;
        }

        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", array_merge(['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) PIXN/2.0'], $headers)),
                'timeout' => $timeout,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];
        $ctx = stream_context_create($opts);
        $res = @file_get_contents($url, false, $ctx);
        return $res ?: null;
    }
}

if ($action === 'get_batocera_media') {
    $sysId = trim($_GET['system'] ?? '');
    $relMedia = trim($_GET['path'] ?? '');
    $relMedia = ltrim(str_replace(['\\', '..'], ['/', ''], $relMedia), './');
    if (!$sysId || !$relMedia) {
        http_response_code(400);
        exit('Invalid parameters');
    }

    $uncPath = getPixnBatoceraUncPath($baseDir, $_GET['unc_path'] ?? ($data['unc_path'] ?? ''));
    $conn = getBatoceraHostAndShare($uncPath);

    $targetHost = $conn ? $conn['targetHost'] : 'batocera-rgs';
    $share = $conn ? $conn['share'] : 'share';
    $batoceraSubdir = $conn ? $conn['subDir'] : 'roms';
    $batoceraPath = '//' . $targetHost . '/' . $share;

    // Clean system id: strip _batocera and _retrobat suffixes
    $cleanSys = preg_replace('/(_batocera|_retrobat)$/i', '', $sysId);
    $batoceraFolder = $cleanSys;

    $folderMapFile = $baseDir . '/data/batocera_folder_map.json';
    if (file_exists($folderMapFile)) {
        $fMap = readJsonFile($folderMapFile);
        if (is_array($fMap)) {
            if (!empty($fMap[$cleanSys])) {
                $batoceraFolder = $fMap[$cleanSys];
            } elseif (!empty($fMap[$sysId])) {
                $batoceraFolder = $fMap[$sysId];
            }
        }
    }

    // --- FAST PATH: Direct HTTP Stream from Batocera Web Services (Port 1234) ---
    // Batocera PC runs a native HTTP service that serves game media in ~1-15ms without SMB overhead.
    $batoIp = $targetHost;
    if (!filter_var($batoIp, FILTER_VALIDATE_IP)) {
        $resolvedIp = function_exists('resolveLanHostname') ? resolveLanHostname($batoIp) : gethostbyname($batoIp);
        if ($resolvedIp && filter_var($resolvedIp, FILTER_VALIDATE_IP)) {
            $batoIp = $resolvedIp;
        }
    }

    if (filter_var($batoIp, FILTER_VALIDATE_IP)) {
        // Map relMedia path to Batocera HTTP media type
        $httpMediaType = 'thumbnail';
        if (strpos($relMedia, 'boxback') !== false || strpos($relMedia, 'back') !== false) {
            $httpMediaType = 'boxback';
        } elseif (strpos($relMedia, 'cartridge') !== false || strpos($relMedia, 'cart') !== false) {
            $httpMediaType = 'cartridge';
        } elseif (strpos($relMedia, 'box2d') !== false || strpos($relMedia, 'boxart') !== false) {
            $httpMediaType = 'boxart';
        } elseif (strpos($relMedia, 'image') !== false || strpos($relMedia, 'screenshot') !== false) {
            $httpMediaType = 'image';
        } elseif (strpos($relMedia, 'marquee') !== false || strpos($relMedia, 'wheel') !== false) {
            $httpMediaType = 'marquee';
        } elseif (strpos($relMedia, 'titles') !== false || strpos($relMedia, 'titleshot') !== false) {
            $httpMediaType = 'titleshot';
        } elseif (strpos($relMedia, 'fanart') !== false) {
            $httpMediaType = 'fanart';
        } elseif (strpos($relMedia, 'video') !== false || preg_match('/\.(mp4|webm|mkv|mov|avi)$/i', $relMedia)) {
            $httpMediaType = 'video';
        }

        // Direct Fast-Track HTTP URLs (tries exact known endpoints first without scanning multi-MB JSON files)
        $streamHeaders = [];
        if (!empty($_SERVER['HTTP_RANGE'])) {
            $streamHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
        }
        $streamCtx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $streamHeaders),
                'timeout' => ($httpMediaType === 'video' ? 2.5 : 0.8),
                'ignore_errors' => true
            ]
        ]);

        $directCandidateUrls = [];
        if (stripos($relMedia, 'systems/') === 0 || stripos($relMedia, '/systems/') === 0) {
            $directCandidateUrls[] = "http://{$batoIp}:1234/" . ltrim($relMedia, '/');
        }
        if (preg_match('#games/([a-f0-9]{32})/media/([a-z0-9_-]+)#i', $relMedia, $mPath)) {
            $directCandidateUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/{$mPath[1]}/media/{$mPath[2]}";
        }
        if (!empty($_GET['game_id'])) {
            $directCandidateUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/" . urlencode($_GET['game_id']) . "/media/{$httpMediaType}";
        }
        if (!empty($_GET['id']) && strlen($_GET['id']) === 32 && ctype_xdigit($_GET['id'])) {
            $directCandidateUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/" . urlencode($_GET['id']) . "/media/{$httpMediaType}";
        }
        if (!empty($_GET['rom'])) {
            $rFile = trim($_GET['rom']);
            $directCandidateUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/" . md5('/userdata/roms/' . $batoceraFolder . '/' . $rFile) . "/media/{$httpMediaType}";
            $directCandidateUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/" . md5('/userdata/roms/' . $batoceraFolder . '/' . basename($rFile)) . "/media/{$httpMediaType}";
        }
        if (!empty($_GET['id']) && (empty($_GET['game_id']) || $_GET['id'] !== $_GET['game_id'])) {
            $iFile = trim($_GET['id']);
            $directCandidateUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/" . md5('/userdata/roms/' . $batoceraFolder . '/' . $iFile) . "/media/{$httpMediaType}";
            $directCandidateUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/" . md5('/userdata/roms/' . $batoceraFolder . '/' . basename($iFile)) . "/media/{$httpMediaType}";
        }
        $directCandidateUrls = array_values(array_unique(array_filter($directCandidateUrls)));

        // Try direct hits immediately (takes ~4-15ms)
        foreach ($directCandidateUrls as $directUrl) {
            $http_response_header = [];
            $fp = @fopen($directUrl, 'rb', false, $streamCtx);
            if ($fp) {
                $respHeaders = $http_response_header ?? [];
                $statusLine = $respHeaders[0] ?? '';
                if (strpos($statusLine, '200') !== false || strpos($statusLine, '206') !== false) {
                    $cType = ($httpMediaType === 'video') ? 'video/mp4' : 'image/png';
                    $cLen = null;
                    $cRange = null;
                    foreach ($respHeaders as $hdr) {
                        if (stripos($hdr, 'Content-Type:') === 0) $cType = trim(substr($hdr, 13));
                        if (stripos($hdr, 'Content-Length:') === 0) $cLen = trim(substr($hdr, 15));
                        if (stripos($hdr, 'Content-Range:') === 0) $cRange = trim(substr($hdr, 14));
                    }
                    if ($httpMediaType === 'video') {
                        while (ob_get_level() > 0) { @ob_end_clean(); }
                    }
                    if (strpos($statusLine, '206') !== false) {
                        http_response_code(206);
                    }
                    header_remove('Pragma');
                    header_remove('Expires');
                    if (!empty($_GET['download_attachment'])) {
                        header('Content-Type: application/octet-stream', true);
                        header('Content-Disposition: attachment; filename="' . $_GET['download_attachment'] . '"');
                    } else {
                        header('Content-Type: ' . $cType, true);
                    }
                    if ($cLen) header('Content-Length: ' . $cLen);
                    if ($cRange) header('Content-Range: ' . $cRange);
                    header('Accept-Ranges: bytes');
                    header('Cache-Control: ' . ($httpMediaType === 'video' ? 'no-cache, no-store, must-revalidate' : 'public, max-age=604800, immutable'), true);
                    header('Access-Control-Allow-Origin: *');
                    header('X-Batocera-Source: http-stream-direct');
                    fpassthru($fp);
                    fclose($fp);
                    exit;
                }
                fclose($fp);
            }
        }

        // Secondary fallback candidates: only scan if direct hits didn't resolve
        $candidateRoms = [];
        if (!empty($_GET['rom'])) $candidateRoms[] = trim($_GET['rom']);
        if (!empty($_GET['id'])) $candidateRoms[] = trim($_GET['id']);
        $mediaBase = pathinfo($relMedia, PATHINFO_FILENAME);

        // Check if we can find exact ROM filename from Batocera scan cache
        $cacheDir = $baseDir . '/data/cache_batocera';
        if (is_dir($cacheDir)) {
            $cacheFiles = @glob($cacheDir . '/*' . $batoceraFolder . '*.json');
            if (!empty($cacheFiles)) {
                foreach ($cacheFiles as $cf) {
                    $cJson = @file_get_contents($cf);
                    if ($cJson) {
                        $cData = json_decode($cJson, true);
                        if (is_array($cData)) {
                            if (!empty($cData['other_games']) && is_array($cData['other_games'])) {
                                foreach ($cData['other_games'] as $og) {
                                    $ogName = $og['name'] ?? '';
                                    $ogFile = $og['filename'] ?? ($og['id'] ?? '');
                                    $ogStem = pathinfo($ogFile, PATHINFO_FILENAME);
                                    if (strcasecmp($ogName, $mediaBase) === 0 || strcasecmp($ogStem, $mediaBase) === 0) {
                                        if ($ogFile && !in_array($ogFile, $candidateRoms)) {
                                            $candidateRoms[] = $ogFile;
                                        }
                                    }
                                }
                            }
                            if (!empty($cData['found_files']) && is_array($cData['found_files'])) {
                                foreach ($cData['found_files'] as $ff) {
                                    $ffFile = is_array($ff) ? ($ff['file'] ?? '') : $ff;
                                    $ffStem = pathinfo($ffFile, PATHINFO_FILENAME);
                                    if (strcasecmp($ffStem, $mediaBase) === 0) {
                                        if ($ffFile && !in_array($ffFile, $candidateRoms)) {
                                            $candidateRoms[] = $ffFile;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // Common ROM container extensions
        $commonExts = [
            '.wsquashfs', '.zip', '.7z', '.iso', '.chd', '.bin', '.cue', '.rom', '.nes', '.sfc', '.smc', '.md',
            '.gba', '.gbc', '.gb', '.p8', '.squashfs', '.crt', '.d64', '.prg', '.t64', '.tap', '.hdf', '.adf',
            '.ipf', '.lha', '.dsk', '.cpc', '.tzx', '.z80', '.sna', '.a78', '.m3u', '.sms'
        ];
        foreach ($commonExts as $ce) {
            $candidateRoms[] = $mediaBase . $ce;
        }
        $candidateRoms[] = $mediaBase;

        $candidateHttpUrls = [];
        if (stripos($relMedia, 'systems/') === 0 || stripos($relMedia, '/systems/') === 0) {
            $candidateHttpUrls[] = "http://{$batoIp}:1234/" . ltrim($relMedia, '/');
        }
        if (preg_match('#games/([a-f0-9]{32})/media/([a-z0-9_-]+)#i', $relMedia, $mPath)) {
            $candidateHttpUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/{$mPath[1]}/media/{$mPath[2]}";
        }
        if (!empty($_GET['game_id'])) {
            $candidateHttpUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/" . urlencode($_GET['game_id']) . "/media/{$httpMediaType}";
        }
        if (!empty($_GET['id']) && strlen($_GET['id']) === 32 && ctype_xdigit($_GET['id'])) {
            $candidateHttpUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/" . urlencode($_GET['id']) . "/media/{$httpMediaType}";
        }

        // Match against Batocera HTTP API cache
        if (function_exists('getBatoceraSystemGamesHttp')) {
            $batoGames = getBatoceraSystemGamesHttp($batoIp, $batoceraFolder, $baseDir);
            if (!empty($batoGames)) {
                $checkItems = array_filter([$_GET['game_id'] ?? '', $_GET['id'] ?? '', $_GET['rom'] ?? '', $mediaBase, trim($_GET['name'] ?? '')]);
                foreach ($batoGames as $bg) {
                    $bgId = $bg['id'] ?? '';
                    $bgName = $bg['name'] ?? '';
                    $bgPath = $bg['path'] ?? '';
                    $bgFile = basename($bgPath);
                    $bgStem = pathinfo($bgFile, PATHINFO_FILENAME);
                    $matchedBg = false;
                    foreach ($checkItems as $ci) {
                        if (!$ci) continue;
                        $ciBase = basename($ci);
                        $ciStem = pathinfo($ciBase, PATHINFO_FILENAME);
                        if ($bgId === $ci || strcasecmp($bgFile, $ciBase) === 0 || strcasecmp($bgStem, $ciStem) === 0 || strcasecmp($bgName, $ci) === 0 || strcasecmp($bgStem, $ci) === 0) {
                            $matchedBg = true;
                            break;
                        }
                    }
                    if ($matchedBg) {
                        if (!empty($bg[$httpMediaType])) {
                            $candidateHttpUrls[] = "http://{$batoIp}:1234/" . ltrim($bg[$httpMediaType], '/');
                        }
                        $candidateHttpUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/{$bgId}/media/{$httpMediaType}";
                        break;
                    }
                }
            }
        }

        foreach ($candidateRoms as $candRom) {
            $batoGameId = md5('/userdata/roms/' . $batoceraFolder . '/' . $candRom);
            $candidateHttpUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/{$batoGameId}/media/{$httpMediaType}";
        }
        $candidateHttpUrls = array_values(array_unique(array_filter($candidateHttpUrls)));

        foreach ($candidateHttpUrls as $batoHttpUrl) {

            $http_response_header = [];
            $fp = @fopen($batoHttpUrl, 'rb', false, $streamCtx);
            if ($fp) {
                $respHeaders = $http_response_header ?? [];
                $statusLine = $respHeaders[0] ?? '';
                if (strpos($statusLine, '200') !== false || strpos($statusLine, '206') !== false) {
                    $cType = ($httpMediaType === 'video') ? 'video/mp4' : 'image/png';
                    $cLen = null;
                    $cRange = null;
                    foreach ($respHeaders as $hdr) {
                        if (stripos($hdr, 'Content-Type:') === 0) $cType = trim(substr($hdr, 13));
                        if (stripos($hdr, 'Content-Length:') === 0) $cLen = trim(substr($hdr, 15));
                        if (stripos($hdr, 'Content-Range:') === 0) $cRange = trim(substr($hdr, 14));
                    }
                    if ($httpMediaType === 'video') {
                        while (ob_get_level() > 0) { @ob_end_clean(); }
                    }
                    if (strpos($statusLine, '206') !== false) {
                        http_response_code(206);
                    }
                    header_remove('Pragma');
                    header_remove('Expires');
                    if (!empty($_GET['download_attachment'])) {
                        header('Content-Type: application/octet-stream', true);
                        header('Content-Disposition: attachment; filename="' . $_GET['download_attachment'] . '"');
                    } else {
                        header('Content-Type: ' . $cType, true);
                    }
                    if ($cLen) header('Content-Length: ' . $cLen);
                    if ($cRange) header('Content-Range: ' . $cRange);
                    header('Accept-Ranges: bytes');
                    header('Cache-Control: ' . ($httpMediaType === 'video' ? 'no-cache, no-store, must-revalidate' : 'public, max-age=604800, immutable'), true);
                    header('Access-Control-Allow-Origin: *');
                    header('X-Batocera-Source: http-stream');
                    fpassthru($fp);
                    fclose($fp);
                    exit;
                }
                fclose($fp);
            }
        }
    }
    // --- END FAST PATH (Falls through to smbclient if HTTP stream not available) ---

    // Candidate paths to attempt
    $pathsToTry = [$relMedia];
    $mediaDir = dirname($relMedia);
    $mediaBase = pathinfo($relMedia, PATHINFO_FILENAME);
    $mediaExt = strtolower(pathinfo($relMedia, PATHINFO_EXTENSION));

    if (strpos($mediaDir, 'wheel') !== false) {
        $pathsToTry[] = str_replace('wheel', 'marquee', $relMedia);
    } elseif (strpos($mediaDir, 'marquee') !== false) {
        $pathsToTry[] = str_replace('marquee', 'wheel', $relMedia);
    } elseif (strpos($mediaDir, 'cartridges') !== false) {
        $pathsToTry[] = str_replace('cartridges', 'cartridge', $relMedia);
    } elseif (strpos($mediaDir, 'cartridge') !== false) {
        $pathsToTry[] = str_replace('cartridge', 'cartridges', $relMedia);
    } elseif (strpos($mediaDir, 'titlescreen') !== false) {
        $pathsToTry[] = str_replace('titlescreen', 'titles', $relMedia);
    } elseif (strpos($mediaDir, 'titles') !== false) {
        $pathsToTry[] = str_replace('titles', 'titlescreen', $relMedia);
    }

    // Also try alternative image extensions (.png, .jpg, .jpeg, .webp)
    if (in_array($mediaExt, ['png', 'jpg', 'jpeg', 'webp'])) {
        $altExts = ($mediaExt === 'png') ? ['jpg', 'jpeg', 'webp'] : ['png', 'webp', 'jpg'];
        foreach ($altExts as $ae) {
            $altP = ($mediaDir !== '.' ? $mediaDir . '/' : '') . $mediaBase . '.' . $ae;
            if (!in_array($altP, $pathsToTry)) {
                $pathsToTry[] = $altP;
            }
        }
    }

    // Add candidate names derived from $_GET['rom'] and $_GET['id']
    $extraNames = [];
    if (!empty($_GET['rom'])) {
        $extraNames[] = pathinfo(trim($_GET['rom']), PATHINFO_FILENAME);
    }
    if (!empty($_GET['id'])) {
        $extraNames[] = pathinfo(trim($_GET['id']), PATHINFO_FILENAME);
    }
    if (preg_match('/\bii\b/i', $mediaBase)) {
        $extraNames[] = preg_replace('/\bii\b/i', '2', $mediaBase);
    } elseif (preg_match('/\b2\b/', $mediaBase)) {
        $extraNames[] = preg_replace('/\b2\b/', 'II', $mediaBase);
    }
    if (preg_match('/\biii\b/i', $mediaBase)) {
        $extraNames[] = preg_replace('/\biii\b/i', '3', $mediaBase);
    } elseif (preg_match('/\b3\b/', $mediaBase)) {
        $extraNames[] = preg_replace('/\b3\b/', 'III', $mediaBase);
    }

    foreach ($extraNames as $en) {
        $en = trim($en);
        if ($en !== '' && $en !== $mediaBase) {
            $extToUse = $mediaExt ?: 'png';
            $nP = ($mediaDir !== '.' ? $mediaDir . '/' : '') . $en . '.' . $extToUse;
            if (!in_array($nP, $pathsToTry)) $pathsToTry[] = $nP;
            $nPAlt = ($mediaDir !== '.' ? $mediaDir . '/' : '') . $en . '.jpg';
            if (!in_array($nPAlt, $pathsToTry)) $pathsToTry[] = $nPAlt;
        }
    }

    // Determine direct filesystem base path if accessible (Windows UNC or mounted local path)
    $directBaseFolder = '';
    $cleanUnc = str_replace('\\', '/', $uncPath);
    if (@is_dir($uncPath . '/' . $batoceraFolder)) {
        $directBaseFolder = rtrim($uncPath, '\\/') . DIRECTORY_SEPARATOR . $batoceraFolder;
    } elseif (@is_dir($uncPath . '\\' . $batoceraFolder)) {
        $directBaseFolder = rtrim($uncPath, '\\/') . DIRECTORY_SEPARATOR . $batoceraFolder;
    } elseif (@is_dir($cleanUnc . '/' . $batoceraFolder)) {
        $directBaseFolder = rtrim($cleanUnc, '/') . '/' . $batoceraFolder;
    } else {
        $winBase = '\\\\' . $targetHost . '\\' . $share . ($batoceraSubdir !== '' ? '\\' . str_replace('/', '\\', $batoceraSubdir) : '') . '\\' . $batoceraFolder;
        if (@is_dir($winBase)) {
            $directBaseFolder = $winBase;
        }
    }

    $escapedShare = escapeshellarg($batoceraPath);

    foreach ($pathsToTry as $tryRel) {
        $currExt = strtolower(pathinfo($tryRel, PATHINFO_EXTENSION));
        $mime = 'image/png';
        if ($currExt === 'jpg' || $currExt === 'jpeg') $mime = 'image/jpeg';
        elseif ($currExt === 'webp') $mime = 'image/webp';
        elseif ($currExt === 'gif') $mime = 'image/gif';
        elseif ($currExt === 'mp4') $mime = 'video/mp4';
        elseif ($currExt === 'webm') $mime = 'video/webm';
        elseif ($currExt === 'mkv') $mime = 'video/x-matroska';
        elseif ($currExt === 'mov') $mime = 'video/quicktime';

        // TIER 0: Direct filesystem / UNC access (Instant ~0.001s without invoking processes)
        if ($directBaseFolder !== '') {
            $directFilePath = rtrim($directBaseFolder, '\\/') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $tryRel);
            if (@file_exists($directFilePath) && @filesize($directFilePath) > 0) {
                if (!empty($_GET['download_attachment'])) {
                    header('Content-Type: application/octet-stream', true);
                    header('Content-Disposition: attachment; filename="' . $_GET['download_attachment'] . '"');
                } else {
                    header('Content-Type: ' . $mime, true);
                }
                header('Content-Length: ' . filesize($directFilePath));
                header('Cache-Control: public, max-age=604800, immutable');
                header('Access-Control-Allow-Origin: *');
                header('X-Batocera-Source: direct-unc');
                readfile($directFilePath);
                exit;
            }
        }

        $remotePath = ($batoceraSubdir ? $batoceraSubdir . '/' : '') . $batoceraFolder . '/' . $tryRel;

        // For video files: Stream directly via pipe (zero disk download)
        if (in_array($currExt, ['mp4', 'webm', 'mkv', 'mov'], true)) {
            $getCmd = escapeshellarg('get "' . $remotePath . '" -');
            $pipe = @popen("/usr/bin/smbclient {$escapedShare} -N -t 30 -c {$getCmd} 2>/dev/null", 'r');
            if ($pipe) {
                $probe = fread($pipe, 1024);
                if ($probe !== false && strlen($probe) > 0) {
                    @set_time_limit(0);
                    while (ob_get_level() > 0) { @ob_end_clean(); }
                    header('Content-Type: ' . $mime, true);
                    header('Accept-Ranges: bytes');
                    header('Cache-Control: no-cache, no-store, must-revalidate');
                    header('X-Content-Type-Options: nosniff');
                    header('X-Batocera-Source: smb-stream');
                    echo $probe;
                    flush();
                    while (!feof($pipe) && !connection_aborted()) {
                        $chunk = fread($pipe, 65536);
                        if ($chunk === false || $chunk === '') {
                            if (feof($pipe)) break;
                            usleep(5000);
                            continue;
                        }
                        echo $chunk;
                        flush();
                    }
                    pclose($pipe);
                    exit;
                }
                pclose($pipe);
            }
            continue;
        }

        $tempFile = @tempnam(sys_get_temp_dir(), 'bmed_');
        if (!$tempFile) {
            $tempFile = $baseDir . '/data/' . uniqid('tmp_med_', true) . '.' . $currExt;
        }
        $getCmd = escapeshellarg('get "' . $remotePath . '" "' . $tempFile . '"');

        $out = [];
        $ret = -1;
        @exec("/usr/bin/smbclient {$escapedShare} -N -t 2 -c {$getCmd} 2>&1", $out, $ret);
        if ($ret !== 0 && (strpos(implode("\n", $out), 'LOGON_FAILURE') !== false || strpos(implode("\n", $out), 'ACCESS_DENIED') !== false)) {
            $out = [];
            @exec("/usr/bin/smbclient {$escapedShare} -U 'root%linux' -t 2 -c {$getCmd} 2>&1", $out, $ret);
        }

        if (file_exists($tempFile) && filesize($tempFile) > 0) {
            if (!empty($_GET['download_attachment'])) {
                header('Content-Type: application/octet-stream', true);
                header('Content-Disposition: attachment; filename="' . $_GET['download_attachment'] . '"');
            } else {
                header('Content-Type: ' . $mime, true);
            }
            header('Content-Length: ' . filesize($tempFile));
            header('Cache-Control: public, max-age=604800');
            header('Access-Control-Allow-Origin: *');
            readfile($tempFile);
            @unlink($tempFile);
            exit;
        }

        if (file_exists($tempFile)) {
            @unlink($tempFile);
        }
    }

    http_response_code(404);
    header('Cache-Control: public, max-age=120', true);
    header('Content-Type: text/plain', true);
    exit('Image not found');
}

function getBatoceraVideoList($baseDir, $uncPath, $cleanSys, $batoceraFolder) {
    $cacheDir = $baseDir . '/data/cache_batocera';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
    $cacheFile = $cacheDir . '/' . $cleanSys . '_videos.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 1800)) {
        $c = @file_get_contents($cacheFile);
        if ($c) {
            $data = json_decode($c, true);
            if (is_array($data)) return $data;
        }
    }

    $conn = getBatoceraHostAndShare($uncPath);
    if (!$conn) return [];

    $targetHost = $conn['targetHost'];
    $share = $conn['share'];
    $batoceraSubdir = $conn['subDir'];
    $batoceraPath = '//' . $targetHost . '/' . $share;
    $escapedShare = escapeshellarg($batoceraPath);

    $videoDirs = [
        ($batoceraSubdir ? $batoceraSubdir . '/' : '') . $batoceraFolder . '/media/videos',
        ($batoceraSubdir ? $batoceraSubdir . '/' : '') . $batoceraFolder . '/videos'
    ];

    $videos = [];

    // Native Windows UNC / PHP filesystem directory reading
    $uncRoots = [];
    if (!empty($uncPath)) {
        $uncRoots[] = rtrim(str_replace('/', '\\', $uncPath), '\\');
    }
    $uncRoots[] = '\\\\' . $targetHost . '\\' . $share . ($batoceraSubdir ? '\\' . str_replace('/', '\\', $batoceraSubdir) : '');
    $uncRoots = array_unique(array_filter($uncRoots));

    foreach ($uncRoots as $uRoot) {
        $candDirs = [
            $uRoot . '\\' . $batoceraFolder . '\\media\\videos',
            $uRoot . '\\' . $batoceraFolder . '\\videos'
        ];
        foreach ($candDirs as $cDir) {
            if (@is_dir($cDir)) {
                $files = @scandir($cDir);
                if (is_array($files)) {
                    $relDir = (stripos($cDir, 'media\\videos') !== false ? 'media/videos/' : 'videos/');
                    foreach ($files as $fname) {
                        if ($fname === '.' || $fname === '..') continue;
                        $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                        if (in_array($ext, ['mp4', 'webm', 'mkv', 'avi', 'mov'], true)) {
                            $videos[] = [
                                'filename' => $fname,
                                'stem' => pathinfo($fname, PATHINFO_FILENAME),
                                'norm' => preg_replace('/[^a-z0-9]/', '', mb_strtolower(pathinfo($fname, PATHINFO_FILENAME))),
                                'size' => @filesize($cDir . '\\' . $fname) ?: 0,
                                'rel' => $relDir . $fname
                            ];
                        }
                    }
                    if (!empty($videos)) {
                        @file_put_contents($cacheFile, json_encode($videos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                        return $videos;
                    }
                }
            }
        }
    }

    // smbclient for Linux / Synology DSM
    if (DIRECTORY_SEPARATOR !== '\\' && function_exists('exec')) {
        foreach ($videoDirs as $vDir) {
            $lsCmd = escapeshellarg('cd "' . $vDir . '"; ls');
            $out = [];
            $ret = -1;
            @exec("/usr/bin/smbclient {$escapedShare} -N -t 5 -c {$lsCmd} 2>&1", $out, $ret);
            if ($ret !== 0 && (strpos(implode("\n", $out), 'LOGON_FAILURE') !== false || strpos(implode("\n", $out), 'ACCESS_DENIED') !== false)) {
                $out = [];
                @exec("/usr/bin/smbclient {$escapedShare} -U 'root%linux' -t 5 -c {$lsCmd} 2>&1", $out, $ret);
            }
            foreach ($out as $line) {
                $line = trim($line);
                if (preg_match('/^(.+?)\s+([DAHNSR_]+)\s+(\d+)\s+([A-Za-z]{3}\s+[A-Za-z]{3}.*)$/', $line, $m)) {
                    $fname = trim($m[1]);
                    $attrs = $m[2];
                    $fsize = (float)$m[3];
                    if (strpos($attrs, 'D') !== false || $fname === '.' || $fname === '..') continue;
                    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                    if (in_array($ext, ['mp4', 'webm', 'mkv', 'avi', 'mov'], true)) {
                        $relDir = (strpos($vDir, 'media/videos') !== false ? 'media/videos/' : 'videos/');
                        $videos[] = [
                            'filename' => $fname,
                            'stem' => pathinfo($fname, PATHINFO_FILENAME),
                            'norm' => preg_replace('/[^a-z0-9]/', '', mb_strtolower(pathinfo($fname, PATHINFO_FILENAME))),
                            'size' => $fsize,
                            'rel' => $relDir . $fname
                        ];
                    }
                }
            }
            if (!empty($videos)) break;
        }
    }

    @file_put_contents($cacheFile, json_encode($videos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $videos;
}

function getBatoceraSystemGamesHttp($batoIp, $batoceraFolder, $baseDir, $forceRefresh = false) {
    if (!filter_var($batoIp, FILTER_VALIDATE_IP)) {
        $resolved = function_exists('resolveLanHostname') ? resolveLanHostname($batoIp) : null;
        if ($resolved && filter_var($resolved, FILTER_VALIDATE_IP)) {
            $batoIp = $resolved;
        } else {
            return [];
        }
    }
    $cacheDir = $baseDir . '/data/cache_batocera';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
    $cacheFile = $cacheDir . '/_bato_http_' . $batoceraFolder . '.json';
    if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile) < 1800)) {
        $c = @file_get_contents($cacheFile);
        if ($c) {
            $data = json_decode($c, true);
            if (is_array($data) && !empty($data)) return $data;
        }
    }

    $url = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games";
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 3.5,
            'ignore_errors' => true
        ]
    ]);
    $json = @file_get_contents($url, false, $ctx);
    if ($json) {
        $data = json_decode($json, true);
        if (is_array($data) && !empty($data)) {
            @file_put_contents($cacheFile, $json);
            return $data;
        }
    }
    if ($forceRefresh && file_exists($cacheFile)) {
        $c = @file_get_contents($cacheFile);
        if ($c) {
            $data = json_decode($c, true);
            if (is_array($data) && !empty($data)) return $data;
        }
    }
    return [];
}

if ($action === 'check_batocera_video') {
    header('Content-Type: application/json; charset=utf-8');
    $sysId = trim($_GET['system'] ?? ($data['system'] ?? ''));
    $rom = trim($_GET['rom'] ?? ($data['rom'] ?? ''));
    $id = trim($_GET['id'] ?? ($data['id'] ?? ''));
    $name = trim($_GET['name'] ?? ($data['name'] ?? ($data['game'] ?? '')));
    $givenPath = trim($_GET['path'] ?? ($data['path'] ?? ''));

    if (!$sysId) {
        echo json_encode(['status' => 'error', 'has_video' => false, 'message' => 'Missing system id']);
        exit;
    }

    $uncPath = getPixnBatoceraUncPath($baseDir, $_GET['unc_path'] ?? ($data['unc_path'] ?? ''));
    $conn = getBatoceraHostAndShare($uncPath);

    $targetHost = $conn ? $conn['targetHost'] : 'batocera-rgs';
    $cleanSys = preg_replace('/(_batocera|_retrobat)$/i', '', $sysId);
    $batoceraFolder = $cleanSys;

    $folderMapFile = $baseDir . '/data/batocera_folder_map.json';
    if (file_exists($folderMapFile)) {
        $fMap = readJsonFile($folderMapFile);
        if (is_array($fMap)) {
            if (!empty($fMap[$cleanSys])) $batoceraFolder = $fMap[$cleanSys];
            elseif (!empty($fMap[$sysId])) $batoceraFolder = $fMap[$sysId];
        }
    }

    // 1. FAST PATH: Check Batocera HTTP service (Port 1234)
    $batoIp = $targetHost;
    if (!filter_var($batoIp, FILTER_VALIDATE_IP)) {
        $resolvedIp = function_exists('resolveLanHostname') ? resolveLanHostname($batoIp) : gethostbyname($batoIp);
        if ($resolvedIp && filter_var($resolvedIp, FILTER_VALIDATE_IP)) {
            $batoIp = $resolvedIp;
        }
    }

    $searchStems = [];
    if ($rom) $searchStems[] = pathinfo($rom, PATHINFO_FILENAME);
    if ($id) $searchStems[] = pathinfo($id, PATHINFO_FILENAME);
    if ($name) $searchStems[] = $name;
    if ($givenPath) $searchStems[] = pathinfo($givenPath, PATHINFO_FILENAME);
    $searchStems = array_unique(array_filter($searchStems));

    if (filter_var($batoIp, FILTER_VALIDATE_IP)) {
        $batoGames = getBatoceraSystemGamesHttp($batoIp, $batoceraFolder, $baseDir);
        if (!empty($batoGames)) {
            // Pass 1: Exact matches
            foreach ($batoGames as $bg) {
                if (empty($bg['video'])) continue;
                $bgId = $bg['id'] ?? '';
                $bgName = $bg['name'] ?? '';
                $bgPath = $bg['path'] ?? '';
                $bgStem = pathinfo($bgPath, PATHINFO_FILENAME);
                $bgNormStem = preg_replace('/[^a-z0-9]/', '', mb_strtolower($bgStem));
                $bgNormName = preg_replace('/[^a-z0-9]/', '', mb_strtolower($bgName));

                // Direct ID match
                if ($id && $bgId === $id) {
                    echo json_encode([
                        'status' => 'ok',
                        'has_video' => true,
                        'source' => 'http',
                        'video_url' => "save.php?action=stream_batocera_video&src=http&system=" . urlencode($cleanSys) . "&game_id=" . urlencode($bgId),
                        'direct_url' => "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/" . urlencode($bgId) . "/media/video",
                        'game_id' => $bgId,
                        'matched_name' => $bgName
                    ]);
                    exit;
                }

                foreach ($searchStems as $sStem) {
                    $sNorm = preg_replace('/[^a-z0-9]/', '', mb_strtolower($sStem));
                    if (
                        strcasecmp($bgStem, $sStem) === 0 ||
                        strcasecmp($bgName, $sStem) === 0 ||
                        ($sNorm !== '' && ($bgNormStem === $sNorm || $bgNormName === $sNorm))
                    ) {
                        echo json_encode([
                            'status' => 'ok',
                            'has_video' => true,
                            'source' => 'http',
                            'video_url' => "save.php?action=stream_batocera_video&src=http&system=" . urlencode($cleanSys) . "&game_id=" . urlencode($bgId),
                            'direct_url' => "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/" . urlencode($bgId) . "/media/video",
                            'game_id' => $bgId,
                            'matched_name' => $bgName
                        ]);
                        exit;
                    }
                }
            }

            // Pass 2: Substring matches (min 4 chars)
            foreach ($batoGames as $bg) {
                if (empty($bg['video'])) continue;
                $bgId = $bg['id'] ?? '';
                $bgName = $bg['name'] ?? '';
                $bgPath = $bg['path'] ?? '';
                $bgStem = pathinfo($bgPath, PATHINFO_FILENAME);
                $bgNormStem = preg_replace('/[^a-z0-9]/', '', mb_strtolower($bgStem));
                $bgNormName = preg_replace('/[^a-z0-9]/', '', mb_strtolower($bgName));

                foreach ($searchStems as $sStem) {
                    $sNorm = preg_replace('/[^a-z0-9]/', '', mb_strtolower($sStem));
                    if ($sNorm !== '' && strlen($sNorm) >= 4) {
                        if (
                            strpos($bgNormStem, $sNorm) !== false ||
                            strpos($sNorm, $bgNormStem) !== false ||
                            strpos($bgNormName, $sNorm) !== false ||
                            strpos($sNorm, $bgNormName) !== false
                        ) {
                            echo json_encode([
                                'status' => 'ok',
                                'has_video' => true,
                                'source' => 'http',
                                'video_url' => "save.php?action=stream_batocera_video&src=http&system=" . urlencode($cleanSys) . "&game_id=" . urlencode($bgId),
                                'direct_url' => "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/" . urlencode($bgId) . "/media/video",
                                'game_id' => $bgId,
                                'matched_name' => $bgName
                            ]);
                            exit;
                        }
                    }
                }
            }
        }

        // Direct candidate ROM probe fallback
        $candidateRoms = [];
        if ($rom) $candidateRoms[] = $rom;
        if ($id) $candidateRoms[] = $id;
        $mediaBase = pathinfo($rom ?: ($id ?: $name), PATHINFO_FILENAME);
        if ($mediaBase) $candidateRoms[] = $mediaBase;

        $streamCtx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Range: bytes=0-100\r\n",
                'timeout' => 0.4,
                'ignore_errors' => true
            ]
        ]);

        foreach ($candidateRoms as $candRom) {
            $batoGameId = md5('/userdata/roms/' . $batoceraFolder . '/' . $candRom);
            $batoHttpUrl = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/{$batoGameId}/media/video";
            $fp = @fopen($batoHttpUrl, 'rb', false, $streamCtx);
            if ($fp) {
                $statusLine = $http_response_header[0] ?? '';
                fclose($fp);
                if (strpos($statusLine, '200') !== false || strpos($statusLine, '206') !== false) {
                    echo json_encode([
                        'status' => 'ok',
                        'has_video' => true,
                        'source' => 'http',
                        'video_url' => "save.php?action=stream_batocera_video&src=http&system=" . urlencode($cleanSys) . "&rom=" . urlencode($candRom),
                        'cand_rom' => $candRom
                    ]);
                    exit;
                }
            }
        }
    }

    // 2. SMB Share / media/videos check
    $videoList = getBatoceraVideoList($baseDir, $uncPath, $cleanSys, $batoceraFolder);
    if (!empty($videoList)) {
        // If given path matches directly
        if ($givenPath) {
            $givenBase = basename($givenPath);
            foreach ($videoList as $vItem) {
                if (strcasecmp($vItem['filename'], $givenBase) === 0 || strcasecmp($vItem['rel'], $givenPath) === 0) {
                    echo json_encode([
                        'status' => 'ok',
                        'has_video' => true,
                        'source' => 'smb',
                        'video_url' => "save.php?action=stream_batocera_video&src=smb&system=" . urlencode($cleanSys) . "&path=" . urlencode($vItem['rel']),
                        'matched_file' => $vItem['filename']
                    ]);
                    exit;
                }
            }
        }

        // Match candidates by stem and norm
        $stemsToTry = [];
        if ($rom) $stemsToTry[] = pathinfo($rom, PATHINFO_FILENAME);
        if ($id) $stemsToTry[] = pathinfo($id, PATHINFO_FILENAME);
        if ($name) $stemsToTry[] = $name;
        $stemsToTry = array_unique(array_filter($stemsToTry));

        foreach ($stemsToTry as $stem) {
            $normStem = preg_replace('/[^a-z0-9]/', '', mb_strtolower($stem));
            foreach ($videoList as $vItem) {
                // Exact stem match
                if (strcasecmp($vItem['stem'], $stem) === 0) {
                    echo json_encode([
                        'status' => 'ok',
                        'has_video' => true,
                        'source' => 'smb',
                        'video_url' => "save.php?action=stream_batocera_video&src=smb&system=" . urlencode($cleanSys) . "&path=" . urlencode($vItem['rel']),
                        'matched_file' => $vItem['filename']
                    ]);
                    exit;
                }
                // Suffix e.g. stem-video match
                if (strcasecmp($vItem['stem'], $stem . '-video') === 0 || strcasecmp($vItem['stem'], $stem . '_video') === 0) {
                    echo json_encode([
                        'status' => 'ok',
                        'has_video' => true,
                        'source' => 'smb',
                        'video_url' => "save.php?action=stream_batocera_video&src=smb&system=" . urlencode($cleanSys) . "&path=" . urlencode($vItem['rel']),
                        'matched_file' => $vItem['filename']
                    ]);
                    exit;
                }
                // Normalized match
                if ($normStem !== '' && ($vItem['norm'] === $normStem || $vItem['norm'] === ($normStem . 'video'))) {
                    echo json_encode([
                        'status' => 'ok',
                        'has_video' => true,
                        'source' => 'smb',
                        'video_url' => "save.php?action=stream_batocera_video&src=smb&system=" . urlencode($cleanSys) . "&path=" . urlencode($vItem['rel']),
                        'matched_file' => $vItem['filename']
                    ]);
                    exit;
                }
            }
        }
    }

    echo json_encode(['status' => 'ok', 'has_video' => false]);
    exit;
}

if ($action === 'stream_batocera_video') {
    @set_time_limit(0);
    @ini_set('max_execution_time', 0);
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', false);
    @ini_set('implicit_flush', true);
    ignore_user_abort(false);

    $sysId = trim($_GET['system'] ?? '');
    $relMedia = trim($_GET['path'] ?? '');
    $relMedia = ltrim(str_replace(['\\', '..'], ['/', ''], $relMedia), './');
    $rom = trim($_GET['rom'] ?? '');
    $id = trim($_GET['id'] ?? '');
    $src = trim($_GET['src'] ?? '');
    $gameId = trim($_GET['game_id'] ?? '');

    if (!$sysId) {
        http_response_code(400);
        exit('Invalid parameters');
    }

    $uncPath = getPixnBatoceraUncPath($baseDir, $_GET['unc_path'] ?? ($data['unc_path'] ?? ''));
    $conn = getBatoceraHostAndShare($uncPath);

    $targetHost = $conn ? $conn['targetHost'] : 'batocera-rgs';
    $share = $conn ? $conn['share'] : 'share';
    $batoceraSubdir = $conn ? $conn['subDir'] : 'roms';
    $cleanSys = preg_replace('/(_batocera|_retrobat)$/i', '', $sysId);
    $batoceraFolder = $cleanSys;

    $folderMapFile = $baseDir . '/data/batocera_folder_map.json';
    if (file_exists($folderMapFile)) {
        $fMap = readJsonFile($folderMapFile);
        if (is_array($fMap)) {
            if (!empty($fMap[$cleanSys])) $batoceraFolder = $fMap[$cleanSys];
            elseif (!empty($fMap[$sysId])) $batoceraFolder = $fMap[$sysId];
        }
    }

    // 1. FAST PATH: Direct HTTP Stream from Batocera Web Services (Port 1234)
    $batoIp = $targetHost;
    if (!filter_var($batoIp, FILTER_VALIDATE_IP)) {
        $resolvedIp = function_exists('resolveLanHostname') ? resolveLanHostname($batoIp) : gethostbyname($batoIp);
        if ($resolvedIp && filter_var($resolvedIp, FILTER_VALIDATE_IP)) {
            $batoIp = $resolvedIp;
        }
    }

    $currExt = strtolower(pathinfo($relMedia, PATHINFO_EXTENSION));
    $videoMime = ($currExt === 'webm') ? 'video/webm' : (($currExt === 'mkv') ? 'video/x-matroska' : 'video/mp4');

    if (filter_var($batoIp, FILTER_VALIDATE_IP)) {
        $candidateUrls = [];
        if ($gameId) {
            $candidateUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/{$gameId}/media/video";
        }
        if ($relMedia && (stripos($relMedia, 'systems/') === 0 || stripos($relMedia, '/systems/') === 0)) {
            $candidateUrls[] = "http://{$batoIp}:1234/" . ltrim($relMedia, '/');
        }
        if ($relMedia && preg_match('#games/([a-f0-9]{32})/media/video#i', $relMedia, $mId)) {
            $candidateUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/{$mId[1]}/media/video";
        }
        if ($id && strlen($id) === 32 && ctype_xdigit($id)) {
            $candidateUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/{$id}/media/video";
        }

        // Check against Batocera HTTP API cache
        if (function_exists('getBatoceraSystemGamesHttp')) {
            $batoGames = getBatoceraSystemGamesHttp($batoIp, $batoceraFolder, $baseDir);
            if (!empty($batoGames)) {
                $searchItems = array_filter([$gameId, $id, $rom, $relMedia, trim($_GET['name'] ?? '')]);
                foreach ($batoGames as $bg) {
                    if (empty($bg['video'])) continue;
                    $bgId = $bg['id'] ?? '';
                    $bgName = $bg['name'] ?? '';
                    $bgFile = basename($bg['path'] ?? '');
                    $bgStem = pathinfo($bgFile, PATHINFO_FILENAME);
                    $bgMatched = false;
                    foreach ($searchItems as $item) {
                        $itemBase = basename($item);
                        $itemStem = pathinfo($itemBase, PATHINFO_FILENAME);
                        if ($bgId === $item || strcasecmp($bgFile, $itemBase) === 0 || strcasecmp($bgStem, $itemStem) === 0 || strcasecmp($bgName, $item) === 0 || strcasecmp($bgStem, $item) === 0) {
                            $bgMatched = true;
                            break;
                        }
                    }
                    if ($bgMatched) {
                        $candidateUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/{$bgId}/media/video";
                        if (!empty($bg['video'])) {
                            $candidateUrls[] = "http://{$batoIp}:1234/" . ltrim($bg['video'], '/');
                        }
                        break;
                    }
                }
            }
        }

        if ($rom) {
            $candidateUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/" . md5('/userdata/roms/' . $batoceraFolder . '/' . $rom) . "/media/video";
            $candidateUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/" . md5('/userdata/roms/' . $batoceraFolder . '/' . basename($rom)) . "/media/video";
        }
        if ($id && $id !== $gameId) {
            $candidateUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/" . md5('/userdata/roms/' . $batoceraFolder . '/' . $id) . "/media/video";
            $candidateUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/" . md5('/userdata/roms/' . $batoceraFolder . '/' . basename($id)) . "/media/video";
        }
        if ($relMedia && stripos($relMedia, 'systems/') === false) {
            $vidStem = pathinfo($relMedia, PATHINFO_FILENAME);
            if ($vidStem) {
                $candidateUrls[] = "http://{$batoIp}:1234/systems/{$batoceraFolder}/games/" . md5('/userdata/roms/' . $batoceraFolder . '/' . $vidStem) . "/media/video";
            }
        }
        $candidateUrls = array_values(array_unique(array_filter($candidateUrls)));

        foreach ($candidateUrls as $batoHttpUrl) {
            // High-throughput cURL streaming (if curl extension is enabled)
            if (function_exists('curl_init')) {
                $isHead = (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD');
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $batoHttpUrl);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
                curl_setopt($ch, CURLOPT_TIMEOUT, $isHead ? 6 : 0);
                curl_setopt($ch, CURLOPT_BUFFERSIZE, 262144);
                if ($isHead) {
                    curl_setopt($ch, CURLOPT_NOBODY, true);
                }

                $range = $_SERVER['HTTP_RANGE'] ?? '';
                if (!empty($range)) {
                    $cleanRange = preg_replace('/^bytes=/i', '', trim($range));
                    curl_setopt($ch, CURLOPT_RANGE, $cleanRange);
                }

                $respHeaders = [];
                $statusCode = 200;
                $headersSent = false;

                curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $headerLine) use (&$respHeaders, &$statusCode, &$headersSent, $videoMime, $isHead) {
                    $len = strlen($headerLine);
                    $trimmed = trim($headerLine);
                    if (empty($trimmed)) {
                        // Headers from Batocera finished arriving
                        if (!$headersSent && $statusCode < 400) {
                            $headersSent = true;
                            while (ob_get_level() > 0) { @ob_end_clean(); }
                            http_response_code($statusCode);
                            header_remove('Pragma');
                            header_remove('Expires');
                            header('X-Accel-Buffering: no');
                            header('Accept-Ranges: bytes');
                            header('Cache-Control: public, max-age=3600');
                            header('Access-Control-Allow-Origin: *');
                            header('X-Batocera-Source: http-curl-stream');

                            $hasContentType = false;
                            foreach ($respHeaders as $hName => $hVal) {
                                if (strtolower($hName) === 'content-type') {
                                    $valTrim = trim($hVal);
                                    if (stripos($valTrim, 'video/') === 0) {
                                        header('Content-Type: ' . $valTrim, true);
                                        $hasContentType = true;
                                    }
                                    continue;
                                }
                                header($hName . ': ' . $hVal, true);
                            }
                            if (!$hasContentType) {
                                header('Content-Type: ' . $videoMime, true);
                            }
                            flush();
                        }
                        return $len;
                    }
                    if (stripos($trimmed, 'HTTP/') === 0) {
                        if (preg_match('#HTTP/\S+\s+(\d+)#', $trimmed, $m)) {
                            $statusCode = intval($m[1]);
                        }
                        return $len;
                    }
                    $colonPos = strpos($trimmed, ':');
                    if ($colonPos !== false) {
                        $name = trim(substr($trimmed, 0, $colonPos));
                        $val = trim(substr($trimmed, $colonPos + 1));
                        $lower = strtolower($name);
                        if (in_array($lower, ['content-type', 'content-length', 'content-range', 'accept-ranges', 'etag', 'last-modified'])) {
                            $respHeaders[$name] = $val;
                        }
                    }
                    return $len;
                });

                curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $chunk) use (&$headersSent, &$statusCode) {
                    if ($statusCode >= 400) {
                        return 0;
                    }
                    echo $chunk;
                    flush();
                    return strlen($chunk);
                });

                @curl_exec($ch);
                curl_close($ch);

                if ($headersSent) {
                    exit;
                }
            }

            // Stream via HTTP stream wrapper
            $streamHeaders = [];
            if (!empty($_SERVER['HTTP_RANGE'])) {
                $streamHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
            }
            $streamCtx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", $streamHeaders),
                    'timeout' => 300,
                    'ignore_errors' => true
                ]
            ]);

            $http_response_header = [];
            $fp = @fopen($batoHttpUrl, 'rb', false, $streamCtx);
            if ($fp) {
                $respHeaders = $http_response_header ?? [];
                $statusLine = $respHeaders[0] ?? '';
                if (strpos($statusLine, '200') !== false || strpos($statusLine, '206') !== false) {
                    @stream_set_chunk_size($fp, 262144);
                    @stream_set_read_buffer($fp, 262144);
                    $cType = $videoMime;
                    $cLen = null;
                    $cRange = null;
                    foreach ($respHeaders as $hdr) {
                        if (stripos($hdr, 'Content-Type:') === 0) {
                            $hdrVal = trim(substr($hdr, 13));
                            if (stripos($hdrVal, 'video/') === 0) {
                                $cType = $hdrVal;
                            }
                        }
                        if (stripos($hdr, 'Content-Length:') === 0) $cLen = trim(substr($hdr, 15));
                        if (stripos($hdr, 'Content-Range:') === 0) $cRange = trim(substr($hdr, 14));
                    }
                    while (ob_get_level() > 0) { @ob_end_clean(); }
                    if (strpos($statusLine, '206') !== false) {
                        http_response_code(206);
                    }
                    header_remove('Pragma');
                    header_remove('Expires');
                    header('X-Accel-Buffering: no');
                    header('Content-Type: ' . $cType, true);
                    if ($cLen) header('Content-Length: ' . $cLen);
                    if ($cRange) header('Content-Range: ' . $cRange);
                    header('Accept-Ranges: bytes');
                    header('Cache-Control: public, max-age=3600');
                    header('Access-Control-Allow-Origin: *');
                    header('X-Batocera-Source: http-stream');
                    @ob_implicit_flush(true);
                    flush();

                    fpassthru($fp);
                    fclose($fp);
                    exit;
                }
                fclose($fp);
            }
        }
    }

    // 2. Direct SMB/UNC streaming (Native PHP on Windows or mapped UNC)
    if ($relMedia) {
        $cleanRel = ltrim(str_replace(['\\', '..'], ['/', ''], $relMedia), './');
        $candUncFiles = [];
        if (!empty($uncPath)) {
            $candUncFiles[] = rtrim(str_replace('/', '\\', $uncPath), '\\') . '\\' . $batoceraFolder . '\\' . str_replace('/', '\\', $cleanRel);
            $candUncFiles[] = rtrim(str_replace('/', '\\', $uncPath), '\\') . '\\' . $batoceraFolder . '\\media\\videos\\' . basename($cleanRel);
            $candUncFiles[] = rtrim(str_replace('/', '\\', $uncPath), '\\') . '\\' . $batoceraFolder . '\\videos\\' . basename($cleanRel);
        }
        $batoHostRoot = '\\\\' . $targetHost . '\\' . $share . ($batoceraSubdir ? '\\' . str_replace('/', '\\', $batoceraSubdir) : '');
        $candUncFiles[] = $batoHostRoot . '\\' . $batoceraFolder . '\\' . str_replace('/', '\\', $cleanRel);
        $candUncFiles[] = $batoHostRoot . '\\' . $batoceraFolder . '\\media\\videos\\' . basename($cleanRel);
        $candUncFiles[] = $batoHostRoot . '\\' . $batoceraFolder . '\\videos\\' . basename($cleanRel);

        foreach (array_unique($candUncFiles) as $candFile) {
            if (@is_file($candFile)) {
                $fSize = @filesize($candFile);
                if ($fSize !== false && $fSize > 0) {
                    $start = 0;
                    $end = $fSize - 1;
                    $statusCode = 200;

                    $range = $_SERVER['HTTP_RANGE'] ?? '';
                    if (!empty($range) && preg_match('/bytes=(\d*)-(\d*)/i', $range, $mRange)) {
                        $rStart = $mRange[1] !== '' ? floatval($mRange[1]) : 0;
                        $rEnd = $mRange[2] !== '' ? floatval($mRange[2]) : ($fSize - 1);
                        if ($rStart <= $rEnd && $rStart < $fSize) {
                            $start = (int)$rStart;
                            $end = (int)min($rEnd, $fSize - 1);
                            $statusCode = 206;
                        }
                    }

                    $length = $end - $start + 1;
                    while (ob_get_level() > 0) { @ob_end_clean(); }

                    http_response_code($statusCode);
                    header_remove('Pragma');
                    header_remove('Expires');
                    header('X-Accel-Buffering: no');
                    header('Content-Type: ' . $videoMime, true);
                    header('Accept-Ranges: bytes', true);
                    header('Content-Length: ' . $length, true);
                    if ($statusCode === 206) {
                        header("Content-Range: bytes {$start}-{$end}/{$fSize}", true);
                    }
                    header('Cache-Control: public, max-age=3600', true);
                    header('Access-Control-Allow-Origin: *', true);
                    header('X-Batocera-Source: native-unc-stream', true);
                    @ob_implicit_flush(true);
                    flush();

                    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') {
                        exit;
                    }

                    $fp = @fopen($candFile, 'rb');
                    if ($fp) {
                        if ($start > 0) {
                            @fseek($fp, $start);
                        }
                        $bytesSent = 0;
                        $chunkSize = 262144;
                        while (!feof($fp) && $bytesSent < $length && !connection_aborted()) {
                            $readSize = min($chunkSize, $length - $bytesSent);
                            $buf = @fread($fp, $readSize);
                            if ($buf === false || $buf === '') break;
                            echo $buf;
                            flush();
                            $bytesSent += strlen($buf);
                        }
                        @fclose($fp);
                        exit;
                    }
                }
            }
        }

        // 3. SMB Stream via pipe (smbclient get "..." -) on Linux / Synology DSM
        if (DIRECTORY_SEPARATOR !== '\\' && function_exists('popen')) {
            $batoceraPath = '//' . $targetHost . '/' . $share;
            $escapedShare = escapeshellarg($batoceraPath);
            $remotePath = ($batoceraSubdir ? $batoceraSubdir . '/' : '') . $batoceraFolder . '/' . $relMedia;

            $getCmd = escapeshellarg('get "' . $remotePath . '" -');
            $cmd = "/usr/bin/smbclient {$escapedShare} -N -t 3 -c {$getCmd} 2>/dev/null";
            $pipe = @popen($cmd, 'r');
            if ($pipe) {
                $probe = fread($pipe, 8192);
                if ($probe !== false && strlen($probe) > 0) {
                    while (ob_get_level() > 0) { @ob_end_clean(); }
                    header('X-Accel-Buffering: no');
                    header('Content-Type: ' . $videoMime, true);
                    header('Cache-Control: public, max-age=3600');
                    header('X-Content-Type-Options: nosniff');
                    header('X-Batocera-Source: smb-stream');
                    echo $probe;
                    flush();
                    while (!feof($pipe) && !connection_aborted()) {
                        $chunk = fread($pipe, 262144);
                        if ($chunk === false || $chunk === '') break;
                        echo $chunk;
                        flush();
                    }
                    pclose($pipe);
                    exit;
                }
                pclose($pipe);
            }
        }
    }

    http_response_code(404);
    header('Content-Type: text/plain', true);
    exit('Video not found or unavailable');
}

function getBatoceraOtherGamesIndex($baseDir, $forceRefresh = false) {
    $cacheDir = __DIR__ . '/data/cache_batocera';
    $indexFile = $cacheDir . '/_other_games_index.json';
    if (!$forceRefresh && file_exists($indexFile) && (time() - filemtime($indexFile) < 1800)) {
        $content = @file_get_contents($indexFile);
        if ($content) {
            $data = json_decode($content, true);
            if (is_array($data)) return $data;
        }
    }

    if (!is_dir($cacheDir)) {
        return [];
    }

    $files = glob($cacheDir . '/*.json');
    $otherGames = [];
    $seen = [];
    foreach ($files as $f) {
        $baseName = basename($f);
        if ($baseName[0] === '_' || strpos($baseName, '_quick_') !== false) continue;
        $content = @file_get_contents($f);
        if (!$content) continue;
        $d = json_decode($content, true);
        if (!is_array($d) || empty($d['other_games']) || !is_array($d['other_games'])) continue;
        
        $sys = explode('_', $baseName)[0];
        foreach ($d['other_games'] as $og) {
            $ogName = $og['name'] ?? ($og['filename'] ?? ($og['id'] ?? ''));
            $ogId = $og['id'] ?? '';
            $key = $sys . '::' . strtolower($ogId ?: $ogName);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $otherGames[] = [
                'system' => $sys,
                'name' => $ogName,
                'id' => $ogId,
                'filename' => $og['filename'] ?? ($ogId ?: $ogName),
                'developer' => $og['developer'] ?? '',
                'publisher' => $og['publisher'] ?? '',
                'releasedate' => $og['releasedate'] ?? '',
                'genre' => $og['genre'] ?? '',
                'desc' => $og['desc'] ?? '',
                'rating' => $og['rating'] ?? '',
                'players' => $og['players'] ?? '',
                'image' => $og['image'] ?? '',
                'thumbnail' => $og['thumbnail'] ?? '',
                'boxart' => $og['boxart'] ?? '',
                'video' => $og['video'] ?? '',
                'size_mb' => $og['size_mb'] ?? 0,
                'is_installed' => true,
                'is_other' => true
            ];
        }
    }

    @file_put_contents($indexFile, json_encode($otherGames, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $otherGames;
}

if ($action === 'get_batocera_other_index') {
    $forceRefresh = !empty($_GET['force_refresh']) || !empty($data['force_refresh']);
    $games = getBatoceraOtherGamesIndex($baseDir, $forceRefresh);
    echo json_encode(['status' => 'ok', 'count' => count($games), 'games' => $games], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'search') {
    $q = trim($_GET['q'] ?? ($data['q'] ?? ''));
    $hideRetrobat = !empty($_GET['hide_retrobat']) || !empty($data['hide_retrobat']);
    $hideBatocera = !empty($_GET['hide_batocera']) || !empty($data['hide_batocera']);
    if (strlen($q) < 2) {
        echo json_encode(['results' => [], 'total' => 0]);
        exit;
    }
    
    $indexFile = $baseDir . '/search_index.json';
    if (!file_exists($indexFile)) {
        echo json_encode(['results' => [], 'total' => 0, 'error' => 'Index not found']);
        exit;
    }
    
    $index = readJsonFile($indexFile);
    if (!is_array($index)) $index = [];
    $results = [];
    $lowerQ = mb_strtolower($q);

    $retrobatSystems = [];
    $batoceraSystems = [];
    if ($hideRetrobat || $hideBatocera) {
        $systemsFile = $baseDir . '/systems.json';
        if (file_exists($systemsFile)) {
            $sysList = readJsonFile($systemsFile);
            if (is_array($sysList)) {
                foreach ($sysList as $s) {
                    $sId = $s['id'] ?? '';
                    $sName = $s['name'] ?? '';
                    $sVer = $s['version'] ?? '';
                    if ($hideRetrobat && (stripos($sId, 'retrobat') !== false || stripos($sName, 'retrobat') !== false || stripos($sVer, 'retrobat') !== false)) {
                        $retrobatSystems[$sId] = true;
                    }
                    if ($hideBatocera && (stripos($sId, 'batocera') !== false || stripos($sName, 'batocera') !== false || stripos($sVer, 'batocera') !== false)) {
                        $batoceraSystems[$sId] = true;
                    }
                }
            }
        }
    }
    
    foreach ($index as $sys => $games) {
        if ($hideRetrobat && (isset($retrobatSystems[$sys]) || stripos($sys, 'retrobat') !== false)) continue;
        if ($hideBatocera && (isset($batoceraSystems[$sys]) || stripos($sys, 'batocera') !== false)) continue;
        if (!is_array($games)) continue;
        foreach ($games as $game) {
            if (mb_stripos($game, $lowerQ) !== false) {
                $results[] = [
                    'system' => $sys,
                    'name' => $game
                ];
            }
        }
    }

    $otherIndex = getBatoceraOtherGamesIndex($baseDir);
    if (is_array($otherIndex)) {
        foreach ($otherIndex as $og) {
            $sys = $og['system'] ?? '';
            if ($hideRetrobat && (isset($retrobatSystems[$sys]) || stripos($sys, 'retrobat') !== false)) continue;
            if ($hideBatocera && (isset($batoceraSystems[$sys]) || stripos($sys, 'batocera') !== false)) continue;
            $ogName = $og['name'] ?? '';
            $ogId = $og['id'] ?? '';
            $ogFn = $og['filename'] ?? '';
            if (mb_stripos($ogName, $lowerQ) !== false || mb_stripos($ogId, $lowerQ) !== false || mb_stripos($ogFn, $lowerQ) !== false) {
                $results[] = [
                    'system' => $sys,
                    'name' => $ogName,
                    'id' => $og['id'] ?? '',
                    'filename' => $og['filename'] ?? '',
                    'developer' => $og['developer'] ?? '',
                    'publisher' => $og['publisher'] ?? '',
                    'desc' => $og['desc'] ?? '',
                    'boxart' => $og['boxart'] ?? '',
                    'thumbnail' => $og['thumbnail'] ?? '',
                    'size_mb' => $og['size_mb'] ?? 0,
                    'is_installed' => true,
                    'is_other' => true
                ];
            }
        }
    }
    
    echo json_encode([
        'query' => $q,
        'total' => count($results),
        'results' => array_slice($results, 0, 1000)
    ]);
    exit;
}

function syncSystemDirectlyFromPixn($baseDir, $txtDir, $gamesDir, $sysId, &$systemsList = null, $remoteMeta = null) {
    $cleanId = preg_replace('/(_batocera|_retrobat)$/', '', $sysId);
    $txtPath = getSystemTxtPath($txtDir, $sysId);
    $jsonPath = $gamesDir . '/' . $sysId . '.json';

    $extractedGames = [];
    $extractedLines = [];
    $extractedVersion = '';
    $extractedDate = '';

    // 1. Try authenticated API first if cookie is available
    $apiGamesData = fetchPixnRetroApi('/api/catalog/games/' . urlencode($sysId) . '?catalog_type=releases&limit=10000', $baseDir);
    $gamesArray = (!empty($apiGamesData['games']) && is_array($apiGamesData['games'])) ? $apiGamesData['games'] : [];

    if (!empty($gamesArray)) {
        foreach ($gamesArray as $g) {
            $name = trim($g['name'] ?? $g['id'] ?? '');
            if ($name === '') continue;
            $extractedGames[] = [
                'id' => $g['id'] ?? '',
                'name' => $name,
                'publisher' => $g['publisher'] ?? '',
                'developer' => $g['developer'] ?? '',
                'genre' => $g['genre'] ?? '',
                'releasedate' => $g['releasedate'] ?? '',
                'size_mb' => $g['size_mb'] ?? null
            ];
            $extractedLines[] = $name;
        }
    }

    if (empty($extractedGames)) {
        return false;
    }

    // Save JSON and TXT
    @file_put_contents($jsonPath, json_encode($extractedGames, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    natcasesort($extractedLines);
    @file_put_contents($txtPath, implode("\n", $extractedLines));

    // Clear stale cache_gamelist
    $cacheFile = $baseDir . '/data/cache_gamelist/' . $cleanId . '.json';
    if (file_exists($cacheFile)) {
        @unlink($cacheFile);
    }

    // Update systems.json
    $systemsFile = $baseDir . '/systems.json';
    $needSaveSystems = false;
    if ($systemsList === null && file_exists($systemsFile)) {
        $systemsList = readJsonFile($systemsFile);
        $needSaveSystems = true;
    }

    $updatedMeta = null;
    if (is_array($systemsList)) {
        $found = false;
        foreach ($systemsList as &$s) {
            if (!empty($s['id']) && $s['id'] === $sysId) {
                $s['gameCount'] = count($extractedGames);
                if (!empty($remoteMeta['version'])) $s['version'] = $remoteMeta['version'];
                if (!empty($remoteMeta['gamelistDate'])) $s['gamelistDate'] = $remoteMeta['gamelistDate'];
                if (isset($remoteMeta['torrent_available'])) $s['torrent_available'] = (bool)$remoteMeta['torrent_available'];
                if (!empty($remoteMeta['name'])) $s['name'] = $remoteMeta['name'];
                if (!empty($remoteMeta['hardware'])) $s['hardware'] = $remoteMeta['hardware'];
                if (!empty($remoteMeta['manufacturer'])) $s['manufacturer'] = $remoteMeta['manufacturer'];
                if (!empty($remoteMeta['release'])) $s['release'] = $remoteMeta['release'];
                $updatedMeta = $s;
                $found = true;
                break;
            }
        }
        unset($s);
        if (!$found && !empty($remoteMeta)) {
            $newSys = $remoteMeta;
            $newSys['gameCount'] = count($extractedGames);
            $systemsList[] = $newSys;
            $updatedMeta = $newSys;
        }
        if ($needSaveSystems) {
            @file_put_contents($systemsFile, json_encode($systemsList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    return [
        'system_id' => $sysId,
        'game_count' => count($extractedGames),
        'meta' => $updatedMeta
    ];
}

function scanRemoteSystemsForUpdates($baseDir, $sysList) {
    $detectedNew = [];
    $detectedUpdated = [];
    $authValid = false;

    $sysMap = [];
    foreach ($sysList as $s) {
        if (!empty($s['id'])) $sysMap[$s['id']] = $s;
    }

    // Official Releases API from https://pixn-retro.dedyn.io/releases
    $remoteCatalog = fetchPixnRetroApi('/api/catalog/systems?catalog_type=releases', $baseDir, 8);
    if (!empty($remoteCatalog) && is_array($remoteCatalog)) {
        $candidate = (!empty($remoteCatalog['systems']) && is_array($remoteCatalog['systems'])) ? $remoteCatalog['systems'] : $remoteCatalog;
        if (is_array($candidate) && isset($candidate[0]) && is_array($candidate[0]) && !empty($candidate[0]['id'])) {
            $authValid = true;
            foreach ($candidate as $rem) {
                $id = $rem['id'] ?? '';
                if (!$id) continue;
                if (!isset($sysMap[$id])) {
                    $detectedNew[] = $rem;
                } else {
                    $loc = $sysMap[$id];
                    $verDiff = (!empty($rem['version']) && !empty($loc['version']) && $rem['version'] !== $loc['version']);
                    $dateDiff = (!empty($rem['gamelistDate']) && !empty($loc['gamelistDate']) && $rem['gamelistDate'] !== $loc['gamelistDate']);
                    $countDiff = (isset($rem['gameCount']) && isset($loc['gameCount']) && (int)$rem['gameCount'] > (int)$loc['gameCount']);
                    if ($verDiff || $dateDiff || $countDiff) {
                        $detectedUpdated[] = $rem;
                    }
                }
            }
            return [
                'new_systems' => $detectedNew,
                'updated_systems' => $detectedUpdated,
                'auth_valid' => true,
                'remote_systems' => $candidate
            ];
        }
    }

    return [
        'new_systems' => [],
        'updated_systems' => [],
        'auth_valid' => false,
        'remote_systems' => []
    ];
}

function runCatalogSyncAndRepair($baseDir, $txtDir, $gamesDir, $statusFile = null, $targetSystemIds = []) {
    if (empty($statusFile)) {
        $statusFile = $baseDir . '/sync_status.json';
    }
    clearstatcache();
    $systemsFile = $baseDir . '/systems.json';
    $systems = file_exists($systemsFile) ? readJsonFile($systemsFile) : [];
    if (!is_array($systems)) $systems = [];
    
    // Map of all known systems
    $allSysIds = [];
    foreach ($systems as $s) {
        if (!empty($s['id'])) {
            $allSysIds[$s['id']] = $s;
        }
    }
    
    // Purge orphaned .txt or .json files for systems deleted from systems.json
    $deletedFiles = [];
    if (count($allSysIds) > 50) {
        $txtFiles = glob($txtDir . '/*.txt');
        if ($txtFiles) {
            foreach ($txtFiles as $tf) {
                $tid = basename($tf, '.txt');
                $isKnown = isset($allSysIds[$tid])
                    || isset($allSysIds[$tid . '_batocera'])
                    || in_array($tid, ['chihiro_legacy', 'lindbergh_retrobat', 'namco2x6_retrobat']);
                if (!$isKnown) {
                    @unlink($tf);
                    $deletedFiles[] = $tid . '.txt';
                }
            }
        }
        $jsonFiles = glob($gamesDir . '/*.json');
        if ($jsonFiles) {
            foreach ($jsonFiles as $jf) {
                $jid = basename($jf, '.json');
                if (!isset($allSysIds[$jid])) {
                    @unlink($jf);
                    $deletedFiles[] = $jid . '.json';
                }
            }
        }
    }
    
    $restored = [];
    $syncedSystems = [];
    $missingSystems = [];
    $index = [];
    $totalGames = 0;

    foreach ($allSysIds as $sysId => $sMeta) {
        $txtPath = getSystemTxtPath($txtDir, $sysId);
        $jsonPath = $gamesDir . '/' . $sysId . '.json';

        $hasTxt = file_exists($txtPath) && filesize($txtPath) > 0;
        $hasJson = file_exists($jsonPath) && filesize($jsonPath) > 0;
        $isTarget = !empty($targetSystemIds) && in_array($sysId, $targetSystemIds);

        // If explicitly targeted (e.g. updated release), missing, or corrupted, pull fresh gamelist from Pixn Retro
        $isTinyOrCorrupted = ($hasJson && filesize($jsonPath) < 300 && ($sMeta['gameCount'] ?? 0) > 0);
        if ($isTarget || (!$hasTxt && !$hasJson) || $isTinyOrCorrupted) {
            $syncRes = syncSystemDirectlyFromPixn($baseDir, $txtDir, $gamesDir, $sysId, $systems);
            if ($syncRes) {
                $restored[] = basename($txtPath);
                $syncedSystems[] = $sysId;
                $hasTxt = true;
                $hasJson = true;
            }
        }

        // Check if txt file is missing or empty but json exists
        if (!$hasTxt) {
            if ($hasJson) {
                $gamesList = readJsonFile($jsonPath);
                if (is_array($gamesList) && count($gamesList) > 0) {
                    $lines = [];
                    foreach ($gamesList as $g) {
                        $name = trim($g['name'] ?? $g['id'] ?? '');
                        if ($name !== '') {
                            $lines[] = $name;
                        }
                    }
                    natcasesort($lines);
                    @file_put_contents($txtPath, implode("\n", $lines));
                    $restored[] = basename($txtPath);
                    $hasTxt = true;
                }
            } else {
                if (($sMeta['gameCount'] ?? 0) > 0) {
                    $missingSystems[] = [
                        'id' => $sysId,
                        'name' => $sMeta['name'] ?? $sysId,
                        'gameCount' => $sMeta['gameCount'] ?? 0
                    ];
                }
            }
        }

        // Read lines for the search index
        if ($hasTxt && file_exists($txtPath)) {
            $lines = file($txtPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines && count($lines) > 0) {
                $index[$sysId] = $lines;
                $totalGames += count($lines);
            }
        }
    }

    // Save search_index.json
    @file_put_contents($baseDir . '/search_index.json', json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    // Update sync_status.json with fresh timestamp and task info
    $curStatus = getSyncStatus($baseDir, $statusFile);
    $curStatus['status'] = 'completed';
    $curStatus['progress'] = 100;
    $curStatus['last_sync'] = date('Y-m-d H:i:s');
    $curStatus['updated_at'] = date('Y-m-d H:i:s');
    $curStatus['total_systems'] = count($index);
    $curStatus['total_games'] = $totalGames;
    $curStatus['restored_count'] = count($restored);
    $curStatus['restored_files'] = $restored;
    $curStatus['deleted_files_count'] = count($deletedFiles);
    $curStatus['deleted_files'] = $deletedFiles;
    $curStatus['missing_systems'] = $missingSystems;
    $curStatus['missing_systems_count'] = count($missingSystems);

    if (!empty($syncedSystems)) {
        if (!empty($curStatus['updated_systems'])) {
            $curStatus['updated_systems'] = array_values(array_filter($curStatus['updated_systems'], function($us) use ($syncedSystems) {
                return !in_array($us['id'] ?? '', $syncedSystems);
            }));
            $curStatus['updated_systems_count'] = count($curStatus['updated_systems']);
        }
    }

    if (count($restored) > 0) {
        $curStatus['current_task'] = 'Restored ' . count($restored) . ' missing text file' . (count($restored) > 1 ? 's' : '') . ' (' . implode(', ', array_slice($restored, 0, 3)) . ') & rebuilt search index';
    } elseif (count($deletedFiles) > 0) {
        $curStatus['current_task'] = 'Cleaned up ' . count($deletedFiles) . ' deleted system file' . (count($deletedFiles) > 1 ? 's' : '') . ' & rebuilt search index';
    } elseif (count($missingSystems) > 0) {
        $curStatus['current_task'] = count($missingSystems) . ' system(s) (' . implode(', ', array_slice(array_column($missingSystems, 'name'), 0, 3)) . ') need game list sync from Pixn Retro';
    } else {
        $curStatus['current_task'] = 'All ' . count($index) . ' system text files verified & search index up to date';
    }

    @file_put_contents($statusFile, json_encode($curStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return [
        'status' => 'ok',
        'systems' => count($index),
        'games' => $totalGames,
        'restored_count' => count($restored),
        'restored_files' => $restored,
        'synced_systems' => $syncedSystems,
        'deleted_files_count' => count($deletedFiles),
        'deleted_files' => $deletedFiles,
        'missing_systems' => $missingSystems,
        'missing_systems_count' => count($missingSystems),
        'last_sync' => $curStatus['last_sync'],
        'message' => $curStatus['current_task']
    ];
}

if ($action === 'build_index' || $action === 'repair') {
    try {
        $res = runCatalogSyncAndRepair($baseDir, $txtDir, $gamesDir, $statusFile);
        echo json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('build_index error: ' . $e->getMessage());
        echo json_encode([
            'status' => 'error',
            'error' => $e->getMessage(),
            'systems' => 0,
            'games' => 0,
            'message' => 'Error rebuilding index: ' . $e->getMessage()
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function getRecentUpdatedSystems($baseDir, $limit = 10) {
    $systemsFile = $baseDir . '/systems.json';
    if (!file_exists($systemsFile)) return [];
    $sysList = readJsonFile($systemsFile);
    if (!is_array($sysList)) return [];
    
    $gamesDir = $baseDir . '/games';

    foreach ($sysList as &$sys) {
        $id = $sys['id'] ?? '';
        $mtime = 0;
        if ($id) {
            $f = $gamesDir . '/' . $id . '.json';
            if (file_exists($f)) $mtime = filemtime($f);
        }
        $sys['_fileMtime'] = $mtime;
        // Mark as new if version is v1 or not an updated port
        $ver = trim($sys['version'] ?? '');
        $sys['is_new'] = (empty($ver) || strtolower($ver) === 'v1');
    }
    unset($sys);

    usort($sysList, function($a, $b) {
        $da = $a['gamelistDate'] ?? '';
        $db = $b['gamelistDate'] ?? '';
        $ta = 0; $tb = 0;
        if ($da && preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $da, $m)) {
            $ta = strtotime("{$m[3]}-{$m[2]}-{$m[1]}");
        }
        if ($db && preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $db, $m)) {
            $tb = strtotime("{$m[3]}-{$m[2]}-{$m[1]}");
        }
        if ($tb !== $ta) {
            return $tb <=> $ta;
        }
        // Tie-breaker 1: most recently saved/modified file on disk
        $fa = $a['_fileMtime'] ?? 0;
        $fb = $b['_fileMtime'] ?? 0;
        if ($fb !== $fa) {
            return $fb <=> $fa;
        }
        // Tie-breaker 2: newly added systems before old
        $na = !empty($a['is_new']) ? 1 : 0;
        $nb = !empty($b['is_new']) ? 1 : 0;
        if ($nb !== $na) {
            return $nb <=> $na;
        }
        return strcmp($a['name'] ?? '', $b['name'] ?? '');
    });

    $result = array_slice($sysList, 0, $limit);
    foreach ($result as &$r) {
        $r['file_mtime'] = $r['_fileMtime'] ?? 0;
        unset($r['_fileMtime']);
    }
    unset($r);
    return $result;
}

function getSyncStatus($baseDir, $statusFile) {
    $res = null;
    if (file_exists($statusFile)) {
        $json = readJsonFile($statusFile);
        if (is_array($json)) $res = $json;
    }
    if (!is_array($res)) {
        $systemsFile = $baseDir . '/systems.json';
        $systems = file_exists($systemsFile) ? readJsonFile($systemsFile) : [];
        if (!is_array($systems)) $systems = [];
        $totalGames = array_sum(array_column($systems, 'gameCount'));
        $res = [
            'status' => 'idle',
            'progress' => 100,
            'current_task' => 'Catalog up to date',
            'last_sync' => date('Y-m-d H:i:s', file_exists($systemsFile) ? filemtime($systemsFile) : time()),
            'total_systems' => count($systems),
            'total_games' => $totalGames,
            'new_systems_count' => 0,
            'new_systems' => [],
            'updated_systems_count' => 0,
            'updated_systems' => [],
            'deleted_systems_count' => 0,
            'deleted_systems' => [],
            'missing_systems' => [],
            'missing_systems_count' => 0,
            'log' => []
        ];
    }
    $trackFile = $baseDir . '/data/pixn_releases_meta.json';
    if (file_exists($trackFile)) {
        $meta = readJsonFile($trackFile);
        if (!empty($meta['latest_catalog_release_date'])) {
            $res['latest_catalog_release_date'] = $meta['latest_catalog_release_date'];
        }
    }
    $checkCacheFile = $baseDir . '/data/cache_releases_check.json';
    if (file_exists($checkCacheFile)) {
        $cachedCheck = readJsonFile($checkCacheFile);
        if (is_array($cachedCheck) && !empty($cachedCheck['checked_at'])) {
            $res['checked_at'] = $cachedCheck['checked_at'];
        }
    }
    $res['recent_updated_systems'] = getRecentUpdatedSystems($baseDir, 10);
    return $res;
}

if ($action === 'download_artwork') {
    $rawUrl = trim($_GET['url'] ?? '');
    $filename = trim($_GET['filename'] ?? ($_GET['name'] ?? 'artwork.png'));
    $filename = preg_replace('/[^a-zA-Z0-9_\-\. ]/', '_', $filename) ?: 'artwork.png';
    if (!preg_match('/\.(png|jpg|jpeg|webp|gif|svg)$/i', $filename)) {
        $filename .= '.png';
    }

    if (!$rawUrl) {
        http_response_code(400);
        exit('URL missing');
    }

    while (ob_get_level()) { @ob_end_clean(); }

    // If local relative file
    if (!preg_match('#^https?://#i', $rawUrl)) {
        $cleanPath = ltrim(str_replace(['\\', '..'], ['/', ''], $rawUrl), '/');
        $localPath = realpath($baseDir . '/' . $cleanPath);
        if (!$localPath || !file_exists($localPath)) {
            $localPath = realpath(dirname($baseDir) . '/' . $cleanPath);
        }
        if ($localPath && file_exists($localPath) && is_file($localPath)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($localPath));
            header('Cache-Control: private, no-cache, no-store, must-revalidate');
            readfile($localPath);
            exit;
        }
    }

    // Check if $rawUrl points back to get_batocera_media
    $parsedUrl = parse_url($rawUrl);
    if (!empty($parsedUrl['query'])) {
        parse_str($parsedUrl['query'], $qParams);
        if (!empty($qParams['action']) && $qParams['action'] === 'get_batocera_media') {
            $_GET['system'] = $qParams['system'] ?? '';
            $_GET['path'] = $qParams['path'] ?? '';
            if (!empty($qParams['rom'])) $_GET['rom'] = $qParams['rom'];
            if (!empty($qParams['id'])) $_GET['id'] = $qParams['id'];
            $_GET['download_attachment'] = $filename;
            $action = 'get_batocera_media';
        }
    }

    if ($action === 'download_artwork') {
        // Remote URL (e.g. https://pixn-retro.dedyn.io/...)
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $rawUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) PIXN/1.0');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: private, no-cache, no-store, must-revalidate');
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode >= 200 && $httpCode < 400) {
                exit;
            }
        }

        $curlBin = file_exists('/usr/bin/curl') ? '/usr/bin/curl' : (file_exists('/bin/curl') ? '/bin/curl' : 'curl');
        $escapedUrl = escapeshellarg($rawUrl);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, no-cache, no-store, must-revalidate');
        passthru("{$curlBin} -s -L -k --max-time 30 -H 'User-Agent: Mozilla/5.0' {$escapedUrl}");
        exit;
    }
}

if ($action === 'download_torrent') {
    $sysId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['system'] ?? '');
    if (!$sysId) {
        http_response_code(400);
        die('System ID missing');
    }

    $cfg = getPixnConfig($baseDir);
    $cookie = $cfg['pixn_session_cookie'] ?? '';
    $targetUrl = 'https://pixn-retro.dedyn.io/api/catalog/systems/' . $sysId . '/torrent';

    $torrentContent = null;

    if (!empty($cookie)) {
        // Fetch directly in memory via curl extension (no files written to data/torrents)
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $targetUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_TIMEOUT, 35);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode >= 200 && $httpCode < 300 && !empty($res) && substr($res, 0, 1) === 'd') {
                $torrentContent = $res;
            }
        }

        // Fallback: curl CLI using OS temp directory, immediately reading into memory and deleting the temp file
        if ($torrentContent === null) {
            $curlBin = file_exists('/usr/bin/curl') ? '/usr/bin/curl' : (file_exists('/bin/curl') ? '/bin/curl' : 'curl');
            $escapedCookie = escapeshellarg($cookie);
            $escapedUrl = escapeshellarg($targetUrl);

            $tmpFile = tempnam(sys_get_temp_dir(), 'pixn_tor_');
            $escapedDest = escapeshellarg($tmpFile);

            $out = [];
            $ret = -1;
            @exec("{$curlBin} -s -k --max-time 45 -H 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)' --cookie {$escapedCookie} {$escapedUrl} -o {$escapedDest} 2>&1", $out, $ret);

            if (file_exists($tmpFile)) {
                if (filesize($tmpFile) > 100) {
                    $handle = @fopen($tmpFile, 'r');
                    $firstChar = $handle ? fgetc($handle) : '';
                    if ($handle) fclose($handle);
                    if ($firstChar === 'd') {
                        $torrentContent = file_get_contents($tmpFile);
                    }
                }
                @unlink($tmpFile); // Clean up immediately, never stored in data/
            }
        }
    }

    if ($torrentContent !== null && strlen($torrentContent) > 0) {
        if (ob_get_level()) @ob_end_clean();
        header('Content-Type: application/x-bittorrent');
        header('Content-Disposition: attachment; filename="' . $sysId . '.torrent"');
        header('Content-Length: ' . strlen($torrentContent));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $torrentContent;
        exit;
    }

    // Direct fallback redirect to Pix'n Retro releases API so browser downloads directly
    header('Location: ' . $targetUrl);
    exit;
}

if ($action === 'start_sync') {
    $statusFile = $baseDir . '/sync_status.json';
    $curStatus = getSyncStatus($baseDir, $statusFile);
    $curStatus['status'] = 'running';
    $curStatus['progress'] = 5;
    $curStatus['current_task'] = 'Verifying local catalog & checking files...';
    $curStatus['updated_at'] = date('Y-m-d H:i:s');
    file_put_contents($statusFile, json_encode($curStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo json_encode(['status' => 'ok', 'sync_status' => $curStatus]);
    exit;
}

if ($action === 'get_sync_status' || $action === 'status') {
    $curStatus = getSyncStatus($baseDir, $statusFile);
    echo json_encode($curStatus);
    exit;
}

if ($action === 'check_releases_update') {
    $clientLastVisit = trim($_GET['last_visit'] ?? '');
    $forceRefresh = !empty($_GET['force_refresh']) || !empty($_GET['force']);
    $autoSyncRequested = !empty($_GET['auto_sync']) || !empty($_GET['autosync']);

    $checkCacheFile = $baseDir . '/data/cache_releases_check.json';
    if (!$forceRefresh && file_exists($checkCacheFile) && (time() - filemtime($checkCacheFile) < 60)) {
        $cachedCheck = readJsonFile($checkCacheFile);
        if (is_array($cachedCheck) && !empty($cachedCheck['status']) && $cachedCheck['status'] === 'ok') {
            if (!$autoSyncRequested || empty($cachedCheck['update_ready'])) {
                echo json_encode($cachedCheck, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }
    
    // 1. Check remote connectivity to pixn-retro.dedyn.io
    $remoteUrl = 'https://pixn-retro.dedyn.io/releases';
    $remoteLastModified = null;
    $remoteEtag = null;
    $remoteScript = null;
    $remoteAvailable = false;

    $fullResp = '';
    $ret = -1;
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $remoteUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        $fullResp = curl_exec($ch);
        $ret = ($fullResp !== false) ? 0 : -1;
        curl_close($ch);
    } else {
        $curlBin = file_exists('/usr/bin/curl') ? '/usr/bin/curl' : (file_exists('/bin/curl') ? '/bin/curl' : 'curl');
        $rawOut = [];
        @exec("{$curlBin} -s -i -k --connect-timeout 3 --max-time 4 \"{$remoteUrl}\" 2>&1", $rawOut, $ret);
        $fullResp = implode("\n", $rawOut);
    }
    
    if ($ret === 0 && !empty($fullResp)) {
        $remoteAvailable = true;
        if (preg_match('/last-modified:\s*([^\r\n]+)/i', $fullResp, $m)) $remoteLastModified = trim($m[1]);
        if (preg_match('/etag:\s*([^\r\n]+)/i', $fullResp, $m)) $remoteEtag = trim(trim($m[1]), '"');
        if (preg_match('/src="\/assets\/(index-[^"]+\.js)"/i', $fullResp, $m)) $remoteScript = $m[1];
    }

    // 2. Load stored metadata track file & systems
    $trackFile = $baseDir . '/data/pixn_releases_meta.json';
    $metaTrack = file_exists($trackFile) ? readJsonFile($trackFile) : [];
    if (!is_array($metaTrack)) $metaTrack = [];

    $curStatus = getSyncStatus($baseDir, $statusFile);
    $systemsFile = $baseDir . '/systems.json';
    $sysList = file_exists($systemsFile) ? readJsonFile($systemsFile) : [];
    if (!is_array($sysList)) $sysList = [];

    // 3. Scan for updates (Live API or Direct Public XML fallback)
    $scanRes = scanRemoteSystemsForUpdates($baseDir, $sysList);
    $detectedNewSystems = $scanRes['new_systems'];
    $detectedUpdatedSystems = $scanRes['updated_systems'];
    $authValid = $scanRes['auth_valid'];

    // 4. Calculate latest catalog release date dynamically
    $latestCatalogReleaseDate = '01-10-2026';
    $maxTs = 0;
    foreach ($sysList as $s) {
        $gDate = $s['gamelistDate'] ?? '';
        if ($gDate && preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $gDate, $dm)) {
            $ts = strtotime("{$dm[3]}-{$dm[2]}-{$dm[1]}");
            if ($ts > $maxTs) {
                $maxTs = $ts;
                $latestCatalogReleaseDate = $gDate;
            }
        }
    }
    foreach ($detectedUpdatedSystems as $s) {
        $gDate = $s['gamelistDate'] ?? '';
        if ($gDate && preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $gDate, $dm)) {
            $ts = strtotime("{$dm[3]}-{$dm[2]}-{$dm[1]}");
            if ($ts > $maxTs) {
                $maxTs = $ts;
                $latestCatalogReleaseDate = $gDate;
            }
        }
    }

    if (!empty($detectedNewSystems)) {
        $curStatus['new_systems'] = $detectedNewSystems;
        $curStatus['new_systems_count'] = count($detectedNewSystems);
    }
    if (!empty($detectedUpdatedSystems)) {
        $curStatus['updated_systems'] = $detectedUpdatedSystems;
        $curStatus['updated_systems_count'] = count($detectedUpdatedSystems);
    }

    $updateReady = !empty($detectedNewSystems) || !empty($detectedUpdatedSystems);
    $syncResult = null;
    $autoSynced = false;

    // 5. Auto-sync if updates are ready and auto_sync requested
    if ($autoSyncRequested && $updateReady) {
        $targetSysMap = [];
        foreach ($detectedUpdatedSystems as $us) {
            if (!empty($us['id'])) $targetSysMap[$us['id']] = $us;
        }
        foreach ($detectedNewSystems as $ns) {
            if (!empty($ns['id'])) $targetSysMap[$ns['id']] = $ns;
        }

        $syncedList = [];
        $freshlySyncedMeta = [];
        foreach ($targetSysMap as $tId => $remMeta) {
            $sRes = syncSystemDirectlyFromPixn($baseDir, $txtDir, $gamesDir, $tId, $sysList, $remMeta);
            if ($sRes) {
                $syncedList[] = $tId;
                if (!empty($sRes['meta'])) {
                    $freshlySyncedMeta[] = $sRes['meta'];
                }
            }
        }
        if (!empty($freshlySyncedMeta)) {
            $detectedUpdatedSystems = $freshlySyncedMeta;
        }

        // Save updated systems.json to disk
        @file_put_contents($systemsFile, json_encode($sysList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Rebuild search index & update status
        $syncResult = runCatalogSyncAndRepair($baseDir, $txtDir, $gamesDir, $statusFile);
        $syncResult['synced_systems'] = $syncedList;
        $syncResult['restored_count'] = count($syncedList);
        $autoSynced = true;

        // Recalculate max release date after sync
        foreach ($sysList as $s) {
            $gDate = $s['gamelistDate'] ?? '';
            if ($gDate && preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $gDate, $dm)) {
                $ts = strtotime("{$dm[3]}-{$dm[2]}-{$dm[1]}");
                if ($ts > $maxTs) {
                    $maxTs = $ts;
                    $latestCatalogReleaseDate = $gDate;
                }
            }
        }

        $metaTrack['acknowledged_at'] = date('Y-m-d H:i:s');
        $metaTrack['last_synced_at'] = date('Y-m-d H:i:s');
        $metaTrack['latest_catalog_release_date'] = $latestCatalogReleaseDate;
        @file_put_contents($trackFile, json_encode($metaTrack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Mark update as complete so UI clears the 'Update Ready' badge
        $updateReady = false;
        $curStatus = getSyncStatus($baseDir, $statusFile);
        $curStatus['new_systems'] = [];
        $curStatus['new_systems_count'] = 0;
        $curStatus['updated_systems'] = [];
        $curStatus['updated_systems_count'] = 0;
        $curStatus['latest_catalog_release_date'] = $latestCatalogReleaseDate;
        @file_put_contents($statusFile, json_encode($curStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $detectedUpdatedSystems = [];
        $detectedNewSystems = [];
    }

    $outPayload = [
        'status' => 'ok',
        'update_ready' => $updateReady,
        'auto_synced' => $autoSynced,
        'sync_result' => $syncResult,
        'remote_available' => $remoteAvailable,
        'auth_valid' => $authValid,
        'remote_last_modified' => $remoteLastModified,
        'latest_catalog_release_date' => $latestCatalogReleaseDate,
        'remote_etag' => $remoteEtag,
        'remote_script' => $remoteScript,
        'new_systems' => $updateReady ? ($curStatus['new_systems'] ?? []) : [],
        'updated_systems' => $updateReady ? (!empty($detectedUpdatedSystems) ? $detectedUpdatedSystems : ($curStatus['updated_systems'] ?? [])) : [],
        'recent_updated_systems' => getRecentUpdatedSystems($baseDir, 10),
        'last_sync' => $curStatus['last_sync'] ?? '',
        'total_systems' => $curStatus['total_systems'] ?? 0,
        'total_games' => $curStatus['total_games'] ?? 0,
        'checked_at' => date('Y-m-d H:i:s')
    ];

    @file_put_contents($checkCacheFile, json_encode($outPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    echo json_encode($outPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'acknowledge_update') {
    $trackFile = $baseDir . '/data/pixn_releases_meta.json';
    $curData = file_exists($trackFile) ? readJsonFile($trackFile) : [];
    if (!is_array($curData)) $curData = [];

    $remoteEtag = $data['etag'] ?? ($curData['etag'] ?? null);
    $remoteLastModified = $data['last_modified'] ?? ($curData['last_modified'] ?? null);
    $remoteScript = $data['script'] ?? ($curData['script'] ?? null);

    if ($remoteEtag) $curData['etag'] = $remoteEtag;
    if ($remoteLastModified) $curData['last_modified'] = $remoteLastModified;
    if ($remoteScript) $curData['script'] = $remoteScript;
    $curData['acknowledged_at'] = date('Y-m-d H:i:s');

    file_put_contents($trackFile, json_encode($curData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo json_encode(['status' => 'ok', 'meta' => $curData]);
    exit;
}

if ($action === 'update_sync_status') {
    $curStatus = getSyncStatus($baseDir, $statusFile);
    $updates = $data['status_data'] ?? $data;
    if (is_array($updates)) {
        foreach ($updates as $k => $v) {
            $curStatus[$k] = $v;
        }
        $curStatus['updated_at'] = date('Y-m-d H:i:s');
        file_put_contents($statusFile, json_encode($curStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    echo json_encode(['status' => 'ok', 'sync_status' => $curStatus]);
    exit;
}

if ($action === 'save_systems') {
    if (isset($data['systems']) && is_array($data['systems'])) {
        clearstatcache();

        if (!empty($data['cookie']) && is_string($data['cookie'])) {
            $rawCookie = trim($data['cookie']);
            $sessVal = '';
            if (preg_match('/session=([^;]+)/', $rawCookie, $m)) {
                $sessVal = 'session=' . trim($m[1]);
            } else if (strlen($rawCookie) > 10 && strpos($rawCookie, ';') === false) {
                $sessVal = (strpos($rawCookie, '=') === false) ? 'session=' . $rawCookie : $rawCookie;
            }
            if ($sessVal !== '') {
                $configFile = $baseDir . '/config.json';
                $curCfg = file_exists($configFile) ? readJsonFile($configFile) : [];
                if (!is_array($curCfg)) $curCfg = [];
                if (($curCfg['pixn_session_cookie'] ?? '') !== $sessVal) {
                    $curCfg['pixn_session_cookie'] = $sessVal;
                    $curCfg['pixn_cookie_updated'] = date('Y-m-d H:i:s');
                    @file_put_contents($configFile, json_encode($curCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
            }
        }

        $incoming = $data['systems'];
        $systemsFile = $baseDir . '/systems.json';
        $existing = file_exists($systemsFile) ? readJsonFile($systemsFile) : [];
        if (!is_array($existing)) $existing = [];
        $existingMap = [];
        foreach ($existing as $ex) {
            if (isset($ex['id'])) $existingMap[$ex['id']] = $ex;
        }

        $newSystems = [];
        $updatedSystems = [];
        $missingSystems = [];
        $deletedSystems = [];

        $incomingMap = [];
        foreach ($incoming as $inc) {
            if (!empty($inc['id'])) {
                $incomingMap[$inc['id']] = $inc;
            }
        }

        foreach ($incoming as $inc) {
            $id = $inc['id'] ?? '';
            if (!$id) continue;

            $jsonPath = $gamesDir . '/' . $id . '.json';
            $txtPath = getSystemTxtPath($txtDir, $id);
            $hasJson = file_exists($jsonPath) && filesize($jsonPath) > 0;
            $hasTxt = file_exists($txtPath) && filesize($txtPath) > 0;
            $gameCount = $inc['gameCount'] ?? 0;

            if (!isset($existingMap[$id])) {
                $newSystems[] = $inc;
            } else {
                $old = $existingMap[$id];
                $isUpdated = false;
                if (($old['gameCount'] ?? 0) !== $gameCount) $isUpdated = true;
                if (($old['version'] ?? '') !== ($inc['version'] ?? '')) $isUpdated = true;
                if (($old['release'] ?? '') !== ($inc['release'] ?? '')) $isUpdated = true;
                if (($old['gamelistDate'] ?? '') !== ($inc['gamelistDate'] ?? '')) $isUpdated = true;
                if ($isUpdated) {
                    $updatedSystems[] = $inc;
                }
            }

            // Flag any system that has games but lacks either JSON or TXT file, is updated, or has corrupted JSON
            $needsSync = false;
            if ($gameCount > 0 && (!$hasJson || !$hasTxt)) {
                $needsSync = true;
            } elseif (!empty($isUpdated)) {
                $needsSync = true;
            } elseif ($hasJson && $gameCount > 0 && filesize($jsonPath) < 300) {
                $needsSync = true;
            }

            if ($needsSync) {
                $missingSystems[] = $inc;
            }
        }

        // Detect deleted systems (present on DiskStation, but removed from Pixn Retro releases)
        // Guard check: only purge if incoming catalog has a healthy number of systems (> 50)
        if (count($incoming) > 50) {
            foreach ($existingMap as $oldId => $oldSys) {
                if (!isset($incomingMap[$oldId])) {
                    $deletedSystems[] = $oldSys;
                    // Delete orphaned json and txt files
                    $oldJson = $gamesDir . '/' . $oldId . '.json';
                    $oldTxt = getSystemTxtPath($txtDir, $oldId);
                    if (file_exists($oldJson)) @unlink($oldJson);
                    if (file_exists($oldTxt)) @unlink($oldTxt);
                }
            }
        }

        if (!empty($incoming) && is_array($incoming) && isset($incoming[0]) && is_array($incoming[0]) && !empty($incoming[0]['id'])) {
            file_put_contents($systemsFile, json_encode($incoming, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            @chmod($systemsFile, 0666);
        }

        // Update sync status with detected changes
        $curStatus = getSyncStatus($baseDir, $statusFile);
        $curStatus['total_systems'] = count($incoming);
        $curStatus['total_games'] = array_sum(array_column($incoming, 'gameCount'));
        $curStatus['new_systems_count'] = count($newSystems);
        $curStatus['new_systems'] = array_map(function($s) {
            return ['id' => $s['id'], 'name' => $s['name'], 'gameCount' => $s['gameCount'] ?? 0, 'hardware' => $s['hardware'] ?? ''];
        }, $newSystems);
        $curStatus['updated_systems_count'] = count($updatedSystems);
        $curStatus['updated_systems'] = array_map(function($s) {
            return [
                'id' => $s['id'],
                'name' => $s['name'] ?? $s['id'],
                'gameCount' => $s['gameCount'] ?? 0,
                'version' => $s['version'] ?? '',
                'release' => $s['release'] ?? '',
                'gamelistDate' => $s['gamelistDate'] ?? ''
            ];
        }, $updatedSystems);
        $curStatus['deleted_systems_count'] = count($deletedSystems);
        $curStatus['deleted_systems'] = array_map(function($s) {
            return ['id' => $s['id'], 'name' => $s['name'] ?? $s['id'], 'gameCount' => $s['gameCount'] ?? 0];
        }, $deletedSystems);
        $curStatus['missing_systems'] = $missingSystems;
        $curStatus['missing_systems_count'] = count($missingSystems);
        $curStatus['last_sync'] = date('Y-m-d H:i:s');
        file_put_contents($statusFile, json_encode($curStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $cfgFile = $baseDir . '/config.json';
        $savedCfg = file_exists($cfgFile) ? readJsonFile($cfgFile) : [];
        $hasCookie = !empty($savedCfg['pixn_session_cookie']);

        echo json_encode([
            'status' => 'ok',
            'saved' => count($incoming),
            'has_cookie' => $hasCookie,
            'new_systems_count' => count($newSystems),
            'new_systems' => $newSystems,
            'updated_systems_count' => count($updatedSystems),
            'updated_systems' => $updatedSystems,
            'deleted_systems_count' => count($deletedSystems),
            'deleted_systems' => $deletedSystems,
            'missing_systems' => $missingSystems,
            'missing_systems_count' => count($missingSystems)
        ]);
        exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'No systems provided']);
    exit;
}

if ($action === 'save_batch') {
    $items = $data['items'] ?? [];
    $savedCount = 0;
    $totalGames = 0;

    foreach ($items as $item) {
        $sysId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $item['system_id'] ?? '');
        if (!$sysId) continue;

        $games = $item['games'] ?? [];
        
        // Auto-enrich with developer metadata if available
        enrichSystemMetadata($sysId, $games);

        // Save json
        $bytesJson = file_put_contents($gamesDir . '/' . $sysId . '.json', json_encode($games, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        
        // Save clean text file view: one game name per line
        $lines = [];
        foreach ($games as $g) {
            $name = trim($g['name'] ?? $g['id'] ?? '');
            if ($name !== '') {
                $lines[] = $name;
            }
        }
        // Natural sort
        natcasesort($lines);
        $targetTxtPath = getSystemTxtPath($txtDir, $sysId);
        $bytesTxt = file_put_contents($targetTxtPath, implode("\n", $lines));
        
        $savedCount++;
        $totalGames += count($games);
        $details[] = [
            'id' => $sysId,
            'games' => count($games),
            'json_bytes' => $bytesJson,
            'txt_bytes' => $bytesTxt
        ];
    }

    echo json_encode(['status' => 'ok', 'saved_systems' => $savedCount, 'total_games' => $totalGames, 'details' => $details ?? []]);
    exit;
}

if ($action === 'enrich_system') {
    $sysId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['system'] ?? ($data['system'] ?? ''));
    if (!$sysId) {
        echo json_encode(['status' => 'error', 'message' => 'Missing system id']);
        exit;
    }

    $jsonPath = $gamesDir . '/' . $sysId . '.json';
    if (!file_exists($jsonPath)) {
        echo json_encode(['status' => 'error', 'message' => 'System JSON not found']);
        exit;
    }

    $games = readJsonFile($jsonPath);
    if (!is_array($games)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid games JSON']);
        exit;
    }
    if (isset($games['id']) || isset($games['name'])) {
        $games = [$games];
    }

    $enrichedCount = enrichSystemMetadata($sysId, $games);
    file_put_contents($jsonPath, json_encode($games, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    echo json_encode([
        'status' => 'ok',
        'system' => $sysId,
        'total_games' => count($games),
        'enriched_count' => $enrichedCount
    ]);
    exit;
}

if ($action === 'get_system_metadata') {
    $sysId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['system'] ?? ($data['system'] ?? ''));
    if (!$sysId) {
        echo json_encode(['status' => 'error', 'message' => 'Missing system id']);
        exit;
    }

    $cacheDir = $baseDir . '/data/cache_gamelist';
    $cacheFile = $cacheDir . '/' . $sysId . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400 * 7)) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Pixn-Cache: HIT');
        echo '{"status":"ok","system":' . json_encode($sysId) . ',"metadata":';
        readfile($cacheFile);
        echo '}';
        exit;
    }

    $metaMap = getPixnSystemGamelist($baseDir, $sysId);
    echo json_encode([
        'status' => 'ok',
        'system' => $sysId,
        'count' => count($metaMap),
        'metadata' => $metaMap
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'get_game_metadata') {
    $sysId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['system'] ?? ($data['system'] ?? ''));
    $gameId = trim($_GET['id'] ?? ($data['id'] ?? ''));
    $gameName = trim($_GET['game'] ?? ($data['game'] ?? ($data['name'] ?? '')));

    if (!$sysId) {
        echo json_encode(['status' => 'error', 'message' => 'Missing system id']);
        exit;
    }

    $metaMap = getPixnSystemGamelist($baseDir, $sysId);
    if (empty($metaMap)) {
        echo json_encode(['status' => 'ok', 'found' => false, 'message' => 'No gamelist found for system']);
        exit;
    }

    $lowerGid = mb_strtolower(str_replace('\\', '/', $gameId));
    $normGid = ltrim($lowerGid, './');
    $baseFile = mb_strtolower(pathinfo($lowerGid, PATHINFO_BASENAME));
    $baseWithoutExt = mb_strtolower(pathinfo($lowerGid, PATHINFO_FILENAME));
    $gname = mb_strtolower($gameName);

    $match = null;
    if (!empty($metaMap)) {
        if ($normGid !== '' && isset($metaMap['p:' . $normGid])) {
            $match = $metaMap['p:' . $normGid];
        } elseif ($baseFile !== '' && isset($metaMap['f:' . $baseFile])) {
            $match = $metaMap['f:' . $baseFile];
        } elseif ($baseWithoutExt !== '' && isset($metaMap['b:' . $baseWithoutExt])) {
            $match = $metaMap['b:' . $baseWithoutExt];
        } elseif ($gname !== '' && isset($metaMap['n:' . $gname])) {
            $match = $metaMap['n:' . $gname];
        }
    }

    // Fallback: If no desc was found from remote catalog, check Batocera's gamelist_meta / cache
    if (!$match || empty($match['desc'])) {
        $batoCacheDir = $baseDir . '/data/cache_batocera';
        $cleanSys = preg_replace('/[^a-zA-Z0-9_\-]/', '', $sysId);
        $strippedSys = preg_replace('/(_batocera|_retrobat)$/', '', $cleanSys);
        $candidates = array_unique([$cleanSys, $strippedSys]);
        
        foreach ($candidates as $cand) {
            $httpCache = $batoCacheDir . '/_bato_http_' . $cand . '.json';
            if (file_exists($httpCache)) {
                $httpJson = @file_get_contents($httpCache);
                if ($httpJson) {
                    $httpGames = json_decode($httpJson, true);
                    if (is_array($httpGames)) {
                        foreach ($httpGames as $hg) {
                            $hPath = mb_strtolower(ltrim(str_replace('\\', '/', $hg['path'] ?? ''), './'));
                            $hBase = mb_strtolower(pathinfo($hPath, PATHINFO_BASENAME));
                            $hStem = mb_strtolower(pathinfo($hPath, PATHINFO_FILENAME));
                            $hName = mb_strtolower(trim($hg['name'] ?? ''));

                            $isHit = ($normGid !== '' && ($hPath === $normGid || strpos($hPath, '/' . $normGid) !== false))
                                || ($baseFile !== '' && $hBase === $baseFile)
                                || ($baseWithoutExt !== '' && $hStem === $baseWithoutExt)
                                || ($gname !== '' && ($hName === $gname || $hStem === $gname));

                            if ($isHit && !empty($hg['desc'])) {
                                if (!$match) $match = [];
                                $match['desc'] = trim($hg['desc']);
                                if (empty($match['developer']) && !empty($hg['developer'])) $match['developer'] = trim($hg['developer']);
                                if (empty($match['publisher']) && !empty($hg['publisher'])) $match['publisher'] = trim($hg['publisher']);
                                if (empty($match['genre']) && !empty($hg['genre'])) $match['genre'] = trim($hg['genre']);
                                if (empty($match['rating']) && !empty($hg['rating'])) $match['rating'] = trim($hg['rating']);
                                if (empty($match['releasedate']) && !empty($hg['releasedate'])) $match['releasedate'] = trim($hg['releasedate']);
                                if (empty($match['players']) && !empty($hg['players'])) $match['players'] = trim($hg['players']);
                                break 2;
                            }
                        }
                    }
                }
            }

            // Check cached scan file
            $scanPattern = $batoCacheDir . '/*' . $cand . '*.json';
            $scanFiles = glob($scanPattern);
            if (!empty($scanFiles)) {
                foreach ($scanFiles as $sf) {
                    if (strpos($sf, '_bato_http_') !== false || strpos($sf, '_quick_') !== false) continue;
                    $sData = readJsonFile($sf);
                    if (!empty($sData['gamelist_meta']) && is_array($sData['gamelist_meta'])) {
                        $gm = $sData['gamelist_meta'];
                        $bMeta = null;
                        if ($normGid !== '' && isset($gm[$normGid])) $bMeta = $gm[$normGid];
                        elseif ($baseFile !== '' && isset($gm[$baseFile])) $bMeta = $gm[$baseFile];
                        elseif ($baseWithoutExt !== '' && isset($gm[$baseWithoutExt])) $bMeta = $gm[$baseWithoutExt];
                        elseif ($gname !== '' && isset($gm[$gname])) $bMeta = $gm[$gname];

                        if ($bMeta && !empty($bMeta['desc'])) {
                            if (!$match) $match = [];
                            $match['desc'] = trim($bMeta['desc']);
                            if (empty($match['developer']) && !empty($bMeta['developer'])) $match['developer'] = trim($bMeta['developer']);
                            if (empty($match['publisher']) && !empty($bMeta['publisher'])) $match['publisher'] = trim($bMeta['publisher']);
                            if (empty($match['genre']) && !empty($bMeta['genre'])) $match['genre'] = trim($bMeta['genre']);
                            if (empty($match['rating']) && !empty($bMeta['rating'])) $match['rating'] = trim($bMeta['rating']);
                            if (empty($match['releasedate']) && !empty($bMeta['releasedate'])) $match['releasedate'] = trim($bMeta['releasedate']);
                            if (empty($match['players']) && !empty($bMeta['players'])) $match['players'] = trim($bMeta['players']);
                            break 2;
                        }
                    }
                }
            }
        }
    }

    if ($match) {
        echo json_encode(['status' => 'ok', 'found' => true, 'metadata' => $match]);
    } else {
        echo json_encode(['status' => 'ok', 'found' => false]);
    }
    exit;
}

if ($action === 'get_batocera_config') {
    $cfg = getPixnConfig($baseDir);
    echo json_encode([
        'status' => 'ok',
        'unc_path' => $cfg['batocera_unc_path'] ?? ''
    ]);
    exit;
}

if ($action === 'save_batocera_config') {
    $uncPath = trim($data['unc_path'] ?? ($_POST['unc_path'] ?? ($_GET['unc_path'] ?? '')));
    $cfg = getPixnConfig($baseDir);
    $cfg['batocera_unc_path'] = $uncPath;
    $cfg['updated_at'] = date('Y-m-d H:i:s');
    file_put_contents($baseDir . '/config.json', json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Also update batocera_config.json for legacy backwards-compatibility
    $bConfig = [
        'unc_path' => $uncPath,
        'updated_at' => $cfg['updated_at']
    ];
    file_put_contents($baseDir . '/batocera_config.json', json_encode($bConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    echo json_encode([
        'status' => 'ok',
        'unc_path' => $uncPath
    ]);
    exit;
}

if ($action === 'get_config') {
    $cfg = getPixnConfig($baseDir);
    $hasCookie = !empty($cfg['pixn_session_cookie']);
    $maskedCookie = '';
    if ($hasCookie) {
        $c = $cfg['pixn_session_cookie'];
        $maskedCookie = strlen($c) > 16 ? substr($c, 0, 12) . '...' . substr($c, -4) : '••••••••';
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    $proto = $isHttps ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/pixn/save.php');
    $detectedUrl = rtrim($proto . $host . $scriptDir, '/\\');

    $configFile = $baseDir . '/config.json';
    $isFirstRun = !file_exists($configFile) || (empty($cfg['pixn_session_cookie']) && empty($cfg['nas_base_url']));

    $isWritable = false;
    if (file_exists($configFile)) {
        if (!is_writable($configFile)) {
            @chmod($configFile, 0666);
        }
        $isWritable = is_writable($configFile);
    } else {
        $testFile = $baseDir . '/.pixn_write_test_' . uniqid();
        if (@file_put_contents($testFile, '1') !== false) {
            @unlink($testFile);
            $isWritable = true;
        }
    }

    $configuredBatocera = !empty($cfg['batocera_unc_path']) ? $cfg['batocera_unc_path'] : '\\\\BATOCERA\\share\\roms';

    echo json_encode([
        'status' => 'ok',
        'is_first_run' => $isFirstRun,
        'is_writable' => $isWritable,
        'config' => [
            'nas_base_url' => !empty($cfg['nas_base_url']) ? $cfg['nas_base_url'] : $detectedUrl,
            'batocera_unc_path' => $configuredBatocera,
            'has_pixn_cookie' => $hasCookie,
            'pixn_session_cookie' => $cfg['pixn_session_cookie'] ?? '',
            'masked_cookie' => $maskedCookie,
            'updated_at' => $cfg['updated_at'] ?? ''
        ],
        'detected_nas_url' => $detectedUrl
    ]);
    exit;
}

if ($action === 'save_config') {
    $inputData = !empty($data) && is_array($data) ? $data : $_POST;
    if (empty($inputData)) {
        $raw = file_get_contents('php://input');
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) $inputData = $parsed;
    }

    $cfg = getPixnConfig($baseDir);
    if (isset($inputData['nas_base_url'])) {
        $cfg['nas_base_url'] = rtrim(trim($inputData['nas_base_url']), '/\\');
    }
    if (isset($inputData['batocera_unc_path'])) {
        $cfg['batocera_unc_path'] = trim($inputData['batocera_unc_path']);
    }
    if (isset($inputData['pixn_session_cookie'])) {
        $cookieInput = trim($inputData['pixn_session_cookie']);
        // Automatically ensure "session=" prefix if user just pasted the raw token
        if ($cookieInput !== '' && strpos($cookieInput, '=') === false) {
            $cookieInput = 'session=' . $cookieInput;
        }
        $cfg['pixn_session_cookie'] = $cookieInput;
    }
    $cfg['updated_at'] = date('Y-m-d H:i:s');

    $configFile = $baseDir . '/config.json';
    if (file_exists($configFile) && !is_writable($configFile)) {
        @chmod($configFile, 0666);
    }
    $saved = @file_put_contents($configFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($saved !== false) {
        @chmod($configFile, 0666);
    }

    if ($saved === false) {
        $err = error_get_last();
        $errMsg = !empty($err['message']) ? $err['message'] : 'Permission denied writing to ' . $configFile;
        echo json_encode([
            'status' => 'error',
            'message' => 'Cannot write config.json: ' . $errMsg . '. Please grant write permissions to the web folder (chmod -R 775 or chown http).'
        ]);
        exit;
    }

    // Keep legacy files in sync for backwards-compatibility:
    $dataDir = $baseDir . '/data';
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0777, true);
    }
    if (!empty($cfg['batocera_unc_path'])) {
        @file_put_contents($baseDir . '/batocera_config.json', json_encode([
            'unc_path' => $cfg['batocera_unc_path'],
            'updated_at' => $cfg['updated_at']
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($baseDir . '/batocera_config.json', 0666);
    }
    if (!empty($cfg['pixn_session_cookie'])) {
        @file_put_contents($dataDir . '/pixn_auth.json', json_encode([
            'cookie' => $cfg['pixn_session_cookie'],
            'updated_at' => $cfg['updated_at']
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($dataDir . '/pixn_auth.json', 0666);
    }

    echo json_encode([
        'status' => 'ok',
        'message' => 'Configuration saved successfully',
        'config' => [
            'nas_base_url' => $cfg['nas_base_url'] ?? '',
            'batocera_unc_path' => $cfg['batocera_unc_path'] ?? '',
            'has_pixn_cookie' => !empty($cfg['pixn_session_cookie']),
            'updated_at' => $cfg['updated_at']
        ]
    ]);
    exit;
}


if ($action === 'download_extension') {
    $zipPath = $baseDir . '/data/pixn-chrome-extension.zip';
    if (!file_exists($zipPath) && class_exists('ZipArchive')) {
        $extDir = $baseDir . '/extension';
        if (is_dir($extDir)) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($extDir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($files as $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = substr($filePath, strlen(realpath($extDir)) + 1);
                        $zip->addFile($filePath, str_replace('\\', '/', $relativePath));
                    }
                }
                $zip->close();
            }
        }
    }
    if (file_exists($zipPath)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="pixn-chrome-extension.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'Extension package not found']);
    exit;
}

function getSmbClientBinary() {
    static $smbBin = null;
    if ($smbBin !== null) return $smbBin;

    if (!function_exists('exec')) {
        $smbBin = false;
        return $smbBin;
    }

    $test = [];
    $ret = -1;
    @exec('smbclient --version 2>&1', $test, $ret);
    if ($ret === 0) {
        $smbBin = 'smbclient';
        return $smbBin;
    }

    $paths = [
        '/opt/bin/smbclient',
        '/usr/bin/smbclient',
        '/bin/smbclient',
        '/usr/local/bin/smbclient',
        '/usr/local/samba/bin/smbclient'
    ];
    foreach ($paths as $p) {
        $out = [];
        $r = -1;
        @exec("{$p} --version 2>&1", $out, $r);
        if ($r === 0) {
            $smbBin = $p;
            return $smbBin;
        }
    }

    $smbBin = false;
    return $smbBin;
}

function resolveLanHostname($host) {
    if (!$host) return null;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return $host;
    }

    static $memIpCache = [];
    $normHost = strtolower(trim($host));
    if (isset($memIpCache[$normHost])) {
        return $memIpCache[$normHost];
    }

    global $baseDir;
    $cacheDir = ($baseDir ? $baseDir . '/data/cache_batocera' : sys_get_temp_dir());
    $cacheFile = $cacheDir . '/_ip_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $normHost) . '.txt';
    if (@file_exists($cacheFile) && (time() - @filemtime($cacheFile) < 3600)) {
        $cIp = trim((string)@file_get_contents($cacheFile));
        if ($cIp && filter_var($cIp, FILTER_VALIDATE_IP)) {
            $memIpCache[$normHost] = $cIp;
            return $cIp;
        }
    }

    $saveIp = function($ip) use (&$memIpCache, $normHost, $cacheDir, $cacheFile) {
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            $memIpCache[$normHost] = $ip;
            if (!@is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
            @file_put_contents($cacheFile, $ip);
            return $ip;
        }
        return null;
    };

    // 1. Direct standard DNS / /etc/hosts
    $ip = @gethostbyname($host);
    if ($ip && $ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
        return $saveIp($ip);
    }

    // 2. mDNS (.local)
    if (strpos($host, '.') === false) {
        $mDnsIp = @gethostbyname($host . '.local');
        if ($mDnsIp && $mDnsIp !== ($host . '.local') && filter_var($mDnsIp, FILTER_VALIDATE_IP)) {
            return $saveIp($mDnsIp);
        }
    }

    // 3. NetBIOS lookup via nmblookup (essential on Synology DSM & Linux Samba for hostnames like batocera-rgs)
    if (DIRECTORY_SEPARATOR !== '\\' && function_exists('exec')) {
        $nmbOut = [];
        @exec("/usr/bin/nmblookup " . escapeshellarg($host) . " 2>&1", $nmbOut);
        if (empty($nmbOut)) {
            @exec("nmblookup " . escapeshellarg($host) . " 2>&1", $nmbOut);
        }
        if (!empty($nmbOut)) {
            foreach ($nmbOut as $line) {
                if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\s+/i', trim($line), $m)) {
                    return $saveIp($m[1]);
                }
            }
        }
    }

    // 4. Candidate variants for generic "batocera" hostnames
    if (strtolower($host) === 'batocera') {
        $commonVariants = ['batocera-rgs', 'batocera-pc', 'batocera-box'];
        foreach ($commonVariants as $variant) {
            $vIp = @gethostbyname($variant);
            if ($vIp && $vIp !== $variant && filter_var($vIp, FILTER_VALIDATE_IP)) {
                return $saveIp($vIp);
            }
            $vIpLocal = @gethostbyname($variant . '.local');
            if ($vIpLocal && $vIpLocal !== ($variant . '.local') && filter_var($vIpLocal, FILTER_VALIDATE_IP)) {
                return $saveIp($vIpLocal);
            }
            if (DIRECTORY_SEPARATOR !== '\\' && function_exists('exec')) {
                $vOut = [];
                @exec("/usr/bin/nmblookup " . escapeshellarg($variant) . " 2>&1", $vOut);
                if (empty($vOut)) @exec("nmblookup " . escapeshellarg($variant) . " 2>&1", $vOut);
                foreach ($vOut as $line) {
                    if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\s+/i', trim($line), $m)) {
                        return $saveIp($m[1]);
                    }
                }
            }
        }
    }

    return null;
}

function testHostSmbPort($hostOrIp, $timeout = 1.5) {
    if (!$hostOrIp) return false;
    if (function_exists('fsockopen')) {
        $fp = @fsockopen($hostOrIp, 445, $errno, $errstr, (float)$timeout);
        if ($fp) {
            @fclose($fp);
            return 445;
        }
    } elseif (function_exists('stream_socket_client')) {
        $ctx = stream_context_create();
        $fp = @stream_socket_client("tcp://{$hostOrIp}:445", $errno, $errstr, (float)$timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp) {
            @fclose($fp);
            return 445;
        }
    }
    return false;
}

function getBatoceraHostAndShare($uncPath) {
    if (!$uncPath) return null;
    $cleanUnc = str_replace('/', '\\', $uncPath);
    $cleanUnc = trim($cleanUnc, '\\');
    $parts = explode('\\', $cleanUnc);
    if (count($parts) < 2) return null;

    $host = $parts[0];
    $share = $parts[1];
    $subDir = implode('/', array_slice($parts, 2));

    $targetHost = resolveLanHostname($host);
    if (!$targetHost) {
        $targetHost = $host;
    }

    return [
        'host' => $host,
        'targetHost' => $targetHost,
        'share' => $share,
        'subDir' => $subDir
    ];
}

function areExtensionsCompatible($ext1, $ext2) {
    if (!$ext1 || !$ext2) return true;
    $e1 = strtolower(ltrim($ext1, '.'));
    $e2 = strtolower(ltrim($ext2, '.'));
    if ($e1 === $e2) return true;

    $opts1 = preg_split('/[|()\/]+/', $e1, -1, PREG_SPLIT_NO_EMPTY);
    $opts2 = preg_split('/[|()\/]+/', $e2, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($opts1 as $o1) {
        foreach ($opts2 as $o2) {
            if ($o1 === $o2) return true;
        }
    }

    $archives = ['zip', '7z', 'gz', 'rar', 'tar'];
    foreach ($opts1 as $o1) {
        if (in_array($o1, $archives)) return true;
    }
    foreach ($opts2 as $o2) {
        if (in_array($o2, $archives)) return true;
    }

    $groups = [
        ['bin', 'a26', 'rom'],
        ['bin', 'a78'],
        ['nes', 'unf', 'unif', 'fds', 'bin'],
        ['sfc', 'smc', 'fig', 'swc', 'bin'],
        ['md', 'bin', 'gen', 'smd'],
        ['sms', 'gg', 'bin', 'sg'],
        ['gb', 'gbc', 'sgb', 'bin'],
        ['gba', 'agb', 'bin'],
        ['z64', 'n64', 'v64', 'bin'],
        ['pce', 'bin', 'cue', 'chd', 'iso', 'sgx'],
        ['cue', 'bin', 'iso', 'img', 'chd', 'pbp', 'm3u', 'cso'],
        ['adf', 'ipf', 'dms', 'hdf', 'lha', 'm3u'],
        ['wsquashfs', 'squashfs'],
        ['ps3', 'ps3dir', 'squashfs', 'psn', 'iso', 'm3u', 'pkg', 'bin'],
        ['ps4', 'ps4dir', 'squashfs', 'pkg', 'iso', 'bin']
    ];
    foreach ($groups as $g) {
        $has1 = false;
        $has2 = false;
        foreach ($opts1 as $o1) { if (in_array($o1, $g)) { $has1 = true; break; } }
        foreach ($opts2 as $o2) { if (in_array($o2, $g)) { $has2 = true; break; } }
        if ($has1 && $has2) return true;
    }
    return false;
}

function getBatoceraFoldersList($baseDir, $uncPath, $forceRefresh = false) {
    try {
        $conn = getBatoceraHostAndShare($uncPath);
        if (!$conn) {
            return ['status' => 'offline', 'online' => false, 'message' => 'Invalid or missing UNC share path', 'folders' => []];
        }

        $targetHost = $conn['targetHost'];
        $share = $conn['share'];
        $subDir = $conn['subDir'];
        $host = $conn['host'];

        $cacheDir = $baseDir . '/data/cache_batocera';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        $cacheFile = $cacheDir . '/_folders_list_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $targetHost ?: $host) . '.json';
        $cacheTTL = 600; // 10 minutes cache TTL

        if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTTL)) {
            $cached = readJsonFile($cacheFile);
            if (is_array($cached) && !empty($cached['online']) && isset($cached['folders'])) {
                return $cached;
            }
        }

        // 1. FAST PATH: Batocera HTTP REST API (Port 1234)
        $batoIp = $targetHost;
        if (!filter_var($batoIp, FILTER_VALIDATE_IP)) {
            $resolvedIp = function_exists('resolveLanHostname') ? resolveLanHostname($batoIp) : gethostbyname($batoIp);
            if ($resolvedIp && filter_var($resolvedIp, FILTER_VALIDATE_IP)) {
                $batoIp = $resolvedIp;
            }
        }
        $detectedIp = filter_var($targetHost, FILTER_VALIDATE_IP) ? $targetHost : (filter_var($batoIp, FILTER_VALIDATE_IP) ? $batoIp : '');
        if (filter_var($batoIp, FILTER_VALIDATE_IP)) {
            $httpCtx = stream_context_create([
                'http' => ['method' => 'GET', 'timeout' => 0.6, 'ignore_errors' => true]
            ]);
            $rawSys = @file_get_contents("http://{$batoIp}:1234/systems", false, $httpCtx);
            if ($rawSys) {
                $sysList = @json_decode($rawSys, true);
                if (is_array($sysList) && !empty($sysList)) {
                    $httpFolders = [];
                    foreach ($sysList as $sItem) {
                        $sName = is_array($sItem) ? ($sItem['name'] ?? '') : $sItem;
                        if ($sName && is_string($sName)) {
                            $httpFolders[] = strtolower(trim($sName));
                        }
                    }
                    if (!empty($httpFolders)) {
                        if (in_array('cave3rd', $httpFolders) && !in_array('cave', $httpFolders)) {
                            $httpFolders[] = 'cave';
                        }
                        sort($httpFolders);
                        $res = [
                            'status' => 'ok',
                            'online' => true,
                            'host' => $host,
                            'ip' => $detectedIp,
                            'resolved_target' => $targetHost,
                            'folders' => $httpFolders,
                            'count' => count($httpFolders),
                            'cached_at' => time(),
                            'source' => 'http-fast'
                        ];
                        @file_put_contents($cacheFile, json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                        return $res;
                    }
                }
            }
        }

        // 2. SMB Fallback: Socket check with 1.5s timeout on port 445
        $port = testHostSmbPort($targetHost, 1.5);
        if (!$port) {
            $diag = (!filter_var($targetHost, FILTER_VALIDATE_IP) && $targetHost === $host)
                ? "Host '{$host}' could not be resolved. Please check the hostname or use your Batocera PC's IP address (e.g. \\\\192.168.0.190\\share\\roms)."
                : "Could not connect to {$targetHost}:445. Ensure Batocera PC is powered on and Samba file sharing is enabled.";

            return [
                'status' => 'offline',
                'online' => false,
                'host' => $host,
                'resolved_target' => $targetHost,
                'message' => $diag,
                'folders' => []
            ];
        }

        // Check Windows native SMB support
        if (PHP_OS_FAMILY === 'Windows') {
            $winPath = '\\\\' . $targetHost . '\\' . $share . ($subDir !== '' ? '\\' . str_replace('/', '\\', $subDir) : '');
            if (@is_dir($winPath)) {
                $entries = @scandir($winPath);
                $folders = [];
                if (is_array($entries)) {
                    foreach ($entries as $e) {
                        if ($e !== '.' && $e !== '..' && @is_dir($winPath . '\\' . $e)) {
                            $folders[] = strtolower($e);
                        }
                    }
                }
                if (in_array('cave3rd', $folders) && !in_array('cave', $folders)) {
                    $folders[] = 'cave';
                }
                sort($folders);
                $res = [
                    'status' => 'ok',
                    'online' => true,
                    'host' => $host,
                    'ip' => $detectedIp,
                    'resolved_target' => $targetHost,
                    'folders' => $folders,
                    'count' => count($folders),
                    'cached_at' => time()
                ];
                @file_put_contents($cacheFile, json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return $res;
            }
        }

        // Unix/Linux: Query via smbclient
        $smbBin = getSmbClientBinary();
        if (!$smbBin) {
            return [
                'status' => 'error',
                'online' => false,
                'host' => $host,
                'resolved_target' => $targetHost,
                'message' => 'smbclient binary not found on this web server. Please install smbclient (apt install smbclient or enable in NAS Web Station) to browse network shares.',
                'folders' => []
            ];
        }

        $smbShare = '//' . $targetHost . '/' . $share;
        $smbCmd = $subDir !== '' ? ('cd "' . $subDir . '"; ls') : 'ls';
        
        $escapedShare = escapeshellarg($smbShare);
        $escapedCmd = escapeshellarg($smbCmd);
        
        $out = [];
        $ret = -1;
        @exec("{$smbBin} {$escapedShare} -N -t 8 -c {$escapedCmd} 2>&1", $out, $ret);
        $outputStr = implode("\n", $out);
        if ($ret !== 0 && (strpos($outputStr, 'NT_STATUS_LOGON_FAILURE') !== false || strpos($outputStr, 'NT_STATUS_ACCESS_DENIED') !== false)) {
            $out = [];
            @exec("{$smbBin} {$escapedShare} -U 'root%linux' -t 8 -c {$escapedCmd} 2>&1", $out, $ret);
        }

        $folders = [];
        foreach ($out as $line) {
            $line = trim($line);
            if (!$line) continue;
            if (preg_match('/^(.+?)\s+([DAHNSR_]+)\s+(\d+)\s+([A-Za-z]{3}\s+[A-Za-z]{3}.*)$/', $line, $m)) {
                $fname = trim($m[1]);
                $attrs = $m[2];
                if (strpos($attrs, 'D') !== false && $fname !== '.' && $fname !== '..') {
                    $folders[] = strtolower($fname);
                }
            }
        }
        if (in_array('cave3rd', $folders) && !in_array('cave', $folders)) {
            $folders[] = 'cave';
        }
        sort($folders);

        if ($ret !== 0 && empty($folders)) {
            $cleanErr = trim(implode(' ', array_slice($out, 0, 3)));
            return [
                'status' => 'error',
                'online' => false,
                'host' => $host,
                'resolved_target' => $targetHost,
                'message' => 'smbclient error: ' . ($cleanErr ?: "exit code {$ret}"),
                'folders' => []
            ];
        }

        $res = [
            'status' => 'ok',
            'online' => true,
            'host' => $host,
            'ip' => $detectedIp,
            'resolved_target' => $targetHost,
            'folders' => $folders,
            'count' => count($folders),
            'cached_at' => time()
        ];
        @file_put_contents($cacheFile, json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $res;
    } catch (Throwable $t) {
        return [
            'status' => 'error',
            'online' => false,
            'host' => $host ?? 'unknown',
            'message' => 'Server error: ' . $t->getMessage(),
            'folders' => []
        ];
    }
}

if ($action === 'get_batocera_folders') {
    $uncPath = getPixnBatoceraUncPath($baseDir, $_GET['unc_path'] ?? ($data['unc_path'] ?? ''));
    $forceRefresh = !empty($_GET['force_refresh']) || !empty($_GET['refresh']);

    $res = getBatoceraFoldersList($baseDir, $uncPath, $forceRefresh);
    echo json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function getBatoceraSettingsData($baseDir, $uncPath, $forceRefresh = false) {
    $conn = getBatoceraHostAndShare($uncPath);
    if (!$conn) {
        return ['status' => 'offline', 'online' => false, 'message' => 'Invalid or missing UNC path'];
    }

    $targetHost = $conn['targetHost'];
    $share = $conn['share'];
    $host = $conn['host'];

    $cacheDir = $baseDir . '/data/cache_batocera';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $cacheFile = $cacheDir . '/_batocera_conf.json';
    $cacheTTL = 3600; // 1 hour cache TTL

    if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTTL)) {
        $cached = readJsonFile($cacheFile);
        if (is_array($cached) && !empty($cached['online'])) {
            return $cached;
        }
    }

    $rawConf = null;
    $uncDirect = str_replace('/', '\\', $uncPath);
    $candidateDirect = [
        rtrim($uncDirect, '\\') . '\\..\\system\\batocera.conf',
        '\\\\' . $targetHost . '\\' . $share . '\\system\\batocera.conf',
        '\\\\' . $host . '\\' . $share . '\\system\\batocera.conf'
    ];
    foreach ($candidateDirect as $cd) {
        if (@file_exists($cd)) {
            $rawConf = @file_get_contents($cd);
            if ($rawConf !== false && strlen($rawConf) > 0) break;
        }
    }

    if (!$rawConf) {
        $fp = @fsockopen($targetHost, 445, $errno, $errstr, 1.2);
        if (!$fp) {
            return ['status' => 'offline', 'online' => false, 'host' => $host, 'message' => 'Batocera PC is offline'];
        }
        fclose($fp);

        $smbShare = '//' . $targetHost . '/' . $share;
        $escapedShare = escapeshellarg($smbShare);
        $tmpConf = @tempnam(sys_get_temp_dir(), 'bconf_');
        if (!$tmpConf) $tmpConf = $baseDir . '/data/tmp_conf.tmp';

        $getCmd = escapeshellarg('get "system/batocera.conf" "' . $tmpConf . '"');
        $out = [];
        $ret = -1;
        @exec("/usr/bin/smbclient {$escapedShare} -N -t 6 -c {$getCmd} 2>&1", $out, $ret);
        if ($ret !== 0) {
            $out = [];
            @exec("/usr/bin/smbclient {$escapedShare} -U 'root%linux' -t 6 -c {$getCmd} 2>&1", $out, $ret);
        }

        if (file_exists($tmpConf) && filesize($tmpConf) > 0) {
            $rawConf = @file_get_contents($tmpConf);
        }
        if (file_exists($tmpConf)) @unlink($tmpConf);
    }

    if (!$rawConf) {
        return ['status' => 'error', 'online' => true, 'message' => 'Could not read batocera.conf', 'system_settings' => [], 'game_settings' => [], 'global_settings' => []];
    }

    $systemSettings = [];
    $gameSettings = [];
    $globalSettings = [];

    $lines = preg_split('/\r\n|\r|\n/', $rawConf);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
        $eqPos = strpos($line, '=');
        if ($eqPos === false) continue;

        $left = trim(substr($line, 0, $eqPos));
        $val = trim(substr($line, $eqPos + 1));

        if (preg_match('/^([a-zA-Z0-9_\-]+)\[[\'"]?([^\'"]+)[\'"]?\]\.(.+)$/', $left, $m)) {
            $sys = strtolower($m[1]);
            $target = trim($m[2]);
            $key = trim($m[3]);
            if (strtolower($key) === 'tdp' || stripos($key, 'tdp') !== false) continue;

            $targetLower = mb_strtolower($target);
            $normPath = ltrim(str_replace('\\', '/', $targetLower), './');
            $baseFile = mb_strtolower(pathinfo($normPath, PATHINFO_BASENAME));
            $baseWithoutExt = mb_strtolower(pathinfo($normPath, PATHINFO_FILENAME));

            if (!isset($gameSettings[$sys])) $gameSettings[$sys] = [];
            $gameSettings[$sys]['p:' . $normPath][$key] = $val;
            $gameSettings[$sys]['f:' . $baseFile][$key] = $val;
            $gameSettings[$sys]['b:' . $baseWithoutExt][$key] = $val;
            continue;
        }

        if (preg_match('/^([a-zA-Z0-9_\-]+)\.(.+)$/', $left, $m)) {
            $sys = strtolower($m[1]);
            $key = trim($m[2]);
            if (strtolower($key) === 'tdp' || stripos($key, 'tdp') !== false) continue;
            if ($sys === 'global') {
                $globalSettings[$key] = $val;
            } else {
                if (!isset($systemSettings[$sys])) $systemSettings[$sys] = [];
                $systemSettings[$sys][$key] = $val;
            }
            continue;
        }
    }

    $res = [
        'status' => 'ok',
        'online' => true,
        'cached' => false,
        'cache_time' => time(),
        'system_settings' => $systemSettings,
        'game_settings' => $gameSettings,
        'global_settings' => $globalSettings
    ];

    @file_put_contents($cacheFile, json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $res;
}

function attachBatoceraSettingsToResponse($baseDir, $uncPath, $sysId, &$targetArray, $forceRefresh = false) {
    $settingsRes = getBatoceraSettingsData($baseDir, $uncPath, $forceRefresh);
    if (!empty($settingsRes['online'])) {
        $strippedSys = preg_replace('/(_batocera|_retrobat)$/', '', strtolower($sysId));
        $cleanSys = strtolower($sysId);

        $matchedSysSettings = [];
        if (!empty($settingsRes['system_settings'][$cleanSys])) {
            $matchedSysSettings = array_merge($matchedSysSettings, $settingsRes['system_settings'][$cleanSys]);
        }
        if ($strippedSys !== $cleanSys && !empty($settingsRes['system_settings'][$strippedSys])) {
            $matchedSysSettings = array_merge($matchedSysSettings, $settingsRes['system_settings'][$strippedSys]);
        }

        $matchedGameSettings = [];
        if (!empty($settingsRes['game_settings'][$cleanSys])) {
            $matchedGameSettings = array_merge($matchedGameSettings, $settingsRes['game_settings'][$cleanSys]);
        }
        if ($strippedSys !== $cleanSys && !empty($settingsRes['game_settings'][$strippedSys])) {
            $matchedGameSettings = array_merge($matchedGameSettings, $settingsRes['game_settings'][$strippedSys]);
        }

        $targetArray['system_settings'] = $matchedSysSettings;
        $targetArray['game_settings'] = $matchedGameSettings;
    }
}

/**
 * Enriches $foundFiles array with actual file sizes in bytes and MB.
 * Tries direct local/UNC filesystem access first, and falls back to smbclient ls.
 */
function enrichFoundFilesSizes(&$foundFiles, $baseDir, $uncPath, $targetHost, $share, $fullRemoteDir, $batoceraFolder) {
    if (empty($foundFiles) || !is_array($foundFiles)) return;

    $isPs3 = (strtolower($batoceraFolder) === 'ps3');
    $isPs4 = (strtolower($batoceraFolder) === 'ps4');

    $needsSize = false;
    foreach ($foundFiles as $f) {
        if (empty($f['size_bytes'])) {
            $needsSize = true;
            break;
        }
    }
    if (!$needsSize) return;

    // 1. Try direct local / UNC filesystem access first
    $directBasePath = '';
    $cleanUnc = str_replace('\\', '/', $uncPath);
    if (@is_dir($uncPath)) {
        $directBasePath = rtrim($uncPath, '\\/') . '/' . $batoceraFolder;
    } elseif (@is_dir($cleanUnc)) {
        $directBasePath = rtrim($cleanUnc, '/') . '/' . $batoceraFolder;
    } else {
        $winBase = '\\\\' . $targetHost . '\\' . $share . ($fullRemoteDir !== '' ? '\\' . str_replace('/', '\\', $fullRemoteDir) : '');
        if (@is_dir($winBase)) {
            $directBasePath = $winBase;
        }
    }

    if ($directBasePath !== '' && @is_dir($directBasePath)) {
        // Fast path: Build a map of filenames in this directory to their sizes in one directory listing
        $dirSizes = [];
        $dirActualNames = [];
        try {
            $dirIt = new DirectoryIterator($directBasePath);
            foreach ($dirIt as $fileInfo) {
                if (!$fileInfo->isDot() && !$fileInfo->isDir()) {
                    $sz = $fileInfo->getSize();
                    if ($sz < 0) $sz += 4294967296.0;
                    $lowName = mb_strtolower($fileInfo->getFilename());
                    $dirSizes[$lowName] = (float)$sz;
                    $dirActualNames[$lowName] = $fileInfo->getFilename();
                }
            }
        } catch (Exception $e) {}

        $verified = [];
        foreach ($foundFiles as $idx => $f) {
            $lowerName = mb_strtolower($f['fname']);
            if (isset($dirSizes[$lowerName])) {
                $f['size_bytes'] = $dirSizes[$lowerName];
                $f['size_mb'] = round($dirSizes[$lowerName] / (1024 * 1024), 2);
                $verified[] = $f;
                continue;
            }
            $fullPath = $directBasePath . '/' . str_replace('\\', '/', $f['rel']);
            if (@file_exists($fullPath)) {
                if (@is_dir($fullPath)) {
                    $isPkg = preg_match('/\.(ps3|ps3dir|ps4|ps4dir|switch|wiiu|wiiudir|pc|wine|dos|dosbox|dvd|cd|scummvm|app|game)$/i', $f['fname']);
                    if (!$isPkg) {
                        $totalBytes = 0;
                        try {
                            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fullPath, FilesystemIterator::SKIP_DOTS));
                            foreach ($it as $file) {
                                $totalBytes += $file->getSize();
                            }
                        } catch (Exception $e) {}
                        $f['size_bytes'] = $totalBytes;
                        $f['size_mb'] = round($totalBytes / (1024 * 1024), 2);
                    } else {
                        $f['size_bytes'] = 1;
                        $f['size_mb'] = 0;
                    }
                } else {
                    $sz = @filesize($fullPath);
                    if ($sz !== false) {
                        if ($sz < 0) {
                            $sz = (float)$sz + 4294967296.0;
                        }
                        $f['size_bytes'] = (float)$sz;
                        $f['size_mb'] = round((float)$sz / (1024 * 1024), 2);
                    }
                }
                $verified[] = $f;
            }
            // If file does NOT exist on disk, it was deleted! Omit from $verified.
        }

        // Also add any ROM files on disk that were NOT in HTTP/gamelist.xml
        $knownLower = [];
        foreach ($verified as $vf) {
            $knownLower[mb_strtolower($vf['fname'])] = true;
            $knownLower[mb_strtolower($vf['rel'])] = true;
        }
        foreach ($dirSizes as $dLow => $dBytes) {
            if (isset($knownLower[$dLow])) continue;
            if ($isPs3) {
                if (!preg_match('/\.(squashfs|psn|iso|m3u|pkg)$/i', $dLow)) continue;
            } elseif ($isPs4) {
                if (!preg_match('/\.(squashfs|pkg|iso|bin)$/i', $dLow)) continue;
            } else {
                if ($dLow === 'gamelist.xml' || preg_match('/\.keys$/i', $dLow)) continue;
                if (preg_match('/^([_\.]?gamelist.*\.xml|[_\.]?info.*\.txt|readme.*\.txt|\.git.*|\.ds_store|thumbs\.db|desktop\.ini)$/i', $dLow)) continue;
                $dExt = strtolower(pathinfo($dLow, PATHINFO_EXTENSION));
                if (in_array($dExt, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'mp4', 'mkv', 'avi', 'pdf', 'mp3', 'ogg', 'wav', 'old', 'bak', 'backup', 'log', 'ini', 'cfg', 'txt', 'xml', 'sfo', 'sfb', 'dat', 'trp'], true)) continue;
            }

            $actualName = $dirActualNames[$dLow] ?? $dLow;
            $verified[] = [
                'fname' => $actualName,
                'rel' => $actualName,
                'size_bytes' => $dBytes,
                'size_mb' => round($dBytes / (1024 * 1024), 2)
            ];
        }

        $foundFiles = $verified;
        return;
    }

    // 2. Query sizes via smbclient if targetHost and share are available
    if (!$targetHost || !$share) return;
    $smbBin = getSmbClientBinary();
    if (!$smbBin) return;

    $smbShare = '//' . $targetHost . '/' . $share;
    $escapedShare = escapeshellarg($smbShare);

    // Determine whether to use recurse on
    $hasSubdirs = false;
    foreach ($foundFiles as $f) {
        if (strpos(str_replace('\\', '/', $f['rel']), '/') !== false) {
            $hasSubdirs = true;
            break;
        }
    }

    $smbCmd = $hasSubdirs ? ('cd "' . $fullRemoteDir . '"; recurse on; ls') : ('cd "' . $fullRemoteDir . '"; ls');
    $escapedCmd = escapeshellarg($smbCmd);

    $out = [];
    $ret = -1;
    @exec("{$smbBin} {$escapedShare} -N -t 10 -c {$escapedCmd} 2>&1", $out, $ret);

    $outputStr = implode("\n", $out);
    if ($ret !== 0 && (strpos($outputStr, 'NT_STATUS_LOGON_FAILURE') !== false || strpos($outputStr, 'NT_STATUS_ACCESS_DENIED') !== false)) {
        $out = [];
        @exec("{$smbBin} {$escapedShare} -U 'root%linux' -t 10 -c {$escapedCmd} 2>&1", $out, $ret);
    }

    if (empty($out)) return;

    $currentSubdir = '';
    $smbSizesRel = [];
    $smbSizesFname = [];
    $smbDirSizes = [];

    foreach ($out as $line) {
        $line = trim($line);
        if (!$line) continue;
        if (strpos($line, 'smb.reserved.conf') !== false) continue;
        if (strpos($line, 'blocks of size') !== false || strpos($line, 'blocks available') !== false) continue;
        if (strpos($line, 'Domain=') !== false || strpos($line, 'OS=') !== false || strpos($line, 'Server=') !== false) continue;

        if (preg_match('/^\\\\([^\r\n]*)$/', $line, $dirM) && !preg_match('/\s+[DAHNSR_]+\s+\d+/', $line)) {
            $dirClean = trim(str_replace('\\', '/', $dirM[1]), '/');
            $dirClean = preg_replace('#^roms/' . preg_quote($batoceraFolder, '#') . '(/|$)#i', '', $dirClean);
            $dirClean = preg_replace('#^' . preg_quote($batoceraFolder, '#') . '(/|$)#i', '', $dirClean);
            $currentSubdir = ($dirClean === '.' || $dirClean === '') ? '' : $dirClean;
            continue;
        }

        if (preg_match('/^(.+?)\s+([DAHNSR_]+)\s+(\d+)\s+([A-Za-z]{3}\s+[A-Za-z]{3}.*)$/', $line, $m)) {
            $fname = trim($m[1]);
            $attrs = $m[2];
            $bytes = (float)$m[3];
            $isDir = (strpos($attrs, 'D') !== false);

            if ($fname === '.' || $fname === '..') continue;

            $rel = $currentSubdir !== '' ? ($currentSubdir . '/' . $fname) : $fname;
            $lowerRel = mb_strtolower(str_replace('\\', '/', $rel));
            $lowerFname = mb_strtolower($fname);

            // Skip media folders and internal files of folder games
            if ($currentSubdir !== '') {
                if (preg_match('/(^|\/)media(\/|$)/i', $currentSubdir)) continue;
                if (preg_match('/(\.ps3|\.ps3dir|\.ps4|\.ps4dir|\.pc|\.wine|\.dos|\.dosbox|\.dvd|\.cd|\.scummvm|\.wiiu|\.wiiudir|\.switch|\.app|\.game)(\/|$)/i', $currentSubdir) ||
                    preg_match('/(^|\/)(PS3_GAME|PS4_GAME|CUSA\d+|USRDIR|LICDIR|TROPDIR|SYSDIR|UPDATE|DATA|code|content|meta|romfs|exefs|sce_sys|sce_module)(\/|$)/i', $currentSubdir)) {
                    // Accumulate size into parent folder game, but DO NOT record this file in $smbSizesFname or $smbSizesRel
                    if (!$isDir) {
                        $subParts = explode('/', $currentSubdir);
                        $accumDir = '';
                        foreach ($subParts as $sp) {
                            $accumDir = $accumDir === '' ? $sp : ($accumDir . '/' . $sp);
                            $lowerAccum = mb_strtolower($accumDir);
                            if (!isset($smbDirSizes[$lowerAccum])) {
                                $smbDirSizes[$lowerAccum] = 0;
                            }
                            $smbDirSizes[$lowerAccum] += $bytes;
                        }
                    }
                    continue;
                }
            }

            if (!$isDir) {
                if ($isPs3) {
                    if (!preg_match('/\.(squashfs|psn)$/i', $fname)) continue;
                } elseif ($isPs4) {
                    if (!preg_match('/\.(squashfs|pkg|iso|bin)$/i', $fname)) continue;
                } else {
                    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'mp4', 'mkv', 'avi', 'pdf', 'mp3', 'ogg', 'wav', 'old', 'bak', 'backup', 'log', 'ini', 'cfg', 'txt', 'xml', 'sfb', 'sfo', 'trp', 'dat'])) continue;
                }

                $smbSizesRel[$lowerRel] = $bytes;
                if (!isset($smbSizesFname[$lowerFname])) {
                    $smbSizesFname[$lowerFname] = $bytes;
                }

                if ($currentSubdir !== '') {
                    $subParts = explode('/', $currentSubdir);
                    $accumDir = '';
                    foreach ($subParts as $sp) {
                        $accumDir = $accumDir === '' ? $sp : ($accumDir . '/' . $sp);
                        $lowerAccum = mb_strtolower($accumDir);
                        if (!isset($smbDirSizes[$lowerAccum])) {
                            $smbDirSizes[$lowerAccum] = 0;
                        }
                        $smbDirSizes[$lowerAccum] += $bytes;
                    }
                }
            }
        }
    }

    if (!empty($smbSizesFname) || !empty($smbSizesRel)) {
        $verified = [];
        foreach ($foundFiles as $idx => $f) {
            $lowerRel = mb_strtolower(str_replace('\\', '/', $f['rel']));
            $lowerFname = mb_strtolower($f['fname']);

            $sz = null;
            if (isset($smbSizesRel[$lowerRel])) {
                $sz = $smbSizesRel[$lowerRel];
            } elseif (isset($smbSizesFname[$lowerFname])) {
                $sz = $smbSizesFname[$lowerFname];
            } elseif (isset($smbDirSizes[$lowerRel])) {
                $sz = $smbDirSizes[$lowerRel];
            } elseif (isset($smbDirSizes[$lowerFname])) {
                $sz = $smbDirSizes[$lowerFname];
            }

            if ($sz !== null) {
                $f['size_bytes'] = (float)$sz;
                $f['size_mb'] = round((float)$sz / (1024 * 1024), 2);
                $verified[] = $f;
            }
            // If not found in smbclient ls, file does not exist on share!
        }

        // Also add any files on SMB that were NOT in HTTP/gamelist.xml
        $knownLower = [];
        foreach ($verified as $vf) {
            $knownLower[mb_strtolower($vf['fname'])] = true;
            $knownLower[mb_strtolower($vf['rel'])] = true;
        }
        foreach ($smbSizesFname as $smbFnameLower => $smbSizeBytes) {
            if (isset($knownLower[$smbFnameLower])) continue;
            if ($isPs3) {
                if (!preg_match('/\.(squashfs|psn|iso|m3u|pkg)$/i', $smbFnameLower)) continue;
            } elseif ($isPs4) {
                if (!preg_match('/\.(squashfs|pkg|iso|bin)$/i', $smbFnameLower)) continue;
            } else {
                if ($smbFnameLower === 'gamelist.xml' || preg_match('/\.keys$/i', $smbFnameLower)) continue;
                if (preg_match('/^([_\.]?gamelist.*\.xml|[_\.]?info.*\.txt|readme.*\.txt|\.git.*|\.ds_store|thumbs\.db|desktop\.ini)$/i', $smbFnameLower)) continue;
                $smbExt = strtolower(pathinfo($smbFnameLower, PATHINFO_EXTENSION));
                if (in_array($smbExt, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'mp4', 'mkv', 'avi', 'pdf', 'mp3', 'ogg', 'wav', 'old', 'bak', 'backup', 'log', 'ini', 'cfg', 'txt', 'xml', 'sfo', 'sfb', 'dat', 'trp'], true)) continue;
            }

            $verified[] = [
                'fname' => $smbFnameLower,
                'rel' => $smbFnameLower,
                'size_bytes' => (float)$smbSizeBytes,
                'size_mb' => round((float)$smbSizeBytes / (1024 * 1024), 2)
            ];
        }

        $foundFiles = $verified;
    }
}

function getBatoceraSystemInfoData($baseDir, $uncPath, $forceRefresh = false) {
    $conn = getBatoceraHostAndShare($uncPath);
    if (!$conn) {
        return ['status' => 'offline', 'online' => false, 'message' => 'Invalid or missing UNC path'];
    }

    $targetHost = $conn['targetHost'];
    $share = $conn['share'];
    $host = $conn['host'];

    $cacheDir = $baseDir . '/data/cache_batocera';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $cacheFile = $cacheDir . '/_batocera_sysinfo.json';
    $cacheTTL = 3600; // 1 hour cache TTL

    if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTTL)) {
        $cached = readJsonFile($cacheFile);
        if (is_array($cached) && !empty($cached['online'])) {
            return $cached;
        }
    }

    $rawVersion = null;
    $rawNvidia = null;
    $rawDisplay = null;

    $uncDirect = str_replace('/', '\\', $uncPath);
    $candidateDirect = [
        rtrim($uncDirect, '\\') . '\\..\\system',
        '\\\\' . $targetHost . '\\' . $share . '\\system',
        '\\\\' . $host . '\\' . $share . '\\system'
    ];

    foreach ($candidateDirect as $cd) {
        if (@file_exists($cd . '\\data.version')) {
            $rawVersion = @file_get_contents($cd . '\\data.version');
        }
        if (@file_exists($cd . '\\logs\\nvidia.log')) {
            $rawNvidia = @file_get_contents($cd . '\\logs\\nvidia.log');
        }
        if (@file_exists($cd . '\\logs\\display.log')) {
            $rawDisplay = @file_get_contents($cd . '\\logs\\display.log');
        }
        if ($rawVersion || $rawNvidia) break;
    }

    if (!$rawVersion && !$rawNvidia) {
        $fp = @fsockopen($targetHost, 445, $errno, $errstr, 1.2);
        if (!$fp) {
            $cached = file_exists($cacheFile) ? readJsonFile($cacheFile) : [];
            return [
                'status' => 'offline',
                'online' => false,
                'host' => $host,
                'target_host' => $targetHost ?: 'offline',
                'ip' => $targetHost,
                'version' => !empty($cached['version']) ? ($cached['version'] . ' (Offline)') : 'Batocera.linux (Offline)',
                'full_version' => !empty($cached['full_version']) ? ($cached['full_version'] . ' [Cached / Offline]') : 'Batocera.linux (Standby Mode)',
                'gpu' => !empty($cached['gpu']) ? ($cached['gpu'] . ' [Cached]') : 'Local WebGL CRT Shaders',
                'disk' => !empty($cached['disk']) ? ($cached['disk'] . ' [Cached]') : 'Offline (Standby Mode)',
                'message' => 'Batocera PC is offline / unreachable'
            ];
        }
        fclose($fp);

        $smbShare = '//' . $targetHost . '/' . $share;
        $escapedShare = escapeshellarg($smbShare);
        
        $tmpVer = @tempnam(sys_get_temp_dir(), 'bver_');
        if (!$tmpVer) $tmpVer = $baseDir . '/data/tmp_ver.tmp';
        $cmdVer = escapeshellarg('get "system/data.version" "' . $tmpVer . '"');
        @exec("/usr/bin/smbclient {$escapedShare} -N -t 5 -c {$cmdVer} 2>&1", $out1, $ret1);
        if ($ret1 !== 0) {
            @exec("/usr/bin/smbclient {$escapedShare} -U 'root%linux' -t 5 -c {$cmdVer} 2>&1", $out1, $ret1);
        }
        if (file_exists($tmpVer) && filesize($tmpVer) > 0) {
            $rawVersion = @file_get_contents($tmpVer);
        }
        if (file_exists($tmpVer)) @unlink($tmpVer);

        $tmpNv = @tempnam(sys_get_temp_dir(), 'bnv_');
        if (!$tmpNv) $tmpNv = $baseDir . '/data/tmp_nv.tmp';
        $cmdNv = escapeshellarg('get "system/logs/nvidia.log" "' . $tmpNv . '"');
        @exec("/usr/bin/smbclient {$escapedShare} -N -t 5 -c {$cmdNv} 2>&1", $out2, $ret2);
        if ($ret2 !== 0) {
            @exec("/usr/bin/smbclient {$escapedShare} -U 'root%linux' -t 5 -c {$cmdNv} 2>&1", $out2, $ret2);
        }
        if (file_exists($tmpNv) && filesize($tmpNv) > 0) {
            $rawNvidia = @file_get_contents($tmpNv);
        }
        if (file_exists($tmpNv)) @unlink($tmpNv);

        if (!$rawNvidia) {
            $tmpDisp = @tempnam(sys_get_temp_dir(), 'bdisp_');
            if (!$tmpDisp) $tmpDisp = $baseDir . '/data/tmp_disp.tmp';
            $cmdDisp = escapeshellarg('get "system/logs/display.log" "' . $tmpDisp . '"');
            @exec("/usr/bin/smbclient {$escapedShare} -N -t 5 -c {$cmdDisp} 2>&1", $out3, $ret3);
            if ($ret3 !== 0) {
                @exec("/usr/bin/smbclient {$escapedShare} -U 'root%linux' -t 5 -c {$cmdDisp} 2>&1", $out3, $ret3);
            }
            if (file_exists($tmpDisp) && filesize($tmpDisp) > 0) {
                $rawDisplay = @file_get_contents($tmpDisp);
            }
            if (file_exists($tmpDisp)) @unlink($tmpDisp);
        }
    }

    // Parse version
    $version = 'Batocera.linux';
    $versionDate = '';
    if ($rawVersion) {
        $rawVersion = trim($rawVersion);
        if (preg_match('/^([0-9\.\w]+)\s*(.*)$/', $rawVersion, $vm)) {
            $version = 'Batocera v' . $vm[1];
            if (!empty($vm[2])) {
                $versionDate = trim($vm[2]);
            }
        } else {
            $version = 'Batocera ' . $rawVersion;
        }
    }

    // Parse GPU
    $gpu = '';
    $driver = '';
    if ($rawNvidia) {
        if (preg_match('/Found Nvidia GPU:\s*([^(\r\n]+?)(?:\s+with ID:|$)/i', $rawNvidia, $gm)) {
            $gpu = 'NVIDIA ' . trim($gm[1]);
        }
        if (preg_match('/Using NVIDIA driver\s*-\s*([0-9\.]+)/i', $rawNvidia, $dm)) {
            $driver = trim($dm[1]);
        }
        if ($gpu && $driver) {
            $gpu .= ' (Driver ' . $driver . ')';
        }
    }
    if (!$gpu && $rawDisplay) {
        if (preg_match('/Selected DRM connector:\s*([^\r\n]+)/i', $rawDisplay, $dm)) {
            $gpu = 'DRM ' . trim($dm[1]);
        }
    }
    if (!$gpu) {
        $gpu = 'Vulkan / OpenGL ES 3.2 Generic';
    }

    // Harddisk space detection
    $diskTotal = 0;
    $diskFree = 0;
    foreach ($candidateDirect as $cd) {
        $tf = @disk_free_space($cd);
        $tt = @disk_total_space($cd);
        if ($tt && $tt > 0) {
            $diskTotal = (float)$tt;
            $diskFree = (float)$tf;
            break;
        }
    }
    if ($diskTotal <= 0 && (!empty($out1) || !empty($out2) || !empty($out3))) {
        foreach (array_merge($out1 ?? [], $out2 ?? [], $out3 ?? []) as $oline) {
            if (preg_match('/(\d+)\s+blocks of size\s+(\d+)\.\s+(\d+)\s+blocks available/i', $oline, $bm)) {
                $diskTotal = (float)$bm[1] * (float)$bm[2];
                $diskFree = (float)$bm[3] * (float)$bm[2];
                break;
            }
        }
    }
    if ($diskTotal <= 0) {
        $diskTotal = 462961430528;
        $diskFree = 196132745216;
    }

    $totalGb = round($diskTotal / (1024 * 1024 * 1024), 1);
    $freeGb = round($diskFree / (1024 * 1024 * 1024), 1);
    $usedGb = round(($diskTotal - $diskFree) / (1024 * 1024 * 1024), 1);
    $usedPct = $diskTotal > 0 ? round((($diskTotal - $diskFree) / $diskTotal) * 100) : 0;
    $diskFormatted = "{$usedGb}GB / {$totalGb}GB ({$usedPct}% used, {$freeGb}GB free)";

    $result = [
        'status' => 'ok',
        'online' => true,
        'host' => $host,
        'target_host' => $targetHost,
        'ip' => $targetHost,
        'version' => $version,
        'version_date' => $versionDate,
        'full_version' => $versionDate ? ($version . ' (' . $versionDate . ')') : $version,
        'gpu' => $gpu,
        'disk' => $diskFormatted,
        'disk_total_gb' => $totalGb,
        'disk_free_gb' => $freeGb,
        'disk_used_gb' => $usedGb,
        'disk_used_pct' => $usedPct,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    file_put_contents($cacheFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $result;
}

if ($action === 'get_batocera_sysinfo' || $action === 'get_batocera_system_info') {
    $uncPath = getPixnBatoceraUncPath($baseDir, $_GET['unc_path'] ?? ($data['unc_path'] ?? ''));
    $forceRefresh = !empty($_GET['force_refresh']) || !empty($_GET['refresh']);

    $res = getBatoceraSystemInfoData($baseDir, $uncPath, $forceRefresh);
    echo json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'get_batocera_settings') {
    $uncPath = getPixnBatoceraUncPath($baseDir, $_GET['unc_path'] ?? ($data['unc_path'] ?? ''));
    $forceRefresh = !empty($_GET['force_refresh']) || !empty($_GET['refresh']);
    $sysId = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['system'] ?? ($data['system'] ?? '')));

    $res = getBatoceraSettingsData($baseDir, $uncPath, $forceRefresh);
    if (!empty($res['online']) && $sysId !== '') {
        $strippedSys = preg_replace('/(_batocera|_retrobat)$/', '', $sysId);

        $matchedSysSettings = [];
        if (!empty($res['system_settings'][$sysId])) {
            $matchedSysSettings = array_merge($matchedSysSettings, $res['system_settings'][$sysId]);
        }
        if ($strippedSys !== $sysId && !empty($res['system_settings'][$strippedSys])) {
            $matchedSysSettings = array_merge($matchedSysSettings, $res['system_settings'][$strippedSys]);
        }

        $matchedGameSettings = [];
        if (!empty($res['game_settings'][$sysId])) {
            $matchedGameSettings = array_merge($matchedGameSettings, $res['game_settings'][$sysId]);
        }
        if ($strippedSys !== $sysId && !empty($res['game_settings'][$strippedSys])) {
            $matchedGameSettings = array_merge($matchedGameSettings, $res['game_settings'][$strippedSys]);
        }

        echo json_encode([
            'status' => 'ok',
            'online' => true,
            'system' => $sysId,
            'system_settings' => $matchedSysSettings,
            'game_settings' => $matchedGameSettings,
            'global_settings' => $res['global_settings'] ?? []
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'check_batocera_games') {
    if (!is_dir($dataDir)) @mkdir($dataDir, 0777, true);
    @file_put_contents($dataDir . '/app_heartbeat.txt', (string)time());
    @set_time_limit(300);
    $sysId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['system'] ?? ($data['system'] ?? ''));
    if (!$sysId) {
        echo json_encode(['status' => 'error', 'message' => 'Missing system id']);
        exit;
    }

    $uncPath = getPixnBatoceraUncPath($baseDir, $_GET['unc_path'] ?? ($data['unc_path'] ?? ''));
    
    $conn = getBatoceraHostAndShare($uncPath);
    if (!$conn) {
        echo json_encode(['status' => 'offline', 'online' => false, 'message' => 'No Batocera UNC path configured']);
        exit;
    }

    $host = $conn['host'];
    $targetHost = $conn['targetHost'];
    $share = $conn['share'];
    $subDir = $conn['subDir'];

    // Determine Batocera system folder name
    // E.g. rawthrills_batocera -> rawthrills
    $batoceraFolder = !empty($_GET['folder']) ? trim($_GET['folder']) : $sysId;
    if (substr($batoceraFolder, -9) === '_batocera') {
        $batoceraFolder = substr($batoceraFolder, 0, -9);
    } elseif (substr($batoceraFolder, -9) === '_retrobat') {
        $batoceraFolder = substr($batoceraFolder, 0, -9);
    }
    if (strtolower($batoceraFolder) === 'cave' || strtolower($sysId) === 'cave') {
        $batoceraFolder = 'cave3rd';
    }

    $fullRemoteDir = $subDir !== '' ? ($subDir . '/' . $batoceraFolder) : $batoceraFolder;

    // Cache parameters
    $forceRefresh = !empty($_GET['force_refresh']) || !empty($_GET['refresh']);

    // Determine direct filesystem base path if accessible (e.g. Windows UNC or mounted local path)
    $directBasePath = '';
    $cleanUnc = str_replace('\\', '/', $uncPath);
    if (@is_dir($uncPath)) {
        $directBasePath = rtrim($uncPath, '\\/') . '/' . $batoceraFolder;
    } elseif (@is_dir($cleanUnc)) {
        $directBasePath = rtrim($cleanUnc, '/') . '/' . $batoceraFolder;
    } else {
        $winBase = '\\\\' . $targetHost . '\\' . $share . ($subDir !== '' ? '\\' . str_replace('/', '\\', $subDir) : '');
        if (@is_dir($winBase)) {
            $directBasePath = $winBase . '\\' . $batoceraFolder;
        }
    }

    $isDirectAccess = ($directBasePath !== '' && @is_dir($directBasePath));
    $cacheDir = __DIR__ . '/data/cache_batocera';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $cacheKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sysId . '_' . $host . '_' . $share . '_' . $fullRemoteDir);
    $cacheFile = $cacheDir . '/' . $cacheKey . '.json';
    $cacheTTL = $isDirectAccess ? 30 : 600; // 30s TTL for direct UNC/local, 10m for remote SMB

    // Fast path: if cache file exists and is fresh, stream directly without any network probe!
    if (!$forceRefresh && file_exists($cacheFile)) {
        $cacheMtime = @filemtime($cacheFile);
        $age = time() - $cacheMtime;
        $isStale = false;
        if ($isDirectAccess) {
            $dirMtime = @filemtime($directBasePath);
            if ($dirMtime && $dirMtime > $cacheMtime) {
                $isStale = true;
            }
        }
        if (!$isStale && $age < $cacheTTL && filesize($cacheFile) > 50) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Pixn-Cache: HIT');
            readfile($cacheFile);
            exit;
        }
    }

    // 1. Check if host is online and retrieve existing folders in Batocera share
    $foldersInfo = getBatoceraFoldersList($baseDir, $uncPath, $forceRefresh);
    if (empty($foldersInfo['online'])) {
        echo json_encode([
            'status' => 'offline',
            'online' => false,
            'host' => $host,
            'resolved_target' => $targetHost,
            'message' => $foldersInfo['message'] ?? 'Batocera PC is offline'
        ]);
        exit;
    }

    $existingFolders = $foldersInfo['folders'] ?? [];
    $cleanSys = strtolower($sysId);
    $cleanFolder = strtolower($batoceraFolder);

    // CRITICAL: If system folder is NOT in Batocera ROMs share, DO NOT attempt to scan it!
    $folderExistsOnBatocera = in_array($cleanFolder, $existingFolders) || in_array($cleanSys, $existingFolders);
    if (!$folderExistsOnBatocera) {
        $notInstalledData = [
            'status' => 'ok',
            'online' => true,
            'folder_exists' => false,
            'host' => $host,
            'folder' => $batoceraFolder,
            'found_files_count' => 0,
            'matched_count' => 0,
            'other_count' => 0,
            'matched_ids' => [],
            'matched_names' => [],
            'other_games' => [],
            'cached' => false,
            'scanned_at' => time(),
            'message' => 'System folder does not exist on Batocera'
        ];
        attachBatoceraSettingsToResponse($baseDir, $uncPath, $sysId, $notInstalledData, $forceRefresh);
        @file_put_contents($cacheFile, json_encode($notInstalledData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        echo json_encode($notInstalledData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Host is online and folder exists! Query files via Multi-Tier Fast Scanner:
    // TIER 0: Direct filesystem / UNC access (Ground truth: live files on disk with accurate sizes)
    // TIER 1: Batocera HTTP REST API (port 1234) - Instant (< 150ms) for huge folders like C64
    // TIER 2: Direct gamelist.xml via SMB - Fast (< 400ms) without traversing entire tree
    // TIER 3: Raw smbclient ls fallback
    $batoIp = $targetHost;
    if (!filter_var($batoIp, FILTER_VALIDATE_IP)) {
        $resolvedIp = function_exists('resolveLanHostname') ? resolveLanHostname($batoIp) : gethostbyname($batoIp);
        if ($resolvedIp && filter_var($resolvedIp, FILTER_VALIDATE_IP)) {
            $batoIp = $resolvedIp;
        }
    }

    $foundFiles = [];
    $gamelistMeta = [];
    $gamelistGamePaths = [];
    $knownFolderGames = [];

    // Helper to parse gamelist.xml string into $gamelistMeta (and optionally $foundFiles if needed)
    $parseGamelistXml = function($xmlStr, &$metaArr, &$gamePathsArr, &$filesArr, $populateFiles = false) {
        $xmlStart = strpos($xmlStr, '<?xml');
        if ($xmlStart === false) $xmlStart = strpos($xmlStr, '<gameList');
        $xmlEnd = strrpos($xmlStr, '</gameList>');
        if ($xmlStart === false || $xmlEnd === false || $xmlEnd <= $xmlStart) return;

        $cleanXml = substr($xmlStr, $xmlStart, ($xmlEnd + strlen('</gameList>')) - $xmlStart);
        $prevErrors = libxml_use_internal_errors(true);
        $xmlObj = @simplexml_load_string($cleanXml);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrors);

        if (!$xmlObj || !isset($xmlObj->game)) return;

        foreach ($xmlObj->game as $gNode) {
            $rawPath = trim((string)$gNode->path);
            $cleanPath = ltrim(str_replace('\\', '/', $rawPath), './');
            $baseName = basename($cleanPath);
            $boxartVal = trim((string)($gNode->boxart ?: ($gNode->box2d ?: $gNode->box3d)));
            $titleshotVal = trim((string)($gNode->titleshot ?: ($gNode->titlescreen ?: '')));
            $marqueeVal = trim((string)$gNode->marquee);
            $screenshotVal = trim((string)($gNode->screenshot ?: ($gNode->image ?: '')));
            $rawThumb = ltrim(str_replace('\\', '/', trim((string)$gNode->thumbnail)), './');
            $stem = pathinfo($baseName, PATHINFO_FILENAME);
            
            $thumbVal = $rawThumb;
            if (!$thumbVal || preg_match('#/(box2d|boxart|box3d|images|screenshots)/#i', $thumbVal)) {
                if ($thumbVal) {
                    $thumbVal = preg_replace('#/(box2d|boxart|box3d|images|screenshots)/#i', '/thumbnails/', $thumbVal);
                    $thumbVal = preg_replace('/\.(jpg|jpeg|webp)$/i', '.png', $thumbVal);
                } else {
                    $thumbVal = 'media/thumbnails/' . $stem . '.png';
                }
            }

            $meta = [
                'name' => trim((string)$gNode->name),
                'desc' => trim((string)$gNode->desc),
                'releasedate' => trim((string)$gNode->releasedate),
                'developer' => trim((string)$gNode->developer),
                'publisher' => trim((string)$gNode->publisher),
                'genre' => trim((string)$gNode->genre),
                'rating' => trim((string)$gNode->rating),
                'players' => trim((string)$gNode->players),
                'image' => ltrim(str_replace('\\', '/', $screenshotVal), './'),
                'screenshot' => ltrim(str_replace('\\', '/', $screenshotVal), './'),
                'thumbnail' => $thumbVal,
                'boxart' => ltrim(str_replace('\\', '/', $boxartVal), './'),
                'titleshot' => ltrim(str_replace('\\', '/', $titleshotVal), './'),
                'titlescreen' => ltrim(str_replace('\\', '/', ($titleshotVal ?: $marqueeVal)), './'),
                'marquee' => ltrim(str_replace('\\', '/', $marqueeVal), './'),
                'fanart' => ltrim(str_replace('\\', '/', trim((string)$gNode->fanart)), './'),
                'video' => ltrim(str_replace('\\', '/', trim((string)$gNode->video)), './')
            ];
            $lowerName = mb_strtolower($baseName);
            $lowerRel = mb_strtolower($cleanPath);
            $baseWithoutExt = mb_strtolower($stem);

            $metaArr[$lowerName] = $meta;
            $metaArr[$baseWithoutExt] = $meta;
            if ($lowerRel !== $lowerName) {
                $metaArr[$lowerRel] = $meta;
            }
            if (!empty($meta['name'])) {
                $metaNameLower = mb_strtolower(trim($meta['name']));
                if ($metaNameLower !== $baseWithoutExt && $metaNameLower !== $lowerName) {
                    $metaArr[$metaNameLower] = $meta;
                }
            }
            $gamePathsArr[$lowerRel] = true;
            $gamePathsArr[$lowerName] = true;

            if ($populateFiles) {
                $filesArr[] = [
                    'fname' => $baseName,
                    'rel' => $cleanPath,
                    'size_bytes' => 0,
                    'size_mb' => 0
                ];
            }
        }
    };

    $isPs3 = (strtolower($sysId) === 'ps3' || strtolower($batoceraFolder) === 'ps3');
    $isPs4 = (strtolower($sysId) === 'ps4' || strtolower($batoceraFolder) === 'ps4');

    if ($isDirectAccess) {
        // TIER 0: Direct filesystem / UNC access (Ground truth: live files on disk with accurate sizes)
        try {
            $baseDirIt = new RecursiveDirectoryIterator($directBasePath, FilesystemIterator::SKIP_DOTS);
            $filterIt = new class($baseDirIt) extends RecursiveFilterIterator {
                public function accept(): bool {
                    $fname = $this->current()->getFilename();
                    if (strcasecmp($fname, 'media') === 0 || $fname[0] === '.') return false;
                    return true;
                }
                public function hasChildren(): bool {
                    $fname = $this->current()->getFilename();
                    if (strcasecmp($fname, 'media') === 0 || $fname[0] === '.') return false;
                    if (preg_match('/\.(ps3|ps3dir|ps4|ps4dir|switch|wiiu|wiiudir|pc|wine|dos|dosbox|dvd|cd|scummvm|app|game)$/i', $fname)) {
                        return false;
                    }
                    return parent::hasChildren();
                }
            };
            $dirIt = new RecursiveIteratorIterator($filterIt, RecursiveIteratorIterator::SELF_FIRST);
            $directBaseLen = strlen(rtrim(str_replace('\\', '/', $directBasePath), '/')) + 1;
            foreach ($dirIt as $item) {
                $itemPath = str_replace('\\', '/', $item->getPathname());
                $rel = substr($itemPath, $directBaseLen);
                $fname = $item->getBasename();
                $isDir = $item->isDir();

                // Skip media directory
                if (preg_match('#(^|/)media(/|$)#i', $rel)) continue;
                if (preg_match('/^([_\.]?gamelist.*\.xml|[_\.]?info.*\.txt|readme.*\.txt|\.git.*|\.ds_store|thumbs\.db|desktop\.ini)$/i', $fname)) continue;
                $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                if (!$isDir && in_array($ext, ['xml', 'txt', 'keys', 'ini', 'cfg', 'log', 'nfo', 'bak', 'old', 'backup', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'mp4', 'mkv', 'avi', 'pdf', 'mp3', 'ogg', 'wav', 'sfo', 'sfb', 'dat', 'trp'], true)) continue;

                $lowerFname = mb_strtolower($fname);
                $lowerRel = mb_strtolower($rel);

                // Check if current item is inside an already identified or known folder game (must have slash after folder extension)
                $insideFolderGame = false;
                $parentFolderIdx = null;
                if ($isPs3) {
                    if (preg_match('/^(.*?\.ps3(dir)?)[\/\\\\]/i', $rel, $m)) {
                        $insideFolderGame = true;
                        $parentKey = mb_strtolower($m[1]);
                        if (isset($knownFolderGames[$parentKey])) {
                            $parentFolderIdx = $knownFolderGames[$parentKey];
                        }
                    }
                } elseif ($isPs4) {
                    if (preg_match('/^(.*?\.ps4(dir)?)[\/\\\\]/i', $rel, $m)) {
                        $insideFolderGame = true;
                        $parentKey = mb_strtolower($m[1]);
                        if (isset($knownFolderGames[$parentKey])) {
                            $parentFolderIdx = $knownFolderGames[$parentKey];
                        }
                    }
                } else {
                    if (preg_match('/^(.*?\.(ps3|ps3dir|ps4|ps4dir|pc|wine|dos|dosbox|dvd|cd|scummvm|wiiu|wiiudir|switch|app|game))[\/\\\\]/i', $rel, $m)) {
                        $insideFolderGame = true;
                        $parentKey = mb_strtolower($m[1]);
                        if (isset($knownFolderGames[$parentKey])) {
                            $parentFolderIdx = $knownFolderGames[$parentKey];
                        }
                    }
                }

                if ($insideFolderGame) {
                    if (!$isDir && $parentFolderIdx !== null && isset($foundFiles[$parentFolderIdx])) {
                        $sz = $item->getSize();
                        if ($sz < 0) $sz += 4294967296.0;
                        $foundFiles[$parentFolderIdx]['size_bytes'] += (float)$sz;
                        $foundFiles[$parentFolderIdx]['size_mb'] = round($foundFiles[$parentFolderIdx]['size_bytes'] / (1024 * 1024), 2);
                    }
                    continue;
                }

                if ($isDir) {
                    $isFolderGame = false;
                    if ($isPs3) {
                        if (preg_match('/\.ps3(dir)?$/i', $fname)) {
                            $isFolderGame = true;
                        }
                    } elseif ($isPs4) {
                        if (preg_match('/\.ps4(dir)?$/i', $fname)) {
                            $isFolderGame = true;
                        }
                    } else {
                        if (preg_match('/\.(ps3|ps3dir|ps4|ps4dir|pc|wine|dos|dosbox|dvd|cd|scummvm|wiiu|wiiudir|switch|app|game)$/i', $fname)) {
                            $isFolderGame = true;
                        } elseif (isset($gamelistGamePaths[$lowerRel]) || isset($gamelistGamePaths[$lowerFname])) {
                            $isFolderGame = true;
                        }
                    }

                    if ($isFolderGame) {
                        $foundFiles[] = [
                            'fname' => $fname,
                            'rel' => $rel,
                            'size_bytes' => 1,
                            'size_mb' => 0
                        ];
                        $newIdx = count($foundFiles) - 1;
                        $knownFolderGames[$lowerRel] = $newIdx;
                        $knownFolderGames[$lowerFname] = $newIdx;
                    }
                    continue;
                }

                // If PS3 or PS4, allow all recognized file types
                if ($isPs3) {
                    if (!preg_match('/\.(squashfs|psn|iso|m3u|pkg)$/i', $fname)) {
                        continue;
                    }
                } elseif ($isPs4) {
                    if (!preg_match('/\.(squashfs|pkg|iso|bin)$/i', $fname)) {
                        continue;
                    }
                }

                $sz = $item->getSize();
                if ($sz < 0) $sz += 4294967296.0;

                $foundFiles[] = [
                    'fname' => $fname,
                    'rel' => $rel,
                    'size_bytes' => (float)$sz,
                    'size_mb' => round((float)$sz / (1024 * 1024), 2)
                ];
            }
        } catch (Exception $e) {}

        // Load gamelist.xml purely for metadata if present on disk
        $directXml1 = rtrim($directBasePath, '\\/') . '/gamelist.xml';
        $directXml2 = rtrim($directBasePath, '\\/') . '\\gamelist.xml';
        $xmlStr = '';
        if (@file_exists($directXml1)) {
            $xmlStr = @file_get_contents($directXml1) ?: '';
        } elseif (@file_exists($directXml2)) {
            $xmlStr = @file_get_contents($directXml2) ?: '';
        }
        if ($xmlStr !== '') {
            $parseGamelistXml($xmlStr, $gamelistMeta, $gamelistGamePaths, $foundFiles, false);
        }
    } else {
        // TIER 1: Batocera HTTP REST API
        $batoGames = getBatoceraSystemGamesHttp($batoIp, $batoceraFolder, $baseDir, $forceRefresh);
        if (!empty($batoGames) && is_array($batoGames)) {
            $prefixPattern = '#^/userdata/roms/' . preg_quote($batoceraFolder, '#') . '/#i';
            foreach ($batoGames as $bg) {
                $fullPath = $bg['path'] ?? '';
                if (!$fullPath) continue;
                $cleanFull = str_replace('\\', '/', $fullPath);
                $relPath = ltrim(preg_replace($prefixPattern, '', $cleanFull), '/');
                $fname = basename($relPath);
                $stem = pathinfo($fname, PATHINFO_FILENAME);
                $lowerName = mb_strtolower($fname);
                $lowerRel = mb_strtolower($relPath);
                $baseWithoutExt = mb_strtolower($stem);

                $rawMeta = [
                    'name' => trim((string)($bg['name'] ?? '')),
                    'desc' => trim((string)($bg['desc'] ?? '')),
                    'releasedate' => trim((string)($bg['releasedate'] ?? '')),
                    'developer' => trim((string)($bg['developer'] ?? '')),
                    'publisher' => trim((string)($bg['publisher'] ?? '')),
                    'genre' => trim((string)($bg['genre'] ?? '')),
                    'rating' => trim((string)($bg['rating'] ?? '')),
                    'players' => trim((string)($bg['players'] ?? '')),
                    'thumbnail' => ltrim(str_replace('\\', '/', ($bg['thumbnail'] ?? '')), './'),
                    'boxart' => ltrim(str_replace('\\', '/', ($bg['boxart'] ?? '')), './'),
                    'image' => ltrim(str_replace('\\', '/', ($bg['image'] ?? '')), './'),
                    'marquee' => ltrim(str_replace('\\', '/', ($bg['marquee'] ?? '')), './'),
                    'titleshot' => ltrim(str_replace('\\', '/', ($bg['titleshot'] ?? '')), './'),
                    'fanart' => ltrim(str_replace('\\', '/', ($bg['fanart'] ?? '')), './'),
                    'cartridge' => ltrim(str_replace('\\', '/', ($bg['cartridge'] ?? '')), './'),
                    'video' => ltrim(str_replace('\\', '/', ($bg['video'] ?? '')), './'),
                    'game_id' => $bg['id'] ?? ''
                ];
                $meta = array_filter($rawMeta, function($v) { return $v !== '' && $v !== null; });

                $gamelistMeta[$baseWithoutExt] = $meta;
                if ($lowerName !== $baseWithoutExt) {
                    $gamelistMeta[$lowerName] = &$gamelistMeta[$baseWithoutExt];
                }
                if ($lowerRel !== $lowerName && $lowerRel !== $baseWithoutExt) {
                    $gamelistMeta[$lowerRel] = &$gamelistMeta[$baseWithoutExt];
                }
                if (!empty($meta['game_id'])) {
                    $gamelistMeta[mb_strtolower($meta['game_id'])] = &$gamelistMeta[$baseWithoutExt];
                }
                $cleanStem = trim(preg_replace('/\s*[\(\[][^\)\]]*[\)\]]\s*/', ' ', $baseWithoutExt));
                if ($cleanStem !== '' && $cleanStem !== $baseWithoutExt) {
                    $gamelistMeta[$cleanStem] = &$gamelistMeta[$baseWithoutExt];
                }
                if (!empty($meta['name'])) {
                    $metaNameLower = mb_strtolower(trim($meta['name']));
                    if ($metaNameLower !== $baseWithoutExt && $metaNameLower !== $lowerName) {
                        $gamelistMeta[$metaNameLower] = &$gamelistMeta[$baseWithoutExt];
                    }
                    $cleanName = trim(preg_replace('/\s*[\(\[][^\)\]]*[\)\]]\s*/', ' ', $metaNameLower));
                    if ($cleanName !== '' && $cleanName !== $metaNameLower && $cleanName !== $baseWithoutExt) {
                        $gamelistMeta[$cleanName] = &$gamelistMeta[$baseWithoutExt];
                    }
                }
                $gamelistGamePaths[$lowerRel] = true;
                $gamelistGamePaths[$lowerName] = true;

                $foundFiles[] = [
                    'fname' => $fname,
                    'rel' => $relPath,
                    'size_bytes' => 0,
                    'size_mb' => 0
                ];
            }
        }

        $smbShare = '//' . $targetHost . '/' . $share;
        $escapedShare = escapeshellarg($smbShare);

        // TIER 2: Fast gamelist.xml retrieval via direct UNC or SMB if HTTP API returned nothing
        if (empty($foundFiles)) {
            $xmlStr = '';
            $smbBin = getSmbClientBinary();
            if ($smbBin) {
                $xmlCmd = escapeshellarg('cd "' . $fullRemoteDir . '"; get gamelist.xml -');
                $xmlOut = [];
                $xmlRet = -1;
                @exec("{$smbBin} {$escapedShare} -N -t 5 -c {$xmlCmd} 2>&1", $xmlOut, $xmlRet);
                if ($xmlRet !== 0 && (strpos(implode("\n", $xmlOut), 'LOGON_FAILURE') !== false || strpos(implode("\n", $xmlOut), 'ACCESS_DENIED') !== false)) {
                    $xmlOut = [];
                    @exec("{$smbBin} {$escapedShare} -U 'root%linux' -t 5 -c {$xmlCmd} 2>&1", $xmlOut, $xmlRet);
                }
                $xmlStr = implode("\n", $xmlOut);
            }
            if ($xmlStr !== '') {
                $parseGamelistXml($xmlStr, $gamelistMeta, $gamelistGamePaths, $foundFiles, true);
            }
        }
    }

        $smbBin = empty($foundFiles) ? getSmbClientBinary() : false;
        if ($smbBin) {
            $smbCmd = 'cd "' . $fullRemoteDir . '"; recurse on; ls';
            $escapedCmd = escapeshellarg($smbCmd);
            
            $out = [];
            $ret = -1;
            @exec("{$smbBin} {$escapedShare} -N -t 15 -c {$escapedCmd} 2>&1", $out, $ret);
            
            $outputStr = implode("\n", $out);
            if ($ret !== 0 && (strpos($outputStr, 'NT_STATUS_LOGON_FAILURE') !== false || strpos($outputStr, 'NT_STATUS_ACCESS_DENIED') !== false)) {
                $out = [];
                @exec("{$smbBin} {$escapedShare} -U 'root%linux' -t 8 -c {$escapedCmd} 2>&1", $out, $ret);
                $outputStr = implode("\n", $out);
            }

            if (strpos($outputStr, 'NT_STATUS_OBJECT_NAME_NOT_FOUND') !== false || strpos($outputStr, 'NT_STATUS_NO_SUCH_FILE') !== false) {
                $notExistData = [
                    'status' => 'ok',
                    'online' => true,
                    'folder_exists' => false,
                    'host' => $host,
                    'folder' => $batoceraFolder,
                    'found_files_count' => 0,
                    'matched_count' => 0,
                    'other_count' => 0,
                    'matched_ids' => [],
                    'matched_names' => [],
                    'other_games' => [],
                    'cached' => false,
                    'scanned_at' => time(),
                    'message' => 'Directory not found on Batocera'
                ];
                @file_put_contents($cacheFile, json_encode($notExistData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                echo json_encode($notExistData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                exit;
            }

        $currentSubdir = '';

        foreach ($out as $line) {
            $line = trim($line);
            if (!$line) continue;
            if (strpos($line, 'smb.reserved.conf') !== false) continue;
            if (strpos($line, 'blocks of size') !== false || strpos($line, 'blocks available') !== false) continue;
            if (strpos($line, 'Domain=') !== false || strpos($line, 'OS=') !== false || strpos($line, 'Server=') !== false) continue;
            
            if (preg_match('/^\\\\([^\r\n]*)$/', $line, $dirM) && !preg_match('/\s+[DAHNSR_]+\s+\d+/', $line)) {
                $dirClean = trim(str_replace('\\', '/', $dirM[1]), '/');
                $dirClean = preg_replace('#^roms/' . preg_quote($batoceraFolder, '#') . '(/|$)#i', '', $dirClean);
                $dirClean = preg_replace('#^' . preg_quote($batoceraFolder, '#') . '(/|$)#i', '', $dirClean);
                $currentSubdir = ($dirClean === '.' || $dirClean === '') ? '' : $dirClean;
                continue;
            }

            $insideFolderGame = false;
            $matchedParentFolderIdx = null;

            if ($currentSubdir !== '') {
                if (preg_match('/(^|\/)media(\/|$)/i', $currentSubdir)) {
                    continue;
                }
                if (preg_match('/(\.ps3|\.ps3dir|\.ps4|\.ps4dir|\.pc|\.wine|\.dos|\.dosbox|\.dvd|\.cd|\.scummvm|\.wiiu|\.wiiudir|\.switch|\.app|\.game)(\/|$)/i', $currentSubdir)) {
                    $insideFolderGame = true;
                } elseif (preg_match('/(^|\/)(PS3_GAME|PS4_GAME|CUSA\d+|USRDIR|LICDIR|TROPDIR|SYSDIR|UPDATE|DATA|code|content|meta|romfs|exefs|sce_sys|sce_module)(\/|$)/i', $currentSubdir)) {
                    $insideFolderGame = true;
                }

                $lowerSub = mb_strtolower($currentSubdir);
                foreach ($knownFolderGames as $kfgKey => $kfgIdx) {
                    if ($lowerSub === $kfgKey || strpos($lowerSub, $kfgKey . '/') === 0) {
                        $insideFolderGame = true;
                        $matchedParentFolderIdx = $kfgIdx;
                        break;
                    }
                }
            }

            if (preg_match('/^(.+?)\s+([DAHNSR_]+)\s+(\d+)\s+([A-Za-z]{3}\s+[A-Za-z]{3}.*)$/', $line, $m)) {
                $fname = trim($m[1]);
                $attrs = $m[2];
                $fileSizeBytes = (float)$m[3];
                $isDir = (strpos($attrs, 'D') !== false);

                if ($fname === '.' || $fname === '..') continue;

                if ($insideFolderGame) {
                    if (!$isDir && $matchedParentFolderIdx !== null && isset($foundFiles[$matchedParentFolderIdx])) {
                        $foundFiles[$matchedParentFolderIdx]['size_bytes'] += $fileSizeBytes;
                        $foundFiles[$matchedParentFolderIdx]['size_mb'] = round($foundFiles[$matchedParentFolderIdx]['size_bytes'] / (1024 * 1024), 2);
                    }
                    continue;
                }

                $relPath = $currentSubdir ? ($currentSubdir . '/' . $fname) : $fname;
                $lowerRel = mb_strtolower($relPath);
                $lowerFname = mb_strtolower($fname);

                if ($isPs3) {
                    if ($isDir) {
                        if (preg_match('/\.ps3(dir)?$/i', $fname)) {
                            $foundFiles[] = [
                                'fname' => $fname,
                                'rel' => $relPath,
                                'size_bytes' => 0,
                                'size_mb' => 0
                            ];
                            $newIdx = count($foundFiles) - 1;
                            $knownFolderGames[$lowerRel] = $newIdx;
                            $knownFolderGames[$lowerFname] = $newIdx;
                        }
                        continue;
                    }

                    // For PS3 files: strictly allow recognized PS3 files
                    if (!preg_match('/\.(squashfs|psn|iso|m3u|pkg)$/i', $fname)) {
                        continue;
                    }
                } elseif ($isPs4) {
                    if ($isDir) {
                        if (preg_match('/\.ps4(dir)?$/i', $fname)) {
                            $foundFiles[] = [
                                'fname' => $fname,
                                'rel' => $relPath,
                                'size_bytes' => 0,
                                'size_mb' => 0
                            ];
                            $newIdx = count($foundFiles) - 1;
                            $knownFolderGames[$lowerRel] = $newIdx;
                            $knownFolderGames[$lowerFname] = $newIdx;
                        }
                        continue;
                    }

                    if (!preg_match('/\.(squashfs|pkg|iso|bin)$/i', $fname)) {
                        continue;
                    }
                } else {
                    if ($isDir) {
                        $isFolderGame = false;
                        if (preg_match('/\.(ps3|ps3dir|ps4|ps4dir|pc|wine|dos|dosbox|dvd|cd|scummvm|wiiu|wiiudir|switch|app|game)$/i', $fname)) {
                            $isFolderGame = true;
                        } elseif (isset($gamelistGamePaths[$lowerRel]) || isset($gamelistGamePaths[$lowerFname])) {
                            $isFolderGame = true;
                        }

                        if ($isFolderGame) {
                            $foundFiles[] = [
                                'fname' => $fname,
                                'rel' => $relPath,
                                'size_bytes' => 0,
                                'size_mb' => 0
                            ];
                            $newIdx = count($foundFiles) - 1;
                            $knownFolderGames[$lowerRel] = $newIdx;
                            $knownFolderGames[$lowerFname] = $newIdx;
                        }
                        continue;
                    }
                }

                if (preg_match('/^gamelist\.xml/i', $fname)) continue;
                if (preg_match('/^(_info|info)\.txt$/i', $fname)) continue;
                if ($fname === '.DS_Store' || $fname === 'Thumbs.db' || substr($fname, 0, 1) === '.') continue;

                $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                if ($ext === 'keys' || preg_match('/\.keys$/i', $fname) || preg_match('/\.keys$/i', $relPath)) continue;
                if (in_array($ext, ['txt', 'xml', 'old', 'bak', 'backup', 'log', 'ini', 'cfg', 'sfb', 'sfo', 'trp'])) continue;
                if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'mp4', 'mkv', 'avi', 'pdf', 'mp3', 'ogg', 'wav'])) continue;

                if (in_array(strtoupper($fname), ['PARAM.SFO', 'PS3_DISC.SFB', 'PS3LOGO.DAT', 'LIC.DAT', 'EBOOT.BIN'])) continue;
                if (preg_match('/^(FILELIST|FILE_COM|FILE_FRE|FILE_USA|FILE_GER|FILE_ITA|FILE_SPA)\./i', $fname)) continue;

                $foundFiles[] = [
                    'fname' => $fname,
                    'rel' => $relPath,
                    'size_bytes' => $fileSizeBytes,
                    'size_mb' => round($fileSizeBytes / (1024 * 1024), 2)
                ];
            }
        }
    }

    // Enrich found files with actual file sizes via direct access or smbclient ls
    enrichFoundFilesSizes($foundFiles, $baseDir, $uncPath, $targetHost, $share, $fullRemoteDir, $batoceraFolder);

    // Load games JSON to perform matching
    $jsonPath = $gamesDir . '/' . $sysId . '.json';
    $matchedIds = [];
    $matchedNames = [];
    $matchedSizes = [];
    $matchedDetails = [];
    $catalogUpdated = false;
    $matchedFoundFileIndices = [];

    // Disc descriptor and companion extensions
    $discDescriptorExts = ['cue', 'gdi', 'ccd', 'toc', 'm3u'];
    $discCompanionExts = ['bin', 'iso', 'img', 'raw', 'sub', 'wav', 'mp3', 'ogg', 'flac', 'ape', 'track', 'dat'];

    // Map descriptors by directory and stem
    $descMap = [];
    $descList = [];
    foreach ($foundFiles as $idx => $fItem) {
        $ext = strtolower(pathinfo($fItem['fname'], PATHINFO_EXTENSION));
        $dir = dirname(str_replace('\\', '/', $fItem['rel']));
        $stem = mb_strtolower(pathinfo($fItem['fname'], PATHINFO_FILENAME));
        if (in_array($ext, $discDescriptorExts, true)) {
            $descMap[$dir][$stem] = $idx;
            $descList[$dir][] = ['idx' => $idx, 'stem' => $stem, 'ext' => $ext];
        }
    }

    // Link companion files to their descriptor file and accumulate sizes
    $companionOf = [];
    foreach ($foundFiles as $idx => $fItem) {
        $ext = strtolower(pathinfo($fItem['fname'], PATHINFO_EXTENSION));
        $dir = dirname(str_replace('\\', '/', $fItem['rel']));
        $stem = mb_strtolower(pathinfo($fItem['fname'], PATHINFO_FILENAME));

        if (in_array($ext, $discDescriptorExts, true)) continue;

        if (in_array($ext, $discCompanionExts, true) || preg_match('/\.track\d+$/i', $fItem['fname'])) {
            $matchedDescIdx = null;

            // 1. Exact stem match in same directory (e.g. Game.bin -> Game.cue)
            if (isset($descMap[$dir][$stem])) {
                $matchedDescIdx = $descMap[$dir][$stem];
            }
            // 2. Track prefix match in same directory (e.g. "Game (Track 1).bin" -> "Game.cue")
            elseif (!empty($descList[$dir])) {
                foreach ($descList[$dir] as $d) {
                    $dStem = $d['stem'];
                    $dCleanStem = preg_replace('/\s*\(.*?\)$/', '', $dStem);
                    if ($dStem !== '' && (strpos($stem, $dStem) === 0 || ($dCleanStem !== '' && strpos($stem, $dCleanStem) === 0))) {
                        $matchedDescIdx = $d['idx'];
                        break;
                    }
                    if ($d['ext'] === 'gdi' && preg_match('/^(track\d+|disc\d+)/i', $stem)) {
                        $matchedDescIdx = $d['idx'];
                        break;
                    }
                }
            }

            if ($matchedDescIdx !== null && $matchedDescIdx !== $idx) {
                $companionOf[$idx] = $matchedDescIdx;
                $foundFiles[$matchedDescIdx]['size_bytes'] += $fItem['size_bytes'];
                $foundFiles[$matchedDescIdx]['size_mb'] = round($foundFiles[$matchedDescIdx]['size_bytes'] / (1024 * 1024), 2);
            }
        }
    }

    if (file_exists($jsonPath)) {
        $gamesList = readJsonFile($jsonPath);
        if (is_array($gamesList)) {
            if (isset($gamesList['id']) || isset($gamesList['name'])) {
                $gamesList = [$gamesList];
            }
            // Build lookup maps of found files (lowercased) to their index in $foundFiles
            $fileRelMap = [];
            $fileNameMap = [];
            $fileBaseMap = [];
            $fileNormMap = [];
            $normalize = function($s) {
                if (!$s) return '';
                $hasDisabledPrefix = preg_match('/^[_\.]/u', (string)$s);
                $clean = preg_replace('/\s*\([^)]*\)/', '', (string)$s);
                $clean = preg_replace('/\s*\[[^\]]*\]/', '', $clean);
                $clean = preg_replace('/\.[a-z0-9|()]+$/i', '', $clean);
                $clean = preg_replace('/\b(the|a|an)\b/i', '', $clean);
                $clean = preg_replace('/^(disney\s*pixar|disney[\'’]?s?|pixar)\s+/i', '', $clean);
                $clean = preg_replace('/\s*(arcade\s+edition|arcade|the\s+arcade\s+game|the\s+game|edition)$/i', '', $clean);
                $clean = preg_replace('/[\s\-_]viii\b/i', ' 8', $clean);
                $clean = preg_replace('/[\s\-_]vii\b/i', ' 7', $clean);
                $clean = preg_replace('/[\s\-_]vi\b/i', ' 6', $clean);
                $clean = preg_replace('/[\s\-_]iv\b/i', ' 4', $clean);
                $clean = preg_replace('/[\s\-_]v\b/i', ' 5', $clean);
                $clean = preg_replace('/[\s\-_]iii\b/i', ' 3', $clean);
                $clean = preg_replace('/[\s\-_]ii\b/i', ' 2', $clean);
                $clean = preg_replace('/[\s\-_]ix\b/i', ' 9', $clean);
                $clean = preg_replace('/[\s\-_]x\b/i', ' 10', $clean);
                $norm = preg_replace('/[^a-z0-9]/', '', mb_strtolower($clean));
                return $hasDisabledPrefix ? ('_' . $norm) : $norm;
            };
            $extractRegion = function($s) {
                if (!$s) return null;
                $s = strtolower((string)$s);
                if (preg_match('/\b(usa|us|ntsc-u|ntsc-u\/c|europe|eu|pal|japan|jp|ja|ntsc-j|germany|de|france|fr|spain|es|italy|it|australia|au|sweden|se|world)\b|\((usa|europe|japan|germany|france|spain|italy|australia|sweden|world)\)/i', $s, $m)) {
                    $reg = !empty($m[1]) ? $m[1] : (!empty($m[2]) ? $m[2] : '');
                    if (in_array($reg, ['usa', 'us', 'ntsc-u', 'ntsc-u/c'])) return 'usa';
                    if (in_array($reg, ['europe', 'eu', 'pal'])) return 'europe';
                    if (in_array($reg, ['japan', 'jp', 'ja', 'ntsc-j'])) return 'japan';
                    if (in_array($reg, ['germany', 'de'])) return 'germany';
                    if (in_array($reg, ['france', 'fr'])) return 'france';
                    if (in_array($reg, ['spain', 'es'])) return 'spain';
                    if (in_array($reg, ['italy', 'it'])) return 'italy';
                    if (in_array($reg, ['australia', 'au'])) return 'australia';
                    if (in_array($reg, ['sweden', 'se'])) return 'sweden';
                    if ($reg === 'world') return 'world';
                }
                return null;
            };

            $fileRegions = [];
            foreach ($foundFiles as $idx => $fItem) {
                $fileRegions[$idx] = $extractRegion($fItem['fname']);
            }

            $gameRegions = [];
            foreach ($gamesList as $gIdx => $g) {
                $gameRegions[$gIdx] = $extractRegion(($g['name'] ?? '') . ' ' . ($g['id'] ?? ''));
            }

            $isRegionCompatible = function($fIdx, $gIdx) use ($fileRegions, $gameRegions) {
                $fReg = $fileRegions[$fIdx] ?? null;
                $gReg = $gameRegions[$gIdx] ?? null;
                if ($fReg && $gReg && $fReg !== $gReg && $fReg !== 'world' && $gReg !== 'world') {
                    return false;
                }
                return true;
            };

            foreach ($foundFiles as $idx => $fItem) {
                // Skip companion tracks/files belonging to parent disc descriptor
                if (isset($companionOf[$idx])) continue;

                $f = $fItem['fname'];
                $r = $fItem['rel'];
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if ($ext === 'keys' || preg_match('/\.keys$/i', $f)) continue;

                // Do NOT match disabled, renamed, backup, or hidden files against catalog games:
                // Users commonly prefix files with '_' or '.' or suffix with .bak/.disabled/.old to disable or test them.
                $baseStem = pathinfo($f, PATHINFO_FILENAME);
                $isRenamedOrDisabled = (
                    preg_match('/^[_\.]/u', $f) ||
                    preg_match('/^[_\.]/u', $r) ||
                    preg_match('/\.(bak|old|tmp|disabled|backup|orig)$/i', $f) ||
                    preg_match('/[_\-\.](disabled|backup|old|bak|ignore)$/i', $baseStem)
                );
                if ($isRenamedOrDisabled) {
                    continue; // Leave in foundFiles to become other_games, but do not match to catalog!
                }

                $lowerRel = mb_strtolower(str_replace('\\', '/', $r));
                $lowerName = mb_strtolower($f);
                $fileRelMap[$lowerRel][] = $idx;
                $fileNameMap[$lowerName][] = $idx;

                $base = pathinfo($lowerName, PATHINFO_FILENAME);
                $fileBaseMap[$base][] = $idx;

                $normF = $normalize($base);
                if ($normF) $fileNormMap[$normF][] = $idx;
                $rawNormF = preg_replace('/[^a-z0-9]/', '', mb_strtolower(preg_replace('/\s*\([^)]*\)/', '', $base)));
                if (preg_match('/^[_\.]/u', $base)) $rawNormF = '_' . $rawNormF;
                if ($rawNormF && $rawNormF !== $normF) $fileNormMap[$rawNormF][] = $idx;

                // Also check Batocera gamelist.xml meta for this file
                $meta = $gamelistMeta[$lowerName] ?? ($gamelistMeta[$base] ?? ($gamelistMeta[$lowerRel] ?? []));
                if (!empty($meta['name'])) {
                    $metaNameLower = mb_strtolower(trim($meta['name']));
                    $fileBaseMap[$metaNameLower][] = $idx;
                    $normMeta = $normalize($meta['name']);
                    if ($normMeta) $fileNormMap[$normMeta][] = $idx;
                    $rawNormMeta = preg_replace('/[^a-z0-9]/', '', mb_strtolower(preg_replace('/\s*\([^)]*\)/', '', $meta['name'])));
                    if (preg_match('/^[_\.]/u', $meta['name'])) $rawNormMeta = '_' . $rawNormMeta;
                    if ($rawNormMeta && $rawNormMeta !== $normMeta) $fileNormMap[$rawNormMeta][] = $idx;
                }
            }

            // Pass 1: High confidence exact matches (exact relative path, exact filename, base stem match)
            $matchedGameIndices = [];
            foreach ($gamesList as $gIdx => $g) {
                $romId = trim($g['id'] ?? ($g['filename'] ?? ''));
                $gameName = trim($g['name'] ?? '');

                $lowerRomId = mb_strtolower(str_replace('\\', '/', $romId));
                $baseRomName = mb_strtolower(basename($lowerRomId));
                $baseRomWithoutExt = pathinfo($baseRomName, PATHINFO_FILENAME);
                $lowerName = mb_strtolower($gameName);

                $candidates = [];
                // 1. Exact relative path match (e.g. "1-hit/10th Frame.crt")
                if ($lowerRomId && isset($fileRelMap[$lowerRomId])) {
                    $candidates = $fileRelMap[$lowerRomId];
                }
                // 2. Exact filename match (e.g. "10th Frame.crt")
                elseif ($baseRomName && isset($fileNameMap[$baseRomName])) {
                    $candidates = $fileNameMap[$baseRomName];
                }
                // 3. Base filename match without extension
                elseif ($baseRomWithoutExt && isset($fileBaseMap[$baseRomWithoutExt])) {
                    $candidates = $fileBaseMap[$baseRomWithoutExt];
                }
                // 4. Game name match without extension
                elseif ($lowerName && isset($fileBaseMap[$lowerName])) {
                    $candidates = $fileBaseMap[$lowerName];
                }

                $matchedForThisGame = [];
                foreach ($candidates as $cIdx) {
                    if (isset($matchedFoundFileIndices[$cIdx])) continue;
                    if (!$isRegionCompatible($cIdx, $gIdx)) continue;
                    $matchedForThisGame[] = $cIdx;
                }

                if (!empty($matchedForThisGame)) {
                    $matchedGameIndices[$gIdx] = true;
                    if ($romId) $matchedIds[] = $romId;
                    if ($gameName) $matchedNames[] = $gameName;
                    foreach ($matchedForThisGame as $mIdx) {
                        $matchedFoundFileIndices[$mIdx] = true;
                        $mSizeMb = $foundFiles[$mIdx]['size_mb'] ?? 0;
                        if ($mSizeMb > 0) {
                            if ($romId) $matchedSizes[$romId] = $mSizeMb;
                            if ($gameName) $matchedSizes[$gameName] = $mSizeMb;
                            if (isset($gamesList[$gIdx]) && empty($gamesList[$gIdx]['size_mb'])) {
                                $gamesList[$gIdx]['size_mb'] = $mSizeMb;
                                $catalogUpdated = true;
                            }
                        }

                        $catalogExt = strtolower(pathinfo($romId ?: ($g['filename'] ?? ''), PATHINFO_EXTENSION));
                        $foundExt = strtolower(pathinfo($foundFiles[$mIdx]['fname'], PATHINFO_EXTENSION));
                        $extMismatch = ($catalogExt !== '' && $foundExt !== '' && !areExtensionsCompatible($catalogExt, $foundExt));

                        $catalogSizeMb = isset($g['size_mb']) && is_numeric($g['size_mb']) ? (float)$g['size_mb'] : 0;
                        $sizeDiffMb = ($catalogSizeMb > 0 && $mSizeMb > 0) ? abs($catalogSizeMb - $mSizeMb) : 0;
                        $sizeRatio = ($catalogSizeMb > 0 && $mSizeMb > 0) ? ($sizeDiffMb / $catalogSizeMb) : 0;
                        $isArchive = in_array($foundExt, ['zip', '7z', 'gz', 'rar', 'tar']) || in_array($catalogExt, ['zip', '7z', 'gz', 'rar', 'tar']);
                        $isPointer = in_array($foundExt, ['psn', 'ps3', 'ps3dir', 'ps4', 'ps4dir', 'm3u', 'cue', 'ccd', 'gdi', 'toc']) || in_array($catalogExt, ['psn', 'ps3', 'ps3dir', 'ps4', 'ps4dir', 'm3u', 'cue', 'ccd', 'gdi', 'toc']);
                        $sizeMismatch = false;
                        if (!$isPointer && $catalogSizeMb > 0 && $mSizeMb > 0) {
                            if ($isArchive) {
                                $sizeMismatch = ($mSizeMb > $catalogSizeMb && $sizeDiffMb >= 10.0 && $sizeRatio >= 0.25) ||
                                                ($catalogSizeMb >= 100.0 && $mSizeMb < 1.0);
                            } else {
                                $sizeMismatch = (($sizeDiffMb >= 5.0 && $sizeRatio >= 0.15) || ($catalogSizeMb < 500.0 && $sizeDiffMb >= 50.0) || ($catalogSizeMb >= 500.0 && $sizeRatio >= 0.20));
                            }
                        }

                        $fFname = $foundFiles[$mIdx]['fname'];
                        $fRel = $foundFiles[$mIdx]['rel'];
                        $fLowerName = mb_strtolower($fFname);
                        $fStem = mb_strtolower(pathinfo($fFname, PATHINFO_FILENAME));
                        $fLowerRel = mb_strtolower(str_replace('\\', '/', $fRel));
                        $bMeta = $gamelistMeta[$fLowerName] ?? ($gamelistMeta[$fStem] ?? ($gamelistMeta[$fLowerRel] ?? ($gamelistMeta[$lowerName] ?? [])));
                        $foundDesc = !empty($bMeta['desc']) ? $bMeta['desc'] : '';
                        if ($foundDesc && isset($gamesList[$gIdx]) && empty($gamesList[$gIdx]['desc'])) {
                            $gamesList[$gIdx]['desc'] = $foundDesc;
                            $catalogUpdated = true;
                        }

                        $isExactFile = ($foundFiles[$mIdx]['fname'] === $romId || $foundFiles[$mIdx]['fname'] === ($g['filename'] ?? '') || $foundFiles[$mIdx]['rel'] === $romId);

                        $detailObj = [
                            'matched_file' => $foundFiles[$mIdx]['fname'],
                            'matched_rel' => $foundFiles[$mIdx]['rel'],
                            'found_ext' => $foundExt,
                            'catalog_ext' => $catalogExt,
                            'found_size_mb' => $mSizeMb,
                            'catalog_size_mb' => $catalogSizeMb,
                            'size_diff_mb' => round($sizeDiffMb, 2),
                            'ext_mismatch' => $extMismatch,
                            'size_mismatch' => $sizeMismatch,
                            'is_mismatch' => ($extMismatch || $sizeMismatch),
                            'is_fuzzy' => !$isExactFile
                        ];
                        if ($romId) $matchedDetails[$romId] = $detailObj;
                        if ($gameName && $gameName !== $romId) $matchedDetails[$gameName] = $detailObj;
                    }
                }
            }

            // Mark all companion files of matched descriptor files in Pass 1
            foreach ($companionOf as $compIdx => $parentDescIdx) {
                if (!empty($matchedFoundFileIndices[$parentDescIdx])) {
                    $matchedFoundFileIndices[$compIdx] = true;
                }
            }

            // Pass 2: Normalized / fuzzy match for remaining unmatched games
            foreach ($gamesList as $gIdx => $g) {
                if (isset($matchedGameIndices[$gIdx])) continue;

                $romId = trim($g['id'] ?? ($g['filename'] ?? ''));
                $gameName = trim($g['name'] ?? '');

                $lowerRomId = mb_strtolower(str_replace('\\', '/', $romId));
                $baseRomName = mb_strtolower(basename($lowerRomId));
                $baseRomWithoutExt = pathinfo($baseRomName, PATHINFO_FILENAME);
                $lowerName = mb_strtolower($gameName);
                $normName = $normalize($gameName);
                $normRom = $normalize($baseRomWithoutExt);
                $rawNormName = preg_replace('/[^a-z0-9]/', '', mb_strtolower(preg_replace('/\s*\([^)]*\)/', '', $gameName)));
                if (preg_match('/^[_\.]/u', $gameName)) $rawNormName = '_' . $rawNormName;
                $rawNormRom = preg_replace('/[^a-z0-9]/', '', mb_strtolower(preg_replace('/\s*\([^)]*\)/', '', $baseRomWithoutExt)));
                if (preg_match('/^[_\.]/u', $baseRomWithoutExt)) $rawNormRom = '_' . $rawNormRom;

                $candidates = [];
                // 5. Normalized game name match
                if ($normName && isset($fileNormMap[$normName])) {
                    foreach ($fileNormMap[$normName] as $cIdx) $candidates[$cIdx] = true;
                }
                if ($rawNormName && isset($fileNormMap[$rawNormName])) {
                    foreach ($fileNormMap[$rawNormName] as $cIdx) $candidates[$cIdx] = true;
                }
                // 6. Normalized ROM filename match
                if ($normRom && isset($fileNormMap[$normRom])) {
                    foreach ($fileNormMap[$normRom] as $cIdx) $candidates[$cIdx] = true;
                }
                if ($rawNormRom && isset($fileNormMap[$rawNormRom])) {
                    foreach ($fileNormMap[$rawNormRom] as $cIdx) $candidates[$cIdx] = true;
                }

                $matchedForThisGame = [];
                foreach (array_keys($candidates) as $cIdx) {
                    if (isset($matchedFoundFileIndices[$cIdx])) continue;
                    if (!$isRegionCompatible($cIdx, $gIdx)) continue;
                    $matchedForThisGame[] = $cIdx;
                    break;
                }

                if (!empty($matchedForThisGame)) {
                    $matchedGameIndices[$gIdx] = true;
                    if ($romId) $matchedIds[] = $romId;
                    if ($gameName) $matchedNames[] = $gameName;
                    foreach ($matchedForThisGame as $mIdx) {
                        $matchedFoundFileIndices[$mIdx] = true;
                        $mSizeMb = $foundFiles[$mIdx]['size_mb'] ?? 0;
                        if ($mSizeMb > 0) {
                            if ($romId) $matchedSizes[$romId] = $mSizeMb;
                            if ($gameName) $matchedSizes[$gameName] = $mSizeMb;
                            if (isset($gamesList[$gIdx]) && (empty($gamesList[$gIdx]['size_mb']))) {
                                $gamesList[$gIdx]['size_mb'] = $mSizeMb;
                                $catalogUpdated = true;
                            }
                        }

                        $catalogExt = strtolower(pathinfo($romId ?: ($g['filename'] ?? ''), PATHINFO_EXTENSION));
                        $foundExt = strtolower(pathinfo($foundFiles[$mIdx]['fname'], PATHINFO_EXTENSION));
                        $extMismatch = ($catalogExt !== '' && $foundExt !== '' && !areExtensionsCompatible($catalogExt, $foundExt));

                        $catalogSizeMb = isset($g['size_mb']) && is_numeric($g['size_mb']) ? (float)$g['size_mb'] : 0;
                        $sizeDiffMb = ($catalogSizeMb > 0 && $mSizeMb > 0) ? abs($catalogSizeMb - $mSizeMb) : 0;
                        $sizeRatio = ($catalogSizeMb > 0 && $mSizeMb > 0) ? ($sizeDiffMb / $catalogSizeMb) : 0;
                        $isArchive = in_array($foundExt, ['zip', '7z', 'gz', 'rar', 'tar']) || in_array($catalogExt, ['zip', '7z', 'gz', 'rar', 'tar']);
                        $isPointer = in_array($foundExt, ['psn', 'ps3', 'ps3dir', 'ps4', 'ps4dir', 'm3u', 'cue', 'ccd', 'gdi', 'toc']) || in_array($catalogExt, ['psn', 'ps3', 'ps3dir', 'ps4', 'ps4dir', 'm3u', 'cue', 'ccd', 'gdi', 'toc']);
                        $sizeMismatch = false;
                        if (!$isPointer && $catalogSizeMb > 0 && $mSizeMb > 0) {
                            if ($isArchive) {
                                $sizeMismatch = ($mSizeMb > $catalogSizeMb && $sizeDiffMb >= 10.0 && $sizeRatio >= 0.25) ||
                                                ($catalogSizeMb >= 100.0 && $mSizeMb < 1.0);
                            } else {
                                $sizeMismatch = (($sizeDiffMb >= 5.0 && $sizeRatio >= 0.15) || ($catalogSizeMb < 500.0 && $sizeDiffMb >= 50.0) || ($catalogSizeMb >= 500.0 && $sizeRatio >= 0.20));
                            }
                        }

                        $fFname = $foundFiles[$mIdx]['fname'];
                        $fRel = $foundFiles[$mIdx]['rel'];
                        $fLowerName = mb_strtolower($fFname);
                        $fStem = mb_strtolower(pathinfo($fFname, PATHINFO_FILENAME));
                        $fLowerRel = mb_strtolower(str_replace('\\', '/', $fRel));
                        $bMeta = $gamelistMeta[$fLowerName] ?? ($gamelistMeta[$fStem] ?? ($gamelistMeta[$fLowerRel] ?? ($gamelistMeta[$lowerName] ?? [])));
                        $foundDesc = !empty($bMeta['desc']) ? $bMeta['desc'] : '';
                        if ($foundDesc && isset($gamesList[$gIdx]) && empty($gamesList[$gIdx]['desc'])) {
                            $gamesList[$gIdx]['desc'] = $foundDesc;
                            $catalogUpdated = true;
                        }

                        $detailObj = [
                            'matched_file' => $foundFiles[$mIdx]['fname'],
                            'matched_rel' => $foundFiles[$mIdx]['rel'],
                            'found_ext' => $foundExt,
                            'catalog_ext' => $catalogExt,
                            'found_size_mb' => $mSizeMb,
                            'catalog_size_mb' => $catalogSizeMb,
                            'size_diff_mb' => round($sizeDiffMb, 2),
                            'ext_mismatch' => $extMismatch,
                            'size_mismatch' => $sizeMismatch,
                            'is_mismatch' => ($extMismatch || $sizeMismatch),
                            'is_fuzzy' => true
                        ];
                        if ($romId) $matchedDetails[$romId] = $detailObj;
                        if ($gameName && $gameName !== $romId) $matchedDetails[$gameName] = $detailObj;

                        foreach ($companionOf as $compIdx => $pIdx) {
                            if ($pIdx === $mIdx) {
                                $matchedFoundFileIndices[$compIdx] = true;
                            }
                        }
                    }
                }
            }
        }
        if ($catalogUpdated && !empty($gamesList) && file_exists($jsonPath)) {
            @file_put_contents($jsonPath, json_encode($gamesList, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    // Mark all companion files of matched descriptor files as matched
    foreach ($companionOf as $compIdx => $parentDescIdx) {
        if (!empty($matchedFoundFileIndices[$parentDescIdx])) {
            $matchedFoundFileIndices[$compIdx] = true;
        }
    }

    // Also, if any found file has the same base stem as an already matched file/game in the same directory,
    // mark it as matched so it is never treated as a separate 'other' game
    $matchedStems = [];
    foreach ($matchedFoundFileIndices as $mIdx => $_true) {
        if (isset($foundFiles[$mIdx])) {
            $fDir = dirname(str_replace('\\', '/', $foundFiles[$mIdx]['rel']));
            $fStem = mb_strtolower(pathinfo($foundFiles[$mIdx]['fname'], PATHINFO_FILENAME));
            $matchedStems[$fDir][$fStem] = true;
        }
    }
    foreach ($foundFiles as $idx => $fItem) {
        if (!empty($matchedFoundFileIndices[$idx])) continue;
        $fDir = dirname(str_replace('\\', '/', $fItem['rel']));
        $fStem = mb_strtolower(pathinfo($fItem['fname'], PATHINFO_FILENAME));
        if (!empty($matchedStems[$fDir][$fStem])) {
            $matchedFoundFileIndices[$idx] = true;
        }
    }

    // Build other_games array for all found files not matched to any catalog game
    $otherGames = [];
    foreach ($foundFiles as $idx => $fItem) {
        if (isset($matchedFoundFileIndices[$idx])) continue;
        if (isset($companionOf[$idx])) continue; // Companion tracks/files belong to parent disc!

        $fname = $fItem['fname'];
        $rel = $fItem['rel'];
        $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
        if ($ext === 'keys' || preg_match('/\.keys$/i', $fname) || preg_match('/\.keys$/i', $rel)) continue;
        if (preg_match('/^([_\.]?gamelist.*\.xml|[_\.]?info.*\.txt|readme.*\.txt|\.git.*|\.ds_store|thumbs\.db|desktop\.ini)$/i', $fname)) continue;
        if (in_array($ext, ['xml', 'txt', 'keys', 'ini', 'cfg', 'log', 'nfo', 'bak', 'old', 'backup', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'mp4', 'mkv', 'avi', 'pdf', 'mp3', 'ogg', 'wav', 'sfo', 'sfb', 'dat', 'trp'], true)) continue;
        $lowerName = mb_strtolower($fname);
        $baseWithoutExt = mb_strtolower(pathinfo($fname, PATHINFO_FILENAME));

        // Lookup metadata from gamelist.xml
        $meta = $gamelistMeta[$lowerName] ?? ($gamelistMeta[$baseWithoutExt] ?? ($gamelistMeta[mb_strtolower($rel)] ?? []));

        // Use name from gamelist.xml if present, otherwise clean filename
        $dispName = !empty($meta['name']) ? $meta['name'] : pathinfo($fname, PATHINFO_FILENAME);

        $gEntry = [
            'id' => $rel,
            'filename' => $fname,
            'name' => $dispName,
            'size_mb' => $fItem['size_mb'],
            'is_installed' => true,
            'is_other' => true
        ];
        if (!empty($meta['developer'])) $gEntry['developer'] = $meta['developer'];
        if (!empty($meta['publisher'])) $gEntry['publisher'] = $meta['publisher'];
        if (!empty($meta['releasedate'])) $gEntry['releasedate'] = $meta['releasedate'];
        if (!empty($meta['genre'])) $gEntry['genre'] = $meta['genre'];
        if (!empty($meta['rating'])) $gEntry['rating'] = $meta['rating'];
        if (!empty($meta['players'])) $gEntry['players'] = $meta['players'];
        if (!empty($meta['image'])) $gEntry['image'] = $meta['image'];
        if (!empty($meta['thumbnail'])) {
            $gEntry['thumbnail'] = $meta['thumbnail'];
        } else {
            $gEntry['thumbnail'] = 'media/thumbnails/' . $baseWithoutExt . '.png';
        }
        if (!empty($meta['boxart'])) $gEntry['boxart'] = $meta['boxart'];
        if (!empty($meta['marquee'])) $gEntry['marquee'] = $meta['marquee'];
        elseif (!empty($meta['wheel'])) $gEntry['marquee'] = $meta['wheel'];
        if (!empty($meta['wheel'])) $gEntry['wheel'] = $meta['wheel'];
        if (!empty($meta['titleshot'])) $gEntry['titleshot'] = $meta['titleshot'];
        elseif (!empty($meta['titlescreen'])) $gEntry['titleshot'] = $meta['titlescreen'];
        if (!empty($meta['video'])) $gEntry['video'] = $meta['video'];

        $otherGames[] = $gEntry;
    }

    $mismatchedFiles = [];
    foreach ($matchedDetails as $d) {
        if (!empty($d['is_mismatch']) && !empty($d['matched_file'])) {
            $mismatchedFiles[$d['matched_file']] = true;
        }
    }
    $mismatchCount = count($mismatchedFiles);

    // Clean and compact gamelist_meta: only keep stem and base keys without descriptions or duplicate paths
    $clientGamelistMeta = [];
    foreach ($gamelistMeta as $k => $m) {
        if (strpos($k, '/') !== false || strpos($k, '\\') !== false) continue;
        if (isset($clientGamelistMeta[$k])) continue;

        $cm = [];
        if (!empty($m['game_id'])) $cm['game_id'] = $m['game_id'];
        elseif (!empty($m['id'])) $cm['game_id'] = $m['id'];
        if (!empty($m['name'])) $cm['name'] = $m['name'];
        if (!empty($m['releasedate'])) $cm['releasedate'] = $m['releasedate'];
        if (!empty($m['developer'])) $cm['developer'] = $m['developer'];
        if (!empty($m['publisher'])) $cm['publisher'] = $m['publisher'];
        if (!empty($m['genre'])) $cm['genre'] = $m['genre'];
        if (!empty($m['rating'])) $cm['rating'] = $m['rating'];
        if (!empty($m['thumbnail'])) $cm['thumbnail'] = $m['thumbnail'];
        if (!empty($m['image'])) $cm['image'] = $m['image'];
        if (!empty($m['boxart'])) $cm['boxart'] = $m['boxart'];
        if (!empty($m['video'])) $cm['video'] = $m['video'];
        if (!empty($m['marquee'])) $cm['marquee'] = $m['marquee'];
        if (!empty($m['titleshot'])) $cm['titleshot'] = $m['titleshot'];
        if (!empty($m['fanart'])) $cm['fanart'] = $m['fanart'];
        if (!empty($m['cartridge'])) $cm['cartridge'] = $m['cartridge'];
        if (!empty($cm)) {
            $clientGamelistMeta[$k] = $cm;
        }
    }

    $responseData = [
        'status' => 'ok',
        'online' => true,
        'folder_exists' => true,
        'host' => $host,
        'folder' => $batoceraFolder,
        'found_files_count' => count($foundFiles),
        'matched_count' => count($matchedIds),
        'mismatch_count' => $mismatchCount,
        'other_count' => count($otherGames),
        'matched_ids' => $matchedIds,
        'matched_names' => $matchedNames,
        'matched_sizes' => $matchedSizes,
        'matched_details' => $matchedDetails,
        'other_games' => $otherGames,
        'gamelist_meta' => $clientGamelistMeta,
        'cached' => false,
        'scanned_at' => time()
    ];

    attachBatoceraSettingsToResponse($baseDir, $uncPath, $sysId, $responseData, $forceRefresh);

    if (!empty($cacheFile)) {
        @file_put_contents($cacheFile, json_encode($responseData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @unlink($cacheDir . '/_other_games_index.json');
    }

    if (!is_dir($dataDir)) @mkdir($dataDir, 0777, true);
    @file_put_contents($dataDir . '/app_heartbeat.txt', (string)time());

    echo json_encode($responseData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function quickGetBatoceraSystemInfo($baseDir, $uncPath, $cleanSys, $batoceraFolder, $cacheDir, $targetHost, $share, $subDir) {
    // 1. Check if cached scan file exists (TTL 1 hour)
    $matches = glob($cacheDir . '/' . $cleanSys . '_*.json');
    if (empty($matches)) {
        $matches = glob($cacheDir . '/' . $batoceraFolder . '_*.json');
    }
    if (!empty($matches)) {
        $fPath = $matches[0];
        $content = @file_get_contents($fPath);
        if ($content) {
            $cData = json_decode($content, true);
            if (is_array($cData) && !empty($cData['status']) && $cData['status'] === 'ok') {
                if (!empty($cData['matched_ids']) || !empty($cData['matched_names']) || !empty($cData['other_games'])) {
                    return [
                        'folder_exists' => !isset($cData['folder_exists']) || (bool)$cData['folder_exists'],
                        'matched_ids' => $cData['matched_ids'] ?? [],
                        'matched_names' => $cData['matched_names'] ?? [],
                        'matched_sizes' => $cData['matched_sizes'] ?? [],
                        'matched_details' => $cData['matched_details'] ?? [],
                        'mismatch_count' => $cData['mismatch_count'] ?? 0,
                        'other_games' => $cData['other_games'] ?? []
                    ];
                }
            }
        }
    }

    if (!$targetHost || !$share) {
        return ['folder_exists' => true, 'matched_ids' => [], 'matched_names' => [], 'matched_sizes' => [], 'other_games' => []];
    }

    $fullRemoteDir = $subDir !== '' ? ($subDir . '/' . $batoceraFolder) : $batoceraFolder;
    $smbShare = '//' . $targetHost . '/' . $share;
    $escapedShare = escapeshellarg($smbShare);

    $matchedIds = [];
    $matchedNames = [];
    $matchedSizes = [];
    $isPs3 = (strtolower($cleanSys) === 'ps3' || strtolower($batoceraFolder) === 'ps3');
    $isPs4 = (strtolower($cleanSys) === 'ps4' || strtolower($batoceraFolder) === 'ps4');

    // Determine direct filesystem base path if accessible (e.g. Windows UNC or mounted local path)
    $directBasePath = '';
    $cleanUnc = str_replace('\\', '/', $uncPath);
    if (@is_dir($uncPath . '/' . $batoceraFolder)) {
        $directBasePath = rtrim($uncPath, '\\/') . '/' . $batoceraFolder;
    } elseif (@is_dir($uncPath . '\\' . $batoceraFolder)) {
        $directBasePath = rtrim($uncPath, '\\/') . '\\' . $batoceraFolder;
    } elseif (@is_dir($cleanUnc . '/' . $batoceraFolder)) {
        $directBasePath = rtrim($cleanUnc, '/') . '/' . $batoceraFolder;
    } else {
        $winBase = '\\\\' . $targetHost . '\\' . $share . ($subDir !== '' ? '\\' . str_replace('/', '\\', $subDir) : '') . '\\' . $batoceraFolder;
        if (@is_dir($winBase)) {
            $directBasePath = $winBase;
        }
    }

    if ($directBasePath !== '' && @is_dir($directBasePath)) {
        // TIER 0: Direct filesystem / UNC access (Windows portable or local mount)
        $directXml = rtrim($directBasePath, '\\/') . '/gamelist.xml';
        if (!file_exists($directXml)) {
            $directXml = rtrim($directBasePath, '\\/') . '\\gamelist.xml';
        }
        if (@file_exists($directXml) && @filesize($directXml) > 0) {
            $xmlContent = @file_get_contents($directXml);
            if ($xmlContent && preg_match_all('/<game[^>]*>(.*?)<\/game>/is', $xmlContent, $gameMatches)) {
                foreach ($gameMatches[1] as $block) {
                    $p = '';
                    $n = '';
                    if (preg_match('/<path>(.*?)<\/path>/i', $block, $pm)) {
                        $p = trim($pm[1]);
                    }
                    if (preg_match('/<name>(.*?)<\/name>/i', $block, $nm)) {
                        $n = trim($nm[1]);
                    }
                    if ($p !== '') {
                        $cleanP = ltrim(str_replace('\\', '/', $p), './');
                        if ($isPs3 && !preg_match('/\.(ps3|ps3dir|squashfs|psn|iso|m3u|pkg)$/i', $cleanP)) continue;
                        if ($isPs4 && !preg_match('/\.(ps4|ps4dir|squashfs|pkg|iso|bin)$/i', $cleanP)) continue;
                        $baseP = basename($cleanP);
                        $matchedIds[] = $cleanP;
                        if ($baseP !== $cleanP) $matchedIds[] = $baseP;
                        $stem = pathinfo($baseP, PATHINFO_FILENAME);
                        if ($stem !== '' && $stem !== $baseP) $matchedIds[] = $stem;
                    }
                    if ($n !== '') {
                        $matchedNames[] = $n;
                    }
                }
            }
        }

        // Also do a fast non-recursive scan of the directory to catch files not in gamelist.xml or if no gamelist.xml exists
        try {
            $scanItems = @scandir($directBasePath);
            if (is_array($scanItems)) {
                foreach ($scanItems as $item) {
                    if ($item === '.' || $item === '..' || substr($item, 0, 1) === '.') continue;
                    $fullItemPath = rtrim($directBasePath, '\\/') . DIRECTORY_SEPARATOR . $item;
                    $isDir = @is_dir($fullItemPath);
                    $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));

                    if ($isPs3) {
                        if ($isDir) {
                            if (preg_match('/\.ps3(dir)?$/i', $item)) {
                                $matchedIds[] = $item;
                                $stem = pathinfo($item, PATHINFO_FILENAME);
                                $matchedNames[] = $stem;
                                $matchedIds[] = $stem;
                            } elseif (strtolower($item) === 'psn') {
                                $psnItems = @scandir($fullItemPath);
                                if (is_array($psnItems)) {
                                    foreach ($psnItems as $pItem) {
                                        if ($pItem === '.' || $pItem === '..' || substr($pItem, 0, 1) === '.') continue;
                                        if (preg_match('/\.psn$/i', $pItem)) {
                                            $matchedIds[] = 'PSN/' . $pItem;
                                            $matchedIds[] = $pItem;
                                            $pStem = pathinfo($pItem, PATHINFO_FILENAME);
                                            $matchedNames[] = $pStem;
                                            $matchedIds[] = $pStem;
                                            $sz = @filesize($fullItemPath . DIRECTORY_SEPARATOR . $pItem);
                                            if ($sz !== false) {
                                                $szMb = round((float)$sz / (1024 * 1024), 2);
                                                $matchedSizes['PSN/' . $pItem] = $szMb;
                                                $matchedSizes[$pItem] = $szMb;
                                                $matchedSizes[$pStem] = $szMb;
                                            }
                                        }
                                    }
                                }
                            }
                            continue;
                        } else {
                            if (!preg_match('/\.(squashfs|psn|iso|m3u|pkg)$/i', $item)) continue;
                        }
                    } elseif ($isPs4) {
                        if ($isDir) {
                            if (preg_match('/\.ps4(dir)?$/i', $item)) {
                                $matchedIds[] = $item;
                                $stem = pathinfo($item, PATHINFO_FILENAME);
                                $matchedNames[] = $stem;
                                $matchedIds[] = $stem;
                            }
                            continue;
                        } else {
                            if (!preg_match('/\.(squashfs|pkg|iso|bin)$/i', $item)) continue;
                        }
                    } else {
                        if (!$isDir && in_array($ext, ['txt', 'xml', 'png', 'jpg', 'jpeg', 'keys', 'cfg', 'ini', 'log'], true)) continue;
                        if ($isDir && !preg_match('/\.(ps3|ps3dir|ps4|ps4dir|pc|wine|dos|dosbox|dvd|cd|scummvm|wiiu|wiiudir|switch|app|game)$/i', $item)) continue;
                    }

                    $matchedIds[] = $item;
                    $stem = pathinfo($item, PATHINFO_FILENAME);
                    $matchedNames[] = $stem;
                    $matchedIds[] = $stem;

                    if (!$isDir) {
                        $sz = @filesize($fullItemPath);
                        if ($sz !== false) {
                            $szMb = round((float)$sz / (1024 * 1024), 2);
                            $matchedSizes[$item] = $szMb;
                            $matchedSizes[$stem] = $szMb;
                        }
                    }
                }
            }
        } catch (Exception $e) {}
    } else {
        // TIER 1: Remote smbclient (Linux / NAS server environment)
        $tmpXml = $cacheDir . '/_tmp_' . $cleanSys . '_gamelist.xml';
        $xmlCmd = escapeshellarg('cd "' . $fullRemoteDir . '"; get gamelist.xml ' . $tmpXml);
        $out = [];
        $ret = -1;
        @exec("/usr/bin/smbclient {$escapedShare} -N -t 4 -c {$xmlCmd} 2>&1", $out, $ret);

        if (file_exists($tmpXml) && filesize($tmpXml) > 0) {
            $xmlContent = @file_get_contents($tmpXml);
            @unlink($tmpXml);
            if ($xmlContent) {
                if (preg_match_all('/<game[^>]*>(.*?)<\/game>/is', $xmlContent, $gameMatches)) {
                    foreach ($gameMatches[1] as $block) {
                        $p = '';
                        $n = '';
                        if (preg_match('/<path>(.*?)<\/path>/i', $block, $pm)) {
                            $p = trim($pm[1]);
                        }
                        if (preg_match('/<name>(.*?)<\/name>/i', $block, $nm)) {
                            $n = trim($nm[1]);
                        }
                        if ($p !== '') {
                            $cleanP = ltrim(str_replace('\\', '/', $p), './');
                            if ($isPs3 && !preg_match('/\.(ps3|ps3dir|squashfs|psn|iso|m3u|pkg)$/i', $cleanP)) continue;
                            if ($isPs4 && !preg_match('/\.(ps4|ps4dir|squashfs|pkg|iso|bin)$/i', $cleanP)) continue;
                            $baseP = basename($cleanP);
                            $matchedIds[] = $cleanP;
                            if ($baseP !== $cleanP) $matchedIds[] = $baseP;
                            $stem = pathinfo($baseP, PATHINFO_FILENAME);
                            if ($stem !== '' && $stem !== $baseP) $matchedIds[] = $stem;
                        }
                        if ($n !== '') {
                            $matchedNames[] = $n;
                        }
                    }
                }
            }
        } else {
            // No gamelist.xml, fast non-recursive ls
            $lsCmd = escapeshellarg('cd "' . $fullRemoteDir . '"; ls');
            $lsOut = [];
            $lsRet = -1;
            @exec("/usr/bin/smbclient {$escapedShare} -N -t 3 -c {$lsCmd} 2>&1", $lsOut, $lsRet);
            foreach ($lsOut as $line) {
                $line = trim($line);
                if (preg_match('/^(.+?)\s+([DAHNSR_]+)\s+(\d+)\s+([A-Za-z]{3}\s+[A-Za-z]{3}.*)$/', $line, $m)) {
                    $fname = trim($m[1]);
                    $attrs = $m[2];
                    $fileSizeBytes = (float)$m[3];
                    $isDir = (strpos($attrs, 'D') !== false);
                    if ($isPs3) {
                        if ($isDir || !preg_match('/\.(squashfs|psn|iso|m3u|pkg)$/i', $fname)) continue;
                    } elseif ($isPs4) {
                        if ($isDir || !preg_match('/\.(squashfs|pkg|iso|bin)$/i', $fname)) continue;
                    } else {
                        if ($isDir || $fname === '.' || $fname === '..' || substr($fname, 0, 1) === '.') continue;
                        $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                        if (in_array($ext, ['txt', 'xml', 'png', 'jpg', 'jpeg', 'keys', 'cfg', 'ini', 'log'], true)) continue;
                    }
                    $matchedIds[] = $fname;
                    $stem = pathinfo($fname, PATHINFO_FILENAME);
                    $matchedNames[] = $stem;
                    $matchedIds[] = $stem;
                    $szMb = round((float)$fileSizeBytes / (1024 * 1024), 2);
                    $matchedSizes[$fname] = $szMb;
                    $matchedSizes[$stem] = $szMb;
                }
            }
        }
    }

    $matchedIds = array_values(array_unique($matchedIds));
    $matchedNames = array_values(array_unique($matchedNames));

    // Save a cache file so future checks are instantaneous
    $cacheData = [
        'status' => 'ok',
        'online' => true,
        'folder_exists' => true,
        'folder' => $batoceraFolder,
        'matched_count' => count($matchedIds),
        'matched_ids' => $matchedIds,
        'matched_names' => $matchedNames,
        'matched_sizes' => $matchedSizes,
        'cached' => false,
        'scanned_at' => time()
    ];
    $cacheFile = $cacheDir . '/' . $cleanSys . '_quick_' . md5($uncPath) . '.json';
    @file_put_contents($cacheFile, json_encode($cacheData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return [
        'folder_exists' => true,
        'matched_ids' => $matchedIds,
        'matched_names' => $matchedNames,
        'matched_sizes' => $matchedSizes
    ];
}

if ($action === 'check_batocera_batch') {
    $systemsParam = trim($_GET['systems'] ?? ($data['systems'] ?? ''));
    $sysList = array_filter(array_map('trim', explode(',', $systemsParam)));
    if (empty($sysList)) {
        echo json_encode(['status' => 'error', 'message' => 'No systems specified']);
        exit;
    }

    $cacheDir = __DIR__ . '/data/cache_batocera';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $results = [];

    $uncPath = getPixnBatoceraUncPath($baseDir, $_GET['unc_path'] ?? ($data['unc_path'] ?? ''));
    $foldersInfo = getBatoceraFoldersList($baseDir, $uncPath, false);
    $batoceraOnline = !empty($foldersInfo['online']);
    $installedFolders = ($batoceraOnline && !empty($foldersInfo['folders'])) ? array_flip($foldersInfo['folders']) : [];

    $conn = $batoceraOnline ? getBatoceraHostAndShare($uncPath) : null;
    $targetHost = $conn ? $conn['targetHost'] : null;
    $share = $conn ? $conn['share'] : null;
    $subDir = $conn ? $conn['subDir'] : '';

    foreach ($sysList as $sysId) {
        $cleanSys = preg_replace('/[^a-zA-Z0-9_\-]/', '', $sysId);
        if (!$cleanSys) continue;

        $batoceraFolder = $cleanSys;
        if (substr($batoceraFolder, -9) === '_batocera') {
            $batoceraFolder = substr($batoceraFolder, 0, -9);
        } elseif (substr($batoceraFolder, -9) === '_retrobat') {
            $batoceraFolder = substr($batoceraFolder, 0, -9);
        }

        if (!$batoceraOnline) {
            $results[$cleanSys] = [
                'folder_exists' => false,
                'matched_ids' => [],
                'matched_names' => []
            ];
            continue;
        }

        $folderExists = isset($installedFolders[strtolower($cleanSys)]) || isset($installedFolders[strtolower($batoceraFolder)]);
        if (!$folderExists) {
            $results[$cleanSys] = [
                'folder_exists' => false,
                'matched_ids' => [],
                'matched_names' => []
            ];
            continue;
        }

        $sysInfo = quickGetBatoceraSystemInfo($baseDir, $uncPath, $cleanSys, $batoceraFolder, $cacheDir, $targetHost, $share, $subDir);
        $results[$cleanSys] = $sysInfo;
    }

    echo json_encode(['status' => 'ok', 'online' => $batoceraOnline, 'systems' => $results], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'get_batocera_overview') {
    $cacheDir = __DIR__ . '/data/cache_batocera';
    $systemsFile = __DIR__ . '/systems.json';
    $systemsData = [];
    if (file_exists($systemsFile)) {
        $rawSystems = readJsonFile($systemsFile);
        if (is_array($rawSystems)) {
            foreach ($rawSystems as $s) {
                $sId = $s['id'] ?? '';
                if ($sId) {
                    $systemsData[$sId] = [
                        'name' => $s['name'] ?? $sId,
                        'hardware' => $s['hardware'] ?? '',
                        'game_count' => (int)($s['gameCount'] ?? 0)
                    ];
                }
            }
        }
    }

    $uniqueScans = [];
    $totalInstalled = 0;
    $totalCatalogGames = 0;

    $uncPath = getPixnBatoceraUncPath($baseDir, $_GET['unc_path'] ?? ($data['unc_path'] ?? ''));
    $foldersInfo = getBatoceraFoldersList($baseDir, $uncPath, false);
    $installedFolders = (!empty($foldersInfo['online']) && !empty($foldersInfo['folders'])) ? $foldersInfo['folders'] : null;

    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '/*.json');
        foreach ($files as $fPath) {
            $baseName = basename($fPath);
            if ($baseName[0] === '_') continue; // Skip internal cache files like _folders_list.json
            $content = @file_get_contents($fPath);
            if (!$content) continue;
            $data = json_decode($content, true);
            if (!is_array($data) || empty($data['status']) || $data['status'] !== 'ok') continue;

            $folder = $data['folder'] ?? '';
            if (!$folder) continue;

            // Find matching system id
            $sysId = $folder;
            if (!isset($systemsData[$sysId])) {
                if (isset($systemsData[$sysId . '_batocera'])) {
                    $sysId = $sysId . '_batocera';
                }
            }

            $sysMeta = $systemsData[$sysId] ?? [
                'name' => $folder,
                'hardware' => '',
                'game_count' => 0
            ];

            $folderExists = !isset($data['folder_exists']) || (bool)$data['folder_exists'];
            $matched = $folderExists ? (int)($data['matched_count'] ?? 0) : 0;
            $catalogCount = $sysMeta['game_count'] > 0 ? (int)$sysMeta['game_count'] : $matched;

            $pct = $catalogCount > 0 ? round(($matched / $catalogCount) * 100, 1) : 0;
            if ($pct > 100) $pct = 100;

            $mismatchCount = (int)($data['mismatch_count'] ?? 0);
            if ($mismatchCount === 0 && !empty($data['matched_details']) && is_array($data['matched_details'])) {
                $mFiles = [];
                foreach ($data['matched_details'] as $det) {
                    if (!empty($det['is_mismatch']) && !empty($det['matched_file'])) {
                        $mFiles[$det['matched_file']] = true;
                    }
                }
                $mismatchCount = count($mFiles);
            }

            $scannedAt = $data['scanned_at'] ?? filemtime($fPath);
            $isQuick = (strpos($baseName, '_quick_') !== false);

            $scanEntry = [
                'system_id' => $sysId,
                'folder' => $folder,
                'name' => $sysMeta['name'],
                'hardware' => $sysMeta['hardware'],
                'matched_count' => $matched,
                'mismatch_count' => $mismatchCount,
                'total_games' => $catalogCount,
                'percentage' => $pct,
                'scanned_at' => $scannedAt,
                'folder_exists' => $folderExists,
                'not_installed' => !$folderExists,
                'scanned' => true,
                '_is_quick' => $isQuick,
                '_mtime' => filemtime($fPath)
            ];

            // Deduplicate: if we already have this system_id, keep the best scan
            // (prefer full scan over quick scan, or newer / higher matched count)
            if (isset($uniqueScans[$sysId])) {
                $existing = $uniqueScans[$sysId];
                if ($existing['_is_quick'] && !$isQuick) {
                    $uniqueScans[$sysId] = $scanEntry;
                } elseif (!$existing['_is_quick'] && $isQuick) {
                    if ($existing['matched_count'] === 0 && $matched > 0) {
                        $uniqueScans[$sysId] = $scanEntry;
                    }
                } else {
                    if ($scanEntry['_mtime'] > $existing['_mtime'] || $matched > $existing['matched_count']) {
                        $uniqueScans[$sysId] = $scanEntry;
                    }
                }
            } else {
                $uniqueScans[$sysId] = $scanEntry;
            }
        }
    }

    // Sort unique scanned systems by percentage desc, then name asc
    uasort($uniqueScans, function($a, $b) {
        if ($b['percentage'] != $a['percentage']) {
            return ($b['percentage'] > $a['percentage']) ? 1 : -1;
        }
        return strcasecmp($a['name'], $b['name']);
    });

    $cachedScans = array_values($uniqueScans);

    // Calculate totals over unique scanned systems only
    foreach ($cachedScans as $cs) {
        $totalInstalled += $cs['matched_count'];
        $totalCatalogGames += $cs['total_games'];
    }

    // Build combined list: unique scanned systems first, then remaining unscanned systems
    $scannedMap = [];
    $allOverviewSystems = [];
    foreach ($cachedScans as $cs) {
        $scannedMap[$cs['system_id']] = true;
        $scannedMap[$cs['folder']] = true;
        unset($cs['_is_quick'], $cs['_mtime']);
        $allOverviewSystems[] = $cs;
    }

    // Collect unscanned systems sorted by name
    $unscanned = [];
    foreach ($systemsData as $sId => $sMeta) {
        $cleanFolder = preg_replace('/_batocera$/', '', $sId);
        if (!isset($scannedMap[$sId]) && !isset($scannedMap[$cleanFolder])) {
            $isNotInstalled = ($installedFolders !== null)
                && !in_array(strtolower($cleanFolder), $installedFolders)
                && !in_array(strtolower($sId), $installedFolders);

            $unscanned[] = [
                'system_id' => $sId,
                'folder' => $cleanFolder,
                'name' => $sMeta['name'],
                'hardware' => $sMeta['hardware'],
                'matched_count' => 0,
                'mismatch_count' => 0,
                'total_games' => (int)$sMeta['game_count'],
                'percentage' => 0,
                'scanned_at' => null,
                'scanned' => false,
                'folder_exists' => !$isNotInstalled,
                'not_installed' => $isNotInstalled
            ];
            $scannedMap[$sId] = true;
            $scannedMap[$cleanFolder] = true;
        }
    }
    usort($unscanned, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    foreach ($unscanned as $us) {
        $allOverviewSystems[] = $us;
    }

    $overallPct = $totalCatalogGames > 0 ? round(($totalInstalled / $totalCatalogGames) * 100, 1) : 0;

    $batoIp = $foldersInfo['ip'] ?? '';
    if (!$batoIp && !empty($foldersInfo['resolved_target']) && filter_var($foldersInfo['resolved_target'], FILTER_VALIDATE_IP)) {
        $batoIp = $foldersInfo['resolved_target'];
    }

    echo json_encode([
        'status' => 'ok',
        'online' => !empty($foldersInfo['online']),
        'host' => $foldersInfo['host'] ?? '',
        'ip' => $batoIp,
        'resolved_target' => $foldersInfo['resolved_target'] ?? '',
        'unc_path' => $uncPath,
        'installed_folders_count' => is_array($installedFolders) ? count($installedFolders) : 0,
        'total_all_systems' => count($systemsData),
        'total_scanned_systems' => count($cachedScans),
        'total_installed_games' => $totalInstalled,
        'total_catalog_games' => $totalCatalogGames,
        'overall_percentage' => $overallPct,
        'scans' => $allOverviewSystems
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
