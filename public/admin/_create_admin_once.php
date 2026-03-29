<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/lib/db.php';

$username = 'admin';
$password = 'ChangeThisStrongPassword!';

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = db()->prepare("INSERT INTO admins (username, password_hash) VALUES (:u, :h)");
$stmt->execute([
  ':u' => $username,
  ':h' => $hash
]);

echo "Admin created successfully.<br>";
echo "Username: " . htmlspecialchars($username) . "<br>";
echo "Password: " . htmlspecialchars($password) . "<br>";
echo "<strong>DELETE this file now: public/admin/_create_admin_once.php</strong>";