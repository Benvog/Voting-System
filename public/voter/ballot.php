<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/lib/helpers.php';
require_once __DIR__ . '/../../app/lib/auth.php';

start_secure_session();
$pdo = db();
require_voter_active($pdo);

$voterId  = (int)$_SESSION['voter_id'];
$election = current_election($pdo);

if (!$election) {
    flash('info', 'Voting is not open right now.');
    redirect('/voter/dashboard.php');
}
$electionId = (int)$election['id'];

/* Positions this voter still has to vote in, with their candidates. */
$stmt = $pdo->prepare("
    SELECT p.id, p.name
    FROM positions p
    WHERE p.election_id = :e1 AND p.is_active = 1
      AND NOT EXISTS (SELECT 1 FROM votes v WHERE v.position_id = p.id AND v.voter_id = :v AND v.election_id = :e2)
    ORDER BY p.sort_order, p.id
");
$stmt->execute([':e1' => $electionId, ':e2' => $electionId, ':v' => $voterId]);
$positions = $stmt->fetchAll();

$candidates = [];
if ($positions) {
    $ids = array_column($positions, 'id');
    $c = $pdo->prepare("SELECT id, position_id, name, manifesto FROM candidates WHERE is_active = 1 AND position_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY name");
    $c->execute($ids);
    foreach ($c->fetchAll() as $row) {
        $candidates[(int)$row['position_id']][(int)$row['id']] = $row;
    }
}

if (!$positions) {
    redirect('/voter/dashboard.php');
}

if (is_post()) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session expired before the ballot was sent. Nothing was recorded; please vote again.');
        redirect('/voter/ballot.php');
    }

    $choices = is_array($_POST['choice'] ?? null) ? $_POST['choice'] : [];
    $picks   = [];
    foreach ($positions as $pos) {
        $cid = (int)($choices[$pos['id']] ?? 0);
        if ($cid === 0) continue; // skipped: stays open for later
        if (!isset($candidates[(int)$pos['id']][$cid])) {
            flash('error', 'One of your choices is no longer on the ballot. Nothing was recorded; please check and vote again.');
            redirect('/voter/ballot.php');
        }
        $picks[(int)$pos['id']] = $cid;
    }

    if (!$picks) {
        flash('info', 'You skipped every position, so nothing was recorded.');
        redirect('/voter/dashboard.php');
    }

    $receipt = [
        'election' => $election['name'],
        'at'       => date('Y-m-d H:i:s'),
        'votes'    => array_map(fn($pid, $cid) => [
            'position'  => current(array_filter($positions, fn($p) => (int)$p['id'] === $pid))['name'],
            'candidate' => $candidates[$pid][$cid]['name'],
        ], array_keys($picks), $picks),
    ];

    // A demo voter sees the whole flow, but the ballot is never stored.
    if (is_read_only_voter(current_voter())) {
        $_SESSION['receipt'] = $receipt + ['demo' => true];
        redirect('/voter/confirmed.php');
    }

    // All of the ballot is recorded, or none of it.
    $insert = $pdo->prepare("INSERT INTO votes (election_id, position_id, voter_id, candidate_id) VALUES (:e, :p, :v, :c)");
    try {
        $pdo->beginTransaction();
        foreach ($picks as $pid => $cid) {
            $insert->execute([':e' => $electionId, ':p' => $pid, ':v' => $voterId, ':c' => $cid]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        // The unique key fires if this ballot was already sent, e.g. from another tab.
        flash('error', 'Some of these positions already have your vote, so this ballot was not recorded. Your earlier votes stand.');
        redirect('/voter/dashboard.php');
    }

    $_SESSION['receipt'] = $receipt;
    redirect('/voter/confirmed.php');
}

$pageTitle = 'Ballot';
$layout    = 'voter';
require_once __DIR__ . '/../../app/views/partials/header.php';
$total = count($positions);
?>

<form id="ballot" method="post" class="stack">
  <?php echo csrf_field(); ?>

  <div class="ballot-head">
    <a class="crumb" href="/voter/dashboard.php"><?php echo icon('chevron-left'); ?><?php echo e($election['name']); ?></a>
    <div class="progress-steps" aria-hidden="true"><?php echo str_repeat('<span></span>', $total + 1); ?></div>
    <p class="step-count" aria-live="polite"><?php echo plural($total, 'position'); ?> to vote in</p>
  </div>

  <?php foreach ($positions as $i => $pos):
    $list = $candidates[(int)$pos['id']] ?? [];
  ?>
    <fieldset class="ballot-step" data-position="<?php echo (int)$pos['id']; ?>">
      <legend><?php echo e($pos['name']); ?></legend>
      <p class="hint">Choose one candidate, or skip and come back before voting closes.</p>
      <div class="options">
        <?php foreach ($list as $cand): ?>
          <label class="option">
            <input type="radio" name="choice[<?php echo (int)$pos['id']; ?>]" value="<?php echo (int)$cand['id']; ?>" data-label="<?php echo e($cand['name']); ?>">
            <span class="avatar" aria-hidden="true"><?php echo e(initials($cand['name'])); ?></span>
            <span>
              <span class="option-name"><?php echo e($cand['name']); ?></span>
              <?php if ($cand['manifesto']): ?><span class="option-bio"><?php echo e($cand['manifesto']); ?></span><?php endif; ?>
            </span>
            <span class="tick-mark" aria-hidden="true"><?php echo icon('check'); ?></span>
          </label>
        <?php endforeach; ?>
        <label class="option is-abstain">
          <input type="radio" name="choice[<?php echo (int)$pos['id']; ?>]" value="0">
          <span>
            <span class="option-name">Skip for now</span>
            <span class="option-bio">No vote is recorded for this position yet.</span>
          </span>
          <span class="tick-mark" aria-hidden="true"><?php echo icon('check'); ?></span>
        </label>
      </div>
      <p class="step-error alert alert-error" role="alert" hidden><?php echo icon('alert'); ?><span>Choose a candidate or "Skip for now" to continue.</span></p>
      <div class="ballot-nav">
        <?php if ($i > 0): ?>
          <button class="btn btn-ghost" type="button" data-prev><?php echo icon('arrow-left'); ?>Back</button>
        <?php else: ?><span></span><?php endif; ?>
        <button class="btn btn-primary" type="button" data-next>Next<?php echo icon('arrow-right'); ?></button>
      </div>
    </fieldset>
  <?php endforeach; ?>

  <fieldset class="ballot-step" data-review>
    <legend>Review your ballot</legend>
    <p class="hint">Votes can't be changed once submitted. Use Change to fix anything first.</p>
    <div class="card">
      <ul class="review-list">
        <?php foreach ($positions as $i => $pos): ?>
          <li data-review-for="<?php echo (int)$pos['id']; ?>">
            <div>
              <div class="pos"><?php echo e($pos['name']); ?></div>
              <div class="pick is-none">Not chosen yet</div>
            </div>
            <button class="btn btn-quiet btn-sm" type="button" data-jump="<?php echo $i; ?>">Change</button>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div class="ballot-nav">
      <button class="btn btn-ghost" type="button" data-prev><?php echo icon('arrow-left'); ?>Back</button>
      <button class="btn btn-primary btn-lg" type="submit"><?php echo icon('check'); ?>Submit ballot</button>
    </div>
  </fieldset>
</form>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
