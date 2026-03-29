<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../app/lib/auth.php';

start_secure_session();
require_admin();

$pdo = db();

/* ── Election filter ── */
$elections        = $pdo->query("SELECT id, name, status FROM elections ORDER BY created_at DESC")->fetchAll();
$filterElectionId = (int)($_GET['election_id'] ?? ($elections[0]['id'] ?? 0));

$selectedElection = null;
foreach ($elections as $e) {
    if ((int)$e['id'] === $filterElectionId) { $selectedElection = $e; break; }
}

/* ── Results data ── */
$results = [];
$totalVotesCast = 0;

if ($filterElectionId > 0) {
    // Get positions for this election
    $stmt = $pdo->prepare("
        SELECT id, name FROM positions
        WHERE election_id = :eid AND is_active = 1
        ORDER BY sort_order ASC, name ASC
    ");
    $stmt->execute([':eid' => $filterElectionId]);
    $positions = $stmt->fetchAll();

    foreach ($positions as $pos) {
        // Get candidates + vote counts for this position
        $stmt = $pdo->prepare("
            SELECT c.id, c.name,
                   COUNT(v.id) AS vote_count
            FROM candidates c
            LEFT JOIN votes v ON v.candidate_id = c.id
                              AND v.position_id = :pid
                              AND v.election_id = :eid
            WHERE c.position_id = :pid2
              AND c.is_active = 1
            GROUP BY c.id, c.name
            ORDER BY vote_count DESC, c.name ASC
        ");
        $stmt->execute([
            ':pid'  => $pos['id'],
            ':eid'  => $filterElectionId,
            ':pid2' => $pos['id'],
        ]);
        $candidates = $stmt->fetchAll();

        $positionTotal = array_sum(array_column($candidates, 'vote_count'));
        $totalVotesCast += $positionTotal;

        $results[] = [
            'position'      => $pos,
            'candidates'    => $candidates,
            'total_votes'   => $positionTotal,
        ];
    }
}

/* ── Voter turnout ── */
$totalVoters  = (int)$pdo->query("SELECT COUNT(*) FROM voters WHERE is_active = 1")->fetchColumn();
$votedVoters  = 0;
if ($filterElectionId > 0) {
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT voter_id) FROM votes WHERE election_id = :eid");
    $stmt->execute([':eid' => $filterElectionId]);
    $votedVoters = (int)$stmt->fetchColumn();
}
$turnoutPct = $totalVoters > 0 ? round(($votedVoters / $totalVoters) * 100) : 0;

$pageTitle = 'Election Results';
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<div class="page-header">
  <div>
    <h1>Results</h1>
    <p>Live vote counts — auto-refreshes every 30 seconds while election is active.</p>
  </div>
  <div style="display:flex;align-items:center;gap:10px;">
  <?php if ($selectedElection && $selectedElection['status'] === 'active'): ?>
  <span style="font-size:.8rem;color:var(--text-muted);">Auto-refresh in <strong id="refresh-counter">30</strong>s</span>
  <?php endif; ?>
  <a href="?election_id=<?php echo $filterElectionId; ?>" class="btn btn-ghost">↻ Refresh Now</a>
</div>
</div>

<?php if (empty($elections)): ?>
  <div class="card" style="text-align:center; padding:48px; color:var(--text-muted);">
    No elections found. <a href="/admin/elections.php">Create an election first →</a>
  </div>
<?php else: ?>

<!-- Election Filter -->
<div class="card mb-2">
  <form method="get" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
    <div class="form-group" style="flex:1; min-width:220px; margin-bottom:0;">
      <label for="election_id">Election</label>
      <select class="form-control" name="election_id" id="election_id" onchange="this.form.submit()">
        <?php foreach ($elections as $e): ?>
          <option value="<?php echo (int)$e['id']; ?>" <?php echo (int)$e['id'] === $filterElectionId ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($e['name']); ?> (<?php echo htmlspecialchars($e['status']); ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</div>

<!-- Turnout Summary -->
<?php if ($selectedElection): ?>
<div class="stat-grid mb-2">
  <div class="stat-card">
    <div class="stat-label">Election Status</div>
    <div class="stat-value" style="font-size:1.3rem; margin-top:6px;">
      <?php
        $badge = match($selectedElection['status']) {
          'active' => 'badge-active',
          'closed' => 'badge-closed',
          default  => 'badge-draft',
        };
      ?>
      <span class="badge <?php echo $badge; ?>" style="font-size:.9rem; padding: 5px 14px;">
        <?php echo htmlspecialchars($selectedElection['status']); ?>
      </span>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Voters Participated</div>
    <div class="stat-value"><?php echo $votedVoters; ?> <span style="font-size:1rem; color:var(--text-muted);">/ <?php echo $totalVoters; ?></span></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Turnout</div>
    <div class="stat-value"><?php echo $turnoutPct; ?>%</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Votes Cast</div>
    <div class="stat-value"><?php echo $totalVotesCast; ?></div>
  </div>
</div>

<!-- Turnout bar -->
<div class="card mb-2">
  <div class="flex-between mb-1">
    <div class="section-title" style="margin-bottom:0;">Voter Turnout</div>
    <span style="font-size:.9rem; font-weight:700; color:var(--primary);"><?php echo $turnoutPct; ?>%</span>
  </div>
  <div class="progress-wrap">
    <div class="progress-bar" style="width: <?php echo $turnoutPct; ?>%;"></div>
  </div>
  <p class="text-muted mt-1" style="font-size:.82rem;"><?php echo $votedVoters; ?> of <?php echo $totalVoters; ?> active voters have participated.</p>
</div>
<?php endif; ?>

<!-- Results per Position -->
<?php if (empty($results)): ?>
  <div class="card" style="text-align:center; padding:48px; color:var(--text-muted);">
    No positions or votes found for this election.
  </div>
<?php else: ?>
  <?php foreach ($results as $r): ?>
    <div class="card mb-2">

      <div class="flex-between mb-2">
        <div>
          <div class="section-title" style="margin-bottom:2px;">📋 <?php echo htmlspecialchars($r['position']['name']); ?></div>
          <span class="text-muted" style="font-size:.85rem;"><?php echo $r['total_votes']; ?> vote<?php echo $r['total_votes'] !== 1 ? 's' : ''; ?> cast</span>
        </div>
        <?php if (!empty($r['candidates'])): ?>
          <?php $leader = $r['candidates'][0]; ?>
          <?php if ((int)$leader['vote_count'] > 0): ?>
            <div style="text-align:right;">
              <div style="font-size:.75rem; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); margin-bottom:2px;">Leading</div>
              <div style="font-family:'Sora',sans-serif; font-weight:800; color:var(--primary); font-size:1rem;">
                🏆 <?php echo htmlspecialchars($leader['name']); ?>
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <?php if (empty($r['candidates'])): ?>
        <p class="text-muted" style="font-size:.9rem;">No candidates registered for this position.</p>
      <?php else: ?>
        <?php foreach ($r['candidates'] as $i => $c): ?>
          <?php
            $pct     = $r['total_votes'] > 0 ? round(($c['vote_count'] / $r['total_votes']) * 100) : 0;
            $isFirst = $i === 0 && (int)$c['vote_count'] > 0;
          ?>
          <div class="result-bar-wrap">
            <div class="result-bar-label">
              <span style="font-weight: <?php echo $isFirst ? '700' : '500'; ?>; color: <?php echo $isFirst ? 'var(--primary)' : 'var(--text)'; ?>;">
                <?php echo $isFirst ? '🏆 ' : ''; ?><?php echo htmlspecialchars($c['name']); ?>
              </span>
              <span style="font-family:'Sora',sans-serif; font-weight:700; color:var(--text-muted);">
                <?php echo (int)$c['vote_count']; ?> vote<?php echo (int)$c['vote_count'] !== 1 ? 's' : ''; ?>
                <span style="font-size:.8rem;">(<?php echo $pct; ?>%)</span>
              </span>
            </div>
            <div class="result-bar-bg">
              <div
                class="result-bar-fill"
                style="width: <?php echo $pct; ?>%; <?php echo $isFirst ? '' : 'background: linear-gradient(90deg, var(--primary-light), var(--text-faint));'; ?>"
              ></div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>

<?php if ($selectedElection && $selectedElection['status'] === 'active'): ?>
<script>
(function () {
  var seconds   = 30;
  var remaining = seconds;
  var countEl   = document.getElementById('refresh-counter');

  function tick() {
    remaining--;
    if (countEl) countEl.textContent = remaining;
    if (remaining <= 0) window.location.reload();
  }

  setInterval(tick, 1000);
})();
</script>
<?php endif; ?>
