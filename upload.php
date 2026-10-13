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

// Store the raw CSV data
$dir = __DIR__ . '/uploads';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
$sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $token);
$file = $dir . '/' . $sanitized . '_' . date('Ymd_His') . '.csv';
file_put_contents($file, $raw);

// Also try to parse CSV and store as JSON for the dashboard
$lines = str_getcsv_multiline($raw);
if ($lines) {
    $jsonFile = $dir . '/' . $sanitized . '_' . date('Ymd_His') . '.json';
    file_put_contents($jsonFile, json_encode($lines, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

http_response_code(200);
echo json_encode(['status' => 'ok', 'token' => $token]);

// Helper: parse multi-line CSV string into array of associative arrays
function str_getcsv_multiline($csv) {
    $rows = [];
    $lines = explode("\n", trim($csv));
    if (count($lines) < 2) return [];
    $headers = str_getcsv(array_shift($lines));
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $values = str_getcsv($line);
        if (count($values) === count($headers)) {
            $rows[] = array_combine($headers, $values);
        }
    }
    return $rows;
}
?>
