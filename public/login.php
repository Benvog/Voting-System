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
        $error = 'Enter your ' . strtolower(voter_id_label()) . ' and PIN.';
    } elseif (login_is_throttled($pdo, $key = is_demo_identifier($identifier) ? '' : substr(normalize_voter_uid($identifier), 0, 50))) {
        $error = 'Too many failed attempts. Wait ' . LOGIN_WINDOW_MINUTES . ' minutes and try again.';
    } else {
        // Voters first, then admins. A voter ID and an admin username are never
        // allowed to be equal, so the order can't pick the wrong account.
        $stmt = $pdo->prepare("SELECT id, voter_uid, password_hash, is_active FROM voters WHERE voter_uid = :uid LIMIT 1");
        $stmt->execute([':uid' => normalize_voter_uid($identifier)]);
        $account = $stmt->fetch();
        if (!$account) {
            $stmt = $pdo->prepare("SELECT id, password_hash, is_active FROM admins WHERE username = :u LIMIT 1");
            $stmt->execute([':u' => $identifier]);
            $account = $stmt->fetch();
        }

        // Hash something even when the account doesn't exist, so response time
        // doesn't reveal which IDs are real.
        $hash  = $account['password_hash'] ?? '$2y$10$NDfD3xAaNsz8lkLXzGvM0eVSzaHmB/iD1RBAbeClfLEjdYAGWHVTe';
        $valid = password_verify($secret, $hash) && $account && (int)$account['is_active'] === 1;

        if ($valid) {
            clear_login_failures($pdo, $key);
            if (isset($account['voter_uid'])) {
                login_voter((int)$account['id'], (string)$account['voter_uid']);
                redirect('/voter/dashboard.php');
            }
            login_admin((int)$account['id']);
            redirect('/admin/dashboard.php');
        }

        record_login_failure($pdo, $key);
        $error = 'Those details don\'t match an active account.';
    }
}

$pageTitle = 'Log in';
$layout    = 'auth';
require_once __DIR__ . '/../app/views/partials/header.php';
?>

<div class="auth-split">
<aside class="auth-panel">
  <span class="brand"><span class="brand-mark"><?php echo icon('logo'); ?></span><?php echo e(config()['app']['name']); ?></span>
  <div>
    <h2>Your vote, counted once.</h2>
    <p>Log in, pick a candidate for each position, review your choices, then submit them all at once.</p>
  </div>
  <ul class="auth-points">
    <li><?php echo icon('check-circle'); ?>One vote per position. A second one is rejected.</li>
    <li><?php echo icon('list'); ?>Skip a position and come back before voting closes</li>
    <li><?php echo icon('chart'); ?>Results are published the moment voting closes</li>
  </ul>
</aside>
<div class="auth">
  <div class="card">
    <div class="card-body">
      <h1>Log in to vote</h1>
      <p class="lead">Use your <?php echo e(strtolower(voter_id_label())); ?> and the 6-digit PIN you were given.</p>

      <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><?php echo icon('alert'); ?><span><?php echo e($error); ?></span></div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?php echo csrf_field(); ?>
        <div class="field">
          <label class="label" for="identifier"><?php echo e(voter_id_label()); ?></label>
          <input class="input" type="text" id="identifier" name="identifier" required autocomplete="username" autocapitalize="off" spellcheck="false" placeholder="<?php echo e(config()['voters']['id_example']); ?>" value="<?php echo e($identifier); ?>" <?php echo $identifier === '' ? 'autofocus' : ''; ?>>
        </div>
        <div class="field">
          <label class="label" for="secret">PIN</label>
          <input class="input" type="password" id="secret" name="secret" required autocomplete="current-password" <?php echo $identifier !== '' ? 'autofocus' : ''; ?>>
        </div>
        <button class="btn btn-primary btn-lg btn-block" type="submit">Log in</button>
      </form>
    </div>
  </div>
  <p class="auth-foot">Lost your PIN? Ask the election administrator to reset it.</p>
</div>
</div>

<?php require_once __DIR__ . '/../app/views/partials/footer.php'; ?>
