<?php
declare(strict_types=1);
/**
 * Fills a database with realistic demo data: one live election with votes
 * spread over the last day and a half, one closed election and one draft.
 * Every person in it is fictional.
 *
 *   php database/seed_demo.php
 *   php database/seed_demo.php --closes-in-days=365   (for a hosted demo)
 *
 * The live election closes 30 hours after seeding by default, which suits
 * screenshots. A hosted demo can't be re-seeded on a schedule, so give it
 * a long window instead.
 *
 * It wipes every table first, so it refuses to run unless the configured
 * database name ends in "_demo".
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

$closesInDays = (int)(getopt('', ['closes-in-days:'])['closes-in-days'] ?? 0);

require_once __DIR__ . '/../app/lib/db.php';

$dbName = config()['db']['name'];
if (!str_ends_with($dbName, '_demo')) {
    fwrite(STDERR, "Refusing to seed '$dbName': the database name must end in _demo.\n");
    exit(1);
}

mt_srand(2026); // same data on every run
$pdo = db();

$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
foreach (['votes', 'candidates', 'positions', 'elections', 'voters', 'admins', 'login_attempts'] as $t) {
    $pdo->exec("TRUNCATE TABLE $t");
}
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

/* ---------- Accounts ---------- */
$pdo->prepare("INSERT INTO admins (username, password_hash) VALUES ('demo-admin', :h)")
    ->execute([':h' => password_hash('demo-admin-pass', PASSWORD_DEFAULT)]);

$first = ['Achieng', 'Brian', 'Cynthia', 'Dennis', 'Esther', 'Felix', 'Grace', 'Hassan', 'Ivy', 'James', 'Kendi', 'Lucy', 'Mohamed', 'Njeri', 'Otieno', 'Peris', 'Quincy', 'Ruth', 'Samuel', 'Tabitha', 'Umar', 'Vivian', 'Wanjiku', 'Yusuf', 'Zawadi', 'Allan', 'Beatrice', 'Collins', 'Diana', 'Erick'];
$last  = ['Mwangi', 'Odhiambo', 'Kiprono', 'Wanjala', 'Chebet', 'Kamau', 'Omondi', 'Njoroge', 'Wekesa', 'Mutua', 'Achola', 'Barasa', 'Korir', 'Nyambura', 'Ali', 'Owino', 'Kariuki', 'Jeptoo'];

$insertVoter = $pdo->prepare("INSERT INTO voters (voter_uid, full_name, password_hash, is_active, created_at) VALUES (:u, :n, :h, :a, :c)");
$pinHash     = password_hash('246810', PASSWORD_DEFAULT); // every demo voter shares this PIN
$voterIds    = [];
$used        = [];
for ($i = 0; $i < 140; $i++) {
    do {
        $name = $first[mt_rand(0, count($first) - 1)] . ' ' . $last[mt_rand(0, count($last) - 1)];
    } while (isset($used[$name]));
    $used[$name] = true;
    // Registration numbers: course / campus / number / intake month / year.
    // The first three (CS/MK/0001/09/23 ...) are the demo logins.
    $course = $i < 3 ? 'CS' : ['CS', 'IT', 'ED', 'BA', 'EN'][mt_rand(0, 4)];
    $intake = $i < 3 ? 9 : [1, 5, 9][mt_rand(0, 2)];
    $year   = $i < 3 ? 23 : mt_rand(22, 25);
    $uid    = sprintf('%s/MK/%04d/%02d/%d', $course, $i + 1, $intake, $year);
    $insertVoter->execute([
        ':u' => $uid,
        ':n' => $name,
        ':h' => $pinHash,
        ':a' => $i % 47 === 46 ? 0 : 1,
        ':c' => date('Y-m-d H:i:s', strtotime('-' . (20 - intdiv($i, 10)) . ' days')),
    ]);
    $voterIds[] = (int)$pdo->lastInsertId();
}
$activeVoters = array_values(array_filter($voterIds, fn($id, $i) => $i % 47 !== 46, ARRAY_FILTER_USE_BOTH));

/* ---------- Elections ---------- */
function election(PDO $pdo, string $name, string $status, ?string $starts, ?string $ends, array $positions): array
{
    $pdo->prepare("INSERT INTO elections (name, status, starts_at, ends_at) VALUES (:n, :s, :a, :b)")
        ->execute([':n' => $name, ':s' => $status, ':a' => $starts, ':b' => $ends]);
    $eid = (int)$pdo->lastInsertId();
    $out = ['id' => $eid, 'positions' => []];
    $order = 0;
    foreach ($positions as $posName => $candidates) {
        $pdo->prepare("INSERT INTO positions (election_id, name, sort_order) VALUES (:e, :n, :o)")
            ->execute([':e' => $eid, ':n' => $posName, ':o' => $order++]);
        $pid = (int)$pdo->lastInsertId();
        $cids = [];
        foreach ($candidates as [$cName, $bio, $weight]) {
            $pdo->prepare("INSERT INTO candidates (position_id, name, manifesto) VALUES (:p, :n, :m)")
                ->execute([':p' => $pid, ':n' => $cName, ':m' => $bio]);
            $cids[(int)$pdo->lastInsertId()] = $weight;
        }
        $out['positions'][$pid] = $cids;
    }
    return $out;
}

function pick_weighted(array $weights): int
{
    $r = mt_rand(1, array_sum($weights));
    foreach ($weights as $id => $w) {
        if (($r -= $w) <= 0) return $id;
    }
    return array_key_first($weights);
}

/* Votes arrive in bursts: a morning rush, a lunchtime bump, a quiet night. */
function cast_votes(PDO $pdo, array $election, array $voters, float $share, int $startTs, int $endTs): void
{
    $insert = $pdo->prepare("INSERT INTO votes (election_id, position_id, voter_id, candidate_id, cast_at) VALUES (:e, :p, :v, :c, :t)");
    shuffle($voters);
    $voters = array_slice($voters, 0, (int)round(count($voters) * $share));
    foreach ($voters as $vid) {
        do {
            $ts   = mt_rand($startTs, $endTs);
            $hour = (int)date('G', $ts);
            $keep = match (true) {
                $hour >= 8 && $hour < 11  => 100,
                $hour >= 12 && $hour < 14 => 70,
                $hour >= 7 && $hour < 22  => 35,
                default                   => 6,
            };
        } while (mt_rand(1, 100) > $keep);
        foreach ($election['positions'] as $pid => $weights) {
            if (mt_rand(1, 100) <= 4) continue; // a few voters skip a position
            $insert->execute([':e' => $election['id'], ':p' => $pid, ':v' => $vid, ':c' => pick_weighted($weights), ':t' => date('Y-m-d H:i:s', $ts + mt_rand(5, 90))]);
        }
    }
}

$now = time();

$live = election($pdo, 'Student Council Election 2026', 'active',
    date('Y-m-d H:i:00', $now - 33 * 3600), date('Y-m-d H:00:00', $now + ($closesInDays > 0 ? $closesInDays * 86400 : 30 * 3600)), [
    'President' => [
        ['Achieng Odhiambo', 'Longer library hours during exams and a student feedback desk.', 38],
        ['Brian Kiprono', 'Cheaper cafeteria meal plans and better Wi-Fi in hostels.', 33],
        ['Wanjiku Kamau', 'A mentorship programme pairing first years with final years.', 22],
    ],
    'Vice President' => [
        ['Hassan Ali', 'Monthly open forums with the administration.', 47],
        ['Grace Chebet', 'Safer night transport between campus and hostels.', 42],
    ],
    'Secretary General' => [
        ['Felix Wekesa', 'Publish every council decision online within a week.', 30],
        ['Lucy Nyambura', 'A shared calendar for all clubs and societies.', 36],
        ['Otieno Barasa', 'Digitise student ID replacement requests.', 18],
    ],
    'Treasurer' => [
        ['Mohamed Owino', 'Quarterly spending reports anyone can read.', 51],
        ['Ivy Jeptoo', 'A small grants fund for student-led projects.', 40],
    ],
    'Sports Representative' => [
        ['Samuel Korir', 'Inter-faculty league with weekend fixtures.', 44],
        ['Zawadi Mutua', 'Fix the courts and add women\'s football.', 49],
    ],
]);
// Demo voters 1 and 2 have not voted yet, so the ballot can be tried.
$canVote = array_slice($activeVoters, 2);
cast_votes($pdo, $live, $canVote, 0.58, $now - 33 * 3600, $now - 600);

$closed = election($pdo, 'Class Representatives 2025', 'closed',
    '2025-11-10 08:00:00', '2025-11-12 17:00:00', [
    'First-year Representative' => [
        ['Diana Mwangi', 'Orientation week run by students.', 41],
        ['Collins Omondi', 'A first-year study group network.', 35],
    ],
    'Final-year Representative' => [
        ['Beatrice Wanjala', 'Career fair with local employers.', 52],
        ['Erick Njoroge', 'Graduation fee transparency.', 31],
    ],
]);
cast_votes($pdo, $closed, $activeVoters, 0.71, strtotime('2025-11-10 08:00:00'), strtotime('2025-11-12 17:00:00'));

election($pdo, 'Hostel Committee 2027', 'draft', null, null, [
    'Chairperson' => [
        ['Kendi Kariuki', 'Fix hot water in blocks C and D first.', 1],
        ['Yusuf Achola', 'Quiet hours and a better common room.', 1],
    ],
    'Welfare Secretary' => [],
]);

$votes = (int)$pdo->query("SELECT COUNT(*) FROM votes")->fetchColumn();
echo "Seeded '$dbName': " . count($voterIds) . " voters, 3 elections, $votes votes.\n";
echo "Admin login: demo-admin / demo-admin-pass\n";
echo "Voter logins: CS/MK/0001/09/23 or CS/MK/0002/09/23, PIN 246810 (both still to vote)\n";
