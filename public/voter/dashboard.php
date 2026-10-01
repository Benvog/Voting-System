<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/lib/helpers.php';
require_once __DIR__ . '/../../app/lib/auth.php';

start_secure_session();
$pdo = db();
require_voter_active($pdo);

$voterId  = (int)$_SESSION['voter_id'];
$election = current_election($pdo);

// If nothing is live, explain why: not started yet, or already finished.
$upcoming = null;
if (!$election) {
    $upcoming = $pdo->query("
        SELECT id, name, status, starts_at, ends_at FROM elections
        WHERE status = 'active' AND starts_at > NOW()
        ORDER BY starts_at LIMIT 1
    ")->fetch() ?: null;
}

$rows = [];
if ($election) {
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, c.name AS voted_for
        FROM positions p
        LEFT JOIN votes v ON v.position_id = p.id AND v.voter_id = :v AND v.election_id = :e1
        LEFT JOIN candidates c ON c.id = v.candidate_id
        WHERE p.election_id = :e2 AND p.is_active = 1
        ORDER BY p.sort_order, p.id
    ");
    $stmt->execute([':v' => $voterId, ':e1' => (int)$election['id'], ':e2' => (int)$election['id']]);
    $rows = $stmt->fetchAll();
}
$total     = count($rows);
$done      = count(array_filter($rows, fn($r) => $r['voted_for'] !== null));
$remaining = $total - $done;

$pageTitle = 'Your ballot';
$layout    = 'voter';
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<?php if (!$election): ?>
  <div class="card">
    <div class="empty">
      <?php echo icon($upcoming ? 'calendar' : 'ballot'); ?>
      <?php if ($upcoming): ?>
        <h3><?php echo e($upcoming['name']); ?> opens <?php echo e(fmt_datetime($upcoming['starts_at'], 'l d M, H:i')); ?></h3>
        <p>Come back then and log in with your <?php echo e(strtolower(voter_id_label())); ?> and PIN.</p>
      <?php else: ?>
        <h3>No election is open right now</h3>
        <p>When voting opens, your ballot will appear here.</p>
        <a class="btn btn-ghost" href="/results.php"><?php echo icon('chart'); ?>See past results</a>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
  <div class="page-head">
    <div>
      <h1><?php echo e($election['name']); ?></h1>
      <p><?php echo $election['ends_at'] ? 'Voting closes ' . e(fmt_datetime($election['ends_at'], 'l d M, H:i')) . '.' : 'Voting is open.'; ?></p>
    </div>
    <?php echo phase_badge($election); ?>
  </div>

  <?php if ($total === 0): ?>
    <div class="card"><div class="empty"><?php echo icon('list'); ?><h3>The ballot isn't ready yet</h3><p>No positions have been added. Check back soon.</p></div></div>
  <?php else: ?>
    <section class="card">
      <div class="card-body stack">
        <?php if ($remaining === 0): ?>
          <div class="centered">
            <div class="done-mark"><?php echo icon('check'); ?></div>
            <h2>You've voted in every position</h2>
            <p class="muted">Thank you. Your votes are recorded and can't be changed.</p>
          </div>
        <?php else: ?>
          <div>
            <div class="meter-row"><span><?php echo $done === 0 ? 'You haven\'t voted yet' : 'You\'ve voted in ' . $done . ' of ' . $total . ' positions'; ?></span><strong><?php echo plural($remaining, 'position') . ' left'; ?></strong></div>
            <?php echo meter($total ? $done / $total * 100 : 0, 'Share of positions voted'); ?>
          </div>
          <a class="btn btn-primary btn-lg btn-block" href="/voter/ballot.php"><?php echo $done === 0 ? 'Start voting' : 'Continue voting'; ?><?php echo icon('arrow-right'); ?></a>
        <?php endif; ?>
        <?php if ($election['ends_at']): ?>
          <div>
            <div class="meter-row"><span>Time left to vote</span></div>
            <div class="countdown" data-countdown="<?php echo e(date('Y-m-d\TH:i:s', strtotime($election['ends_at']))); ?>">
              <div><b data-unit="d">00</b><span>Days</span></div>
              <div><b data-unit="h">00</b><span>Hours</span></div>
              <div><b data-unit="m">00</b><span>Mins</span></div>
              <div><b data-unit="s">00</b><span>Secs</span></div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="card" aria-labelledby="ballot-title">
      <div class="card-head"><h2 id="ballot-title">Your ballot</h2></div>
      <ul class="review-list">
        <?php foreach ($rows as $r): ?>
          <li>
            <div>
              <div class="pos"><?php echo e($r['name']); ?></div>
              <div class="pick<?php echo $r['voted_for'] === null ? ' is-none' : ''; ?>"><?php echo $r['voted_for'] !== null ? e($r['voted_for']) : 'Not voted yet'; ?></div>
            </div>
            <?php echo $r['voted_for'] !== null ? '<span class="badge badge-live">Voted</span>' : '<span class="badge badge-muted">Open</span>'; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
