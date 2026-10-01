<?php
// Admins and voters share one login page now; keep old links working.
header('Location: /login.php', true, 301);
exit;
