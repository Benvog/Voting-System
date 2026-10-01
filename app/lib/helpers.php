<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';

/* ---------------- OUTPUT ---------------- */

function e(?string $value): string
{
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function csrf_field(): string
{
  return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function plural(int $n, string $word, ?string $many = null): string
{
  return number_format($n) . ' ' . ($n === 1 ? $word : ($many ?? $word . 's'));
}

function fmt_datetime(?string $value, string $format = 'd M Y, H:i'): string
{
  return $value ? date($format, strtotime($value)) : '—';
}

/* A meter (progress track). Width is the only inline style: it is data, not styling. */
function meter(float $percent, string $label): string
{
  $p = max(0, min(100, (int)round($percent)));
  return '<div class="meter" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $p . '" aria-label="' . e($label) . '"><span style="width: ' . $p . '%"></span></div>';
}

function initials(string $name): string
{
  $parts = preg_split('/\s+/', trim($name)) ?: [];
  $first = mb_substr($parts[0] ?? '', 0, 1);
  $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
  return mb_strtoupper($first . $last);
}

/* ---------------- REQUEST FLOW ---------------- */

function redirect(string $to): never
{
  header('Location: ' . $to);
  exit;
}

function is_post(): bool
{
  return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/* Messages survive one redirect (post/redirect/get), so refreshing a page
   never re-submits a form. */
function flash(string $type, string $message): void
{
  $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
  $messages = $_SESSION['flash'] ?? [];
  unset($_SESSION['flash']);
  return $messages;
}

/* ---------------- ELECTIONS ---------------- */

/* The one election voters can vote in right now: active and inside its time
   window. Only one election can be active at a time; ordering by id keeps
   the choice stable even for older data that broke that rule. */
function current_election(PDO $pdo): ?array
{
  $row = $pdo->query("
    SELECT id, name, status, starts_at, ends_at
    FROM elections
    WHERE status = 'active'
      AND (starts_at IS NULL OR starts_at <= NOW())
      AND (ends_at   IS NULL OR ends_at   >= NOW())
    ORDER BY id DESC
    LIMIT 1
  ")->fetch();
  return $row ?: null;
}

/* How an election looks to a person, which is more than its status column:
   an active election can still be waiting for its start time or past its end. */
function election_phase(array $election): string
{
  if ($election['status'] === 'draft')  return 'draft';
  if ($election['status'] === 'closed') return 'closed';
  $now = time();
  if ($election['starts_at'] && strtotime($election['starts_at']) > $now) return 'scheduled';
  if ($election['ends_at']   && strtotime($election['ends_at'])   < $now) return 'ended';
  return 'live';
}

/* Counts an admin needs to judge an election at a glance. */
function election_summary(PDO $pdo, int $electionId): array
{
  $stmt = $pdo->prepare("
    SELECT
      (SELECT COUNT(*) FROM positions WHERE election_id = :e1 AND is_active = 1) AS positions,
      (SELECT COUNT(*) FROM candidates c JOIN positions p ON p.id = c.position_id
        WHERE p.election_id = :e2 AND p.is_active = 1 AND c.is_active = 1) AS candidates,
      (SELECT COUNT(*) FROM positions p WHERE p.election_id = :e3 AND p.is_active = 1
        AND NOT EXISTS (SELECT 1 FROM candidates c WHERE c.position_id = p.id AND c.is_active = 1)) AS empty_positions,
      (SELECT COUNT(*) FROM votes WHERE election_id = :e4) AS votes,
      (SELECT COUNT(DISTINCT voter_id) FROM votes WHERE election_id = :e5) AS voted,
      (SELECT COUNT(*) FROM voters WHERE is_active = 1) AS eligible
  ");
  $stmt->execute([':e1' => $electionId, ':e2' => $electionId, ':e3' => $electionId, ':e4' => $electionId, ':e5' => $electionId]);
  $s = array_map('intval', $stmt->fetch());
  $s['turnout'] = $s['eligible'] > 0 ? (int)round($s['voted'] / $s['eligible'] * 100) : 0;
  return $s;
}

function phase_badge(array $election): string
{
  [$class, $label] = match (election_phase($election)) {
    'live'      => ['badge-live', 'Live'],
    'scheduled' => ['badge-info', 'Scheduled'],
    'ended'     => ['badge-warn', 'Time up'],
    'closed'    => ['badge-muted', 'Closed'],
    default     => ['badge-muted', 'Draft'],
  };
  return '<span class="badge ' . $class . '">' . $label . '</span>';
}

/* ---------------- ICONS ----------------
   One stroke icon set (24px grid, 1.75 stroke) replaces the emoji the app
   used before. */
function icon(string $name, string $class = ''): string
{
  static $paths = [
    'logo'      => '<path d="M5 9h14v11H5z"/><path d="M9 9V4h6v5"/><path d="m9.5 14 2 2 3.5-3.5"/>',
    'overview'  => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
    'ballot'    => '<path d="m9 12 2 2 4-4"/><path d="M5 7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v12H5z"/><path d="M22 19H2"/>',
    'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'chart'     => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
    'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
    'plus'      => '<path d="M12 5v14M5 12h14"/>',
    'trash'     => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
    'edit'      => '<path d="M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>',
    'check'     => '<path d="M20 6 9 17l-5-5"/>',
    'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
    'clock'     => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    'search'    => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'key'       => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
    'lock'      => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    'menu'      => '<path d="M4 6h16M4 12h16M4 18h16"/>',
    'x'         => '<path d="M18 6 6 18M6 6l12 12"/>',
    'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
    'chevron-left'  => '<path d="m15 18-6-6 6-6"/>',
    'arrow-right'   => '<path d="M5 12h14M12 5l7 7-7 7"/>',
    'arrow-left'    => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
    'sun'       => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>',
    'moon'      => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
    'alert'     => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/>',
    'info'      => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
    'play'      => '<path d="m7 4 13 8-13 8z"/>',
    'stop'      => '<rect x="6" y="6" width="12" height="12" rx="1.5"/>',
    'list'      => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    'copy'      => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
    'refresh'   => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
    'power'     => '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.77.04"/>',
    'external'  => '<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    'download'  => '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>',
    'table'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/>',
  ];
  $body = $paths[$name] ?? '';
  return '<svg class="icon ' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}
