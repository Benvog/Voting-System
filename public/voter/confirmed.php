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

$pageTitle = 'Ballot submitted';
$layout    = 'voter';
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<div class="stack">
  <div class="centered">
    <div class="done-mark"><?php echo icon('check'); ?></div>
    <h1>Your ballot is in</h1>
    <p class="muted">Recorded <?php echo e(fmt_datetime($receipt['at'], 'd M Y, H:i')); ?> for <?php echo e($receipt['election']); ?>.</p>
  </div>

  <section class="card" aria-labelledby="receipt-title">
    <div class="card-head"><h2 id="receipt-title">What you voted for</h2></div>
    <ul class="review-list">
      <?php foreach ($receipt['votes'] as $v): ?>
        <li><div><div class="pos"><?php echo e($v['position']); ?></div><div class="pick"><?php echo e($v['candidate']); ?></div></div><?php echo icon('check-circle', 'is-good'); ?></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <div class="form-actions">
    <a class="btn btn-primary" href="/voter/dashboard.php">Back to your ballot</a>
    <a class="btn btn-ghost" href="/logout.php"><?php echo icon('logout'); ?>Log out</a>
  </div>
</div>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
