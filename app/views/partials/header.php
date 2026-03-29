<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($pageTitle ?? 'Voting System'); ?> — VoteMS</title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <script>
    // Apply saved theme before page renders (prevents flash)
    (function() {
      var t = localStorage.getItem('theme') || 'light';
      document.documentElement.setAttribute('data-theme', t);
    })();
  </script>
</head>
<body>

<nav class="navbar">
  <a href="/" class="brand">
    <span class="brand-icon">🗳</span>
    VoteMS
  </a>

  <?php if (!empty($_SESSION['admin_id'])): ?>
    <a href="/admin/dashboard.php">Dashboard</a>
    <a href="/admin/elections.php">Elections</a>
    <a href="/admin/voters.php">Voters</a>
    <a href="/admin/results.php">Results</a>
    <a href="/admin/logout.php">Logout</a>
  <?php elseif (!empty($_SESSION['voter_id'])): ?>
    <a href="/voter/dashboard.php">My Ballot</a>
    <a href="/voter/logout.php">Logout</a>
  <?php else: ?>
    <a href="/admin/login.php">Admin</a>
    <a href="/voter/login.php">Voter Login</a>
  <?php endif; ?>

  <button class="theme-toggle" id="themeToggle" title="Toggle dark mode" aria-label="Toggle dark mode">
    🌙
  </button>
</nav>

<div class="container">

<script>
  // Dark mode toggle
  (function() {
    var btn = document.getElementById('themeToggle');
    function applyTheme(t) {
      document.documentElement.setAttribute('data-theme', t);
      btn.textContent = t === 'dark' ? '☀️' : '🌙';
      localStorage.setItem('theme', t);
    }
    // Set correct icon on load
    applyTheme(localStorage.getItem('theme') || 'light');
    btn.addEventListener('click', function() {
      var current = document.documentElement.getAttribute('data-theme');
      applyTheme(current === 'dark' ? 'light' : 'dark');
    });
  })();
</script>
