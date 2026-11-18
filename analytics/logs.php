<?php
require __DIR__ . '/config.php';
session_start();

if (empty($_SESSION['analytics_ok'])) {
    header('Location: dashboard.php');
    exit;
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fmt_dur($seconds): string
{
    $seconds = (int)$seconds;
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
    return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
}

$logs = db()->query(
    'SELECT url, title, time_spent, viewed_at
       FROM pageviews ORDER BY id DESC LIMIT 200'
)->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta http-equiv="refresh" content="15">
  <title>Page visit log</title>
  <style>
    body{font:15px system-ui,sans-serif;max-width:1200px;margin:36px auto;padding:0 20px;color:#172033;background:#f5f7fb}
    h1{margin-bottom:6px}.note{color:#586579;margin:0 0 20px}
    section{background:white;border:1px solid #e5e9f0;border-radius:10px;padding:18px;overflow:auto}
    table{width:fit-content;max-width:100%;border-collapse:collapse;text-align:left}
    th,td{padding:10px;border-bottom:1px solid #e5e9f0;vertical-align:top}
    th:nth-child(2),td:nth-child(2){max-width:150px;overflow-wrap:anywhere}
    th{font-size:13px;color:#68758a;white-space:nowrap}td{font-size:14px}
    .detail{display:block;color:#68758a;font-size:12px;margin-top:3px}
    a{color:#4338ca}
  </style>
</head>
<body>
  <h1>Page visit log</h1>
  <p class="note">Latest <?= count($logs) ?> page visits (maximum 200), timestamps in EAT. Duration is elapsed time until the page is left, including background-tab time.</p>
  <p><a href="dashboard.php">Back to dashboard</a></p>
  <section>
    <table>
      <thead><tr><th>Time (EAT)</th><th>Page</th><th>Duration</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $row): ?>
        <tr>
          <td><?= e($row['viewed_at']) ?></td>
          <td><?= e(trim((string)$row['title']) !== '' ? $row['title'] : ($row['url'] ?: 'Untitled page')) ?></td>
          <td><?= e(fmt_dur($row['time_spent'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$logs): ?><tr><td colspan="3">No page visits yet. Browse the site to create log entries.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>
</body>
</html>
