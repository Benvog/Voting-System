<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/lib/auth.php';

logout();
header('Location: /login.php');
exit;
