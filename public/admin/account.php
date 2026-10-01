<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/lib/helpers.php';
require_once __DIR__ . '/../../app/lib/auth.php';

start_secure_session();
require_admin();

$pdo   = db();
$admin = current_admin();
const MIN_PASSWORD = 10;

if (is_post()) {
    $action  = (string)($_POST['action'] ?? '');
    $current = (string)($_POST['current_password'] ?? '');

    $stmt = $pdo->prepare("SELECT password_hash FROM admins WHERE id = :id");
    $stmt->execute([':id' => $admin['id']]);
    $hash = (string)$stmt->fetchColumn();

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
    } elseif (!password_verify($current, $hash)) {
        // Asking for the current password stops anyone who finds an unlocked
        // screen from taking over the account.
        flash('error', 'Your current password is not correct.');
    } elseif ($action === 'username') {
        $username = trim((string)($_POST['username'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            flash('error', 'Usernames are 3–50 characters: letters, numbers, dots, dashes and underscores.');
        } elseif (is_voter_uid($username)) {
            flash('error', 'That looks like a voter ID. Pick a username that doesn\'t start with VOT- or VTR-.');
        } else {
            try {
                $pdo->prepare("UPDATE admins SET username = :u WHERE id = :id")->execute([':u' => $username, ':id' => $admin['id']]);
                flash('success', "Your username is now $username.");
            } catch (PDOException $e) {
                flash('error', 'That username is taken.');
            }
        }
    } elseif ($action === 'password') {
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if (mb_strlen($new) < MIN_PASSWORD) {
            flash('error', 'Use at least ' . MIN_PASSWORD . ' characters for the new password.');
        } elseif ($new !== $confirm) {
            flash('error', 'The two new passwords don\'t match.');
        } elseif (password_verify($new, $hash)) {
            flash('error', 'The new password is the same as the current one.');
        } else {
            $pdo->prepare("UPDATE admins SET password_hash = :h WHERE id = :id")
                ->execute([':h' => password_hash($new, PASSWORD_DEFAULT), ':id' => $admin['id']]);
            session_regenerate_id(true);
            flash('success', 'Password changed. Use the new one next time you log in.');
        }
    }
    redirect('/admin/account.php');
}

$pageTitle = 'Account';
$layout    = 'admin';
$activeNav = 'account';
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<div class="page-head">
  <div>
    <h1>Account</h1>
    <p>Signed in as <strong><?php echo e($admin['username']); ?></strong>. Both changes ask for your current password.</p>
  </div>
</div>

<div class="grid-2 grid-even">
  <section class="card" aria-labelledby="pw-title">
    <div class="card-head"><h2 id="pw-title">Change password</h2></div>
    <div class="card-body">
      <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="password">
        <input type="text" name="username" value="<?php echo e($admin['username']); ?>" autocomplete="username" hidden>
        <div class="field">
          <label class="label" for="pw-current">Current password</label>
          <input class="input" type="password" id="pw-current" name="current_password" required autocomplete="current-password">
        </div>
        <div class="field">
          <label class="label" for="pw-new">New password</label>
          <input class="input" type="password" id="pw-new" name="new_password" required minlength="<?php echo MIN_PASSWORD; ?>" autocomplete="new-password">
          <span class="hint">At least <?php echo MIN_PASSWORD; ?> characters. A short phrase is easier to remember.</span>
        </div>
        <div class="field">
          <label class="label" for="pw-confirm">Repeat new password</label>
          <input class="input" type="password" id="pw-confirm" name="confirm_password" required minlength="<?php echo MIN_PASSWORD; ?>" autocomplete="new-password">
        </div>
        <button class="btn btn-primary" type="submit">Change password</button>
      </form>
    </div>
  </section>

  <section class="card" aria-labelledby="un-title">
    <div class="card-head"><h2 id="un-title">Change username</h2></div>
    <div class="card-body">
      <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="username">
        <div class="field">
          <label class="label" for="un-new">New username</label>
          <input class="input" id="un-new" name="username" required pattern="[A-Za-z0-9._\-]{3,50}" value="<?php echo e($admin['username']); ?>" autocomplete="username">
          <span class="hint">Letters, numbers, dots, dashes and underscores.</span>
        </div>
        <div class="field">
          <label class="label" for="un-current">Current password</label>
          <input class="input" type="password" id="un-current" name="current_password" required autocomplete="current-password">
        </div>
        <button class="btn btn-ghost" type="submit">Change username</button>
      </form>
    </div>
  </section>
</div>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
