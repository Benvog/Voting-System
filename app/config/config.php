<?php
declare(strict_types=1);

// In production, move these into environment variables.
return [
  'db' => [
    'host' => '127.0.0.1',
    'name' => 'voting_db',
    'user' => 'root',
    'pass' => '',
    'charset' => 'utf8mb4',
  ],
  'session' => [
    'name' => 'VOTINGSESSID',
  ]
];