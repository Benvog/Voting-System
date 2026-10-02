# Deploying the demo to InfinityFree

The public demo lives at **https://tally.xo.je**. It runs the fictional
`voting_demo` data with read-only demo logins (see `demo` in
`app/config/config.php`). Your own admin account keeps full control.

InfinityFree has no command line and no scheduled jobs, so everything is
prepared on this PC and uploaded: files through the file manager (or FTP),
data through phpMyAdmin.

## 1. On InfinityFree: create the database

1. Client area → your account (`tally.xo.je`) → **Control Panel**.
2. **MySQL Databases** → create a database (e.g. `tally`). Its full name
   looks like `if0_12345678_tally`.
3. Note the **MySQL Host Name**, **DB Name**, **User Name** and **Password**
   (the password is your hosting account password, shown in the client area
   under "Account details").

## 2. On this PC: prepare the data

In PowerShell, from `C:\xampp\htdocs\voting-system`:

```powershell
# Use the demo database for the next commands
Copy-Item app\config\config.local.demo.php app\config\config.local.php

# Fresh demo data whose live election stays open for a year
C:\xampp\php\php.exe database\seed_demo.php --closes-in-days=365

# Your own full admin. Type the password when asked; pick a strong one.
# (Seeding wipes admins, so this must come after the seed.)
C:\xampp\php\php.exe database\create_admin.php your-username

# Build the upload folder and export the data to C:\xampp\tally-upload\tally.sql
powershell -ExecutionPolicy Bypass -File deploy\build-infinityfree.ps1 -ExportSql

# Back to your real data
Remove-Item app\config\config.local.php
```

## 3. Fill in the live config

Open `C:\xampp\tally-upload\htdocs\app\config\config.local.php` and replace
the four `db` values with the ones from step 1. Leave the `demo` block as it
is. This file contains a password: don't commit it or share it.

## 4. On InfinityFree: import the data

Control Panel → **MySQL Databases** → **Admin** (phpMyAdmin) next to your
database → **Import** → choose `C:\xampp\tally-upload\tally.sql` → **Go**.

## 5. Upload the files

Control Panel → **Online File Manager** → open `htdocs`.

1. Delete the placeholder files already in `htdocs` (e.g. `index2.html`).
2. Upload the **contents** of `C:\xampp\tally-upload\htdocs`: the `.htaccess`
   file and the `app` and `public` folders. `.htaccess` starts with a dot, so
   make sure your upload tool shows hidden files.

FTP works too (FileZilla, details under "FTP Details" in the client area).

## 6. Turn on HTTPS

Client area → **Free SSL Certificates** → request one for `tally.xo.je` and
install it. Logins only send the session cookie over HTTPS once it's on.

## 7. Check it

- https://tally.xo.je shows the landing page with a live countdown.
- https://tally.xo.je/app/config/config.local.php gives **Not Found**. If it
  shows anything else, stop and fix the upload before sharing the link.
- `demo-admin` / `demo-admin-pass` logs in and shows the demo banner.
- `CS/MK/0001/09/23`, PIN `246810`, can go through the ballot.
- Your own admin logs in with no banner.

## Updating later

After changing the code: rebuild (step 2's build line, without `-ExportSql`
unless the data changed too) and upload the changed files. The build keeps
your filled-in `config.local.php`.
