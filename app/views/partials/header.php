<?php
declare(strict_types=1);
/**
 * Page shell. Set before including:
 *   $pageTitle  string
 *   $layout     'admin' | 'voter' | 'public' | 'auth'   (default 'public')
 *   $activeNav  key of the current admin sidebar link
 */
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../lib/auth.php';

$layout    = $layout ?? 'public';
$activeNav = $activeNav ?? '';
$appName   = config()['app']['name'];
$flashes   = take_flashes();

function nav_link(string $href, string $iconName, string $label, string $key, string $active): string
{
  $current = $key === $active ? ' aria-current="page"' : '';
  return '<a class="sb-link" href="' . e($href) . '"' . $current . '>' . icon($iconName) . '<span>' . e($label) . '</span></a>';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo e($pageTitle ?? 'Voting'); ?> · <?php echo e($appName); ?></title>
  <script>
    // Apply the saved (or system) theme before first paint.
    (function () {
      document.documentElement.classList.add('js');
      var t = null;
      try { t = localStorage.getItem('theme'); } catch (e) {}
      if (t !== 'light' && t !== 'dark') {
        t = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      }
      document.documentElement.setAttribute('data-theme', t);
    })();
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@500&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/app.css">
  <script src="/assets/js/app.js" defer></script>
</head>
<body class="layout-<?php echo e($layout); ?>">
<a class="skip" href="#main">Skip to content</a>

<?php if ($layout === 'admin'):
  $admin = current_admin();
  $live  = current_election(db());
?>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="/admin/dashboard.php"><span class="brand-mark"><?php echo icon('logo'); ?></span><?php echo e($appName); ?></a>
    <nav class="sb-nav" aria-label="Admin">
      <?php
        echo nav_link('/admin/dashboard.php', 'overview', 'Overview', 'overview', $activeNav);
        echo nav_link('/admin/elections.php', 'ballot', 'Elections', 'elections', $activeNav);
        echo nav_link('/admin/voters.php', 'users', 'Voters', 'voters', $activeNav);
        echo nav_link('/admin/results.php', 'chart', 'Results', 'results', $activeNav);
      ?>
    </nav>
    <div class="sb-foot">
      <?php echo nav_link('/admin/account.php', 'user', 'Account', 'account', $activeNav); ?>
      <a class="sb-link" href="/results.php" target="_blank" rel="noopener"><?php echo icon('external'); ?><span>Public results</span></a>
      <a class="sb-link" href="/logout.php"><?php echo icon('logout'); ?><span>Log out</span></a>
    </div>
  </aside>
  <div class="scrim" data-close-sidebar></div>

  <div class="main">
    <header class="topbar">
      <button class="icon-btn menu-btn" type="button" data-open-sidebar aria-label="Open menu" aria-controls="sidebar"><?php echo icon('menu'); ?></button>
      <?php if ($live): ?>
        <a class="live-pill" href="/admin/election.php?id=<?php echo (int)$live['id']; ?>"><span class="dot" aria-hidden="true"></span><span class="live-label">Live</span><span class="live-name"><?php echo e($live['name']); ?></span></a>
      <?php else: ?>
        <span class="live-pill is-idle"><span class="dot" aria-hidden="true"></span>No election live</span>
      <?php endif; ?>
      <div class="topbar-end">
        <button class="icon-btn" type="button" data-theme-toggle aria-label="Toggle dark mode"><?php echo icon('moon', 'when-light') . icon('sun', 'when-dark'); ?></button>
        <a class="user-chip" href="/admin/account.php"><span class="avatar"><?php echo e(initials($admin['username'] ?? '')); ?></span><span class="user-name"><?php echo e($admin['username'] ?? ''); ?></span></a>
      </div>
    </header>
    <main class="content" id="main">

<?php elseif ($layout === 'voter'): ?>
<header class="bar">
  <div class="bar-inner">
    <a class="brand" href="/voter/dashboard.php"><span class="brand-mark"><?php echo icon('logo'); ?></span><?php echo e($appName); ?></a>
    <div class="bar-end">
      <span class="voter-id" title="Your voter ID"><?php echo icon('user'); ?><?php echo e((string)($_SESSION['voter_uid'] ?? '')); ?></span>
      <button class="icon-btn" type="button" data-theme-toggle aria-label="Toggle dark mode"><?php echo icon('moon', 'when-light') . icon('sun', 'when-dark'); ?></button>
      <a class="btn btn-ghost btn-sm" href="/logout.php"><?php echo icon('logout'); ?>Log out</a>
    </div>
  </div>
</header>
<main class="page page-narrow" id="main">

<?php else: ?>
<header class="bar">
  <div class="bar-inner">
    <a class="brand" href="/"><span class="brand-mark"><?php echo icon('logo'); ?></span><?php echo e($appName); ?></a>
    <div class="bar-end">
      <?php if ($layout !== 'auth'): ?>
        <a class="bar-link" href="/results.php">Results</a>
      <?php endif; ?>
      <button class="icon-btn" type="button" data-theme-toggle aria-label="Toggle dark mode"><?php echo icon('moon', 'when-light') . icon('sun', 'when-dark'); ?></button>
      <?php if ($layout !== 'auth'): ?>
        <?php $home = home_for_session(); ?>
        <a class="btn btn-primary btn-sm" href="<?php echo e($home ?? '/login.php'); ?>"><?php echo $home ? 'Open dashboard' : 'Log in'; ?></a>
      <?php endif; ?>
    </div>
  </div>
</header>
<main class="page<?php echo $layout === 'auth' ? ' page-auth' : ''; ?>" id="main">
<?php endif; ?>

<?php if ($flashes): ?>
  <div class="flashes" role="status">
    <?php foreach ($flashes as $f): ?>
      <div class="alert alert-<?php echo e($f['type']); ?>">
        <?php echo icon($f['type'] === 'error' ? 'alert' : ($f['type'] === 'success' ? 'check-circle' : 'info')); ?>
        <span><?php echo e($f['message']); ?></span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
