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
$newVoterInfo = null; // holds plain-text credentials after creation

/* ── Helpers ── */
function generateVoterUid(PDO $pdo): string
{
    do {
        $uid = 'VOT-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $exists = $pdo->prepare("SELECT id FROM voters WHERE voter_uid = :uid");
        $exists->execute([':uid' => $uid]);
    } while ($exists->fetch());
    return $uid;
}

function generatePin(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/* ── Handle POST actions ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        /* Create voter */
        if ($action === 'create') {
            $fullName = trim($_POST['full_name'] ?? '');
            if ($fullName === '') {
                $error = 'Full name is required.';
            } else {
                $uid  = generateVoterUid($pdo);
                $pin  = generatePin();
                $hash = password_hash($pin, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("
                    INSERT INTO voters (voter_uid, full_name, password_hash, is_active)
                    VALUES (:uid, :name, :hash, 1)
                ");
                $stmt->execute([':uid' => $uid, ':name' => $fullName, ':hash' => $hash]);

                $newVoterInfo = ['uid' => $uid, 'pin' => $pin, 'name' => $fullName];
                $success = 'Voter created successfully. Share the credentials below with the voter.';
            }

        /* Reset PIN */
        } elseif ($action === 'reset_pin') {
            $id  = (int)($_POST['voter_id'] ?? 0);
            $pin = generatePin();
            $hash = password_hash($pin, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                SELECT voter_uid, full_name FROM voters WHERE id = :id
            ");
            $stmt->execute([':id' => $id]);
            $voter = $stmt->fetch();

            if ($voter) {
                $pdo->prepare("UPDATE voters SET password_hash = :hash WHERE id = :id")
                    ->execute([':hash' => $hash, ':id' => $id]);
                $newVoterInfo = ['uid' => $voter['voter_uid'], 'pin' => $pin, 'name' => $voter['full_name']];
                $success = 'PIN reset successfully. Share the new credentials below.';
            }

        /* Toggle active */
        } elseif ($action === 'toggle_active') {
            $id        = (int)($_POST['voter_id'] ?? 0);
            $newStatus = (int)($_POST['new_status'] ?? 0);
            $pdo->prepare("UPDATE voters SET is_active = :s WHERE id = :id")
                ->execute([':s' => $newStatus, ':id' => $id]);
            $success = 'Voter status updated.';

        /* Delete voter */
        } elseif ($action === 'delete') {
            $id = (int)($_POST['voter_id'] ?? 0);
            $pdo->prepare("DELETE FROM voters WHERE id = :id")->execute([':id' => $id]);
            $success = 'Voter deleted.';
        }
    }
}

/* ── Data ── */
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $stmt = $pdo->prepare("
        SELECT * FROM voters
        WHERE full_name LIKE :s1 OR voter_uid LIKE :s2
        ORDER BY created_at DESC
    ");
    $stmt->execute([':s1' => '%' . $search . '%', ':s2' => '%' . $search . '%']);
} else {
    $stmt = $pdo->query("SELECT * FROM voters ORDER BY created_at DESC");
}
$voters = $stmt->fetchAll();

$pageTitle = 'Manage Voters';
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<div class="page-header">
  <div>
    <h1>Voters</h1>
    <p>Create voters, manage credentials, and control access.</p>
  </div>
  <div style="font-size:.9rem; color:var(--text-muted);">
    Total: <strong><?php echo count($voters); ?></strong> voter<?php echo count($voters) !== 1 ? 's' : ''; ?>
  </div>
</div>

<?php if ($success): ?>
  <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- New Voter Credentials (shown once after create/reset) -->
<?php if ($newVoterInfo): ?>
  <div class="card mb-2" style="border: 2px solid var(--primary); background: var(--surface-alt);">
    <div class="section-title" style="color: var(--primary);">🔑 Voter Credentials — Share with voter now</div>
    <p class="text-muted mb-1" style="font-size:.88rem;">These credentials are shown <strong>once only</strong>. Copy and share them securely.</p>
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:16px; margin-top:12px;">
      <div class="stat-card" style="border-left-color: var(--primary);">
        <div class="stat-label">Full Name</div>
        <div style="font-family:'Sora',sans-serif; font-weight:700; font-size:1.1rem; color:var(--text); margin-top:4px;">
          <?php echo htmlspecialchars($newVoterInfo['name']); ?>
        </div>
      </div>
      <div class="stat-card" style="border-left-color: var(--accent);">
        <div class="stat-label">Voter ID</div>
        <div style="font-family:'Sora',sans-serif; font-weight:800; font-size:1.4rem; color:var(--primary); letter-spacing:.05em; margin-top:4px;">
          <?php echo htmlspecialchars($newVoterInfo['uid']); ?>
        </div>
      </div>
      <div class="stat-card" style="border-left-color: var(--success);">
        <div class="stat-label">PIN</div>
        <div style="font-family:'Sora',sans-serif; font-weight:800; font-size:1.8rem; color:var(--success); letter-spacing:.15em; margin-top:4px;">
          <?php echo htmlspecialchars($newVoterInfo['pin']); ?>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Create Voter -->
<div class="card mb-2">
  <div class="section-title">Create New Voter</div>
  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="action" value="create">
    <div class="form-row">
      <div class="form-group" style="margin-bottom:0;">
        <label for="full_name">Full Name</label>
        <input
          class="form-control"
          type="text"
          id="full_name"
          name="full_name"
          required
          placeholder="e.g. Jane Doe"
        >
      </div>
      <div class="form-group" style="margin-bottom:0; display:flex; align-items:flex-end;">
        <button type="submit" class="btn btn-primary" style="width:100%;">+ Create Voter</button>
      </div>
    </div>
    <p class="text-muted mt-1" style="font-size:.82rem;">Voter ID and PIN are auto-generated and shown once after creation.</p>
  </form>
</div>

<!-- Search -->
<div class="card mb-2">
  <form method="get" style="display:flex; gap:10px; align-items:flex-end;">
    <div class="form-group" style="flex:1; margin-bottom:0;">
      <label for="search">Search Voters</label>
      <input
        class="form-control"
        type="text"
        id="search"
        name="search"
        placeholder="Search by name or Voter ID…"
        value="<?php echo htmlspecialchars($search); ?>"
      >
    </div>
    <button type="submit" class="btn btn-ghost">Search</button>
    <?php if ($search): ?>
      <a href="/admin/voters.php" class="btn btn-ghost">Clear</a>
    <?php endif; ?>
  </form>
</div>

<!-- Voters Table -->
<div class="section-title">
  <?php echo $search ? 'Results for "' . htmlspecialchars($search) . '"' : 'All Voters'; ?>
</div>

<?php if (empty($voters)): ?>
  <div class="card" style="text-align:center; padding:48px; color:var(--text-muted);">
    <?php echo $search ? 'No voters match your search.' : 'No voters yet. Create one above.'; ?>
  </div>
<?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Voter ID</th>
          <th>Full Name</th>
          <th>Status</th>
          <th>Created</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($voters as $v): ?>
        <tr>
          <td>
            <span style="font-family:'Sora',sans-serif; font-weight:700; font-size:.9rem; color:var(--primary); letter-spacing:.03em;">
              <?php echo htmlspecialchars($v['voter_uid']); ?>
            </span>
          </td>
          <td><strong><?php echo htmlspecialchars($v['full_name']); ?></strong></td>
          <td>
            <?php if ((int)$v['is_active'] === 1): ?>
              <span class="badge badge-active">Active</span>
            <?php else: ?>
              <span class="badge badge-closed">Disabled</span>
            <?php endif; ?>
          </td>
          <td class="text-muted" style="font-size:.85rem;">
            <?php echo date('d M Y', strtotime($v['created_at'])); ?>
          </td>
          <td>
            <div style="display:flex; gap:6px; flex-wrap:wrap;">

              <!-- Reset PIN -->
              <form method="post" onsubmit="return confirm('Reset PIN for <?php echo htmlspecialchars(addslashes($v['full_name'])); ?>?')">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="action" value="reset_pin">
                <input type="hidden" name="voter_id" value="<?php echo (int)$v['id']; ?>">
                <button class="btn btn-ghost btn-sm" type="submit">Reset PIN</button>
              </form>

              <!-- Toggle active -->
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="voter_id" value="<?php echo (int)$v['id']; ?>">
                <input type="hidden" name="new_status" value="<?php echo (int)$v['is_active'] === 1 ? '0' : '1'; ?>">
                <button class="btn btn-sm <?php echo (int)$v['is_active'] === 1 ? 'btn-accent' : 'btn-success'; ?>" type="submit">
                  <?php echo (int)$v['is_active'] === 1 ? 'Disable' : 'Enable'; ?>
                </button>
              </form>

              <!-- Delete -->
              <form method="post" onsubmit="return confirm('Permanently delete voter <?php echo htmlspecialchars(addslashes($v['full_name'])); ?>?')">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="voter_id" value="<?php echo (int)$v['id']; ?>">
                <button class="btn btn-danger btn-sm" type="submit">Delete</button>
              </form>

            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
