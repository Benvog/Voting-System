<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/lib/helpers.php';
require_once __DIR__ . '/../app/lib/auth.php';

start_secure_session();

if ($home = home_for_session()) {
    redirect($home);
}

$error      = '';
$identifier = '';

if (is_post()) {
    $identifier = trim((string)($_POST['identifier'] ?? ''));
    $secret     = (string)($_POST['secret'] ?? '');
    $pdo        = db();

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($identifier === '' || $secret === '') {
        $error = 'Enter your voter ID or username, and your PIN or password.';
    } elseif (login_is_throttled($pdo, $identifier)) {
        $error = 'Too many failed attempts. Wait ' . LOGIN_WINDOW_MINUTES . ' minutes and try again.';
    } else {
        // The shape of the ID decides which table to check, so a voter ID and
        // an admin username can never collide.
        if (is_voter_uid($identifier)) {
            $stmt = $pdo->prepare("SELECT id, voter_uid, password_hash, is_active FROM voters WHERE voter_uid = :uid LIMIT 1");
            $stmt->execute([':uid' => strtoupper($identifier)]);
        } else {
            $stmt = $pdo->prepare("SELECT id, password_hash, is_active FROM admins WHERE username = :u LIMIT 1");
            $stmt->execute([':u' => $identifier]);
        }
        $account = $stmt->fetch();

        // Hash something even when the account doesn't exist, so response time
        // doesn't reveal which IDs are real.
        $hash  = $account['password_hash'] ?? '$2y$10$NDfD3xAaNsz8lkLXzGvM0eVSzaHmB/iD1RBAbeClfLEjdYAGWHVTe';
        $valid = password_verify($secret, $hash) && $account && (int)$account['is_active'] === 1;

        if ($valid) {
            clear_login_failures($pdo, $identifier);
            if (isset($account['voter_uid'])) {
                login_voter((int)$account['id'], (string)$account['voter_uid']);
                redirect('/voter/dashboard.php');
            }
            login_admin((int)$account['id']);
            redirect('/admin/dashboard.php');
        }

        record_login_failure($pdo, $identifier);
        $error = 'Those details don\'t match an active account.';
    }
}

$pageTitle = 'Log in';
$layout    = 'auth';
require_once __DIR__ . '/../app/views/partials/header.php';
?>

<div class="auth">
  <div class="card">
    <div class="card-body">
      <h1>Log in</h1>
      <p class="lead">Voters use the voter ID and PIN they were given. Administrators use their username and password.</p>

      <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><?php echo icon('alert'); ?><span><?php echo e($error); ?></span></div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?php echo csrf_field(); ?>
        <div class="field">
          <label class="label" for="identifier">Voter ID or username</label>
          <input class="input" type="text" id="identifier" name="identifier" required autocomplete="username" autocapitalize="off" spellcheck="false" placeholder="VOT-1A2B3C" value="<?php echo e($identifier); ?>" <?php echo $identifier === '' ? 'autofocus' : ''; ?>>
        </div>
        <div class="field">
          <label class="label" for="secret">PIN or password</label>
          <input class="input" type="password" id="secret" name="secret" required autocomplete="current-password" <?php echo $identifier !== '' ? 'autofocus' : ''; ?>>
        </div>
        <button class="btn btn-primary btn-lg btn-block" type="submit">Log in</button>
      </form>
    </div>
  </div>
  <p class="auth-foot">Lost your PIN? Ask the election administrator to reset it.</p>
</div>

<?php require_once __DIR__ . '/../app/views/partials/footer.php'; ?>
