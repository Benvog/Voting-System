<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../app/lib/auth.php';

start_secure_session();
require_voter_active(db());

$pdo     = db();
$voterId = (int)$_SESSION['voter_id'];

/* ── Active election ── */
$activeElection = $pdo->query("
    SELECT id, name, status, starts_at, ends_at
    FROM elections
    WHERE status = 'active'
      AND (starts_at IS NULL OR starts_at <= NOW())
      AND (ends_at   IS NULL OR ends_at   >= NOW())
    LIMIT 1
")->fetch();

/* ── Positions + voting progress ── */
$rows           = [];
$totalPositions = 0;
$votedCount     = 0;

if ($activeElection) {
    $stmt = $pdo->prepare("
        SELECT
            p.id   AS position_id,
            p.name AS position_name,
            v.id   AS vote_id,
            c.name AS voted_candidate_name
        FROM positions p
        LEFT JOIN votes v
               ON v.position_id = p.id
              AND v.voter_id     = :vid
              AND v.election_id  = :eid1
        LEFT JOIN candidates c ON c.id = v.candidate_id
        WHERE p.election_id = :eid2
          AND p.is_active   = 1
        ORDER BY p.sort_order ASC, p.created_at ASC
    ");
    $stmt->execute([
        ':vid'  => $voterId,
        ':eid1' => (int)$activeElection['id'],
        ':eid2' => (int)$activeElection['id'],
    ]);
    $rows           = $stmt->fetchAll();
    $totalPositions = count($rows);
    foreach ($rows as $r) {
        if (!empty($r['vote_id'])) $votedCount++;
    }
}

$progressPct = $totalPositions > 0 ? round(($votedCount / $totalPositions) * 100) : 0;
$allDone     = $totalPositions > 0 && $votedCount === $totalPositions;

$pageTitle = 'My Ballot';
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<div class="page-header">
  <div>
    <h1>My Ballot</h1>
    <p>Logged in as <strong><?php echo htmlspecialchars((string)($_SESSION['voter_uid'] ?? '')); ?></strong></p>
  </div>
</div>

<?php if (!$activeElection): ?>

  <div class="card" style="text-align:center; padding:64px 32px;">
    <div style="font-size:3rem; margin-bottom:16px;">🗳</div>
    <h2 style="font-family:'Sora',sans-serif; font-weight:800; color:var(--text); margin-bottom:8px;">No Active Election</h2>
    <p class="text-muted">There is no election running right now. Check back later.</p>
  </div>

<?php else: ?>

  <!-- Election Info + Countdown -->
  <div class="card mb-2" style="border-left: 4px solid var(--primary);">

    <div class="flex-between" style="margin-bottom: <?php echo $activeElection['ends_at'] ? '20px' : '0'; ?>;">
      <div>
        <div class="section-title" style="margin-bottom:4px;">Current Election</div>
        <div style="font-family:'Sora',sans-serif; font-weight:800; font-size:1.3rem; color:var(--text);">
          <?php echo htmlspecialchars($activeElection['name']); ?>
        </div>
        <?php if ($activeElection['ends_at']): ?>
          <div class="text-muted mt-1" style="font-size:.82rem;">
            Closes <?php echo date('d M Y, H:i', strtotime($activeElection['ends_at'])); ?>
          </div>
        <?php endif; ?>
      </div>
      <span class="badge badge-active" style="font-size:.85rem; padding:6px 16px;">Live</span>
    </div>

    <?php if ($activeElection['ends_at']): ?>
    <!-- Countdown -->
    <div style="border-top: 1px solid var(--border); padding-top: 18px;">
      <div style="
        font-size: .72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .1em;
        color: var(--text-muted);
        margin-bottom: 12px;
      ">Time remaining to vote</div>

      <div id="countdown-wrap" style="
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
        max-width: 380px;
      ">
        <?php
          $units = ['days' => 'Days', 'hours' => 'Hours', 'mins' => 'Mins', 'secs' => 'Secs'];
          foreach ($units as $id => $label):
        ?>
        <div style="
          background: var(--bg-alt);
          border: 1px solid var(--border);
          border-radius: var(--radius-sm);
          padding: 14px 8px 10px;
          text-align: center;
          position: relative;
          overflow: hidden;
        ">
          <div style="
            position: absolute; top:0; left:0; right:0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--primary-light));
          "></div>
          <div id="cd-<?php echo $id; ?>" style="
            font-family: 'Sora', sans-serif;
            font-weight: 800;
            font-size: 1.8rem;
            color: var(--primary);
            line-height: 1;
            transition: color .3s;
          ">00</div>
          <div style="
            font-size: .65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--text-muted);
            margin-top: 5px;
          "><?php echo $label; ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <script>
    (function () {
      var deadline = new Date("<?php echo date('Y-m-d\TH:i:s', strtotime($activeElection['ends_at'])); ?>").getTime();
      var ids      = ['days','hours','mins','secs'];
      var els      = {};
      ids.forEach(function(id) { els[id] = document.getElementById('cd-' + id); });

      function pad(n) { return n < 10 ? '0' + n : String(n); }

      function tick() {
        var diff = deadline - Date.now();

        if (diff <= 0) {
          document.getElementById('countdown-wrap').innerHTML =
            '<p style="color:var(--text-muted);font-size:.9rem;">Voting period has ended.</p>';
          return;
        }

        var d = Math.floor(diff / 86400000);
        var h = Math.floor((diff % 86400000) / 3600000);
        var m = Math.floor((diff % 3600000)  / 60000);
        var s = Math.floor((diff % 60000)    / 1000);

        els.days.textContent  = pad(d);
        els.hours.textContent = pad(h);
        els.mins.textContent  = pad(m);
        els.secs.textContent  = pad(s);

        // Turn red under 1 hour
        var col = diff < 3600000 ? 'var(--danger)' : 'var(--primary)';
        ids.forEach(function(id) { els[id].style.color = col; });
      }

      tick();
      setInterval(tick, 1000);
    })();
    </script>
    <?php endif; ?>

  </div>

  <!-- Progress -->
  <div class="card mb-2">
    <div class="flex-between mb-1">
      <div class="section-title" style="margin-bottom:0;">Your Progress</div>
      <span style="font-family:'Sora',sans-serif; font-weight:700; color:var(--primary); font-size:.95rem;">
        <?php echo $votedCount; ?> / <?php echo $totalPositions; ?> positions
      </span>
    </div>
    <div class="progress-wrap">
      <div class="progress-bar" style="width: <?php echo $progressPct; ?>%;"></div>
    </div>

    <?php if ($allDone): ?>
      <div class="alert alert-success mt-2" style="margin-bottom:0;">
        ✅ You have voted in all positions. Your ballot is complete!
      </div>
    <?php elseif ($votedCount > 0): ?>
      <p class="text-muted mt-1" style="font-size:.85rem;">
        <?php echo $totalPositions - $votedCount; ?> position<?php echo ($totalPositions - $votedCount) !== 1 ? 's' : ''; ?> remaining.
      </p>
    <?php else: ?>
      <p class="text-muted mt-1" style="font-size:.85rem;">You haven't voted yet. Cast your votes below.</p>
    <?php endif; ?>
  </div>

  <!-- Positions -->
  <?php if (empty($rows)): ?>
    <div class="card" style="text-align:center; padding:48px; color:var(--text-muted);">
      No positions have been set up for this election yet.
    </div>
  <?php else: ?>

    <div class="section-title">Positions</div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Position</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $i => $r): ?>
          <tr>
            <td class="text-muted"><?php echo $i + 1; ?></td>
            <td><strong><?php echo htmlspecialchars($r['position_name']); ?></strong></td>
            <td>
              <?php if (!empty($r['vote_id'])): ?>
                <span class="badge badge-voted">✓ Voted</span>
                <span class="text-muted" style="font-size:.82rem; margin-left:6px;">
                  <?php echo htmlspecialchars((string)$r['voted_candidate_name']); ?>
                </span>
              <?php else: ?>
                <span class="badge badge-pending">Pending</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (empty($r['vote_id'])): ?>
                <a href="vote.php?position_id=<?php echo (int)$r['position_id']; ?>" class="btn btn-primary btn-sm">
                  Vote Now →
                </a>
              <?php else: ?>
                <span class="text-muted" style="font-size:.85rem;">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
