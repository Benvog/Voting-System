<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/lib/helpers.php';
require_once __DIR__ . '/../app/lib/auth.php';

start_secure_session();

try {
    $pdo  = db();
    $live = current_election($pdo);
    $last = $live ? null : ($pdo->query("
        SELECT id, name, status, starts_at, ends_at FROM elections
        WHERE status = 'closed'
        ORDER BY COALESCE(ends_at, created_at) DESC
        LIMIT 1
    ")->fetch() ?: null);
    $summary = $live ? election_summary($pdo, (int)$live['id']) : null;
} catch (\Exception $e) {
    $live = $last = $summary = null;
}

$pageTitle = 'Welcome';
require_once __DIR__ . '/../app/views/partials/header.php';
?>

<section class="hero">
  <div>
    <h1>Vote online, once per position.</h1>
    <p class="lead">Log in with your <?php echo e(strtolower(voter_id_label())); ?> and PIN, choose a candidate for each position, and confirm your ballot in one step.</p>
    <div class="cta">
      <a class="btn btn-primary btn-lg" href="<?php echo e(home_for_session() ?? '/login.php'); ?>"><?php echo home_for_session() ? 'Open dashboard' : 'Log in to vote'; ?><?php echo icon('arrow-right'); ?></a>
      <a class="btn btn-ghost btn-lg" href="/results.php"><?php echo icon('chart'); ?>See results</a>
    </div>
  </div>

  <div class="card status-card">
    <div class="card-body">
      <?php if ($live): ?>
        <div><?php echo phase_badge($live); ?></div>
        <div>
          <h2><?php echo e($live['name']); ?></h2>
          <p class="muted small"><?php echo $live['ends_at'] ? 'Voting closes ' . fmt_datetime($live['ends_at']) : 'Voting is open'; ?></p>
        </div>
        <?php if ($live['ends_at']): ?>
          <div class="countdown" data-countdown="<?php echo e(date('Y-m-d\TH:i:s', strtotime($live['ends_at']))); ?>">
            <div><b data-unit="d">00</b><span>Days</span></div>
            <div><b data-unit="h">00</b><span>Hours</span></div>
            <div><b data-unit="m">00</b><span>Mins</span></div>
            <div><b data-unit="s">00</b><span>Secs</span></div>
          </div>
        <?php endif; ?>
        <div>
          <div class="meter-row"><span>Turnout so far</span><strong><?php echo min(100, $summary['turnout']); ?>%</strong></div>
          <?php echo meter($summary['turnout'], 'Turnout'); ?>
        </div>
      <?php elseif ($last): ?>
        <div><span class="badge badge-muted">No election running</span></div>
        <div>
          <h2><?php echo e($last['name']); ?></h2>
          <p class="muted small">The most recent election<?php echo $last['ends_at'] ? ', closed ' . fmt_datetime($last['ends_at'], 'd M Y') : ''; ?>.</p>
        </div>
        <a class="btn btn-ghost" href="/results.php?election_id=<?php echo (int)$last['id']; ?>"><?php echo icon('chart'); ?>View final results</a>
      <?php else: ?>
        <div><span class="badge badge-muted">No elections yet</span></div>
        <p class="muted">When an election opens, it will show here with a countdown to closing time.</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="features" aria-label="How it works">
  <div class="card feature">
    <?php echo icon('check-circle'); ?>
    <h3>One vote per position</h3>
    <p>The database itself rejects a second vote for the same position, even if two requests arrive at once.</p>
  </div>
  <div class="card feature">
    <?php echo icon('lock'); ?>
    <h3>Private login</h3>
    <p>PINs are stored hashed, and repeated wrong guesses are locked out for a while.</p>
  </div>
  <div class="card feature">
    <?php echo icon('chart'); ?>
    <h3>Open results</h3>
    <p>Anyone can follow turnout and per-position counts while voting is open and after it closes.</p>
  </div>
</section>

<?php require_once __DIR__ . '/../app/views/partials/footer.php'; ?>
