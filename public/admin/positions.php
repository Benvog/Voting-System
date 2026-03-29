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

        /* Add position */
        if ($action === 'add') {
            $electionId = (int)($_POST['election_id'] ?? 0);
            $name       = trim($_POST['name'] ?? '');
            $sortOrder  = (int)($_POST['sort_order'] ?? 0);

            if ($electionId === 0 || $name === '') {
                $error = 'Election and position name are required.';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO positions (election_id, name, sort_order)
                        VALUES (:eid, :name, :sort)
                    ");
                    $stmt->execute([':eid' => $electionId, ':name' => $name, ':sort' => $sortOrder]);
                    $success = 'Position "' . htmlspecialchars($name) . '" added.';
                } catch (\PDOException $e) {
                    $error = 'A position with that name already exists in this election.';
                }
            }

        /* Delete position */
        } elseif ($action === 'delete') {
            $id = (int)($_POST['position_id'] ?? 0);
            $pdo->prepare("DELETE FROM positions WHERE id = :id")->execute([':id' => $id]);
            $success = 'Position deleted.';
        }
    }
}

/* ── Data ── */
$elections = $pdo->query("SELECT id, name, status FROM elections ORDER BY created_at DESC")->fetchAll();

// Selected election filter
$filterElectionId = (int)($_GET['election_id'] ?? ($elections[0]['id'] ?? 0));

$positions = [];
if ($filterElectionId > 0) {
    $stmt = $pdo->prepare("
        SELECT p.*, COUNT(c.id) AS candidate_count
        FROM positions p
        LEFT JOIN candidates c ON c.position_id = p.id
        WHERE p.election_id = :eid
        GROUP BY p.id
        ORDER BY p.sort_order ASC, p.created_at ASC
    ");
    $stmt->execute([':eid' => $filterElectionId]);
    $positions = $stmt->fetchAll();
}

// Active election for the add form default
$selectedElection = null;
foreach ($elections as $e) {
    if ((int)$e['id'] === $filterElectionId) {
        $selectedElection = $e;
        break;
    }
}

$pageTitle = 'Manage Positions';
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<div class="page-header">
  <div>
    <h1>Positions</h1>
    <p>Define voting positions (roles) for each election.</p>
  </div>
</div>

<?php if ($success): ?>
  <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if (empty($elections)): ?>
  <div class="card" style="text-align:center; padding:48px; color:var(--text-muted);">
    No elections found. <a href="/admin/elections.php">Create an election first →</a>
  </div>
<?php else: ?>

<!-- Election Filter -->
<div class="card mb-2">
  <div class="section-title">Select Election</div>
  <form method="get" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
    <div class="form-group" style="flex:1; min-width:220px; margin-bottom:0;">
      <label for="election_id">Election</label>
      <select class="form-control" name="election_id" id="election_id" onchange="this.form.submit()">
        <?php foreach ($elections as $e): ?>
          <option value="<?php echo (int)$e['id']; ?>" <?php echo (int)$e['id'] === $filterElectionId ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($e['name']); ?>
            (<?php echo htmlspecialchars($e['status']); ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</div>

<!-- Add Position -->
<div class="card mb-2">
  <div class="section-title">Add Position</div>
  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="election_id" value="<?php echo $filterElectionId; ?>">

    <div class="form-row">
      <div class="form-group">
        <label for="name">Position Name</label>
        <input
          class="form-control"
          type="text"
          id="name"
          name="name"
          required
          placeholder="e.g. President, Secretary"
        >
      </div>
      <div class="form-group">
        <label for="sort_order">Sort Order <span class="text-muted">(optional)</span></label>
        <input
          class="form-control"
          type="number"
          id="sort_order"
          name="sort_order"
          value="0"
          min="0"
          placeholder="0"
        >
      </div>
    </div>

    <button type="submit" class="btn btn-primary">+ Add Position</button>
  </form>
</div>

<!-- Positions Table -->
<div class="section-title">
  Positions for:
  <strong><?php echo $selectedElection ? htmlspecialchars($selectedElection['name']) : '—'; ?></strong>
</div>

<?php if (empty($positions)): ?>
  <div class="card" style="text-align:center; padding:48px; color:var(--text-muted);">
    No positions yet for this election. Add one above.
  </div>
<?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Position Name</th>
          <th>Sort Order</th>
          <th>Candidates</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($positions as $p): ?>
        <tr>
          <td class="text-muted"><?php echo (int)$p['id']; ?></td>
          <td><strong><?php echo htmlspecialchars($p['name']); ?></strong></td>
          <td class="text-muted"><?php echo (int)$p['sort_order']; ?></td>
          <td>
            <span class="badge badge-active"><?php echo (int)$p['candidate_count']; ?> candidate<?php echo (int)$p['candidate_count'] !== 1 ? 's' : ''; ?></span>
          </td>
          <td>
            <form method="post" onsubmit="return confirm('Delete this position and all its candidates?')">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="position_id" value="<?php echo (int)$p['id']; ?>">
              <button class="btn btn-danger btn-sm" type="submit">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
