<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/lib/helpers.php';
require_once __DIR__ . '/../../app/lib/auth.php';
require_once __DIR__ . '/../../app/lib/election_actions.php';

start_secure_session();
require_admin();

$pdo = db();
$id  = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM elections WHERE id = :id");
$stmt->execute([':id' => $id]);
$election = $stmt->fetch();

if (!$election) {
    flash('error', 'That election no longer exists.');
    redirect('/admin/elections.php');
}

$self   = '/admin/election.php?id=' . $id;
$locked = ballot_is_locked($pdo, $id);

/* Positions and candidates must belong to this election, whatever ids the form sends. */
function owned_position(PDO $pdo, int $positionId, int $electionId): ?array
{
    $s = $pdo->prepare("SELECT id, name, sort_order FROM positions WHERE id = :p AND election_id = :e AND is_active = 1");
    $s->execute([':p' => $positionId, ':e' => $electionId]);
    return $s->fetch() ?: null;
}

if (is_post()) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
        redirect($self);
    }

    $action = (string)($_POST['action'] ?? '');

    if (in_array($action, ['activate', 'close', 'delete'], true)) {
        election_status_action($pdo, $action, $id);
        redirect($action === 'delete' ? '/admin/elections.php' : $self);
    }

    if ($action === 'update_details') {
        $name     = trim((string)($_POST['name'] ?? ''));
        $startsAt = parse_datetime_input($_POST['starts_at'] ?? null);
        $endsAt   = parse_datetime_input($_POST['ends_at'] ?? null);
        if ($name === '') {
            flash('error', 'The election needs a name.');
        } elseif ($problem = validate_window($startsAt, $endsAt)) {
            flash('error', $problem);
        } elseif ($election['status'] === 'closed') {
            flash('error', 'A closed election can no longer be changed.');
        } else {
            $pdo->prepare("UPDATE elections SET name = :n, starts_at = :s, ends_at = :e WHERE id = :id")
                ->execute([':n' => $name, ':s' => $startsAt, ':e' => $endsAt, ':id' => $id]);
            flash('success', 'Election details saved.');
        }
        redirect($self);
    }

    // Everything below changes the ballot itself.
    if ($locked || $election['status'] === 'closed') {
        flash('error', 'Votes have been cast, so positions and candidates can no longer change.');
        redirect($self);
    }

    if ($action === 'add_position') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            flash('error', 'Give the position a name.');
        } else {
            $next = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM positions WHERE election_id = :e");
            $next->execute([':e' => $id]);
            try {
                $pdo->prepare("INSERT INTO positions (election_id, name, sort_order) VALUES (:e, :n, :o)")
                    ->execute([':e' => $id, ':n' => $name, ':o' => (int)$next->fetchColumn()]);
                flash('success', "Added the position \"$name\".");
            } catch (PDOException $e) {
                flash('error', "There is already a position called \"$name\".");
            }
        }
        redirect($self . '#positions');
    }

    if ($action === 'delete_position' && ($pos = owned_position($pdo, (int)($_POST['position_id'] ?? 0), $id))) {
        $pdo->prepare("DELETE FROM positions WHERE id = :p")->execute([':p' => $pos['id']]);
        flash('success', "Removed \"{$pos['name']}\" and its candidates.");
        redirect($self . '#positions');
    }

    if (in_array($action, ['move_up', 'move_down'], true) && ($pos = owned_position($pdo, (int)($_POST['position_id'] ?? 0), $id))) {
        // Renumber everything first so older data with duplicate sort orders still moves predictably.
        $ids = $pdo->prepare("SELECT id FROM positions WHERE election_id = :e AND is_active = 1 ORDER BY sort_order, id");
        $ids->execute([':e' => $id]);
        $order = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
        $i     = array_search((int)$pos['id'], $order, true);
        $j     = $action === 'move_up' ? $i - 1 : $i + 1;
        if ($j >= 0 && $j < count($order)) {
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
            $set = $pdo->prepare("UPDATE positions SET sort_order = :o WHERE id = :p");
            foreach ($order as $n => $pid) {
                $set->execute([':o' => $n, ':p' => $pid]);
            }
        }
        redirect($self . '#positions');
    }

    if ($action === 'add_candidate' && ($pos = owned_position($pdo, (int)($_POST['position_id'] ?? 0), $id))) {
        $name      = trim((string)($_POST['name'] ?? ''));
        $manifesto = trim((string)($_POST['manifesto'] ?? '')) ?: null;
        if ($name === '') {
            flash('error', 'Give the candidate a name.');
        } else {
            try {
                $pdo->prepare("INSERT INTO candidates (position_id, name, manifesto) VALUES (:p, :n, :m)")
                    ->execute([':p' => $pos['id'], ':n' => $name, ':m' => $manifesto]);
                flash('success', "Added $name to {$pos['name']}.");
            } catch (PDOException $e) {
                flash('error', "$name is already standing for {$pos['name']}.");
            }
        }
        redirect($self . '#position-' . $pos['id']);
    }

    if ($action === 'delete_candidate') {
        $s = $pdo->prepare("
            SELECT c.id, c.name, c.position_id FROM candidates c
            JOIN positions p ON p.id = c.position_id
            WHERE c.id = :c AND p.election_id = :e
        ");
        $s->execute([':c' => (int)($_POST['candidate_id'] ?? 0), ':e' => $id]);
        if ($cand = $s->fetch()) {
            $pdo->prepare("DELETE FROM candidates WHERE id = :c")->execute([':c' => $cand['id']]);
            flash('success', "Removed {$cand['name']}.");
            redirect($self . '#position-' . $cand['position_id']);
        }
    }

    redirect($self);
}

/* ---------- Data ---------- */
$positions = $pdo->prepare("SELECT id, name, sort_order FROM positions WHERE election_id = :e AND is_active = 1 ORDER BY sort_order, id");
$positions->execute([':e' => $id]);
$positions = $positions->fetchAll();

$candidates = [];
if ($positions) {
    $c = $pdo->prepare("
        SELECT c.id, c.position_id, c.name, c.manifesto FROM candidates c
        JOIN positions p ON p.id = c.position_id
        WHERE p.election_id = :e AND c.is_active = 1
        ORDER BY c.name
    ");
    $c->execute([':e' => $id]);
    foreach ($c->fetchAll() as $row) {
        $candidates[(int)$row['position_id']][] = $row;
    }
}

$summary  = election_summary($pdo, $id);
$phase    = election_phase($election);
$editable = !$locked && $election['status'] !== 'closed';

$pageTitle = $election['name'];
$layout    = 'admin';
$activeNav = 'elections';
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<a class="crumb" href="/admin/elections.php"><?php echo icon('chevron-left'); ?>Elections</a>
<div class="page-head">
  <div>
    <h1><?php echo e($election['name']); ?></h1>
    <p><?php echo phase_badge($election); ?></p>
  </div>
  <div class="actions">
    <a class="btn btn-ghost" href="/admin/results.php?election_id=<?php echo $id; ?>"><?php echo icon('chart'); ?>Results</a>
    <?php if ($election['status'] === 'draft'): ?>
      <form class="inline-form" method="post" data-confirm="Voters will be able to vote straight away, unless you set a later opening time." data-confirm-title="Open voting?" data-confirm-ok="Open voting" data-confirm-tone="primary">
        <?php echo csrf_field(); ?><input type="hidden" name="action" value="activate">
        <button class="btn btn-primary" type="submit"><?php echo icon('play'); ?>Open voting</button>
      </form>
    <?php elseif ($election['status'] === 'active'): ?>
      <form class="inline-form" method="post" data-confirm="Nobody will be able to vote after this, and the election can't be reopened." data-confirm-title="Close this election?" data-confirm-ok="Close election">
        <?php echo csrf_field(); ?><input type="hidden" name="action" value="close">
        <button class="btn btn-ghost" type="submit"><?php echo icon('stop'); ?>Close election</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="grid-2">
  <div>
    <section id="positions" aria-labelledby="positions-title">
      <div class="page-head">
        <div>
          <h2 id="positions-title">Ballot</h2>
          <p><?php echo plural($summary['positions'], 'position'); ?> · <?php echo plural($summary['candidates'], 'candidate'); ?>. Voters see positions in this order.</p>
        </div>
      </div>

      <?php if ($locked): ?>
        <div class="flashes"><div class="alert alert-info"><?php echo icon('lock'); ?><span>Votes have been cast, so the ballot is locked. Changing it now would change votes people already made.</span></div></div>
      <?php endif; ?>

      <?php if (!$positions): ?>
        <div class="card"><div class="empty">
          <?php echo icon('list'); ?>
          <h3>No positions yet</h3>
          <p>Add the roles people will vote for, such as President or Treasurer.</p>
        </div></div>
      <?php endif; ?>

      <?php foreach ($positions as $n => $pos):
        $list = $candidates[(int)$pos['id']] ?? [];
      ?>
        <article class="card position" id="position-<?php echo (int)$pos['id']; ?>">
          <div class="position-head">
            <span class="order"><?php echo str_pad((string)($n + 1), 2, '0', STR_PAD_LEFT); ?></span>
            <h3><?php echo e($pos['name']); ?></h3>
            <?php if (!$list): ?><span class="badge badge-warn">No candidates</span><?php endif; ?>
            <?php if ($editable): ?>
              <div class="tools">
                <?php foreach (['move_up' => [$n > 0, 'Move up', '<path d="m18 15-6-6-6 6"/>'], 'move_down' => [$n < count($positions) - 1, 'Move down', '<path d="m6 9 6 6 6-6"/>']] as $act => [$can, $label, $path]): ?>
                  <?php if ($can): ?>
                    <form class="inline-form" method="post">
                      <?php echo csrf_field(); ?><input type="hidden" name="action" value="<?php echo $act; ?>"><input type="hidden" name="position_id" value="<?php echo (int)$pos['id']; ?>">
                      <button class="icon-btn" type="submit" aria-label="<?php echo e($label . ': ' . $pos['name']); ?>" title="<?php echo $label; ?>"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo $path; ?></svg></button>
                    </form>
                  <?php endif; ?>
                <?php endforeach; ?>
                <form class="inline-form" method="post" data-confirm="This also removes its <?php echo plural(count($list), 'candidate'); ?>." data-confirm-title="Remove &quot;<?php echo e($pos['name']); ?>&quot;?" data-confirm-ok="Remove position">
                  <?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_position"><input type="hidden" name="position_id" value="<?php echo (int)$pos['id']; ?>">
                  <button class="icon-btn" type="submit" aria-label="<?php echo e('Remove position: ' . $pos['name']); ?>" title="Remove position"><?php echo icon('trash'); ?></button>
                </form>
              </div>
            <?php endif; ?>
          </div>

          <?php if ($list): ?>
            <ul class="cand-list">
              <?php foreach ($list as $cand): ?>
                <li class="cand">
                  <span class="avatar" aria-hidden="true"><?php echo e(initials($cand['name'])); ?></span>
                  <div class="cand-main">
                    <div class="cand-name"><?php echo e($cand['name']); ?></div>
                    <?php if ($cand['manifesto']): ?><div class="cand-bio" title="<?php echo e($cand['manifesto']); ?>"><?php echo e($cand['manifesto']); ?></div><?php endif; ?>
                  </div>
                  <?php if ($editable): ?>
                    <form class="inline-form" method="post" data-confirm="<?php echo e($cand['name']); ?> will no longer appear on the ballot." data-confirm-title="Remove candidate?" data-confirm-ok="Remove">
                      <?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_candidate"><input type="hidden" name="candidate_id" value="<?php echo (int)$cand['id']; ?>">
                      <button class="icon-btn" type="submit" aria-label="<?php echo e('Remove ' . $cand['name']); ?>" title="Remove candidate"><?php echo icon('x'); ?></button>
                    </form>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <?php if ($editable): ?>
            <details class="add-cand" <?php echo $list ? '' : 'open'; ?>>
              <summary><?php echo icon('plus'); ?>Add candidate</summary>
              <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_candidate">
                <input type="hidden" name="position_id" value="<?php echo (int)$pos['id']; ?>">
                <div class="field">
                  <label class="label" for="cand-name-<?php echo (int)$pos['id']; ?>">Full name</label>
                  <input class="input" id="cand-name-<?php echo (int)$pos['id']; ?>" name="name" required maxlength="120">
                </div>
                <div class="field">
                  <label class="label" for="cand-bio-<?php echo (int)$pos['id']; ?>">Manifesto <span class="opt">(optional, one or two lines)</span></label>
                  <textarea class="textarea" id="cand-bio-<?php echo (int)$pos['id']; ?>" name="manifesto" rows="2"></textarea>
                </div>
                <button class="btn btn-primary btn-sm" type="submit">Add to <?php echo e($pos['name']); ?></button>
              </form>
            </details>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>

      <?php if ($editable): ?>
        <form class="card card-body" method="post">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="add_position">
          <div class="inline-fields">
            <div class="field">
              <label class="label" for="pos-name">New position</label>
              <input class="input" id="pos-name" name="name" required maxlength="100" placeholder="e.g. Treasurer">
            </div>
            <button class="btn btn-ghost" type="submit"><?php echo icon('plus'); ?>Add position</button>
          </div>
        </form>
      <?php endif; ?>
    </section>
  </div>

  <aside class="stack">
    <section class="card" aria-labelledby="details-title">
      <div class="card-head"><h2 id="details-title">Details</h2></div>
      <div class="card-body">
        <?php if ($election['status'] === 'closed'): ?>
          <dl class="kv">
            <dt>Opened</dt><dd><?php echo fmt_datetime($election['starts_at']); ?></dd>
            <dt>Closed</dt><dd><?php echo fmt_datetime($election['ends_at']); ?></dd>
          </dl>
        <?php else: ?>
          <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_details">
            <div class="field">
              <label class="label" for="el-name">Name</label>
              <input class="input" id="el-name" name="name" required maxlength="120" value="<?php echo e($election['name']); ?>">
            </div>
            <div class="field">
              <label class="label" for="el-start">Opens <span class="opt">(optional)</span></label>
              <input class="input" type="datetime-local" id="el-start" name="starts_at" value="<?php echo $election['starts_at'] ? e(date('Y-m-d\TH:i', strtotime($election['starts_at']))) : ''; ?>">
            </div>
            <div class="field">
              <label class="label" for="el-end">Closes <span class="opt">(optional)</span></label>
              <input class="input" type="datetime-local" id="el-end" name="ends_at" value="<?php echo $election['ends_at'] ? e(date('Y-m-d\TH:i', strtotime($election['ends_at']))) : ''; ?>">
              <span class="hint">Voting is only accepted between these times while the election is open.</span>
            </div>
            <button class="btn btn-ghost btn-sm" type="submit">Save details</button>
          </form>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($election['status'] !== 'draft'): ?>
      <section class="card" aria-labelledby="turnout-title">
        <div class="card-head"><h2 id="turnout-title">Turnout</h2></div>
        <div class="card-body">
          <div class="meter-row"><span><?php echo number_format($summary['voted']); ?> of <?php echo number_format($summary['eligible']); ?> voters</span><strong><?php echo min(100, $summary['turnout']); ?>%</strong></div>
          <?php echo meter($summary['turnout'], 'Turnout'); ?>
        </div>
      </section>
    <?php endif; ?>

    <section class="card" aria-labelledby="danger-title">
      <div class="card-head"><h2 id="danger-title">Delete election</h2></div>
      <div class="card-body stack">
        <p class="muted small">Removes the election with all its positions, candidates and <?php echo plural($summary['votes'], 'vote'); ?>. This can't be undone.</p>
        <form method="post" data-confirm="&quot;<?php echo e($election['name']); ?>&quot; and <?php echo plural($summary['votes'], 'vote'); ?> will be permanently deleted." data-confirm-title="Delete this election?" data-confirm-ok="Delete election">
          <?php echo csrf_field(); ?><input type="hidden" name="action" value="delete">
          <button class="btn btn-ghost btn-sm" type="submit"><?php echo icon('trash'); ?>Delete election</button>
        </form>
      </div>
    </section>
  </aside>
</div>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
