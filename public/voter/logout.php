<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/lib/auth.php';

start_secure_session();

// Clear session data and destroy
$_SESSION = [];
session_destroy();

header('Location: /');
exit;
