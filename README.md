# VoteMS

A web app for running small elections online, such as a student council. Admins set up an election with its positions and candidates and register voters. Each voter logs in with a generated voter ID and PIN and casts one vote per position. Results, including turnout over time, are public while voting is open and after it closes.

Plain PHP 8 and MySQL/MariaDB, with no framework and no build step.

## Features

**Voters**
- One login page for everyone. A voter ID (`VOT-1A2B3C`) logs you in as a voter; a username logs you in as an admin.
- A step-by-step ballot that works well on a phone: one position per screen, an option to skip and come back later, and a review screen before submitting.
- The whole ballot is saved in one database transaction, so it is recorded completely or not at all.
- A countdown to closing time, and a receipt showing what you voted for.

**Admins**
- An overview of turnout, votes cast and a readiness checklist (positions without candidates, no voters, no closing time).
- One page per election for its positions and candidates, with reordering. Once anyone has voted the ballot locks, so a removed candidate can't take votes with it.
- Only one election can be open at a time, and an election can't be opened while a position has no candidates.
- Add voters in bulk by pasting one name per line. Login details are shown once, with a CSV download. Admins can reset PINs, disable voters and search the list.
- Account settings for changing the admin's username and password.

**Results**
- Turnout, a chart of participation over time (with a table view) and a bar chart per position with the leader highlighted. Ties are called out.
- Refreshes automatically while voting is open.

## Security

- Prepared statements for every query; all output escaped.
- CSRF tokens on every form; post/redirect/get, so a refresh never re-submits.
- PINs and passwords hashed with `password_hash`. Login responses take the same time whether or not the account exists.
- Login throttling: 5 failed attempts lock a voter ID or username for 15 minutes.
- The session is regenerated on login, and one browser can't be logged in as an admin and a voter at once.
- A voter's active status is re-checked on every request, so disabling them takes effect immediately.
- A unique key on `(election, position, voter)` makes double voting impossible, even with two requests at the same moment.

## Running it locally (XAMPP)

1. Clone into `C:\xampp\htdocs\voting-system`.
2. Create the database and tables:
   ```
   mysql -u root -e "CREATE DATABASE voting_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   mysql -u root voting_db < database/schema.sql
   ```
3. Create an admin (you'll be asked for a password):
   ```
   php database/create_admin.php your-username
   ```
4. Point a virtual host at `public/`. The app uses root-relative links, so it must be served from the root of a host. In `httpd-vhosts.conf`:
   ```apache
   <VirtualHost *:80>
     ServerName voting.local
     DocumentRoot "C:/xampp/htdocs/voting-system/public"
     <Directory "C:/xampp/htdocs/voting-system/public">
       Require all granted
     </Directory>
   </VirtualHost>
   ```
   Add `127.0.0.1 voting.local` to `C:\Windows\System32\drivers\etc\hosts`, restart Apache and open http://voting.local.

### Demo data

`database/seed_demo.php` fills a database whose name ends in `_demo` with a live election (votes spread over the last day and a half), a closed election and a draft. Everyone in it is fictional.

```
mysql -u root -e "CREATE DATABASE voting_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

Then create `app/config/config.local.php`:

```php
<?php
return ['db' => ['name' => 'voting_demo']];
```

and run `php database/seed_demo.php`. Demo logins:

| Role  | Login | Password / PIN |
|-------|-------|----------------|
| Admin | `demo-admin` | `demo-admin-pass` |
| Voter | `VOT-DE0001` or `VOT-DE0002` | `246810` |

## Configuration

Defaults are in `app/config/config.php`. To override them on a server, copy `app/config/config.local.example.php` to `app/config/config.local.php` and set only what differs (database credentials, timezone). That file is git-ignored. The timezone is applied to both PHP and MySQL so election windows behave the same on any host.

## Project structure

```
app/
  config/      settings (+ git-ignored local override)
  lib/         db, auth, CSRF, helpers, election rules
  views/       page shell and the shared results view
database/
  schema.sql        tables (safe to re-run)
  create_admin.php  CLI: add an admin
  seed_demo.php     CLI: demo data
public/         web root
  admin/        overview, elections, election, voters, results, account
  voter/        dashboard, ballot, confirmation
  assets/       app.css, app.js
  index.php, login.php, logout.php, results.php
```
