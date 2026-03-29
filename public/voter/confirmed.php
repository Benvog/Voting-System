<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../app/lib/auth.php';

start_secure_session();
require_voter_active(db());

$pdo     = db();
$voterId = (int)$_SESSION['voter_id'];

// Only accessible via redirect from vote.php with ?voted=1
if (empty($_GET['voted'])) {
    header('Location: dashboard.php');
    exit;
}

// Get the most recently cast vote for this voter
$stmt = $pdo->prepare("
    SELECT
        v.cast_at,
        c.name  AS candidate_name,
        p.name  AS position_name,
        e.name  AS election_name,
        (SELECT COUNT(*) FROM positions pos
         WHERE pos.election_id = e.id AND pos.is_active = 1) AS total_positions,
        (SELECT COUNT(*) FROM votes vt
         WHERE vt.voter_id = :vid2 AND vt.election_id = e.id) AS voted_count
    FROM votes v
    JOIN candidates c ON c.id = v.candidate_id
    JOIN positions  p ON p.id = v.position_id
    JOIN elections  e ON e.id = v.election_id
    WHERE v.voter_id = :vid
    ORDER BY v.cast_at DESC
    LIMIT 1
");
$stmt->execute([':vid' => $voterId, ':vid2' => $voterId]);
$receipt = $stmt->fetch();

if (!$receipt) {
    header('Location: dashboard.php');
    exit;
}

$allDone = (int)$receipt['voted_count'] >= (int)$receipt['total_positions'];

$pageTitle = 'Vote Recorded';
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<div style="max-width: 520px; margin: 48px auto 0;">

  <!-- Success icon -->
  <div style="text-align:center; margin-bottom: 28px;">
    <div style="
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 80px; height: 80px;
      background: linear-gradient(135deg, var(--success), #34d9c8);
      border-radius: 50%;
      font-size: 2.2rem;
      box-shadow: 0 8px 28px rgba(42,157,143,.3);
      animation: pop .4s cubic-bezier(.175,.885,.32,1.275) both;
    ">✓</div>
  </div>

  <style>
    @keyframes pop {
      from { transform: scale(.5); opacity: 0; }
      to   { transform: scale(1);  opacity: 1; }
    }
  </style>

  <!-- Receipt card -->
  <div class="card" style="border: 2px solid var(--success); text-align:center;">

    <div style="
      font-family: 'Sora', sans-serif;
      font-weight: 800;
      font-size: 1.5rem;
      color: var(--text);
      margin-bottom: 6px;
    ">Vote Recorded!</div>

    <p class="text-muted" style="font-size:.9rem; margin-bottom: 24px;">
      Your vote has been securely recorded. Here is your receipt.
    </p>

    <hr class="divider">

    <!-- Receipt details -->
    <div style="
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
      text-align: left;
      margin-bottom: 24px;
    ">
      <div>
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:4px;">Election</div>
        <div style="font-weight:600; font-size:.95rem; color:var(--text);">
          <?php echo htmlspecialchars($receipt['election_name']); ?>
        </div>
      </div>
      <div>
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:4px;">Position</div>
        <div style="font-weight:600; font-size:.95rem; color:var(--text);">
          <?php echo htmlspecialchars($receipt['position_name']); ?>
        </div>
      </div>
      <div>
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:4px;">You Voted For</div>
        <div style="font-family:'Sora',sans-serif; font-weight:800; font-size:1.05rem; color:var(--primary);">
          <?php echo htmlspecialchars($receipt['candidate_name']); ?>
        </div>
      </div>
      <div>
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:4px;">Time Cast</div>
        <div style="font-weight:600; font-size:.88rem; color:var(--text);">
          <?php echo date('d M Y, H:i:s', strtotime($receipt['cast_at'])); ?>
        </div>
      </div>
    </div>

    <hr class="divider">

    <!-- Progress -->
    <div style="margin-bottom:24px;">
      <div class="flex-between mb-1">
        <span style="font-size:.85rem; color:var(--text-muted);">Overall ballot progress</span>
        <span style="font-family:'Sora',sans-serif; font-weight:700; color:var(--primary); font-size:.9rem;">
          <?php echo (int)$receipt['voted_count']; ?> / <?php echo (int)$receipt['total_positions']; ?>
        </span>
      </div>
      <div class="progress-wrap">
        <?php $pct = (int)$receipt['total_positions'] > 0
          ? round(((int)$receipt['voted_count'] / (int)$receipt['total_positions']) * 100)
          : 0; ?>
        <div class="progress-bar" style="width:<?php echo $pct; ?>%;"></div>
      </div>
    </div>

    <?php if ($allDone): ?>
      <div class="alert alert-success" style="margin-bottom:20px;">
        🎉 You have completed your entire ballot. Thank you for voting!
      </div>
      <a href="dashboard.php" class="btn btn-success btn-full">View My Ballot Summary</a>
    <?php else: ?>
      <div class="alert alert-warning" style="margin-bottom:20px;">
        You still have <?php echo (int)$receipt['total_positions'] - (int)$receipt['voted_count']; ?>
        position<?php echo ((int)$receipt['total_positions'] - (int)$receipt['voted_count']) !== 1 ? 's' : ''; ?> remaining.
      </div>
      <a href="dashboard.php" class="btn btn-primary btn-full">Continue Voting →</a>
    <?php endif; ?>

  </div>

  <p style="text-align:center; margin-top:16px; font-size:.82rem;" class="text-muted">
    This receipt is for your reference only. Your vote is anonymous.
  </p>

</div>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
