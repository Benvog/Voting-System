<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/lib/helpers.php';
require_once __DIR__ . '/../../app/lib/auth.php';

start_secure_session();
require_admin();

$pdo = db();

$voterCounts = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(is_active = 1), 0) AS active FROM voters")->fetch();
$elections   = $pdo->query("SELECT id, name, status, starts_at, ends_at FROM elections ORDER BY id DESC")->fetchAll();
$activeOnes  = array_values(array_filter($elections, fn($e) => $e['status'] === 'active'));

// The election this page is about: the live one, else the newest active,
// else the newest draft being prepared, else the latest of any kind.
$focus = current_election($pdo)
    ?? ($activeOnes[0] ?? null)
    ?? (array_values(array_filter($elections, fn($e) => $e['status'] === 'draft'))[0] ?? null)
    ?? ($elections[0] ?? null);
$summary = $focus ? election_summary($pdo, (int)$focus['id']) : null;
$phase   = $focus ? election_phase($focus) : null;

$byStatus = ['draft' => 0, 'active' => 0, 'closed' => 0];
foreach ($elections as $e) {
    $byStatus[$e['status']]++;
}

$pageTitle = 'Overview';
$layout    = 'admin';
$activeNav = 'overview';
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<div class="page-head">
  <div>
    <h1>Overview</h1>
    <p>Where your elections stand right now.</p>
  </div>
  <div class="actions">
    <a class="btn btn-primary" href="/admin/elections.php#new"><?php echo icon('plus'); ?>New election</a>
  </div>
</div>

<?php if (count($activeOnes) > 1): ?>
  <div class="flashes">
    <div class="alert alert-warn"><?php echo icon('alert'); ?>
      <span><strong><?php echo count($activeOnes); ?> elections are active at once.</strong> Voters only see the newest one. Close the others on the <a href="/admin/elections.php">Elections</a> page.</span>
    </div>
  </div>
<?php endif; ?>

<div class="stats">
  <div class="card stat">
    <div class="stat-label">Registered voters</div>
    <div class="stat-value"><?php echo number_format((int)$voterCounts['active']); ?></div>
    <div class="stat-sub"><?php echo (int)$voterCounts['total'] - (int)$voterCounts['active']; ?> disabled</div>
  </div>
  <div class="card stat">
    <div class="stat-label">Turnout</div>
    <div class="stat-value"><?php echo $summary ? min(100, $summary['turnout']) . '%' : '—'; ?></div>
    <div class="stat-sub"><?php echo $summary ? number_format($summary['voted']) . ' of ' . number_format($summary['eligible']) . ' voted' : 'No election yet'; ?></div>
  </div>
  <div class="card stat">
    <div class="stat-label">Votes cast</div>
    <div class="stat-value"><?php echo $summary ? number_format($summary['votes']) : '—'; ?></div>
    <div class="stat-sub"><?php echo $summary ? 'Across ' . plural($summary['positions'], 'position') : 'No election yet'; ?></div>
  </div>
  <div class="card stat">
    <div class="stat-label">Elections</div>
    <div class="stat-value"><?php echo count($elections); ?></div>
    <div class="stat-sub"><?php echo $byStatus['draft']; ?> draft · <?php echo $byStatus['closed']; ?> closed</div>
  </div>
</div>

<?php if (!$focus): ?>
  <div class="card">
    <div class="empty">
      <?php echo icon('ballot'); ?>
      <h3>No elections yet</h3>
      <p>Create an election, add its positions and candidates, register voters, then open voting.</p>
      <a class="btn btn-primary" href="/admin/elections.php#new"><?php echo icon('plus'); ?>Create your first election</a>
    </div>
  </div>
<?php else:
  $checks = [
    ['done' => $summary['positions'] > 0, 'text' => $summary['positions'] > 0 ? plural($summary['positions'], 'position') . ' set up' : 'Add at least one position'],
    ['done' => $summary['positions'] > 0 && $summary['empty_positions'] === 0, 'text' => $summary['empty_positions'] > 0 ? plural($summary['empty_positions'], 'position has', 'positions have') . ' no candidates' : 'Every position has candidates'],
    ['done' => $summary['eligible'] > 0, 'text' => $summary['eligible'] > 0 ? plural($summary['eligible'], 'voter') . ' registered' : 'Register voters'],
    ['done' => (bool)$focus['ends_at'], 'text' => $focus['ends_at'] ? 'Closes ' . fmt_datetime($focus['ends_at']) : 'No closing time set (voting stays open until you close it)'],
    ['done' => $focus['status'] !== 'draft', 'text' => $focus['status'] !== 'draft' ? 'Voting opened' : 'Open voting when ready'],
  ];
?>
<div class="grid-2">
  <section class="card" aria-labelledby="focus-title">
    <div class="card-head">
      <div>
        <h2 id="focus-title"><?php echo e($focus['name']); ?></h2>
        <div class="sub"><?php echo $phase === 'live' ? 'Voting is open' : ($phase === 'draft' ? 'Being prepared' : ($phase === 'scheduled' ? 'Opens ' . fmt_datetime($focus['starts_at']) : ($phase === 'ended' ? 'Closing time has passed' : 'Finished'))); ?></div>
      </div>
      <?php echo phase_badge($focus); ?>
    </div>
    <div class="card-body stack">
      <div>
        <div class="meter-row"><span>Turnout</span><span><strong class="nowrap"><?php echo min(100, $summary['turnout']); ?>%</strong> · <?php echo number_format($summary['voted']); ?> of <?php echo number_format($summary['eligible']); ?></span></div>
        <?php echo meter($summary['turnout'], 'Turnout'); ?>
      </div>
      <?php if ($phase === 'live' && $focus['ends_at']): ?>
        <div>
          <div class="meter-row"><span>Time left to vote</span></div>
          <div class="countdown" data-countdown="<?php echo e(date('Y-m-d\TH:i:s', strtotime($focus['ends_at']))); ?>">
            <div><b data-unit="d">00</b><span>Days</span></div>
            <div><b data-unit="h">00</b><span>Hours</span></div>
            <div><b data-unit="m">00</b><span>Mins</span></div>
            <div><b data-unit="s">00</b><span>Secs</span></div>
          </div>
        </div>
      <?php endif; ?>
      <dl class="kv">
        <dt>Positions</dt><dd><?php echo number_format($summary['positions']); ?></dd>
        <dt>Candidates</dt><dd><?php echo number_format($summary['candidates']); ?></dd>
        <dt>Opens</dt><dd><?php echo $focus['starts_at'] ? fmt_datetime($focus['starts_at']) : 'As soon as it is activated'; ?></dd>
        <dt>Closes</dt><dd><?php echo $focus['ends_at'] ? fmt_datetime($focus['ends_at']) : 'When you close it'; ?></dd>
      </dl>
      <div class="form-actions">
        <a class="btn btn-ghost" href="/admin/election.php?id=<?php echo (int)$focus['id']; ?>"><?php echo icon('edit'); ?>Manage</a>
        <a class="btn btn-ghost" href="/admin/results.php?election_id=<?php echo (int)$focus['id']; ?>"><?php echo icon('chart'); ?>Results</a>
      </div>
    </div>
  </section>

  <section class="card" aria-labelledby="checks-title">
    <div class="card-head">
      <div>
        <h2 id="checks-title">Readiness</h2>
        <div class="sub">What this election still needs</div>
      </div>
    </div>
    <ul class="checklist">
      <?php foreach ($checks as $c): ?>
        <li class="<?php echo $c['done'] ? 'is-done' : 'is-todo'; ?>">
          <?php echo icon($c['done'] ? 'check-circle' : 'alert'); ?>
          <span><span class="sr-only"><?php echo $c['done'] ? 'Done: ' : 'To do: '; ?></span><?php echo e($c['text']); ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
