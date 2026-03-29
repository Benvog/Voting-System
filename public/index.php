<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/lib/db.php';

try {
    $pdo            = db();
    $activeElection = $pdo->query("
        SELECT name, ends_at FROM elections
        WHERE status = 'active'
          AND (starts_at IS NULL OR starts_at <= NOW())
          AND (ends_at   IS NULL OR ends_at   >= NOW())
        LIMIT 1
    ")->fetch();
    $lastElection = $pdo->query("
        SELECT name, ends_at FROM elections
        WHERE status = 'closed'
        ORDER BY ends_at DESC
        LIMIT 1
    ")->fetch();
} catch (\Exception $e) {
    $activeElection = null;
    $lastElection   = null;
}

$pageTitle = 'Welcome';
require_once __DIR__ . '/../app/views/partials/header.php';
?>

<!-- Hero -->
<div style="text-align:center; padding: 64px 24px 48px;">

  <div style="
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 80px; height: 80px;
    background: linear-gradient(135deg, var(--primary), var(--primary-light));
    border-radius: 22px;
    font-size: 2.2rem;
    margin-bottom: 24px;
    box-shadow: var(--shadow-md);
  ">🗳</div>

  <h1 style="
    font-family: 'Sora', sans-serif;
    font-weight: 800;
    font-size: 2.6rem;
    color: var(--text);
    letter-spacing: -.04em;
    line-height: 1.1;
    margin-bottom: 14px;
  ">Voting Management System</h1>

  <p style="
    color: var(--text-muted);
    font-size: 1.05rem;
    max-width: 480px;
    margin: 0 auto 32px;
    line-height: 1.6;
  ">
    A secure, simple platform for conducting elections online.
    Cast your vote or manage the entire election process.
  </p>

  <?php if ($activeElection): ?>

    <!-- Live badge -->
    <div style="margin-bottom: 24px;">
      <span class="badge badge-active" style="font-size:.88rem; padding: 6px 18px;">
        🟢 <?php echo htmlspecialchars($activeElection['name']); ?> — Live Now
      </span>
    </div>

    <?php if ($activeElection['ends_at']): ?>
    <!-- Countdown label -->
    <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-muted);margin-bottom:12px;">Voting closes in</div>
    <!-- Countdown tiles -->
    <div id="countdown-wrap" style="display:inline-grid;grid-template-columns:repeat(4,80px);gap:10px;margin-bottom:40px;">
      <?php foreach (['days'=>'Days','hours'=>'Hours','mins'=>'Mins','secs'=>'Secs'] as $id=>$label): ?>
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px 8px 12px;box-shadow:var(--shadow-sm);position:relative;overflow:hidden;">
        <div style="position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--primary),var(--primary-light));"></div>
        <div id="cd-<?php echo $id; ?>" style="font-family:'Sora',sans-serif;font-weight:800;font-size:2.2rem;color:var(--primary);line-height:1;letter-spacing:-.02em;transition:color .3s;">00</div>
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-muted);margin-top:6px;"><?php echo $label; ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <script>
    (function(){
      var deadline=new Date("<?php echo date('Y-m-d\TH:i:s',strtotime($activeElection['ends_at'])); ?>").getTime();
      var ids=['days','hours','mins','secs'],els={};
      ids.forEach(function(id){els[id]=document.getElementById('cd-'+id);});
      function pad(n){return n<10?'0'+n:String(n);}
      function tick(){
        var diff=deadline-Date.now();
        if(diff<=0){document.getElementById('countdown-wrap').innerHTML='<p style="color:var(--text-muted);font-size:.95rem;grid-column:1/-1;">Voting has closed.</p>';return;}
        els.days.textContent=pad(Math.floor(diff/86400000));
        els.hours.textContent=pad(Math.floor((diff%86400000)/3600000));
        els.mins.textContent=pad(Math.floor((diff%3600000)/60000));
        els.secs.textContent=pad(Math.floor((diff%60000)/1000));
        var col=diff<3600000?'var(--danger)':'var(--primary)';
        ids.forEach(function(id){els[id].style.color=col;});
      }
      tick();setInterval(tick,1000);
    })();
    </script>
    <?php endif; ?>

  <?php elseif ($lastElection): ?>

    <!-- No active election — show last closed -->
    <div style="margin-bottom:36px;">
      <span class="badge badge-closed" style="font-size:.85rem;padding:6px 16px;display:inline-block;margin-bottom:10px;">
        No election currently running
      </span>
      <p class="text-muted" style="font-size:.88rem;margin-top:8px;">
        Last election: <strong><?php echo htmlspecialchars($lastElection['name']); ?></strong>
        <?php if ($lastElection['ends_at']): ?>
          — closed <?php echo date('d M Y', strtotime($lastElection['ends_at'])); ?>
        <?php endif; ?>
      </p>
    </div>

  <?php else: ?>

    <!-- No elections at all -->
    <div style="margin-bottom:36px;">
      <span class="badge badge-draft" style="font-size:.85rem;padding:6px 16px;">
        No elections scheduled yet
      </span>
    </div>

  <?php endif; ?>

</div>

<!-- Portal Cards -->
<div style="
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 20px;
  max-width: 760px;
  margin: 0 auto 48px;
">

  <a href="/voter/login.php" style="text-decoration:none;">
    <div class="card" style="text-align:center; padding:40px 28px; border:2px solid var(--border); transition:all .22s; cursor:pointer;"
      onmouseover="this.style.borderColor='var(--primary)'; this.style.transform='translateY(-4px)'; this.style.boxShadow='var(--shadow-lg)';"
      onmouseout="this.style.borderColor=''; this.style.transform=''; this.style.boxShadow='';">
      <div style="width:64px;height:64px;border-radius:18px;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;font-size:1.8rem;margin:0 auto 18px;box-shadow:0 8px 24px var(--primary-glow);">🧑‍💼</div>
      <div style="font-family:'Sora',sans-serif;font-weight:800;font-size:1.25rem;color:var(--text);margin-bottom:8px;">Voter Portal</div>
      <p style="color:var(--text-muted);font-size:.9rem;line-height:1.5;margin-bottom:20px;">Log in with your Voter ID and PIN to access your ballot and cast your votes.</p>
      <span class="btn btn-primary" style="pointer-events:none;">Enter Voter Portal →</span>
    </div>
  </a>

  <a href="/admin/login.php" style="text-decoration:none;">
    <div class="card" style="text-align:center; padding:40px 28px; border:2px solid var(--border); transition:all .22s; cursor:pointer;"
      onmouseover="this.style.borderColor='var(--accent)'; this.style.transform='translateY(-4px)'; this.style.boxShadow='var(--shadow-lg)';"
      onmouseout="this.style.borderColor=''; this.style.transform=''; this.style.boxShadow='';">
      <div style="width:64px;height:64px;border-radius:18px;background:linear-gradient(135deg,var(--accent-dark),var(--accent));display:flex;align-items:center;justify-content:center;font-size:1.8rem;margin:0 auto 18px;box-shadow:0 8px 24px rgba(244,162,97,.25);">⚙️</div>
      <div style="font-family:'Sora',sans-serif;font-weight:800;font-size:1.25rem;color:var(--text);margin-bottom:8px;">Admin Panel</div>
      <p style="color:var(--text-muted);font-size:.9rem;line-height:1.5;margin-bottom:20px;">Manage elections, positions, candidates, and voters. View live results.</p>
      <span class="btn btn-accent" style="pointer-events:none;">Enter Admin Panel →</span>
    </div>
  </a>

</div>

<!-- Results link -->
<div style="text-align:center; margin-bottom:24px;">
  <a href="/results.php" class="btn btn-ghost">📊 View Public Results →</a>
</div>

<!-- Features strip -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;max-width:760px;margin:0 auto;padding-top:16px;border-top:1px solid var(--border);">
  <?php foreach ([
    ['🔒','Secure',      'Password hashing, CSRF protection & session hardening'],
    ['✅','One Vote',    'Database constraints prevent any double voting'],
    ['📊','Live Results','Vote counts update in real time for admins'],
    ['🌙','Dark Mode',   'Switch between light and dark with one click'],
  ] as $f): ?>
    <div style="text-align:center;padding:20px 12px;">
      <div style="font-size:1.6rem;margin-bottom:8px;"><?php echo $f[0]; ?></div>
      <div style="font-family:'Sora',sans-serif;font-weight:700;font-size:.92rem;color:var(--text);margin-bottom:4px;"><?php echo $f[1]; ?></div>
      <div style="font-size:.8rem;color:var(--text-muted);line-height:1.4;"><?php echo $f[2]; ?></div>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../app/views/partials/footer.php'; ?>
