<?php
require __DIR__ . '/config.php';
session_start();

// ---- Login / logout ----
if (isset($_GET['logout'])) { session_destroy(); header('Location: dashboard.php'); exit; }

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (hash_equals(DASHBOARD_PASSWORD, (string)$_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['analytics_ok'] = true;
        header('Location: dashboard.php'); exit;
    }
    $loginError = 'Wrong password.';
}

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['analytics_ok'])): ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Analytics Login</title>
<style>
body{font-family:system-ui,sans-serif;background:#f3f4f6;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
form{background:#fff;padding:32px;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);width:300px}
h1{font-size:20px;margin:0 0 16px}input,button{width:100%;padding:10px;font-size:15px;box-sizing:border-box;margin-top:8px;border-radius:6px}
input{border:1px solid #d1d5db}button{background:#2563eb;color:#fff;border:0;cursor:pointer}.err{color:#dc2626;font-size:14px}
</style></head><body>
<form method="post"><h1>Analytics Dashboard</h1>
<?php if ($loginError): ?><div class="err"><?= e($loginError) ?></div><?php endif; ?>
<input type="password" name="password" placeholder="Password" autofocus required>
<button type="submit">Sign in</button></form></body></html>
<?php exit; endif;

// ---- Data ----
$days = (int)($_GET['days'] ?? 7);
if (!in_array($days, [1, 7, 30, 90], true)) $days = 7;
$from = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
$pdo = db();

function one(PDO $pdo, string $sql, array $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function all(PDO $pdo, string $sql, array $p = []): array { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }

function fmt_dur($sec): string
{
    $sec = (int)round($sec);
    if ($sec < 60) return $sec . 's';
    if ($sec < 3600) return floor($sec / 60) . 'm ' . ($sec % 60) . 's';
    return floor($sec / 3600) . 'h ' . floor(($sec % 3600) / 60) . 'm';
}

$online = (int)one($pdo, 'SELECT COUNT(DISTINCT visitor_id) FROM sessions WHERE last_activity >= ?',
    [date('Y-m-d H:i:s', time() - ONLINE_WINDOW_MIN * 60)]);
$uniqueVisitors = (int)one($pdo, 'SELECT COUNT(DISTINCT visitor_id) FROM sessions WHERE started_at >= ?', [$from]);
$newVisitors    = (int)one($pdo, 'SELECT COUNT(*) FROM visitors WHERE first_seen >= ?', [$from]);
$sessionsCount  = (int)one($pdo, 'SELECT COUNT(*) FROM sessions WHERE started_at >= ?', [$from]);
$pageviews      = (int)one($pdo, 'SELECT COUNT(*) FROM pageviews WHERE viewed_at >= ?', [$from]);
$avgDuration    = (float)one($pdo, 'SELECT COALESCE(AVG(duration_seconds),0) FROM sessions WHERE started_at >= ?', [$from]);
$bounces        = (int)one($pdo, 'SELECT COUNT(*) FROM sessions WHERE started_at >= ? AND page_views <= 1', [$from]);
$bounceRate     = $sessionsCount ? round($bounces / $sessionsCount * 100) : 0;
$returning      = max(0, $uniqueVisitors - $newVisitors);

$daily = all($pdo,
    'SELECT DATE(started_at) d, COUNT(DISTINCT visitor_id) visitors, COUNT(*) sessions
       FROM sessions WHERE started_at >= ? GROUP BY DATE(started_at) ORDER BY d', [$from]);
$dailyMap = [];
foreach ($daily as $r) $dailyMap[$r['d']] = $r;
$series = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $series[] = ['d' => $d, 'visitors' => (int)($dailyMap[$d]['visitors'] ?? 0)];
}
$maxVisitors = max(1, max(array_column($series, 'visitors')));

$topPages = all($pdo,
    'SELECT url, COUNT(*) views, COUNT(DISTINCT session_id) sessions, AVG(time_spent) avg_time
       FROM pageviews WHERE viewed_at >= ? GROUP BY url ORDER BY views DESC LIMIT 10', [$from]);
$referrers = all($pdo,
    "SELECT IF(referrer_host='' OR referrer_host IS NULL,'Direct / none',referrer_host) src, COUNT(*) n
       FROM sessions WHERE started_at >= ? GROUP BY src ORDER BY n DESC LIMIT 8", [$from]);
$devices = all($pdo,
    'SELECT v.device k, COUNT(DISTINCT s.visitor_id) n FROM sessions s JOIN visitors v ON v.visitor_id = s.visitor_id
      WHERE s.started_at >= ? GROUP BY v.device ORDER BY n DESC', [$from]);
$browsers = all($pdo,
    'SELECT v.browser k, COUNT(DISTINCT s.visitor_id) n FROM sessions s JOIN visitors v ON v.visitor_id = s.visitor_id
      WHERE s.started_at >= ? GROUP BY v.browser ORDER BY n DESC', [$from]);
$recent = all($pdo,
    'SELECT s.started_at, s.duration_seconds, s.page_views, s.entry_page, s.referrer_host,
            v.device, v.browser, v.os, v.total_sessions, SUBSTRING(v.visitor_id,1,8) vid
       FROM sessions s JOIN visitors v ON v.visitor_id = s.visitor_id
      ORDER BY s.started_at DESC LIMIT 20');

function bars(array $rows, string $label = 'k'): void
{
    $max = max(1, max(array_column($rows, 'n') ?: [1]));
    foreach ($rows as $r) {
        $pct = round($r['n'] / $max * 100);
        echo '<div class="row"><span class="lbl">' . e($r[$label] ?: 'Unknown') . '</span>'
           . '<span class="bar"><i style="width:' . $pct . '%"></i></span><b>' . (int)$r['n'] . '</b></div>';
    }
    if (!$rows) echo '<p class="muted">No data yet.</p>';
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Analytics Dashboard</title>
<style>
:root{--bg:#f3f4f6;--card:#fff;--text:#111827;--muted:#6b7280;--accent:#2563eb;--line:#e5e7eb}
*{box-sizing:border-box}body{margin:0;font-family:system-ui,sans-serif;background:var(--bg);color:var(--text)}
header{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;padding:16px 24px;background:var(--card);border-bottom:1px solid var(--line)}
h1{font-size:20px;margin:0}main{padding:24px;max-width:1200px;margin:auto}
.filters a{padding:6px 12px;border-radius:6px;text-decoration:none;color:var(--text);font-size:14px;border:1px solid var(--line)}
.filters a.on{background:var(--accent);color:#fff;border-color:var(--accent)}
.logout{color:var(--muted);font-size:14px;margin-left:12px}
.grid{display:grid;gap:16px}.kpis{grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:16px}
.two{grid-template-columns:repeat(auto-fit,minmax(340px,1fr));margin-bottom:16px}
.card{background:var(--card);border-radius:12px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.kpi .n{font-size:28px;font-weight:700}.kpi .t{color:var(--muted);font-size:13px}
.live{color:#16a34a}.live:before{content:"";display:inline-block;width:8px;height:8px;border-radius:50%;background:#16a34a;margin-right:6px}
h2{font-size:15px;margin:0 0 12px}
.chart{display:flex;align-items:flex-end;gap:3px;height:160px;border-bottom:1px solid var(--line)}
.chart div{flex:1;background:var(--accent);border-radius:3px 3px 0 0;min-height:2px;position:relative}
.chart div:hover:after{content:attr(data-tip);position:absolute;bottom:100%;left:50%;transform:translateX(-50%);background:#111827;color:#fff;font-size:11px;padding:3px 6px;border-radius:4px;white-space:nowrap}
.row{display:flex;align-items:center;gap:10px;margin:7px 0;font-size:14px}.lbl{width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bar{flex:1;background:var(--line);height:8px;border-radius:4px;overflow:hidden}.bar i{display:block;height:100%;background:var(--accent)}
table{width:100%;border-collapse:collapse;font-size:13px}th,td{text-align:left;padding:8px 6px;border-bottom:1px solid var(--line)}
th{color:var(--muted);font-weight:600}.muted{color:var(--muted)}.scroll{overflow-x:auto}
</style></head><body>
<header>
  <h1>Website Analytics</h1>
  <div>
    <span class="filters">
      <?php foreach ([1 => 'Today', 7 => '7 days', 30 => '30 days', 90 => '90 days'] as $d => $lab): ?>
        <a href="?days=<?= $d ?>" class="<?= $d === $days ? 'on' : '' ?>"><?= $lab ?></a>
      <?php endforeach; ?>
    </span>
    <a class="logout" href="logs.php">Page visit log</a>
    <a class="logout" href="?logout=1">Log out</a>
  </div>
</header>
<main>
  <div class="grid kpis">
    <div class="card kpi"><div class="n live"><?= $online ?></div><div class="t">Online now</div></div>
    <div class="card kpi"><div class="n"><?= $uniqueVisitors ?></div><div class="t">Unique visitors</div></div>
    <div class="card kpi"><div class="n"><?= $newVisitors ?> / <?= $returning ?></div><div class="t">New / returning</div></div>
    <div class="card kpi"><div class="n"><?= $sessionsCount ?></div><div class="t">Visits (sessions)</div></div>
    <div class="card kpi"><div class="n"><?= $pageviews ?></div><div class="t">Page views</div></div>
    <div class="card kpi"><div class="n"><?= e(fmt_dur($avgDuration)) ?></div><div class="t">Avg. time on site</div></div>
    <div class="card kpi"><div class="n"><?= $bounceRate ?>%</div><div class="t">Bounce rate</div></div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <h2>Visitors per day</h2>
    <div class="chart">
      <?php foreach ($series as $s): ?>
        <div style="height:<?= round($s['visitors'] / $maxVisitors * 100) ?>%" data-tip="<?= e($s['d']) ?>: <?= $s['visitors'] ?>"></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="grid two">
    <div class="card"><h2>Top pages</h2><div class="scroll"><table>
      <tr><th>Page</th><th>Views</th><th>Avg. time</th></tr>
      <?php foreach ($topPages as $p): ?>
        <tr><td><?= e($p['url']) ?></td><td><?= (int)$p['views'] ?></td><td><?= e(fmt_dur($p['avg_time'])) ?></td></tr>
      <?php endforeach; if (!$topPages) echo '<tr><td colspan="3" class="muted">No data yet.</td></tr>'; ?>
    </table></div></div>
    <div class="card"><h2>Traffic sources</h2><?php bars($referrers, 'src'); ?></div>
  </div>

  <div class="grid two">
    <div class="card"><h2>Devices</h2><?php bars($devices); ?></div>
    <div class="card"><h2>Browsers</h2><?php bars($browsers); ?></div>
  </div>

  <div class="card"><h2>Recent visits</h2><div class="scroll"><table>
    <tr><th>Started (EAT)</th><th>Visitor</th><th>Time on site</th><th>Pages</th><th>Entry page</th><th>Source</th><th>Device</th><th>Visit #</th></tr>
    <?php foreach ($recent as $r): ?>
      <tr>
        <td><?= e($r['started_at']) ?></td>
        <td><code><?= e($r['vid']) ?></code></td>
        <td><?= e(fmt_dur($r['duration_seconds'])) ?></td>
        <td><?= (int)$r['page_views'] ?></td>
        <td><?= e($r['entry_page']) ?></td>
        <td><?= e($r['referrer_host'] ?: 'Direct') ?></td>
        <td><?= e($r['device'] . ' · ' . $r['browser'] . ' · ' . $r['os']) ?></td>
        <td><?= (int)$r['total_sessions'] ?></td>
      </tr>
    <?php endforeach; if (!$recent) echo '<tr><td colspan="8" class="muted">No visits recorded yet.</td></tr>'; ?>
  </table></div></div>
</main>
</body></html>
