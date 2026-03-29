<?php
declare(strict_types=1);

function start_secure_session(): void
{
  if (session_status() === PHP_SESSION_ACTIVE) {
    return;
  }

  session_name('VOTINGSESSID');

  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => false,   // set TRUE on HTTPS
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  session_start();
}

/* ---------------- ADMIN AUTH ---------------- */

function login_admin(int $adminId): void
{
  session_regenerate_id(true);
  $_SESSION['admin_id'] = $adminId;
}

function require_admin(): void
{
  if (empty($_SESSION['admin_id'])) {
    header('Location: login.php'); // relative to /admin/
    exit;
  }
}

/* ---------------- VOTER AUTH ---------------- */

function login_voter(int $voterId, string $voterUid): void
{
  session_regenerate_id(true);
  $_SESSION['voter_id'] = $voterId;
  $_SESSION['voter_uid'] = $voterUid;
}

function require_voter(): void
{
  if (empty($_SESSION['voter_id'])) {
    header('Location: login.php'); // relative to /voter/
    exit;
  }
}
function require_voter_active(PDO $pdo): void
{
  require_voter();

  $stmt = $pdo->prepare("SELECT is_active FROM voters WHERE id = :id LIMIT 1");
  $stmt->execute([':id' => (int)$_SESSION['voter_id']]);
  $row = $stmt->fetch();

  if (!$row || (int)$row['is_active'] !== 1) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php'); // voter login (relative)
    exit;
  }
}