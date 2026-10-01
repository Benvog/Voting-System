<?php
// Voting happens on the step-by-step ballot now; keep old links working.
header('Location: /voter/ballot.php', true, 301);
exit;
