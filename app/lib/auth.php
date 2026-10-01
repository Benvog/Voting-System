<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const LOGIN_MAX_FAILURES    = 5;   // per voter ID / username: this is what stops PIN guessing
const LOGIN_MAX_IP_FAILURES = 100; // per IP; high because a school lab may share one address
const LOGIN_WINDOW_MINUTES  = 15;

function start_secure_session(): void
{
  if (session_status() === PHP_SESSION_ACTIVE) {
    return;
  }

  session_name(config()['session']['name']);

  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  session_start();
}

/* Voter IDs are generated as VOT-XXXXXX (older ones as VTR-XXXXXX), so the
   login form can tell a voter from an admin by the shape of the ID alone. */
function is_voter_uid(string $id): bool
{
  return (bool)preg_match('/^V(OT|TR)-[0-9A-F]{6}$/i', $id);
}

/* Starting a new login always wipes the previous one, so one browser can
   never hold an admin and a voter session at the same time. */
function begin_login(): void
{
  $_SESSION = [];
  session_regenerate_id(true);
}

function logout(): void
{
  start_secure_session();
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
  }
  session_destroy();
}

/* ---------------- LOGIN THROTTLING ---------------- */

function client_ip(): string
{
  return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function login_is_throttled(PDO $pdo, string $identifier): bool
{
  $stmt = $pdo->prepare("
    SELECT
      COALESCE(SUM(identifier = :id), 0) AS by_id,
      COALESCE(SUM(ip = :ip), 0)         AS by_ip
    FROM login_attempts
    WHERE (ip = :ip2 OR identifier = :id2)
      AND attempted_at > NOW() - INTERVAL " . LOGIN_WINDOW_MINUTES . " MINUTE
  ");
  $stmt->execute([':id' => $identifier, ':id2' => $identifier, ':ip' => client_ip(), ':ip2' => client_ip()]);
  $row = $stmt->fetch();

  return (int)$row['by_id'] >= LOGIN_MAX_FAILURES || (int)$row['by_ip'] >= LOGIN_MAX_IP_FAILURES;
}

function record_login_failure(PDO $pdo, string $identifier): void
{
  $pdo->prepare("INSERT INTO login_attempts (ip, identifier) VALUES (:ip, :id)")
      ->execute([':ip' => client_ip(), ':id' => $identifier]);
  $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY");
}

function clear_login_failures(PDO $pdo, string $identifier): void
{
  $pdo->prepare("DELETE FROM login_attempts WHERE identifier = :id")->execute([':id' => $identifier]);
}

/* ---------------- ADMIN AUTH ---------------- */

function login_admin(int $adminId): void
{
  begin_login();
  $_SESSION['admin_id'] = $adminId;
}

/* The signed-in admin's row, re-read on every request so a disabled admin
   loses access immediately. */
function current_admin(): ?array
{
  static $admin = false;
  if ($admin !== false) {
    return $admin;
  }
  $admin = null;
  if (!empty($_SESSION['admin_id'])) {
    $stmt = db()->prepare("SELECT id, username, is_active FROM admins WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => (int)$_SESSION['admin_id']]);
    $row = $stmt->fetch();
    if ($row && (int)$row['is_active'] === 1) {
      $admin = $row;
    }
  }
  return $admin;
}

function require_admin(): void
{
  if (!current_admin()) {
    if (!empty($_SESSION['voter_id']) && empty($_SESSION['admin_id'])) {
      header('Location: /voter/dashboard.php'); // a voter in the wrong place, not an intruder
      exit;
    }
    $_SESSION = []; // e.g. an admin who was disabled mid-session
    header('Location: /login.php');
    exit;
  }
}

/* ---------------- VOTER AUTH ---------------- */

function login_voter(int $voterId, string $voterUid): void
{
  begin_login();
  $_SESSION['voter_id']  = $voterId;
  $_SESSION['voter_uid'] = $voterUid;
}

function require_voter_active(PDO $pdo): void
{
  if (empty($_SESSION['voter_id'])) {
    header('Location: /login.php');
    exit;
  }

  $stmt = $pdo->prepare("SELECT is_active FROM voters WHERE id = :id LIMIT 1");
  $stmt->execute([':id' => (int)$_SESSION['voter_id']]);
  $row = $stmt->fetch();

  if (!$row || (int)$row['is_active'] !== 1) {
    $_SESSION = [];
    session_destroy();
    header('Location: /login.php');
    exit;
  }
}

/* Where a signed-in user belongs, or null if nobody is signed in. */
function home_for_session(): ?string
{
  if (!empty($_SESSION['admin_id'])) return '/admin/dashboard.php';
  if (!empty($_SESSION['voter_id'])) return '/voter/dashboard.php';
  return null;
}
