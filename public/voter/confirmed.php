<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/lib/helpers.php';
require_once __DIR__ . '/../../app/lib/auth.php';

start_secure_session();
require_voter_active(db());

// Only reachable straight after submitting a ballot; shown once.
$receipt = $_SESSION['receipt'] ?? null;
unset($_SESSION['receipt']);
if (!$receipt) {
    redirect('/voter/dashboard.php');
}

$demo = !empty($receipt['demo']); // a demo account's ballot, checked but not stored

$pageTitle = $demo ? 'Demo ballot' : 'Ballot submitted';
$layout    = 'voter';
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<div class="stack">
  <div class="centered">
    <div class="done-mark"><?php echo icon('check'); ?></div>
    <?php if ($demo): ?>
      <h1>That's how voting works</h1>
      <p class="muted">This is a demo account, so your ballot for <?php echo e($receipt['election']); ?> wasn't recorded. A real voter's ballot would be saved now, in one go.</p>
    <?php else: ?>
      <h1>Your ballot is in</h1>
      <p class="muted">Recorded <?php echo e(fmt_datetime($receipt['at'], 'd M Y, H:i')); ?> for <?php echo e($receipt['election']); ?>.</p>
    <?php endif; ?>
  </div>

  <section class="card" aria-labelledby="receipt-title">
    <div class="card-head"><h2 id="receipt-title"><?php echo $demo ? 'What you would have voted for' : 'What you voted for'; ?></h2></div>
    <ul class="review-list">
      <?php foreach ($receipt['votes'] as $v): ?>
        <li><div><div class="pos"><?php echo e($v['position']); ?></div><div class="pick"><?php echo e($v['candidate']); ?></div></div><?php echo icon('check-circle', 'is-good'); ?></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <div class="form-actions">
    <a class="btn btn-primary" href="/voter/dashboard.php">Back to your ballot</a>
    <a class="btn btn-ghost" href="/results.php"><?php echo icon('chart'); ?>See results</a>
    <a class="btn btn-ghost" href="/logout.php"><?php echo icon('logout'); ?>Log out</a>
  </div>
</div>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
