<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../app/lib/auth.php';
require_once __DIR__ . '/../../app/lib/csrf.php';

start_secure_session();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $voterUid = trim($_POST['voter_uid'] ?? '');
    $pin      = (string)($_POST['pin'] ?? '');
    $token    = $_POST['csrf_token'] ?? '';

    if (!csrf_verify($token)) {
        $error = 'Invalid request (CSRF). Refresh and try again.';
    } elseif ($voterUid === '' || $pin === '') {
        $error = 'Voter ID and PIN are required.';
    } else {
        $stmt = db()->prepare("
            SELECT id, voter_uid, password_hash, is_active
            FROM voters
            WHERE voter_uid = :uid
            LIMIT 1
        ");
        $stmt->execute([':uid' => $voterUid]);
        $voter = $stmt->fetch();

        if ($voter && (int)$voter['is_active'] === 1 && password_verify($pin, $voter['password_hash'])) {
            login_voter((int)$voter['id'], (string)$voter['voter_uid']);
            header('Location: dashboard.php');
            exit;
        }

        $error = 'Invalid Voter ID or PIN, or account is inactive.';
    }
}

$pageTitle = 'Voter Login';
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<div class="card-center">
  <div class="card">

    <div class="card-eyebrow">Voter Portal</div>
    <div class="card-title">Cast your vote</div>
    <div class="card-sub">Enter your Voter ID and PIN to access your ballot.</div>

    <?php if ($error): ?>
      <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

      <div class="form-group">
        <label for="voter_uid">Voter ID</label>
        <input
          class="form-control"
          type="text"
          id="voter_uid"
          name="voter_uid"
          required
          autocomplete="username"
          placeholder="e.g. VOT-00123"
          value="<?php echo htmlspecialchars($_POST['voter_uid'] ?? ''); ?>"
        >
      </div>

      <div class="form-group">
        <label for="pin">PIN</label>
        <input
          class="form-control"
          type="password"
          id="pin"
          name="pin"
          required
          autocomplete="current-password"
          placeholder="••••••••"
        >
      </div>

      <div class="mt-3">
        <button type="submit" class="btn btn-primary btn-full">Access My Ballot</button>
      </div>
    </form>

    <hr class="divider">
    <p style="text-align:center; font-size:.85rem;" class="text-muted">
      Admin? <a href="/admin/login.php">Admin login →</a>
    </p>

  </div>
</div>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
