<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/lib/helpers.php';
require_once __DIR__ . '/../../app/lib/auth.php';

start_secure_session();
require_admin();

$pdo = db();
const PER_PAGE  = 25;
const BULK_MAX  = 200;

function generate_voter_uid(PDO $pdo): string
{
    $check = $pdo->prepare("SELECT 1 FROM voters WHERE voter_uid = :uid");
    do {
        $uid = 'VOT-' . strtoupper(bin2hex(random_bytes(3)));
        $check->execute([':uid' => $uid]);
    } while ($check->fetchColumn());
    return $uid;
}

function generate_pin(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/* Keep the list view (search, filter, page) when coming back from an action. */
function back_to_list(): string
{
    $q = array_intersect_key($_GET, array_flip(['q', 'status', 'page']));
    return '/admin/voters.php' . ($q ? '?' . http_build_query($q) : '');
}

if (is_post()) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
        redirect(back_to_list());
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        // One name per line; blank lines ignored.
        $names = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($_POST['names'] ?? '')))));
        $names = array_map(fn($n) => mb_substr($n, 0, 120), $names);

        if (!$names) {
            flash('error', 'Enter at least one name.');
        } elseif (count($names) > BULK_MAX) {
            flash('error', 'Add at most ' . BULK_MAX . ' voters at a time.');
        } else {
            $insert = $pdo->prepare("INSERT INTO voters (voter_uid, full_name, password_hash, is_active) VALUES (:u, :n, :h, 1)");
            $created = [];
            $pdo->beginTransaction();
            foreach ($names as $name) {
                $uid = generate_voter_uid($pdo);
                $pin = generate_pin();
                $insert->execute([':u' => $uid, ':n' => $name, ':h' => password_hash($pin, PASSWORD_DEFAULT)]);
                $created[] = ['name' => $name, 'uid' => $uid, 'pin' => $pin];
            }
            $pdo->commit();
            // Shown once on the next page view, then forgotten.
            $_SESSION['new_credentials'] = $created;
            flash('success', 'Registered ' . plural(count($created), 'voter') . '. Share their login details below; PINs are not shown again.');
        }
        redirect('/admin/voters.php');
    }

    $voterId = (int)($_POST['voter_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, voter_uid, full_name, is_active FROM voters WHERE id = :id");
    $stmt->execute([':id' => $voterId]);
    $voter = $stmt->fetch();

    if (!$voter) {
        flash('error', 'That voter no longer exists.');
    } elseif ($action === 'reset_pin') {
        $pin = generate_pin();
        $pdo->prepare("UPDATE voters SET password_hash = :h WHERE id = :id")
            ->execute([':h' => password_hash($pin, PASSWORD_DEFAULT), ':id' => $voterId]);
        $pdo->prepare("DELETE FROM login_attempts WHERE identifier = :u")->execute([':u' => $voter['voter_uid']]);
        $_SESSION['new_credentials'] = [['name' => $voter['full_name'], 'uid' => $voter['voter_uid'], 'pin' => $pin]];
        flash('success', "New PIN created for {$voter['full_name']}. The old one no longer works.");
    } elseif ($action === 'toggle_active') {
        $active = (int)$voter['is_active'] === 1 ? 0 : 1;
        $pdo->prepare("UPDATE voters SET is_active = :a WHERE id = :id")->execute([':a' => $active, ':id' => $voterId]);
        flash('success', $active ? "{$voter['full_name']} can vote again." : "{$voter['full_name']} is disabled and is logged out on their next click.");
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM voters WHERE id = :id")->execute([':id' => $voterId]);
        flash('success', "Deleted {$voter['full_name']} and any votes they cast.");
    }
    redirect(back_to_list());
}

/* ---------- List ---------- */
$q      = trim((string)($_GET['q'] ?? ''));
$status = in_array($_GET['status'] ?? '', ['active', 'disabled'], true) ? $_GET['status'] : '';
$live   = current_election($pdo);

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(v.full_name LIKE :q1 OR v.voter_uid LIKE :q2)';
    $params[':q1'] = $params[':q2'] = '%' . $q . '%';
}
if ($status !== '') {
    $where[] = 'v.is_active = ' . ($status === 'active' ? 1 : 0);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count = $pdo->prepare("SELECT COUNT(*) FROM voters v $whereSql");
$count->execute($params);
$total = (int)$count->fetchColumn();
$pages = max(1, (int)ceil($total / PER_PAGE));
$page  = min($pages, max(1, (int)($_GET['page'] ?? 1)));

$votedSql = $live
    ? '(SELECT COUNT(*) FROM votes x WHERE x.voter_id = v.id AND x.election_id = ' . (int)$live['id'] . ')'
    : 'NULL';
$list = $pdo->prepare("
    SELECT v.id, v.voter_uid, v.full_name, v.is_active, v.created_at, $votedSql AS voted
    FROM voters v $whereSql
    ORDER BY v.created_at DESC, v.id DESC
    LIMIT " . PER_PAGE . " OFFSET " . (($page - 1) * PER_PAGE));
$list->execute($params);
$voters = $list->fetchAll();

$counts = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(is_active = 1), 0) AS active FROM voters")->fetch();

$newCredentials = $_SESSION['new_credentials'] ?? [];
unset($_SESSION['new_credentials']);

function page_url(int $page): string
{
    $q = array_intersect_key($_GET, array_flip(['q', 'status']));
    $q['page'] = $page;
    return '/admin/voters.php?' . http_build_query($q);
}

$pageTitle = 'Voters';
$layout    = 'admin';
$activeNav = 'voters';
require_once __DIR__ . '/../../app/views/partials/header.php';
?>

<div class="page-head">
  <div>
    <h1>Voters</h1>
    <p><?php echo number_format((int)$counts['active']); ?> can vote · <?php echo number_format((int)$counts['total'] - (int)$counts['active']); ?> disabled. Every voter logs in with a generated voter ID and a 6-digit PIN.</p>
  </div>
  <div class="actions">
    <a class="btn btn-primary" href="#add"><?php echo icon('plus'); ?>Add voters</a>
  </div>
</div>

<?php if ($newCredentials): ?>
  <section class="card creds" aria-labelledby="creds-title">
    <div class="card-head">
      <div>
        <h2 id="creds-title"><?php echo icon('key'); ?><span class="sr-only">New </span>Login details</h2>
        <div class="sub">Shown once. Copy or download them now and hand each voter their own.</div>
      </div>
      <?php if (count($newCredentials) > 1): ?>
        <button class="btn btn-ghost btn-sm" type="button" data-download-csv="#creds-table" data-filename="voter-logins.csv"><?php echo icon('download'); ?>Download CSV</button>
      <?php endif; ?>
    </div>
    <?php if (count($newCredentials) === 1): $c = $newCredentials[0]; ?>
      <div class="card-body">
        <p class="muted small"><?php echo e($c['name']); ?></p>
        <dl class="creds-grid">
          <div class="cred"><div><dt>Voter ID</dt><dd><?php echo e($c['uid']); ?></dd></div><button class="icon-btn" type="button" data-copy="<?php echo e($c['uid']); ?>" aria-label="Copy voter ID"><?php echo icon('copy'); ?></button></div>
          <div class="cred"><div><dt>PIN</dt><dd><?php echo e($c['pin']); ?></dd></div><button class="icon-btn" type="button" data-copy="<?php echo e($c['pin']); ?>" aria-label="Copy PIN"><?php echo icon('copy'); ?></button></div>
        </dl>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table" id="creds-table">
          <thead><tr><th>Name</th><th>Voter ID</th><th>PIN</th></tr></thead>
          <tbody>
            <?php foreach ($newCredentials as $c): ?>
              <tr><td><?php echo e($c['name']); ?></td><td class="mono"><?php echo e($c['uid']); ?></td><td class="mono"><?php echo e($c['pin']); ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<section class="card" aria-labelledby="list-title">
  <div class="card-head">
    <h2 id="list-title" class="sr-only">Voter list</h2>
    <form class="inline-fields" method="get" role="search">
      <div class="field grow input-icon">
        <label class="sr-only" for="q">Search voters</label>
        <?php echo icon('search'); ?>
        <input class="input" id="q" name="q" value="<?php echo e($q); ?>" placeholder="Search by name or voter ID">
      </div>
      <div class="field fit">
        <label class="sr-only" for="status">Status</label>
        <select class="select" id="status" name="status" data-autosubmit>
          <option value="">All voters</option>
          <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Can vote</option>
          <option value="disabled" <?php echo $status === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
        </select>
      </div>
      <button class="btn btn-ghost" type="submit">Search</button>
      <?php if ($q !== '' || $status !== ''): ?><a class="btn btn-quiet" href="/admin/voters.php">Clear</a><?php endif; ?>
    </form>
  </div>

  <?php if (!$voters): ?>
    <div class="empty">
      <?php echo icon('users'); ?>
      <h3><?php echo $q !== '' || $status !== '' ? 'No voters match' : 'No voters yet'; ?></h3>
      <p><?php echo $q !== '' || $status !== '' ? 'Try a different name or ID.' : 'Add voters below to give them login details.'; ?></p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Voter</th>
            <th>Status</th>
            <?php if ($live): ?><th>Current election</th><?php endif; ?>
            <th>Added</th>
            <th class="actions"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($voters as $v): ?>
            <tr>
              <td>
                <div class="person">
                  <span class="avatar" aria-hidden="true"><?php echo e(initials($v['full_name'])); ?></span>
                  <div>
                    <div class="row-title"><?php echo e($v['full_name']); ?></div>
                    <div class="row-sub mono"><?php echo e($v['voter_uid']); ?></div>
                  </div>
                </div>
              </td>
              <td><?php echo (int)$v['is_active'] === 1 ? '<span class="badge badge-live">Can vote</span>' : '<span class="badge badge-muted">Disabled</span>'; ?></td>
              <?php if ($live): ?>
                <td class="row-sub"><?php echo (int)$v['voted'] > 0 ? '<span class="badge badge-info">Voted</span>' : 'Not yet'; ?></td>
              <?php endif; ?>
              <td class="row-sub nowrap"><?php echo fmt_datetime($v['created_at'], 'd M Y'); ?></td>
              <td class="actions">
                <form class="inline-form" method="post" data-confirm="<?php echo e($v['full_name']); ?>'s current PIN stops working and a new one is shown once." data-confirm-title="Reset PIN?" data-confirm-ok="Reset PIN" data-confirm-tone="primary">
                  <?php echo csrf_field(); ?><input type="hidden" name="action" value="reset_pin"><input type="hidden" name="voter_id" value="<?php echo (int)$v['id']; ?>">
                  <button class="icon-btn" type="submit" aria-label="<?php echo e('Reset PIN for ' . $v['full_name']); ?>" title="Reset PIN"><?php echo icon('key'); ?></button>
                </form>
                <form class="inline-form" method="post">
                  <?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="voter_id" value="<?php echo (int)$v['id']; ?>">
                  <button class="icon-btn" type="submit" aria-label="<?php echo e(((int)$v['is_active'] === 1 ? 'Disable ' : 'Enable ') . $v['full_name']); ?>" title="<?php echo (int)$v['is_active'] === 1 ? 'Disable' : 'Enable'; ?>"><?php echo icon('power'); ?></button>
                </form>
                <form class="inline-form" method="post" data-confirm="<?php echo e($v['full_name']); ?> and every vote they cast will be removed. Results will change." data-confirm-title="Delete voter?" data-confirm-ok="Delete voter">
                  <?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="voter_id" value="<?php echo (int)$v['id']; ?>">
                  <button class="icon-btn" type="submit" aria-label="<?php echo e('Delete ' . $v['full_name']); ?>" title="Delete"><?php echo icon('trash'); ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="pager">
      <span class="row-sub"><?php echo number_format(($page - 1) * PER_PAGE + 1); ?>–<?php echo number_format(min($total, $page * PER_PAGE)); ?> of <?php echo number_format($total); ?></span>
      <div class="pager-links">
        <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?php echo e(page_url($page - 1)); ?>"><?php echo icon('chevron-left'); ?>Previous</a><?php endif; ?>
        <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?php echo e(page_url($page + 1)); ?>">Next<?php echo icon('chevron-right'); ?></a><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>

<section class="card" id="add" aria-labelledby="add-title">
  <div class="card-head">
    <div>
      <h2 id="add-title">Add voters</h2>
      <div class="sub">One full name per line, up to <?php echo BULK_MAX; ?> at a time. Each gets a voter ID and PIN.</div>
    </div>
  </div>
  <div class="card-body">
    <form method="post">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="create">
      <div class="field">
        <label class="label" for="names">Names</label>
        <textarea class="textarea" id="names" name="names" rows="4" required placeholder="Jane Wanjiru&#10;Peter Otieno"></textarea>
      </div>
      <button class="btn btn-primary" type="submit"><?php echo icon('plus'); ?>Create login details</button>
    </form>
  </div>
</section>

<?php require_once __DIR__ . '/../../app/views/partials/footer.php'; ?>
