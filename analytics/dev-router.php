<?php
declare(strict_types=1);

$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'analytics-local-' . substr(hash('sha256', __DIR__), 0, 16) . '.sqlite';
$pdo = new PDO('sqlite:' . $databasePath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS visitors (
        visitor_id TEXT PRIMARY KEY,
        first_seen TEXT NOT NULL,
        last_seen TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS sessions (
        session_id TEXT PRIMARY KEY,
        visitor_id TEXT NOT NULL,
        started_at TEXT NOT NULL,
        last_activity TEXT NOT NULL,
        entry_page TEXT NOT NULL,
        page_views INTEGER NOT NULL DEFAULT 0,
        duration_seconds INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS pageviews (
        pageview_id TEXT PRIMARY KEY,
        session_id TEXT NOT NULL,
        url TEXT NOT NULL,
        title TEXT NOT NULL,
        viewed_at TEXT NOT NULL,
        time_spent INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS activity_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id TEXT UNIQUE,
        event_type TEXT NOT NULL,
        visitor_id TEXT NOT NULL,
        session_id TEXT NOT NULL,
        pageview_id TEXT NOT NULL,
        url TEXT NOT NULL,
        title TEXT NOT NULL,
        event_target TEXT,
        time_spent INTEGER NOT NULL DEFAULT 0,
        recorded_at TEXT NOT NULL
    );'
);
$activityColumns = array_column($pdo->query('PRAGMA table_info(activity_logs)')->fetchAll(), 'name');
if (!in_array('event_id', $activityColumns, true)) {
    $pdo->exec('ALTER TABLE activity_logs ADD COLUMN event_id TEXT');
}
if (!in_array('event_target', $activityColumns, true)) {
    $pdo->exec('ALTER TABLE activity_logs ADD COLUMN event_target TEXT');
}
$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_activity_event_id ON activity_logs (event_id)');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_duration(int $seconds): string
{
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's';
    return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
}

function format_eat_time(string $utcTime): string
{
    $utc = new DateTimeImmutable($utcTime, new DateTimeZone('UTC'));
    return $utc->setTimezone(new DateTimeZone('Africa/Nairobi'))->format('Y-m-d H:i:s');
}

if ($path === '/track.php') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit;
    }

    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) {
        http_response_code(400);
        exit;
    }

    $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    foreach (['visitor_id', 'session_id', 'pageview_id'] as $key) {
        if (!isset($data[$key]) || !is_string($data[$key]) || !preg_match($uuidPattern, $data[$key])) {
            http_response_code(400);
            exit;
        }
    }
    $eventId = $data['event_id'] ?? '';
    if ($eventId === '') {
        $hex = bin2hex(random_bytes(16));
        $eventId = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
    if (!is_string($eventId) || !preg_match($uuidPattern, $eventId)) {
        http_response_code(400);
        exit;
    }

    $type = $data['type'] ?? '';
    if (!in_array($type, ['view', 'ping', 'end', 'click', 'form_submit'], true)) {
        http_response_code(400);
        exit;
    }

    $visitorId = $data['visitor_id'];
    $sessionId = $data['session_id'];
    $pageviewId = $data['pageview_id'];
    $timeSpent = max(0, min(86400, (int)($data['time_spent'] ?? 0)));
    $eventTarget = substr(is_string($data['event_target'] ?? null) ? $data['event_target'] : '', 0, 500);
    $now = gmdate('Y-m-d H:i:s');
    $url = substr((string)($data['url'] ?? '/'), 0, 500);
    $title = substr((string)($data['title'] ?? ''), 0, 255);

    $pdo->beginTransaction();
    if ($type === 'view') {
        $pdo->prepare(
            'INSERT INTO visitors (visitor_id, first_seen, last_seen) VALUES (?, ?, ?)
             ON CONFLICT(visitor_id) DO UPDATE SET last_seen = excluded.last_seen'
        )->execute([$visitorId, $now, $now]);
        $pdo->prepare(
            'INSERT OR IGNORE INTO sessions
                (session_id, visitor_id, started_at, last_activity, entry_page)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$sessionId, $visitorId, $now, $now, $url]);
        $insertPageview = $pdo->prepare(
            'INSERT OR IGNORE INTO pageviews (pageview_id, session_id, url, title, viewed_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insertPageview->execute([$pageviewId, $sessionId, $url, $title, $now]);
        if ($insertPageview->rowCount() > 0) {
            $pdo->prepare(
                'UPDATE sessions SET page_views = page_views + 1, last_activity = ? WHERE session_id = ?'
            )->execute([$now, $sessionId]);
        }
    } elseif ($type === 'ping' || $type === 'end') {
        $pdo->prepare(
            'UPDATE pageviews SET time_spent = MAX(time_spent, ?)
             WHERE pageview_id = ? AND session_id = ?'
        )->execute([$timeSpent, $pageviewId, $sessionId]);
        $pdo->prepare(
            'UPDATE sessions SET last_activity = ?,
                duration_seconds = (
                    SELECT COALESCE(SUM(time_spent), 0) FROM pageviews WHERE session_id = ?
                )
             WHERE session_id = ?'
        )->execute([$now, $sessionId, $sessionId]);
        $pdo->prepare('UPDATE visitors SET last_seen = ? WHERE visitor_id = ?')
            ->execute([$now, $visitorId]);
    } else {
        $pdo->prepare('UPDATE sessions SET last_activity = ? WHERE session_id = ?')
            ->execute([$now, $sessionId]);
        $pdo->prepare('UPDATE visitors SET last_seen = ? WHERE visitor_id = ?')
            ->execute([$now, $visitorId]);
    }

    $pdo->prepare(
        'INSERT OR IGNORE INTO activity_logs
            (event_id, event_type, visitor_id, session_id, pageview_id, url, title, event_target, time_spent, recorded_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$eventId, $type, $visitorId, $sessionId, $pageviewId, $url, $title, $eventTarget, $timeSpent, $now]);
    $pdo->commit();

    http_response_code(204);
    exit;
}

if ($path === '/dashboard.php') {
    $visitors = (int)$pdo->query('SELECT COUNT(*) FROM visitors')->fetchColumn();
    $sessions = (int)$pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
    $pageviews = (int)$pdo->query('SELECT COUNT(*) FROM pageviews')->fetchColumn();
    $averageDuration = (int)round((float)$pdo->query(
        'SELECT COALESCE(AVG(duration_seconds), 0) FROM sessions'
    )->fetchColumn());
    $topPages = $pdo->query(
        'SELECT url, COUNT(*) AS views, AVG(time_spent) AS average_time
         FROM pageviews GROUP BY url ORDER BY views DESC LIMIT 10'
    )->fetchAll();
    $recentSessions = $pdo->query(
        'SELECT started_at, duration_seconds, page_views, entry_page, visitor_id
         FROM sessions ORDER BY started_at DESC LIMIT 20'
    )->fetchAll();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Local analytics dashboard</title>
      <style>
        body{font:15px system-ui,sans-serif;max-width:1000px;margin:36px auto;padding:0 20px;color:#172033;background:#f5f7fb}
        h1{margin-bottom:6px}.note{color:#586579;margin:0 0 24px}
        .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:20px 0}
        .card,section{background:white;border:1px solid #e5e9f0;border-radius:10px;padding:18px}
        .value{font-size:28px;font-weight:700}.label{color:#68758a;font-size:13px}
        section{margin-top:16px;overflow:auto}table{width:100%;border-collapse:collapse;text-align:left}
        th,td{padding:10px;border-bottom:1px solid #e5e9f0;vertical-align:top}th{font-size:13px;color:#68758a}
        a{color:#4338ca}
      </style>
    </head>
    <body>
      <h1>Local analytics</h1>
      <p class="note">Test data stored on this computer only. This dashboard is not collecting public website visitors.</p>
      <p><a href="/test.html">Open the demo site</a> · <a href="/logs.php">View activity log</a></p>
      <div class="cards">
        <div class="card"><div class="value"><?= $visitors ?></div><div class="label">Unique browsers</div></div>
        <div class="card"><div class="value"><?= $sessions ?></div><div class="label">Sessions</div></div>
        <div class="card"><div class="value"><?= $pageviews ?></div><div class="label">Page views</div></div>
        <div class="card"><div class="value"><?= h(format_duration($averageDuration)) ?></div><div class="label">Average active time / session</div></div>
      </div>
      <section>
        <h2>Top pages</h2>
        <table><thead><tr><th>Page</th><th>Views</th><th>Average active time</th></tr></thead><tbody>
        <?php foreach ($topPages as $row): ?>
          <tr><td><?= h($row['url']) ?></td><td><?= (int)$row['views'] ?></td>
          <td><?= h(format_duration((int)round((float)$row['average_time']))) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$topPages): ?><tr><td colspan="3">No visits yet. Browse the demo to create test data.</td></tr><?php endif; ?>
        </tbody></table>
      </section>
      <section>
        <h2>Recent sessions</h2>
        <table><thead><tr><th>Started (EAT)</th><th>Visitor</th><th>Entry page</th><th>Page views</th><th>Active time</th></tr></thead><tbody>
        <?php foreach ($recentSessions as $row): ?>
          <tr><td><?= h(format_eat_time($row['started_at'])) ?></td><td><?= h(substr($row['visitor_id'], 0, 8)) ?></td>
          <td><?= h($row['entry_page']) ?></td><td><?= (int)$row['page_views'] ?></td>
          <td><?= h(format_duration((int)$row['duration_seconds'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$recentSessions): ?><tr><td colspan="5">No sessions yet.</td></tr><?php endif; ?>
        </tbody></table>
      </section>
    </body>
    </html>
    <?php
    exit;
}

if ($path === '/logs.php') {
    $logs = $pdo->query(
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
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <meta http-equiv="refresh" content="5">
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
      <p class="note">Showing the latest <?= count($logs) ?> events (maximum 200), timestamps shown in East Africa Time (EAT).
        Form values and visible labels are never collected.</p>
      <p><a href="/dashboard.php">Back to dashboard</a> · <a href="/test.html">Open the demo site</a></p>
      <section>
        <table>
          <thead><tr><th>Time (EAT)</th><th>Activity</th><th>Visitor / session</th><th>Page</th><th>Target</th><th>Active time</th></tr></thead>
          <tbody>
          <?php foreach ($logs as $row): ?>
            <tr>
              <td><?= h(format_eat_time($row['recorded_at'])) ?></td>
              <td><?= h($eventLabels[$row['event_type']] ?? $row['event_type']) ?></td>
              <td><?= h(substr($row['visitor_id'], 0, 8)) ?><span class="detail">Session <?= h(substr($row['session_id'], 0, 8)) ?></span></td>
              <td><?= h(trim((string)$row['title']) !== '' ? $row['title'] : ($row['url'] ?: 'Untitled page')) ?></td>
              <td><?= h($row['event_target'] ?? '') ?></td>
              <td><?= h(format_duration((int)$row['time_spent'])) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$logs): ?><tr><td colspan="6">No activity yet. Browse the demo to create log entries.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </section>
    </body>
    </html>
    <?php
    exit;
}

if ($path === '/') {
    header('Location: /test.html', true, 302);
    exit;
}

$file = __DIR__ . $path;
if (is_file($file)) {
    return false;
}

http_response_code(404);
