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
  // How voters identify themselves. The ID is stored upper-case with spaces
  // removed, then checked against this pattern.
  'voters' => [
    'id_label'   => 'Registration number',
    'id_example' => 'CS/MK/0700/09/23',
    'id_pattern' => '#^[A-Z0-9][A-Z0-9/.\-]{1,38}[A-Z0-9]$#',
  ],
  'session' => [
    'name' => 'VOTINGSESSID',
  ],
  // Accounts whose logins are published for a public demo. They can see every
  // page, but nothing they submit is saved. Voter IDs are written as stored
  // (upper-case, no spaces).
  'demo' => [
    'read_only_admins' => [],
    'read_only_voters' => [],
    // One-click "Try the demo" logins on the landing and login pages, e.g.
    // ['label' => 'a voter', 'id' => 'CS/MK/0001/09/23', 'secret' => '246810'].
    // Only IDs in the read-only lists above are ever shown.
    'show_logins' => [],
  ],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
  $config = array_replace_recursive($config, require $local);
}

return $config;
