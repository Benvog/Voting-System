<?php
declare(strict_types=1);
/**
 * Creates an administrator account from the command line:
 *
 *   php database/create_admin.php <username>
 *
 * You are asked for the password, so it never lands in shell history or in
 * a file the web server can serve.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../app/lib/auth.php';

$username = $argv[1] ?? '';
if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
    fwrite(STDERR, "Usage: php database/create_admin.php <username>\n"
        . "Usernames are 3-50 letters, numbers, dots, dashes or underscores.\n");
    exit(1);
}
if (identifier_owner(db(), $username) === 'voter') {
    fwrite(STDERR, "A voter already logs in with '$username'. Pick a different username.\n");
    exit(1);
}

echo "Password for $username (at least 10 characters): ";
// Strip a byte-order mark: PowerShell adds one when input is piped in.
$password = trim((string)preg_replace('/^\x{FEFF}/u', '', (string)fgets(STDIN)));
if (strlen($password) < 10) {
    fwrite(STDERR, "Password too short.\n");
    exit(1);
}

try {
    db()->prepare("INSERT INTO admins (username, password_hash) VALUES (:u, :h)")
        ->execute([':u' => $username, ':h' => password_hash($password, PASSWORD_DEFAULT)]);
} catch (PDOException $e) {
    fwrite(STDERR, "Could not create the admin: that username may already exist.\n");
    exit(1);
}

echo "Created admin '$username'. Log in at /login.php\n";
