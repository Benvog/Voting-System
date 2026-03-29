<?php
declare(strict_types=1);

function db(): PDO
{
  static $pdo = null;

  if ($pdo instanceof PDO) {
    return $pdo;
  }

  // Adjust credentials to your environment
  $host = '127.0.0.1';
  $name = 'voting_db';
  $user = 'root';
  $pass = '';
  $charset = 'utf8mb4';

  $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";

  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);

  return $pdo;
}