<?php
// ---- Settings: change these ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'site_analytics');
define('DB_USER', 'root');          // XAMPP default
define('DB_PASS', '');              // XAMPP default is empty

define('DASHBOARD_PASSWORD', 'change-me-now');            // password for dashboard.php
define('IP_SALT', 'replace-with-a-long-random-string');   // used to hash IPs
define('ONLINE_WINDOW_MIN', 5);                           // "online now" = active in last N minutes
define('ALLOWED_ORIGIN', '*');                            // set to 'https://yoursite.com' in production

date_default_timezone_set('Africa/Nairobi');

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}
