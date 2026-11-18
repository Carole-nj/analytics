<?php
// Collector endpoint: receives events from track.js and stores them.
require __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); exit; }

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) { http_response_code(400); exit; }

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
$type = $data['type'] ?? '';
foreach (['visitor_id', 'session_id', 'pageview_id'] as $k) {
    if (!isset($data[$k]) || !is_string($data[$k]) || !preg_match($uuidRe, $data[$k])) { http_response_code(400); exit; }
}
$eventId = $data['event_id'] ?? '';
if ($eventId === '') {
    $hex = bin2hex(random_bytes(16));
    $eventId = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}
if (!is_string($eventId) || !preg_match($uuidRe, $eventId)) { http_response_code(400); exit; }
if (!in_array($type, ['view', 'ping', 'end', 'click', 'form_submit'], true)) { http_response_code(400); exit; }

$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
// Ignore bots and crawlers
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|curl|wget|headless|lighthouse|facebookexternalhit/i', $ua)) {
    http_response_code(204); exit;
}

function parse_ua(string $ua): array
{
    $device = 'Desktop';
    if (preg_match('/ipad|tablet|kindle|silk/i', $ua)) $device = 'Tablet';
    elseif (preg_match('/mobi|iphone|android/i', $ua))  $device = 'Mobile';

    $browser = 'Other';
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Safari' => 'Safari'] as $needle => $name) {
        if (stripos($ua, $needle) !== false) { $browser = $name; break; }
    }

    $os = 'Other';
    foreach (['Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iOS',
              'Mac OS' => 'macOS', 'Linux' => 'Linux'] as $needle => $name) {
        if (stripos($ua, $needle) !== false) { $os = $name; break; }
    }
    return [$device, $browser, $os];
}

$visitorId   = $data['visitor_id'];
$sessionId   = $data['session_id'];
$pageviewId  = $data['pageview_id'];
$timeSpent   = max(0, min(86400, (int)($data['time_spent'] ?? 0)));
$eventTarget = substr(is_string($data['event_target'] ?? null) ? $data['event_target'] : '', 0, 500);
$url         = substr((string)($data['url'] ?? '/'), 0, 500);
$title       = substr((string)($data['title'] ?? ''), 0, 255);
$nowStr      = date('Y-m-d H:i:s');

try {
    $pdo = db();
    $pdo->beginTransaction();

    if ($type === 'view') {
        // Referrer host (empty = direct). Ignore referrals from our own site.
        $refHost = '';
        $ref = (string)($data['referrer'] ?? '');
        if ($ref !== '') {
            $refHost = strtolower((string)parse_url($ref, PHP_URL_HOST));
            $ownHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
            $siteHost = strtolower((string)parse_url($_SERVER['HTTP_ORIGIN'] ?? '', PHP_URL_HOST));
            if ($refHost === $ownHost || ($siteHost !== '' && $refHost === $siteHost)) $refHost = '';
        }

        [$device, $browser, $os] = parse_ua($ua);
        $ipHash = hash('sha256', IP_SALT . ($_SERVER['REMOTE_ADDR'] ?? ''));

        // Visitor
        $pdo->prepare(
            'INSERT INTO visitors (visitor_id, first_seen, last_seen, ip_hash, user_agent, device, browser, os)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)'
        )->execute([$visitorId, $nowStr, $nowStr, $ipHash, $ua, $device, $browser, $os]);

        // Session
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO sessions (session_id, visitor_id, started_at, last_activity, entry_page, referrer_host)
             VALUES (?,?,?,?,?,?)'
        );
        $ins->execute([$sessionId, $visitorId, $nowStr, $nowStr, $url, $refHost]);
        if ($ins->rowCount() === 1) {
            $pdo->prepare('UPDATE visitors SET total_sessions = total_sessions + 1 WHERE visitor_id = ?')
                ->execute([$visitorId]);
        }

        // Page view
        $pv = $pdo->prepare(
            'INSERT IGNORE INTO pageviews (pageview_id, session_id, url, title, viewed_at) VALUES (?,?,?,?,?)'
        );
        $pv->execute([$pageviewId, $sessionId, $url, $title, $nowStr]);
        if ($pv->rowCount() === 1) {
            $pdo->prepare('UPDATE sessions SET page_views = page_views + 1, last_activity = ? WHERE session_id = ?')
                ->execute([$nowStr, $sessionId]);
        }
    } elseif ($type === 'ping' || $type === 'end') {
        // ping / end: update time spent on this page and the session total
        $pdo->prepare('UPDATE pageviews SET time_spent = GREATEST(time_spent, ?) WHERE pageview_id = ? AND session_id = ?')
            ->execute([$timeSpent, $pageviewId, $sessionId]);

        $pdo->prepare(
            'UPDATE sessions
                SET last_activity = ?,
                    duration_seconds = (SELECT COALESCE(SUM(time_spent),0) FROM pageviews WHERE session_id = ?)
              WHERE session_id = ?'
        )->execute([$nowStr, $sessionId, $sessionId]);

        $pdo->prepare('UPDATE visitors SET last_seen = ? WHERE visitor_id = ?')->execute([$nowStr, $visitorId]);
    } else {
        // Clicks and form submissions count as activity but never include form values or labels.
        $pdo->prepare('UPDATE sessions SET last_activity = ? WHERE session_id = ?')
            ->execute([$nowStr, $sessionId]);
        $pdo->prepare('UPDATE visitors SET last_seen = ? WHERE visitor_id = ?')
            ->execute([$nowStr, $visitorId]);
    }

    $pdo->prepare(
        'INSERT IGNORE INTO activity_logs
            (event_id, event_type, visitor_id, session_id, pageview_id, url, title, event_target, time_spent, recorded_at)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    )->execute([$eventId, $type, $visitorId, $sessionId, $pageviewId, $url, $title, $eventTarget, $timeSpent, $nowStr]);

    $pdo->commit();
    http_response_code(204);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('analytics: ' . $e->getMessage());
    http_response_code(500);
}
