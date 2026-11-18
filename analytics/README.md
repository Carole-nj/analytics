# Website Visitor Analytics (PHP + MySQL)

Tracks, for any website you add it to:
- **Who visits**: unique visitors, new vs returning, device / browser / OS, traffic source
- **How long they stay**: elapsed duration per page and per visit
- **What they do**: page views, link/button clicks, form submissions, top pages, bounce rate, who is online right now

## Files
| File | Purpose |
|---|---|
| `schema.sql` | Creates the database and tables |
| `config.php` | Database login, dashboard password, salt |
| `track.js` | The script you add to your website pages |
| `track.php` | Receives events from `track.js` and saves them |
| `dashboard.php` | Password-protected reports |
| `logs.php` | Password-protected list of the latest 200 page visits |
| `test.html` | Small multi-page demo site for testing the tracker |

## Configuration
Edit the settings at the top of `config.php`:

| Setting | Purpose |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | MySQL connection details. `DB_NAME` must match the database created by `schema.sql` (`site_analytics` by default). |
| `DASHBOARD_PASSWORD` | Password used to sign in to `dashboard.php`. Replace the default before deployment. |
| `IP_SALT` | Secret value used when hashing visitor IP addresses. Set a long, random value and keep it private; changing it affects hashes collected afterward. |
| `ONLINE_WINDOW_MIN` | Number of minutes without activity after which a visitor is no longer counted as online. |
| `ALLOWED_ORIGIN` | Origin permitted to send tracking requests. Use `*` for local testing; in production, set it to the full origin of the site using the tracker, such as `https://www.example.com`. |

The configured timezone (`Africa/Nairobi` by default) is used for PHP-generated timestamps and dashboard date ranges. The local demo stores UTC timestamps and displays them converted to East Africa Time (EAT).

## Setup on XAMPP
1. Copy this folder to `C:\xampp\htdocs\analytics\`.
2. Start **Apache** and **MySQL** in XAMPP.
3. Open phpMyAdmin → **Import** → choose `schema.sql` → Go.
4. Edit `config.php`: set your database credentials, choose a strong `DASHBOARD_PASSWORD`, and set `IP_SALT` to a long, random secret. If this is an existing installation, import `schema.sql` again to create the new activity log table.
5. Visit `http://localhost/analytics/test.html`, browse the Home, Stories, and About pages, and check the page visit log for page durations.
6. Open `http://localhost/analytics/dashboard.php` and log in, then choose **Page visit log** to see page titles and durations.

## Run a local demo from VS Code without MySQL
This local-only mode uses PHP's built-in development server and SQLite; it does not require the XAMPP Control Panel or a MySQL server. It requires PHP CLI with `pdo_sqlite` enabled.

1. In the VS Code PowerShell terminal, run `powershell -NoProfile -ExecutionPolicy Bypass -File .\analytics\start-local.ps1`.
2. Open `http://127.0.0.1:8000/test.html`, browse between pages, then view summaries at `http://127.0.0.1:8000/dashboard.php` or page visits at `http://127.0.0.1:8000/logs.php`.
3. Stop the server with Ctrl+C.

The local dashboard only shows visits sent to this computer. It does not collect visitors from a publicly hosted website. Its SQLite data is stored in the system temporary directory.
The page visit log shows each page title and its duration in EAT. Duration is elapsed time until the page is left, including background-tab time. The tracker also stores clicks and form submissions, but they are not shown in this page visit list.

## Add it to your website
Put this before `</head>` on every page (header include / layout file):

```html
<script src="/analytics/track.js" defer></script>
```

If the tracker lives on a different domain, add the endpoint:

```html
<script src="https://analytics.example.com/track.js"
        data-endpoint="https://analytics.example.com/track.php" defer></script>
```

## How it works
- A random visitor ID is stored in the browser (`localStorage`). Same ID on return = returning visitor.
- A **session** (visit) ends after 30 minutes of inactivity.
- Duration is measured from page load until the page is left; periodic heartbeats update it while the page is visible, and the final duration includes background-tab time.
- Click events record the clicked link destination or button type; form events record the method and action path. Form values and visible labels are never collected. Page URLs omit query strings.
- Bots and crawlers are ignored. Browsers with Do Not Track enabled are not tracked.

## Before going live
- Change `DASHBOARD_PASSWORD` and `IP_SALT`; set `ALLOWED_ORIGIN` in `config.php` to the exact origin of the site using the tracker (scheme and hostname, and a non-default port if applicable).
- Serve over HTTPS.
- Use a dedicated MySQL user instead of `root`.
- Raw IPs are never stored (only a salted hash). If your visitors are in the EU/Kenya, mention analytics in your privacy policy (Kenya Data Protection Act applies).
- To keep the database small, delete old rows occasionally from both `pageviews` (`viewed_at`) and `activity_logs` (`recorded_at`), e.g.
  `DELETE FROM activity_logs WHERE recorded_at < NOW() - INTERVAL 1 YEAR;`
