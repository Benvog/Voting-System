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

        /* Add candidate */
        if ($action === 'add') {
            $positionId = (int)($_POST['position_id'] ?? 0);
            $name       = trim($_POST['name'] ?? '');
            $manifesto  = trim($_POST['manifesto'] ?? '') ?: null;

            if ($positionId === 0 || $name === '') {
                $error = 'Position and candidate name are required.';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO candidates (position_id, name, manifesto)
                        VALUES (:pid, :name, :manifesto)
                    ");
                    $stmt->execute([':pid' => $positionId, ':name' => $name, ':manifesto' => $manifesto]);
                    $success = 'Candidate "' . htmlspecialchars($name) . '" added.';
                } catch (\PDOException $e) {
                    $error = 'A candidate with that name already exists for this position.';
                }
            }

        /* Delete candidate */
        } elseif ($action === 'delete') {
            $id = (int)($_POST['candidate_id'] ?? 0);
            $pdo->prepare("DELETE FROM candidates WHERE id = :id")->execute([':id' => $id]);
            $success = 'Candidate deleted.';
        }
    }
}

/* ── Data ── */
$elections        = $pdo->query("SELECT id, name, status FROM elections ORDER BY created_at DESC")->fetchAll();
$filterElectionId = (int)($_GET['election_id'] ?? ($elections[0]['id'] ?? 0));

// Positions for selected election
$positions = [];
if ($filterElectionId > 0) {
    $stmt = $pdo->prepare("SELECT id, name FROM positions WHERE election_id = :eid AND is_active = 1 ORDER BY sort_order ASC, name ASC");
    $stmt->execute([':eid' => $filterElectionId]);
    $positions = $stmt->fetchAll();
}

// Candidates grouped by position
$candidatesByPosition = [];
if (!empty($positions)) {
    $posIds = array_column($positions, 'id');
    $in     = implode(',', array_fill(0, count($posIds), '?'));
    $stmt   = $pdo->prepare("
        SELECT c.*, p.name AS position_name
        FROM candidates c
        JOIN positions p ON p.id = c.position_id
        WHERE c.position_id IN ($in)
        ORDER BY p.sort_order ASC, c.name ASC
    ");
    $stmt->execute($posIds);
    foreach ($stmt->fetchAll() as $c) {
        $candidatesByPosition[$c['position_id']][] = $c;
    }
}

$selectedElection = null;
foreach ($elections as $e) {
    if ((int)$e['id'] === $filterElectionId) { $selectedElection = $e; break; }
}

$pageTitle = 'Manage Candidates';
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<div class="page-header">
  <div>
    <h1>Candidates</h1>
    <p>Register candidates against positions for each election.</p>
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
  <form method="get">
    <div class="form-group" style="max-width:420px; margin-bottom:0;">
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

<?php if (empty($positions)): ?>
  <div class="card" style="text-align:center; padding:48px; color:var(--text-muted);">
    No positions found for this election. <a href="/admin/positions.php?election_id=<?php echo $filterElectionId; ?>">Add positions first →</a>
  </div>
<?php else: ?>

<!-- Add Candidate -->
<div class="card mb-2">
  <div class="section-title">Add Candidate</div>
  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="action" value="add">

    <div class="form-row">
      <div class="form-group">
        <label for="position_id">Position</label>
        <select class="form-control" name="position_id" id="position_id" required>
          <option value="">— Select position —</option>
          <?php foreach ($positions as $p): ?>
            <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="name">Candidate Name</label>
        <input
          class="form-control"
          type="text"
          id="name"
          name="name"
          required
          placeholder="Full name"
        >
      </div>
    </div>

    <div class="form-group">
      <label for="manifesto">Manifesto / Bio <span class="text-muted">(optional)</span></label>
      <textarea
        class="form-control"
        id="manifesto"
        name="manifesto"
        placeholder="Brief description or manifesto…"
      ></textarea>
    </div>

    <button type="submit" class="btn btn-primary">+ Add Candidate</button>
  </form>
</div>

<!-- Candidates by Position -->
<div class="section-title">
  Candidates for: <strong><?php echo $selectedElection ? htmlspecialchars($selectedElection['name']) : '—'; ?></strong>
</div>

<?php if (empty($candidatesByPosition)): ?>
  <div class="card" style="text-align:center; padding:48px; color:var(--text-muted);">
    No candidates yet. Add one above.
  </div>
<?php else: ?>
  <?php foreach ($positions as $p): ?>
    <?php $candidates = $candidatesByPosition[$p['id']] ?? []; ?>
    <div class="card mb-2">
      <div class="flex-between mb-1">
        <div class="section-title" style="margin-bottom:0;">
          📋 <?php echo htmlspecialchars($p['name']); ?>
        </div>
        <span class="badge badge-active"><?php echo count($candidates); ?> candidate<?php echo count($candidates) !== 1 ? 's' : ''; ?></span>
      </div>

      <?php if (empty($candidates)): ?>
        <p class="text-muted" style="font-size:.9rem; padding: 12px 0;">No candidates for this position yet.</p>
      <?php else: ?>
        <div class="table-wrap" style="margin-top:12px;">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Name</th>
                <th>Manifesto</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($candidates as $c): ?>
              <tr>
                <td class="text-muted"><?php echo (int)$c['id']; ?></td>
                <td><strong><?php echo htmlspecialchars($c['name']); ?></strong></td>
                <td style="font-size:.88rem; color:var(--text-muted); max-width:320px;">
                  <?php echo $c['manifesto'] ? htmlspecialchars($c['manifesto']) : '<em>—</em>'; ?>
                </td>
                <td>
                  <form method="post" onsubmit="return confirm('Delete candidate <?php echo htmlspecialchars(addslashes($c['name'])); ?>?')">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="candidate_id" value="<?php echo (int)$c['id']; ?>">
                    <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
