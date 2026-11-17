# Website Visitor Analytics (PHP + MySQL)

Tracks, for any website you add it to:
- **Who visits**: unique visitors, new vs returning, device / browser / OS, traffic source
- **How long they stay**: active time per page and per visit (only counts while the tab is visible)
- **What they do**: page views, top pages, bounce rate, who is online right now

## Files
| File | Purpose |
|---|---|
| `schema.sql` | Creates the database and tables |
| `config.php` | Database login, dashboard password, salt |
| `track.js` | The script you add to your website pages |
| `track.php` | Receives events from `track.js` and saves them |
| `dashboard.php` | Password-protected reports |
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

The configured timezone (`Africa/Nairobi` by default) is used for PHP-generated timestamps and dashboard date ranges. Change it in `config.php` if your reporting should use a different timezone.

## Setup on XAMPP
1. Copy this folder to `C:\xampp\htdocs\analytics\`.
2. Start **Apache** and **MySQL** in XAMPP.
3. Open phpMyAdmin → **Import** → choose `schema.sql` → Go.
4. Edit `config.php`: set your database credentials, choose a strong `DASHBOARD_PASSWORD`, and set `IP_SALT` to a long, random secret.
5. Visit `http://localhost/analytics/test.html`, browse the Home, Stories, and About pages, and switch tabs to test active-time tracking.
6. Open `http://localhost/analytics/dashboard.php` and log in.

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
- Every second the tab is visible counts as active time; a heartbeat is sent every 15 s and when the page is closed or hidden.
- Bots and crawlers are ignored. Browsers with Do Not Track enabled are not tracked.

## Before going live
- Change `DASHBOARD_PASSWORD` and `IP_SALT`; set `ALLOWED_ORIGIN` in `config.php` to the exact origin of the site using the tracker (scheme and hostname, and a non-default port if applicable).
- Serve over HTTPS.
- Use a dedicated MySQL user instead of `root`.
- Raw IPs are never stored (only a salted hash). If your visitors are in the EU/Kenya, mention analytics in your privacy policy (Kenya Data Protection Act applies).
- To keep the database small, delete old rows occasionally, e.g.
  `DELETE FROM pageviews WHERE viewed_at < NOW() - INTERVAL 1 YEAR;`
