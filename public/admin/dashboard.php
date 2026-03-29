<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../app/lib/auth.php';

start_secure_session();
require_admin();

// Quick stats
$pdo = db();

$totalVoters     = (int)$pdo->query("SELECT COUNT(*) FROM voters")->fetchColumn();
$activeVoters    = (int)$pdo->query("SELECT COUNT(*) FROM voters WHERE is_active = 1")->fetchColumn();
$totalVotes      = (int)$pdo->query("SELECT COUNT(*) FROM votes")->fetchColumn();
$totalCandidates = (int)$pdo->query("SELECT COUNT(*) FROM candidates")->fetchColumn();

$activeElection  = $pdo->query("
    SELECT name FROM elections
    WHERE status = 'active'
      AND (starts_at IS NULL OR starts_at <= NOW())
      AND (ends_at   IS NULL OR ends_at   >= NOW())
    LIMIT 1
")->fetchColumn();

$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<div class="page-header">
  <div>
    <h1>Admin Dashboard</h1>
    <p>Welcome back. Here's a live snapshot of the system.</p>
  </div>
</div>

<!-- Stats -->
<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-label">Total Voters</div>
    <div class="stat-value"><?php echo $totalVoters; ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Active Voters</div>
    <div class="stat-value"><?php echo $activeVoters; ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Votes Cast</div>
    <div class="stat-value"><?php echo $totalVotes; ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Candidates</div>
    <div class="stat-value"><?php echo $totalCandidates; ?></div>
  </div>
</div>

<?php if ($activeElection): ?>
  <div class="alert alert-success">
    ✅ Active election: <strong><?php echo htmlspecialchars((string)$activeElection); ?></strong> is currently running.
  </div>
<?php else: ?>
  <div class="alert alert-warning">
    ⚠️ No election is currently active. Go to <a href="/admin/elections.php">Elections</a> to activate one.
  </div>
<?php endif; ?>

<!-- Menu -->
<div class="section-title">Manage</div>
<div class="menu-grid">

  <a href="/admin/elections.php" class="menu-card">
    <div class="menu-icon">🗳</div>
    <div class="menu-label">Elections</div>
    <div class="text-muted" style="font-size:.82rem;">Create &amp; manage elections</div>
  </a>

  <a href="/admin/positions.php" class="menu-card">
    <div class="menu-icon">📋</div>
    <div class="menu-label">Positions</div>
    <div class="text-muted" style="font-size:.82rem;">Add positions per election</div>
  </a>

  <a href="/admin/candidates.php" class="menu-card">
    <div class="menu-icon">👤</div>
    <div class="menu-label">Candidates</div>
    <div class="text-muted" style="font-size:.82rem;">Register candidates</div>
  </a>

  <a href="/admin/voters.php" class="menu-card">
    <div class="menu-icon">🧑‍🤝‍🧑</div>
    <div class="menu-label">Voters</div>
    <div class="text-muted" style="font-size:.82rem;">Create &amp; manage voters</div>
  </a>

  <a href="/admin/results.php" class="menu-card">
    <div class="menu-icon">📊</div>
    <div class="menu-label">Results</div>
    <div class="text-muted" style="font-size:.82rem;">Live vote counts</div>
  </a>

  <a href="/admin/logout.php" class="menu-card" style="border-color: var(--border);">
    <div class="menu-icon">🚪</div>
    <div class="menu-label">Logout</div>
    <div class="text-muted" style="font-size:.82rem;">End your session</div>
  </a>

</div>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
