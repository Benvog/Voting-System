# Builds an upload folder for InfinityFree (or any shared host whose web root
# is a single "htdocs" folder you can't move).
#
#   powershell -ExecutionPolicy Bypass -File deploy\build-infinityfree.ps1
#   powershell -ExecutionPolicy Bypass -File deploy\build-infinityfree.ps1 -ExportSql
#
# Output (default C:\xampp\tally-upload, outside the local web root):
#   htdocs\           upload the CONTENTS of this folder into the host's htdocs
#   htdocs\.htaccess  serves everything from public\, so app\ is never reachable
#   htdocs\app\config\config.local.php  fill in the host's database details
#   tally.sql         (with -ExportSql) the voting_demo database, for phpMyAdmin
#
# config.local.php holds a password, so it is only written if it doesn't exist
# yet; rebuilding never overwrites the details you filled in.

param(
  [string]$Out = 'C:\xampp\tally-upload',
  [switch]$ExportSql
)
$ErrorActionPreference = 'Stop'
$repo = Split-Path $PSScriptRoot -Parent
$htdocs = Join-Path $Out 'htdocs'

# Keep the filled-in config across rebuilds.
$configPath = Join-Path $htdocs 'app\config\config.local.php'
$savedConfig = if (Test-Path $configPath) { Get-Content $configPath -Raw } else { $null }

if (Test-Path $htdocs) { Remove-Item $htdocs -Recurse -Force }
New-Item -ItemType Directory -Force $htdocs | Out-Null

# Only what the site needs at runtime: no .git, database\ or deploy\.
Copy-Item (Join-Path $repo 'app') $htdocs -Recurse
Copy-Item (Join-Path $repo 'public') $htdocs -Recurse
# Local-only configs never leave this PC.
Get-ChildItem (Join-Path $htdocs 'app\config') -Filter 'config.local*.php' |
  Where-Object Name -ne 'config.local.example.php' | Remove-Item -Force

$utf8 = New-Object Text.UTF8Encoding $false
[IO.File]::WriteAllText((Join-Path $htdocs '.htaccess'), @'
# The app's web root is public/. Every request is routed there, so nothing
# else in this folder (app/, its config and passwords) can be fetched.
Options -Indexes
DirectoryIndex index.php
RewriteEngine On
RewriteCond %{REQUEST_URI} !^/public/
RewriteRule ^(.*)$ /public/$1 [L]
'@, $utf8)

# A second lock, in case the rewrite above is ever removed.
[IO.File]::WriteAllText((Join-Path $htdocs 'app\.htaccess'), "Require all denied`n", $utf8)

if ($savedConfig) {
  [IO.File]::WriteAllText($configPath, $savedConfig, $utf8)
  "Kept your existing config.local.php"
} else {
  [IO.File]::WriteAllText($configPath, @'
<?php
// Live-site settings. Fill in the four database values from the InfinityFree
// control panel (MySQL Databases). This file is never committed to git.
return [
  'db' => [
    'host' => 'sqlXXX.infinityfree.com',   // "MySQL Host Name"
    'name' => 'if0_XXXXXXXX_tally',        // "MySQL DB Name"
    'user' => 'if0_XXXXXXXX',              // "MySQL User Name"
    'pass' => 'your-vpanel-password',      // "MySQL Password"
  ],
  // The logins published on the portfolio: they can look, but not save.
  'demo' => [
    'read_only_admins' => ['demo-admin'],
    'read_only_voters' => ['CS/MK/0001/09/23', 'CS/MK/0002/09/23'],
  ],
];
'@, $utf8)
  "Wrote a config.local.php template: fill in the database details before uploading"
}

if ($ExportSql) {
  # No CREATE DATABASE/USE: the host's database already exists with its own name.
  $sql = Join-Path $Out 'tally.sql'
  & 'C:\xampp\mysql\bin\mysqldump.exe' -u root --single-transaction --skip-comments --no-tablespaces --result-file=$sql voting_demo
  if ($LASTEXITCODE -ne 0) { throw "mysqldump failed" }
  "Exported voting_demo to $sql ($([int]((Get-Item $sql).Length / 1KB)) KB)"
}

"Built $htdocs"
