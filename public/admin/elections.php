<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../app/lib/auth.php';
require_once __DIR__ . '/../../app/lib/csrf.php';

start_secure_session();
require_admin();

$pdo     = db();
$success = '';
$error   = '';

/* ── Handle POST actions ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        /* Create */
        if ($action === 'create') {
            $name      = trim($_POST['name'] ?? '');
            $startsAt  = trim($_POST['starts_at'] ?? '') ?: null;
            $endsAt    = trim($_POST['ends_at']   ?? '') ?: null;

            if ($name === '') {
                $error = 'Election name is required.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO elections (name, status, starts_at, ends_at)
                    VALUES (:name, 'draft', :starts_at, :ends_at)
                ");
                $stmt->execute([':name' => $name, ':starts_at' => $startsAt, ':ends_at' => $endsAt]);
                $success = 'Election "' . htmlspecialchars($name) . '" created.';
            }

        /* Activate */
        } elseif ($action === 'activate') {
            $id = (int)($_POST['election_id'] ?? 0);
            $pdo->prepare("UPDATE elections SET status = 'active' WHERE id = :id")
                ->execute([':id' => $id]);
            $success = 'Election activated.';

        /* Close */
        } elseif ($action === 'close') {
            $id = (int)($_POST['election_id'] ?? 0);
            $pdo->prepare("UPDATE elections SET status = 'closed' WHERE id = :id")
                ->execute([':id' => $id]);
            $success = 'Election closed.';

        /* Delete */
        } elseif ($action === 'delete') {
            $id = (int)($_POST['election_id'] ?? 0);
            $pdo->prepare("DELETE FROM elections WHERE id = :id")
                ->execute([':id' => $id]);
            $success = 'Election deleted.';

        /* Update dates */
        } elseif ($action === 'update_dates') {
            $id       = (int)($_POST['election_id'] ?? 0);
            $startsAt = trim($_POST['starts_at'] ?? '') ?: null;
            $endsAt   = trim($_POST['ends_at']   ?? '') ?: null;
            $pdo->prepare("UPDATE elections SET starts_at = :s, ends_at = :e WHERE id = :id")
                ->execute([':s' => $startsAt, ':e' => $endsAt, ':id' => $id]);
            $success = 'Dates updated.';
        }
    }
}

$elections = $pdo->query("SELECT * FROM elections ORDER BY created_at DESC")->fetchAll();

$pageTitle = 'Manage Elections';
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<div class="page-header">
  <div>
    <h1>Elections</h1>
    <p>Create and manage elections, set time windows, and control status.</p>
  </div>
</div>

<?php if ($success): ?>
  <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Create Election -->
<div class="card mb-2">
  <div class="section-title">New Election</div>
  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="action" value="create">

    <div class="form-group">
      <label for="name">Election Name</label>
      <input class="form-control" type="text" id="name" name="name" required placeholder="e.g. Student Council 2025">
    </div>

    <div class="form-row">
      <div class="form-group">
        <label for="starts_at">Start Date &amp; Time <span class="text-muted">(optional)</span></label>
        <input class="form-control" type="datetime-local" id="starts_at" name="starts_at">
      </div>
      <div class="form-group">
        <label for="ends_at">End Date &amp; Time <span class="text-muted">(optional)</span></label>
        <input class="form-control" type="datetime-local" id="ends_at" name="ends_at">
      </div>
    </div>

    <button type="submit" class="btn btn-primary">+ Create Election</button>
  </form>
</div>

<!-- Elections Table -->
<div class="section-title">All Elections</div>

<?php if (empty($elections)): ?>
  <div class="card" style="text-align:center; padding: 48px; color: var(--text-muted);">
    No elections yet. Create one above.
  </div>
<?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Status</th>
          <th>Start</th>
          <th>End</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($elections as $e): ?>
        <tr>
          <td class="text-muted"><?php echo (int)$e['id']; ?></td>
          <td><strong><?php echo htmlspecialchars($e['name']); ?></strong></td>
          <td>
            <?php
              $badge = match($e['status']) {
                'active' => 'badge-active',
                'closed' => 'badge-closed',
                default  => 'badge-draft',
              };
            ?>
            <span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($e['status']); ?></span>
          </td>
          <td class="text-muted" style="font-size:.85rem;">
            <?php echo $e['starts_at'] ? date('d M Y, H:i', strtotime($e['starts_at'])) : '—'; ?>
          </td>
          <td class="text-muted" style="font-size:.85rem;">
            <?php echo $e['ends_at'] ? date('d M Y, H:i', strtotime($e['ends_at'])) : '—'; ?>
          </td>
          <td>
            <div style="display:flex; gap:6px; flex-wrap:wrap;">

              <?php if ($e['status'] === 'draft'): ?>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="action" value="activate">
                  <input type="hidden" name="election_id" value="<?php echo (int)$e['id']; ?>">
                  <button class="btn btn-success btn-sm" type="submit">Activate</button>
                </form>
              <?php elseif ($e['status'] === 'active'): ?>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="action" value="close">
                  <input type="hidden" name="election_id" value="<?php echo (int)$e['id']; ?>">
                  <button class="btn btn-accent btn-sm" type="submit">Close</button>
                </form>
              <?php endif; ?>

              <!-- Edit dates toggle -->
              <button
                class="btn btn-ghost btn-sm"
                onclick="toggleDates(<?php echo (int)$e['id']; ?>)"
                type="button"
              >Edit Dates</button>

              <form method="post" onsubmit="return confirm('Delete this election and all its data?')">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="election_id" value="<?php echo (int)$e['id']; ?>">
                <button class="btn btn-danger btn-sm" type="submit">Delete</button>
              </form>

            </div>

            <!-- Inline date editor -->
            <div id="dates-<?php echo (int)$e['id']; ?>" style="display:none; margin-top:10px;">
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="action" value="update_dates">
                <input type="hidden" name="election_id" value="<?php echo (int)$e['id']; ?>">
                <div class="form-row" style="margin-bottom:8px;">
                  <div class="form-group" style="margin-bottom:0;">
                    <label>Start</label>
                    <input
                      class="form-control"
                      type="datetime-local"
                      name="starts_at"
                      value="<?php echo $e['starts_at'] ? date('Y-m-d\TH:i', strtotime($e['starts_at'])) : ''; ?>"
                    >
                  </div>
                  <div class="form-group" style="margin-bottom:0;">
                    <label>End</label>
                    <input
                      class="form-control"
                      type="datetime-local"
                      name="ends_at"
                      value="<?php echo $e['ends_at'] ? date('Y-m-d\TH:i', strtotime($e['ends_at'])) : ''; ?>"
                    >
                  </div>
                </div>
                <button class="btn btn-primary btn-sm" type="submit">Save Dates</button>
              </form>
            </div>

          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<script>
function toggleDates(id) {
  var el = document.getElementById('dates-' + id);
  el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
