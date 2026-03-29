<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../app/lib/auth.php';
require_once __DIR__ . '/../../app/lib/csrf.php';

start_secure_session();
require_voter_active(db());

$pdo        = db();
$voterId    = (int)$_SESSION['voter_id'];
$positionId = (int)($_GET['position_id'] ?? 0);
$error      = '';
$success    = '';

if ($positionId === 0) {
    header('Location: dashboard.php');
    exit;
}

/* ── Load active election ── */
$activeElection = $pdo->query("
    SELECT id, name, starts_at, ends_at
    FROM elections
    WHERE status = 'active'
      AND (starts_at IS NULL OR starts_at <= NOW())
      AND (ends_at   IS NULL OR ends_at   >= NOW())
    LIMIT 1
")->fetch();

if (!$activeElection) {
    header('Location: dashboard.php');
    exit;
}

$electionId = (int)$activeElection['id'];

/* ── Load position ── */
$stmt = $pdo->prepare("
    SELECT id, name FROM positions
    WHERE id = :pid AND election_id = :eid AND is_active = 1
    LIMIT 1
");
$stmt->execute([':pid' => $positionId, ':eid' => $electionId]);
$position = $stmt->fetch();

if (!$position) {
    header('Location: dashboard.php');
    exit;
}

/* ── Check already voted ── */
$stmt = $pdo->prepare("
    SELECT id FROM votes
    WHERE voter_id = :vid AND position_id = :pid AND election_id = :eid
    LIMIT 1
");
$stmt->execute([':vid' => $voterId, ':pid' => $positionId, ':eid' => $electionId]);
if ($stmt->fetch()) {
    header('Location: dashboard.php');
    exit;
}

/* ── Load candidates ── */
$stmt = $pdo->prepare("
    SELECT id, name, manifesto FROM candidates
    WHERE position_id = :pid AND is_active = 1
    ORDER BY name ASC
");
$stmt->execute([':pid' => $positionId]);
$candidates = $stmt->fetchAll();

/* ── Handle vote submission ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token       = $_POST['csrf_token'] ?? '';
    $candidateId = (int)($_POST['candidate_id'] ?? 0);

    if (!csrf_verify($token)) {
        $error = 'Invalid request. Please refresh and try again.';
    } elseif ($candidateId === 0) {
        $error = 'Please select a candidate before submitting.';
    } else {
        /* Verify candidate belongs to this position */
        $stmt = $pdo->prepare("
            SELECT id FROM candidates
            WHERE id = :cid AND position_id = :pid AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([':cid' => $candidateId, ':pid' => $positionId]);

        if (!$stmt->fetch()) {
            $error = 'Invalid candidate selection.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO votes (election_id, position_id, voter_id, candidate_id)
                    VALUES (:eid, :pid, :vid, :cid)
                ");
                $stmt->execute([
                    ':eid' => $electionId,
                    ':pid' => $positionId,
                    ':vid' => $voterId,
                    ':cid' => $candidateId,
                ]);
                header('Location: confirmed.php?voted=1');
                exit;
            } catch (\PDOException $e) {
                $error = 'You have already voted for this position.';
            }
        }
    }
}

/* ── Position progress (for back context) ── */
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM positions
    WHERE election_id = :eid AND is_active = 1
");
$stmt->execute([':eid' => $electionId]);
$totalPositions = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM votes
    WHERE voter_id = :vid AND election_id = :eid
");
$stmt->execute([':vid' => $voterId, ':eid' => $electionId]);
$votedCount = (int)$stmt->fetchColumn();

$pageTitle = 'Vote — ' . $position['name'];
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<!-- Back link -->
<div style="margin-bottom: 20px;">
  <a href="dashboard.php" class="btn btn-ghost btn-sm">← Back to My Ballot</a>
</div>

<div class="page-header">
  <div>
    <h1><?php echo htmlspecialchars($position['name']); ?></h1>
    <p>
      <?php echo htmlspecialchars($activeElection['name']); ?>
      &mdash; Select one candidate and confirm your vote.
    </p>
  </div>
  <span class="text-muted" style="font-size:.88rem; white-space:nowrap;">
    <?php echo $votedCount; ?> / <?php echo $totalPositions; ?> positions done
  </span>
</div>

<?php if ($error): ?>
  <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if (empty($candidates)): ?>
  <div class="card" style="text-align:center; padding:48px; color:var(--text-muted);">
    No candidates have been registered for this position yet.
  </div>
<?php else: ?>

  <form method="post" id="voteForm" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

    <!-- Candidate cards -->
    <div class="vote-grid" id="candidateGrid">
      <?php foreach ($candidates as $c): ?>
        <label class="vote-option" for="candidate_<?php echo (int)$c['id']; ?>">
          <input
            type="radio"
            name="candidate_id"
            id="candidate_<?php echo (int)$c['id']; ?>"
            value="<?php echo (int)$c['id']; ?>"
            style="position:absolute; opacity:0; pointer-events:none;"
          >

          <!-- Avatar circle -->
          <div style="
            width: 56px; height: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            display: flex; align-items: center; justify-content: center;
            font-family: 'Sora', sans-serif;
            font-weight: 800;
            font-size: 1.3rem;
            color: #fff;
            flex-shrink: 0;
          ">
            <?php echo strtoupper(mb_substr($c['name'], 0, 1)); ?>
          </div>

          <div style="font-family:'Sora',sans-serif; font-weight:700; font-size:1rem; color:var(--text);">
            <?php echo htmlspecialchars($c['name']); ?>
          </div>

          <?php if ($c['manifesto']): ?>
            <div style="font-size:.82rem; color:var(--text-muted); line-height:1.4; text-align:center;">
              <?php echo htmlspecialchars($c['manifesto']); ?>
            </div>
          <?php endif; ?>

          <!-- Selected indicator -->
          <div class="selected-check" style="
            display:none;
            background: var(--primary);
            color: #fff;
            border-radius: var(--radius-pill);
            padding: 3px 12px;
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .05em;
          ">✓ Selected</div>
        </label>
      <?php endforeach; ?>
    </div>

    <!-- Confirm section -->
    <div class="card mt-3" id="confirmSection" style="display:none; border: 2px solid var(--primary);">
      <div class="flex-between">
        <div>
          <div class="section-title" style="margin-bottom:4px; color:var(--primary);">Confirm Your Vote</div>
          <div style="font-size:.92rem; color:var(--text-muted);">
            You are voting for <strong id="selectedName" style="color:var(--text);"></strong>
            for <strong><?php echo htmlspecialchars($position['name']); ?></strong>.
          </div>
          <div class="text-muted mt-1" style="font-size:.82rem;">⚠️ This action cannot be undone.</div>
        </div>
        <button type="submit" class="btn btn-primary" style="flex-shrink:0;">
          Confirm Vote ✓
        </button>
      </div>
    </div>

  </form>

<?php endif; ?>

<script>
(function () {
  var cards    = document.querySelectorAll('.vote-option');
  var confirm  = document.getElementById('confirmSection');
  var selName  = document.getElementById('selectedName');

  cards.forEach(function (card) {
    card.addEventListener('click', function () {
      // Deselect all
      cards.forEach(function (c) {
        c.style.borderColor   = '';
        c.style.boxShadow     = '';
        c.style.background    = '';
        var chk = c.querySelector('.selected-check');
        if (chk) chk.style.display = 'none';
        var radio = c.querySelector('input[type="radio"]');
        if (radio) radio.checked = false;
      });

      // Select this one
      var radio = card.querySelector('input[type="radio"]');
      if (radio) radio.checked = true;

      card.style.borderColor = 'var(--primary)';
      card.style.boxShadow   = '0 0 0 3px var(--primary-glow)';
      card.style.background  = 'var(--surface-alt)';

      var chk = card.querySelector('.selected-check');
      if (chk) chk.style.display = 'block';

      // Show confirm bar
      var nameEl = card.querySelector('[style*="font-weight:700"]');
      if (selName && nameEl) selName.textContent = nameEl.textContent.trim();
      if (confirm) confirm.style.display = 'block';

      // Smooth scroll to confirm
      if (confirm) confirm.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
  });
})();
</script>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
