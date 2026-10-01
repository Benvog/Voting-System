<?php
declare(strict_types=1);
/**
 * Results for one election, shared by the admin and public results pages.
 * Chart rules: one series colour, the leader emphasised and everyone else
 * grey, values always printed as text, and a table twin for the line chart.
 */

require_once __DIR__ . '/../lib/helpers.php';

function results_data(PDO $pdo, array $election): array
{
    $eid = (int)$election['id'];

    $positions = $pdo->prepare("SELECT id, name FROM positions WHERE election_id = :e AND is_active = 1 ORDER BY sort_order, id");
    $positions->execute([':e' => $eid]);

    $counts = $pdo->prepare("
        SELECT c.id, c.name, c.position_id, COUNT(v.id) AS votes
        FROM candidates c
        JOIN positions p ON p.id = c.position_id
        LEFT JOIN votes v ON v.candidate_id = c.id AND v.election_id = :e1
        WHERE p.election_id = :e2 AND c.is_active = 1
        GROUP BY c.id, c.name, c.position_id
        ORDER BY votes DESC, c.name
    ");
    $counts->execute([':e1' => $eid, ':e2' => $eid]);
    $byPosition = [];
    foreach ($counts->fetchAll() as $row) {
        $byPosition[(int)$row['position_id']][] = $row;
    }

    $results = [];
    foreach ($positions->fetchAll() as $pos) {
        $cands = $byPosition[(int)$pos['id']] ?? [];
        $total = array_sum(array_map(fn($c) => (int)$c['votes'], $cands));
        $top   = (int)($cands[0]['votes'] ?? 0);
        $tied  = $top > 0 && count(array_filter($cands, fn($c) => (int)$c['votes'] === $top)) > 1;
        $results[] = ['position' => $pos, 'candidates' => $cands, 'total' => $total, 'top' => $top, 'tied' => $tied];
    }

    // When each voter first voted, for the participation-over-time chart.
    $firsts = $pdo->prepare("SELECT MIN(cast_at) AS t FROM votes WHERE election_id = :e GROUP BY voter_id ORDER BY t");
    $firsts->execute([':e' => $eid]);

    return [
        'summary'   => election_summary($pdo, $eid),
        'positions' => $results,
        'firsts'    => array_map('strtotime', $firsts->fetchAll(PDO::FETCH_COLUMN)),
    ];
}

/* Cumulative voters over time, bucketed so the line has a sensible number of points. */
function participation_series(array $election, array $firsts): array
{
    if (!$firsts) return [];

    $start = $election['starts_at'] ? strtotime($election['starts_at']) : $firsts[0];
    $start = min($start, $firsts[0]);
    $end   = $election['status'] === 'closed'
        ? max(end($firsts), $election['ends_at'] ? min(strtotime($election['ends_at']), time()) : end($firsts))
        : ($election['ends_at'] ? min(time(), strtotime($election['ends_at'])) : time());
    $end   = max($end, end($firsts));

    $span = $end - $start;
    $step = match (true) {
        $span <= 3 * 86400  => 3600,
        $span <= 14 * 86400 => 6 * 3600,
        default             => 86400,
    };
    $start = intdiv($start, $step) * $step;

    $series = [];
    $i = 0;
    $n = count($firsts);
    for ($t = $start; $t <= $end + $step; $t += $step) {
        while ($i < $n && $firsts[$i] <= $t) $i++;
        $series[] = [$t, $i];
        if ($t >= $end) break;
    }
    return ['points' => $series, 'step' => $step];
}

function nice_max(int $value): int
{
    if ($value <= 4) return 4;
    $mag  = 10 ** (int)floor(log10($value));
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        if ($m * $mag >= $value) return (int)($m * $mag);
    }
    return $value;
}

function render_participation_chart(array $series, int $eligible): string
{
    $W = 640; $H = 220; $L = 40; $R = 12; $T = 12; $B = 28;
    $pts  = $series['points'];
    $step = $series['step'];
    $max  = nice_max(max(1, end($pts)[1]));
    $t0   = $pts[0][0];
    $t1   = end($pts)[0];
    $x    = fn($t) => $L + ($t1 > $t0 ? ($t - $t0) / ($t1 - $t0) : 0) * ($W - $L - $R);
    $y    = fn($v) => $T + (1 - $v / $max) * ($H - $T - $B);
    $fmt  = $step < 86400 ? 'D H:i' : 'd M';

    $line = [];
    $hover = [];
    foreach ($pts as [$t, $v]) {
        $px = round($x($t), 1); $py = round($y($v), 1);
        $line[]  = "$px,$py";
        $hover[] = [$px, $py, date($fmt === 'D H:i' ? 'D d M, H:i' : 'd M Y', $t), number_format($v) . ' voted'];
    }
    $area = 'M' . $line[0] . ' L' . implode(' L', $line) . ' L' . round($x($t1), 1) . ',' . ($H - $B) . ' L' . round($x($t0), 1) . ',' . ($H - $B) . ' Z';

    $svg = '';
    for ($k = 0; $k <= 4; $k++) {
        $v  = $max * $k / 4;
        $yy = round($y($v), 1);
        $svg .= '<line class="' . ($k === 0 ? 'baseline' : 'gridline') . '" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '"/>';
        $svg .= '<text class="tick" x="' . ($L - 8) . '" y="' . ($yy + 4) . '" text-anchor="end">' . number_format($v) . '</text>';
    }
    $labels = min(5, count($pts));
    for ($k = 0; $k < $labels; $k++) {
        $idx = (int)round($k * (count($pts) - 1) / max(1, $labels - 1));
        $t   = $pts[$idx][0];
        $anchor = $k === 0 ? 'start' : ($k === $labels - 1 ? 'end' : 'middle');
        $svg .= '<text class="tick" x="' . round($x($t), 1) . '" y="' . ($H - 8) . '" text-anchor="' . $anchor . '">' . e(date($fmt, $t)) . '</text>';
    }
    [$lx, $ly] = [round($x($t1), 1), round($y(end($pts)[1]), 1)];

    $label = 'Line chart: voters who have voted over time, rising to ' . number_format(end($pts)[1]) . ' of ' . number_format($eligible) . '.';

    $html  = '<div class="chart" tabindex="0" role="img" aria-label="' . e($label) . '" data-points="' . e(json_encode($hover)) . '">';
    $html .= '<svg viewBox="0 0 ' . $W . ' ' . $H . '" aria-hidden="true">' . $svg;
    $html .= '<path class="area" d="' . $area . '"/>';
    $html .= '<polyline class="line" points="' . implode(' ', $line) . '"/>';
    $html .= '<circle class="end-dot" cx="' . $lx . '" cy="' . $ly . '" r="4.5"/>';
    $html .= '<line class="cross" x1="0" x2="0" y1="' . $T . '" y2="' . ($H - $B) . '"/>';
    $html .= '<circle class="cross-dot" r="4.5" cx="0" cy="0"/>';
    $html .= '<rect class="hit" x="' . $L . '" y="0" width="' . ($W - $L - $R) . '" height="' . $H . '"/>';
    $html .= '</svg><div class="tooltip" aria-hidden="true"></div></div>';

    // Table twin: every value reachable without hovering.
    $html .= '<details class="table-toggle"><summary>' . icon('table') . 'Show as table</summary><div class="table-wrap"><table class="table"><thead><tr><th>Time</th><th class="num">Voters who had voted</th></tr></thead><tbody>';
    foreach ($pts as [$t, $v]) {
        $html .= '<tr><td>' . e(date($fmt === 'D H:i' ? 'D d M, H:i' : 'd M Y', $t)) . '</td><td class="num">' . number_format($v) . '</td></tr>';
    }
    $html .= '</tbody></table></div></details>';
    return $html;
}

function render_results(PDO $pdo, array $election): void
{
    $data    = results_data($pdo, $election);
    $s       = $data['summary'];
    $turnout = min(100, $s['turnout']);
    $phase   = election_phase($election);
    $series  = participation_series($election, $data['firsts']);
    ?>
    <div class="stats">
      <div class="card stat">
        <div class="stat-label">Turnout</div>
        <div class="stat-value"><?php echo $turnout; ?>%</div>
        <?php echo meter($turnout, 'Turnout'); ?>
      </div>
      <div class="card stat">
        <div class="stat-label">Voters who voted</div>
        <div class="stat-value"><?php echo number_format($s['voted']); ?> <small>of <?php echo number_format($s['eligible']); ?></small></div>
      </div>
      <div class="card stat">
        <div class="stat-label">Votes cast</div>
        <div class="stat-value"><?php echo number_format($s['votes']); ?></div>
        <div class="stat-sub">Across <?php echo plural($s['positions'], 'position'); ?></div>
      </div>
      <div class="card stat">
        <div class="stat-label"><?php echo $phase === 'closed' ? 'Closed' : ($phase === 'live' ? 'Closes' : 'Status'); ?></div>
        <div class="stat-value stat-value-sm"><?php
          echo $phase === 'closed' ? e(fmt_datetime($election['ends_at'], 'd M Y'))
             : ($phase === 'live' ? ($election['ends_at'] ? e(fmt_datetime($election['ends_at'], 'd M, H:i')) : 'When closed') : phase_badge($election));
        ?></div>
        <?php if ($phase === 'live'): ?><div class="stat-sub">Counts are live and may change</div><?php endif; ?>
      </div>
    </div>

    <?php if ($series): ?>
      <section class="card" aria-labelledby="pt-title">
        <div class="card-head">
          <div>
            <h2 id="pt-title">Participation over time</h2>
            <div class="sub">Voters who had cast at least one vote</div>
          </div>
        </div>
        <div class="card-body"><?php echo render_participation_chart($series, $s['eligible']); ?></div>
      </section>
    <?php endif; ?>

    <?php if (!$data['positions']): ?>
      <div class="card"><div class="empty"><?php echo icon('chart'); ?><h3>Nothing to count yet</h3><p>This election has no positions.</p></div></div>
    <?php endif; ?>

    <div class="results-grid">
      <?php foreach ($data['positions'] as $r):
        $leader = !$r['tied'] && $r['top'] > 0 ? $r['candidates'][0] : null;
      ?>
        <section class="card" aria-labelledby="pos-<?php echo (int)$r['position']['id']; ?>">
          <div class="card-head result-head">
            <div>
              <h2 id="pos-<?php echo (int)$r['position']['id']; ?>"><?php echo e($r['position']['name']); ?></h2>
              <div class="sub"><?php echo plural($r['total'], 'vote'); ?></div>
            </div>
            <?php if ($leader): ?>
              <div class="leader"><?php echo $phase === 'closed' ? 'Winner' : 'Leading'; ?><strong><?php echo e($leader['name']); ?></strong></div>
            <?php elseif ($r['tied']): ?>
              <div class="leader">Tied at the top<strong><?php echo plural($r['top'], 'vote'); ?> each</strong></div>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <?php if (!$r['candidates']): ?>
              <p class="muted small">No candidates.</p>
            <?php else: ?>
              <div class="bars">
                <?php foreach ($r['candidates'] as $c):
                  $votes = (int)$c['votes'];
                  $share = $r['total'] > 0 ? $votes / $r['total'] * 100 : 0;
                  $width = $r['top'] > 0 ? $votes / $r['top'] * 100 : 0; // longest bar spans the track
                  $isLead = $votes > 0 && $votes === $r['top'];
                ?>
                  <div class="bar-row<?php echo $isLead ? ' is-lead' : ''; ?>">
                    <div class="bar-meta">
                      <span><?php echo e($c['name']); ?></span>
                      <span class="val"><b><?php echo number_format($votes); ?></b> · <?php echo number_format($share, $share > 0 && $share < 1 ? 1 : 0); ?>%</span>
                    </div>
                    <div class="bar-track" aria-hidden="true"><div class="bar-fill" style="width: <?php echo round($width, 2); ?>%"></div></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
    <?php
}
