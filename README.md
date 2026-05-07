# Wifi Stealer Dashboard

Educational/offensive-security lab project that demonstrates how stolen Wi-Fi
profiles from a Windows host can be sent to a PHP server and visualized in a dashboard.

## Features

- PowerShell payload collects Wi-Fi SSIDs and passwords from Windows.
- Sends data (SSID, password, IP, OS) to `wifi-recv.php` on a PHP/Apache server.
- `index.php` shows a live dashboard with stats, auto-refresh, and delete controls.

> Warning: For educational and authorized security testing only.
> Do **not** use this on systems you do not own or have explicit permission to test.

## Usage

- Host `index.php` and `wifi-recv.php` on a PHP/Apache server.
- Configure your BadUSB/Digispark payload to POST the CSV data to `wifi-recv.php`.
- Open the dashboard in a browser to view captured entries.
# wifi-stealer
# wifi-stealer
# wifi-stealer
# wifi-stealer
# wifi-stealer
# wifi-stealer
