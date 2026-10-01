<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Status changes shared by the elections list and the election page.
 * Each one records a flash message; the caller redirects afterwards.
 */
function election_status_action(PDO $pdo, string $action, int $electionId): void
{
  $stmt = $pdo->prepare("SELECT id, name, status FROM elections WHERE id = :id");
  $stmt->execute([':id' => $electionId]);
  $election = $stmt->fetch();

  if (!$election) {
    flash('error', 'That election no longer exists.');
    return;
  }

  if ($action === 'activate') {
    if ($election['status'] !== 'draft') {
      flash('error', 'Only a draft election can be opened.');
      return;
    }

    // Only one election may be active, so voters never see the wrong ballot.
    $other = $pdo->query("SELECT name FROM elections WHERE status = 'active' LIMIT 1")->fetchColumn();
    if ($other !== false) {
      flash('error', "Close \"$other\" before opening another election.");
      return;
    }

    $s = election_summary($pdo, $electionId);
    if ($s['positions'] === 0) {
      flash('error', 'Add at least one position before opening voting.');
      return;
    }
    if ($s['empty_positions'] > 0) {
      flash('error', plural($s['empty_positions'], 'position has', 'positions have') . ' no candidates yet. Add candidates or remove those positions first.');
      return;
    }

    $pdo->prepare("UPDATE elections SET status = 'active' WHERE id = :id AND status = 'draft'")
        ->execute([':id' => $electionId]);
    flash('success', "Voting is open for \"{$election['name']}\".");
    return;
  }

  if ($action === 'close') {
    if ($election['status'] !== 'active') {
      flash('error', 'Only an active election can be closed.');
      return;
    }
    $pdo->prepare("UPDATE elections SET status = 'closed' WHERE id = :id")
        ->execute([':id' => $electionId]);
    flash('success', "\"{$election['name']}\" is closed. Its results are final.");
    return;
  }

  if ($action === 'delete') {
    $pdo->prepare("DELETE FROM elections WHERE id = :id")->execute([':id' => $electionId]);
    flash('success', "Deleted \"{$election['name']}\" with its positions, candidates and votes.");
    return;
  }

  flash('error', 'Unknown action.');
}

/* Whether positions and candidates can still change. Once anyone has voted,
   removing a candidate would silently delete their votes, so the ballot
   structure is frozen. */
function ballot_is_locked(PDO $pdo, int $electionId): bool
{
  $stmt = $pdo->prepare("SELECT EXISTS (SELECT 1 FROM votes WHERE election_id = :id)");
  $stmt->execute([':id' => $electionId]);
  return (bool)$stmt->fetchColumn();
}

/* datetime-local input -> MySQL DATETIME, or null when left empty. */
function parse_datetime_input(?string $value): ?string
{
  $value = trim((string)$value);
  if ($value === '') return null;
  $ts = strtotime($value);
  return $ts === false ? null : date('Y-m-d H:i:00', $ts);
}

function validate_window(?string $startsAt, ?string $endsAt): ?string
{
  if ($startsAt && $endsAt && strtotime($endsAt) <= strtotime($startsAt)) {
    return 'The closing time must be after the opening time.';
  }
  return null;
}
