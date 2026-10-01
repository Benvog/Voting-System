<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/lib/helpers.php';
require_once __DIR__ . '/../app/lib/auth.php';
require_once __DIR__ . '/../app/views/results.php';

start_secure_session();

$pdo = db();

// Drafts stay private; only elections that have opened are public.
$elections = $pdo->query("
    SELECT * FROM elections
    WHERE status IN ('active', 'closed')
    ORDER BY FIELD(status, 'active', 'closed'), id DESC
")->fetchAll();
$wanted   = (int)($_GET['election_id'] ?? 0);
$election = null;
foreach ($elections as $el) {
    if ((int)$el['id'] === $wanted) $election = $el;
}
$election ??= $elections[0] ?? null;
$isLive = $election && election_phase($election) === 'live';

$pageTitle = 'Results';
require_once __DIR__ . '/../app/views/partials/header.php';
?>

<div class="page-head">
  <div>
    <h1>Election results</h1>
    <p><?php echo $isLive ? 'Voting is still open, so these numbers will change.' : 'Final counts for elections that have closed.'; ?></p>
  </div>
</div>

<?php if (!$election): ?>
  <div class="card"><div class="empty"><?php echo icon('chart'); ?><h3>No results yet</h3><p>Results appear here once an election opens.</p></div></div>
<?php else: ?>
  <form class="filter-row" method="get">
    <label class="sr-only" for="election_id">Election</label>
    <select class="select" id="election_id" name="election_id" data-autosubmit>
      <?php foreach ($elections as $el): ?>
        <option value="<?php echo (int)$el['id']; ?>" <?php echo (int)$el['id'] === (int)$election['id'] ? 'selected' : ''; ?>><?php echo e($el['name']); ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-ghost" type="submit">Show</button></noscript>
    <?php echo phase_badge($election); ?>
    <?php if ($isLive): ?>
      <span class="refresh-note">Refreshing in <span data-autorefresh="60">60</span>s</span>
    <?php endif; ?>
  </form>

  <?php render_results($pdo, $election); ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../app/views/partials/footer.php'; ?>
