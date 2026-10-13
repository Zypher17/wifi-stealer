<?php
/* upload.php – receives PowerShell JSON payload and stores it in the DB */
require_once __DIR__ . '/db.php';

// ---------------------------------------------------------------------
// 1️⃣ Retrieve and validate the token (query string)
// ---------------------------------------------------------------------
$token = $_GET['token'] ?? '';
if (empty($token)) {
    http_response_code(403);
    exit('Unauthorized – missing token');
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE token = ?');
$stmt->execute([$token]);
$user = $stmt->fetch();
if (!$user) {
    http_response_code(403);
    exit('Unauthorized – invalid token');
}
$user_id = $user['id'];

// ---------------------------------------------------------------------
// 2️⃣ Read raw POST body (JSON)
// ---------------------------------------------------------------------
$raw = file_get_contents('php://input');
if ($raw === false) {
    http_response_code(400);
    exit('Bad request – cannot read input');
}

$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    exit('Bad JSON payload: ' . json_last_error_msg());
}

if (empty($data)) {
    // No data – still return 200 so the client doesn’t retry forever
    http_response_code(204);
    exit;
}

// Normalise to an array of items (PowerShell may emit a single object)
if (isset($data['SSID'])) {
    $data = [$data];
}

// ---------------------------------------------------------------------
// 3️⃣ Insert each record into `test_results`
// ---------------------------------------------------------------------
$insert = $pdo->prepare(
    'INSERT INTO test_results (user_id, ssid, captured_data, flagged) VALUES (?, ?, ?, ?)'
);

foreach ($data as $item) {
    $ssid      = $item['SSID'] ?? 'unknown';
    $jsonChunk = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // Simple flag – mark as 1 if the word "unauthorized" appears anywhere
    $flagged   = (strpos(strtolower($jsonChunk), 'unauthorized') !== false) ? 1 : 0;
    $insert->execute([$user_id, $ssid, $jsonChunk, $flagged]);
}

// ---------------------------------------------------------------------
// 4️⃣ Respond to the client
// ---------------------------------------------------------------------
http_response_code(200);
echo json_encode(['status' => 'ok', 'user' => $token]);
?>
