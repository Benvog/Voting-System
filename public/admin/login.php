<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../app/lib/auth.php';
require_once __DIR__ . '/../../app/lib/csrf.php';

start_secure_session();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $token    = $_POST['csrf_token'] ?? '';

    if (!csrf_verify($token)) {
        $error = 'Invalid request. Please refresh and try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        $stmt = db()->prepare("SELECT id, password_hash, is_active FROM admins WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $username]);
        $admin = $stmt->fetch();

        if ($admin && (int)$admin['is_active'] === 1 && password_verify($password, $admin['password_hash'])) {
            login_admin((int)$admin['id']);
            header('Location: /admin/dashboard.php');
            exit;
        }

        $error = 'Invalid username or password.';
    }
}

$pageTitle = 'Admin Login';
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<div class="card-center">
  <div class="card">

    <div class="card-eyebrow">Administration</div>
    <div class="card-title">Welcome back</div>
    <div class="card-sub">Sign in to the admin control panel.</div>

    <?php if ($error): ?>
      <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

      <div class="form-group">
        <label for="username">Username</label>
        <input
          class="form-control"
          type="text"
          id="username"
          name="username"
          required
          autocomplete="username"
          placeholder="admin"
          value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
        >
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input
          class="form-control"
          type="password"
          id="password"
          name="password"
          required
          autocomplete="current-password"
          placeholder="••••••••"
        >
      </div>

      <div class="mt-3">
        <button type="submit" class="btn btn-primary btn-full">Sign In</button>
      </div>
    </form>

    <hr class="divider">
    <p style="text-align:center; font-size:.85rem;" class="text-muted">
      Are you a voter? <a href="/voter/login.php">Voter login →</a>
    </p>

  </div>
</div>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
