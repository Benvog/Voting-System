<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/lib/helpers.php';
require_once __DIR__ . '/../../app/lib/auth.php';
require_once __DIR__ . '/../../app/lib/election_actions.php';

start_secure_session();
require_admin();

$pdo = db();

if (is_post()) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
        redirect('/admin/elections.php');
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $name     = trim((string)($_POST['name'] ?? ''));
        $startsAt = parse_datetime_input($_POST['starts_at'] ?? null);
        $endsAt   = parse_datetime_input($_POST['ends_at'] ?? null);

        if ($name === '') {
            flash('error', 'Give the election a name.');
        } elseif ($problem = validate_window($startsAt, $endsAt)) {
            flash('error', $problem);
        } else {
            $pdo->prepare("INSERT INTO elections (name, status, starts_at, ends_at) VALUES (:n, 'draft', :s, :e)")
                ->execute([':n' => $name, ':s' => $startsAt, ':e' => $endsAt]);
            $id = (int)$pdo->lastInsertId();
            flash('success', "Created \"$name\". Add its positions and candidates next.");
            redirect('/admin/election.php?id=' . $id);
        }
        redirect('/admin/elections.php#new');
    }

    election_status_action($pdo, $action, (int)($_POST['election_id'] ?? 0));
    redirect('/admin/elections.php');
}

$elections = $pdo->query("
    SELECT e.*,
      (SELECT COUNT(*) FROM positions p WHERE p.election_id = e.id AND p.is_active = 1) AS positions,
      (SELECT COUNT(DISTINCT v.voter_id) FROM votes v WHERE v.election_id = e.id) AS voted
    FROM elections e
    ORDER BY FIELD(e.status, 'active', 'draft', 'closed'), e.id DESC
")->fetchAll();
$eligible = (int)$pdo->query("SELECT COUNT(*) FROM voters WHERE is_active = 1")->fetchColumn();

$pageTitle = 'Elections';
$layout    = 'admin';
$activeNav = 'elections';
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<div class="page-head">
  <div>
    <h1>Elections</h1>
    <p>Each election has its own positions, candidates and voting window. Only one can be open at a time.</p>
  </div>
</div>

<section class="card" aria-labelledby="list-title">
  <div class="card-head"><h2 id="list-title">All elections</h2></div>
  <?php if (!$elections): ?>
    <div class="empty">
      <?php echo icon('ballot'); ?>
      <h3>No elections yet</h3>
      <p>Create your first one below.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th>Election</th><th>Status</th><th>Voting window</th><th class="num">Turnout</th><th class="actions"><span class="sr-only">Actions</span></th></tr>
        </thead>
        <tbody>
          <?php foreach ($elections as $el):
            $turnout = $eligible > 0 ? min(100, (int)round($el['voted'] / $eligible * 100)) : 0;
          ?>
            <tr>
              <td>
                <a class="row-title" href="/admin/election.php?id=<?php echo (int)$el['id']; ?>"><?php echo e($el['name']); ?></a>
                <div class="row-sub"><?php echo plural((int)$el['positions'], 'position'); ?></div>
              </td>
              <td><?php echo phase_badge($el); ?></td>
              <td class="row-sub nowrap"><?php echo $el['starts_at'] || $el['ends_at'] ? fmt_datetime($el['starts_at'], 'd M, H:i') . ' → ' . fmt_datetime($el['ends_at'], 'd M, H:i') : 'Open until closed'; ?></td>
              <td class="num"><?php echo $el['status'] === 'draft' ? '—' : $turnout . '%'; ?></td>
              <td class="actions">
                <?php if ($el['status'] === 'draft'): ?>
                  <form class="inline-form" method="post" data-confirm="Voters will be able to vote in &quot;<?php echo e($el['name']); ?>&quot; straight away, unless it has a later opening time." data-confirm-title="Open voting?" data-confirm-ok="Open voting" data-confirm-tone="primary">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="activate">
                    <input type="hidden" name="election_id" value="<?php echo (int)$el['id']; ?>">
                    <button class="btn btn-ghost btn-sm" type="submit"><?php echo icon('play'); ?>Open</button>
                  </form>
                <?php elseif ($el['status'] === 'active'): ?>
                  <form class="inline-form" method="post" data-confirm="Nobody will be able to vote in &quot;<?php echo e($el['name']); ?>&quot; after this, and it can't be reopened." data-confirm-title="Close this election?" data-confirm-ok="Close election">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="close">
                    <input type="hidden" name="election_id" value="<?php echo (int)$el['id']; ?>">
                    <button class="btn btn-ghost btn-sm" type="submit"><?php echo icon('stop'); ?>Close</button>
                  </form>
                <?php endif; ?>
                <a class="btn btn-quiet btn-sm" href="/admin/election.php?id=<?php echo (int)$el['id']; ?>">Manage<?php echo icon('chevron-right'); ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="card" id="new" aria-labelledby="new-title">
  <div class="card-head">
    <div>
      <h2 id="new-title">New election</h2>
      <div class="sub">It starts as a draft. Nobody can vote until you open it.</div>
    </div>
  </div>
  <div class="card-body">
    <form method="post">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="create">
      <div class="field">
        <label class="label" for="name">Name</label>
        <input class="input" id="name" name="name" required maxlength="120" placeholder="Student Council Election 2027">
      </div>
      <div class="form-grid">
        <div class="field">
          <label class="label" for="starts_at">Opens <span class="opt">(optional)</span></label>
          <input class="input" type="datetime-local" id="starts_at" name="starts_at">
          <span class="hint">Empty means as soon as you open it.</span>
        </div>
        <div class="field">
          <label class="label" for="ends_at">Closes <span class="opt">(optional)</span></label>
          <input class="input" type="datetime-local" id="ends_at" name="ends_at">
          <span class="hint">Empty means when you close it.</span>
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?php echo icon('plus'); ?>Create election</button>
      </div>
    </form>
  </div>
</section>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
