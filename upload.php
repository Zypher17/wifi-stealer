<?php
/* upload.php – receives JSON payload and saves to file */
$token = $_GET['token'] ?? '';
if (empty($token)) {
    http_response_code(403);
    exit('Missing token');
}
$raw = file_get_contents('php://input');
if ($raw === false) {
    http_response_code(400);
    exit('Cannot read input');
}
$dir = __DIR__ . '/uploads';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
$sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $token);
$file = $dir . '/' . $sanitized . '_' . time() . '.json';
file_put_contents($file, $raw);
http_response_code(200);
echo json_encode(['status'=>'ok']);
?>
