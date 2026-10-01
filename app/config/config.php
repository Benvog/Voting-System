<?php
declare(strict_types=1);

// Defaults for local XAMPP. On a server, copy config.local.example.php to
// config.local.php (git-ignored) and override only what differs.
$config = [
  'db' => [
    'host'    => '127.0.0.1',
    'name'    => 'voting_db',
    'user'    => 'root',
    'pass'    => '',
    'charset' => 'utf8mb4',
  ],
  'app' => [
    'name'     => 'VoteMS',
    'timezone' => 'Africa/Nairobi',
  ],
  'session' => [
    'name' => 'VOTINGSESSID',
  ],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
  $config = array_replace_recursive($config, require $local);
}

return $config;
