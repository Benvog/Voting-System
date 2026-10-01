<?php
declare(strict_types=1);

function config(): array
{
  static $config = null;
  return $config ??= require __DIR__ . '/../config/config.php';
}

date_default_timezone_set(config()['app']['timezone']);

function db(): PDO
{
  static $pdo = null;

  if ($pdo instanceof PDO) {
    return $pdo;
  }

  $c   = config()['db'];
  $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}";

  $pdo = new PDO($dsn, $c['user'], $c['pass'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ]);

  // Keep MySQL's NOW() in the same timezone PHP uses, so election windows
  // behave the same on any host.
  $pdo->prepare("SET time_zone = :tz")->execute([':tz' => (new DateTime())->format('P')]);

  return $pdo;
}
