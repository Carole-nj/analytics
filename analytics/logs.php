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
    'SELECT event_type, visitor_id, session_id, url, title, event_target, time_spent, recorded_at
       FROM activity_logs ORDER BY id DESC LIMIT 200'
)->fetchAll();
$eventLabels = [
    'view' => 'Page view',
    'ping' => 'Active-time update',
    'end' => 'Page hidden / closed',
    'click' => 'Link / button click',
    'form_submit' => 'Form submitted',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta http-equiv="refresh" content="15">
  <title>Website activity log</title>
  <style>
    body{font:15px system-ui,sans-serif;max-width:1200px;margin:36px auto;padding:0 20px;color:#172033;background:#f5f7fb}
    h1{margin-bottom:6px}.note{color:#586579;margin:0 0 20px}
    section{background:white;border:1px solid #e5e9f0;border-radius:10px;padding:18px;overflow:auto}
    table{width:100%;border-collapse:collapse;text-align:left}
    th,td{padding:10px;border-bottom:1px solid #e5e9f0;vertical-align:top}
    th{font-size:13px;color:#68758a;white-space:nowrap}td{font-size:14px}
    .detail{display:block;color:#68758a;font-size:12px;margin-top:3px}
    a{color:#4338ca}
  </style>
</head>
<body>
  <h1>Website activity log</h1>
  <p class="note">Latest <?= count($logs) ?> events (maximum 200). Form values and visible labels are not collected.</p>
  <p><a href="dashboard.php">Back to dashboard</a></p>
  <section>
    <table>
      <thead><tr><th>Time</th><th>Activity</th><th>Visitor / session</th><th>Page</th><th>Target</th><th>Active time</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $row): ?>
        <tr>
          <td><?= e($row['recorded_at']) ?></td>
          <td><?= e($eventLabels[$row['event_type']] ?? $row['event_type']) ?></td>
          <td><?= e(substr($row['visitor_id'], 0, 8)) ?><span class="detail">Session <?= e(substr($row['session_id'], 0, 8)) ?></span></td>
          <td><?= e($row['url']) ?><span class="detail"><?= e($row['title']) ?></span></td>
          <td><?= e($row['event_target'] ?? '') ?></td>
          <td><?= e(fmt_dur($row['time_spent'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$logs): ?><tr><td colspan="6">No activity yet. Browse the site to create log entries.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>
</body>
</html>
