<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/lib/helpers.php';
require_once __DIR__ . '/../../app/lib/auth.php';
require_once __DIR__ . '/../../app/views/results.php';

start_secure_session();
require_admin();

$pdo       = db();
$elections = $pdo->query("SELECT * FROM elections ORDER BY FIELD(status, 'active', 'closed', 'draft'), id DESC")->fetchAll();
$wanted    = (int)($_GET['election_id'] ?? (current_election($pdo)['id'] ?? 0));
$election  = null;
foreach ($elections as $el) {
    if ((int)$el['id'] === $wanted) $election = $el;
}
$election ??= $elections[0] ?? null;
$isLive = $election && election_phase($election) === 'live';

$pageTitle = 'Results';
$layout    = 'admin';
$activeNav = 'results';
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<div class="page-head">
  <div>
    <h1>Results</h1>
    <p>Turnout and vote counts per position<?php echo $isLive ? ', updated live' : ''; ?>.</p>
  </div>
  <?php if ($election && $election['status'] !== 'draft'): ?>
    <div class="actions">
      <a class="btn btn-ghost" href="/results.php?election_id=<?php echo (int)$election['id']; ?>" target="_blank" rel="noopener"><?php echo icon('external'); ?>Public page</a>
    </div>
  <?php endif; ?>
</div>

<?php if (!$election): ?>
  <div class="card"><div class="empty"><?php echo icon('chart'); ?><h3>No elections yet</h3><p>Results appear here once an election has votes.</p><a class="btn btn-primary" href="/admin/elections.php#new">Create an election</a></div></div>
<?php else: ?>
  <form class="filter-row" method="get">
    <label class="sr-only" for="election_id">Election</label>
    <select class="select" id="election_id" name="election_id" data-autosubmit>
      <?php foreach ($elections as $el): ?>
        <option value="<?php echo (int)$el['id']; ?>" <?php echo (int)$el['id'] === (int)$election['id'] ? 'selected' : ''; ?>><?php echo e($el['name']); ?> (<?php echo e($el['status']); ?>)</option>
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

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
