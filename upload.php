<?php
/* upload.php – receives CSV payload from PowerShell and saves it */
$token = $_GET['token'] ?? '';
if (empty($token)) {
    http_response_code(403);
    exit('Missing token');
}

$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') {
    http_response_code(400);
    exit('No data received');
}

// Create uploads directory
$dir = __DIR__ . '/uploads';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $token);
$timestamp = date('Ymd_His');

// Save raw CSV
$csvFile = $dir . '/' . $sanitized . '_' . $timestamp . '.csv';
file_put_contents($csvFile, $raw);

// Parse CSV and save as JSON too
$lines = explode("\n", trim($raw));
if (count($lines) >= 2) {
    $headers = str_getcsv(array_shift($lines));
    $rows = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $values = str_getcsv($line);
        if (count($values) === count($headers)) {
            $rows[] = array_combine($headers, $values);
        }
    }
    if ($rows) {
        $jsonFile = $dir . '/' . $sanitized . '_' . $timestamp . '.json';
        file_put_contents($jsonFile, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

http_response_code(200);
echo json_encode(['status' => 'ok', 'token' => $token, 'records' => count($rows ?? [])]);
?>
