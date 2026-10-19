# Configuration

All options are in `config/nightguardian.php` and can be set via `.env`.

| Env | Default | Description |
|---|---|---|
| `NIGHTGUARDIAN_ENABLED` | `true` | Master switch. `false` in tests is recommended. |
| `NIGHTGUARDIAN_URL` | – | Collector base URL, e.g. `https://ng.example.com` |
| `NIGHTGUARDIAN_TOKEN` | – | Project token (`ng_…`). Without URL+token the SDK is inactive. |
| `NIGHTGUARDIAN_ENVIRONMENT` | `APP_ENV` | |
| `NIGHTGUARDIAN_RELEASE` | auto (git SHA) | |
| `NIGHTGUARDIAN_LOG_LEVEL` | `error` | Minimum PSR-3 level to capture from logs |
| `NIGHTGUARDIAN_SLOW_REQUEST_MS` | `2000` | `0` disables |
| `NIGHTGUARDIAN_SLOW_QUERY_MS` | `1000` | `0` disables |
| `NIGHTGUARDIAN_ERROR_PAGE` | `true` | Friendly 500 page (only when `APP_DEBUG=false`) |
| `NIGHTGUARDIAN_FEEDBACK` | `true` | Feedback box on the error page |
| `NIGHTGUARDIAN_TIMEOUT` | `2` | Total HTTP timeout (seconds) |
| `NIGHTGUARDIAN_CONNECT_TIMEOUT` | `1` | |
| `NIGHTGUARDIAN_SPOOL_MAX_MB` | `10` | |
| `NIGHTGUARDIAN_SPOOL_MAX_AGE_DAYS` | `7` | Spooled batches older than this are deleted |
| `NIGHTGUARDIAN_SEND_IP` | `false` | |
| `NIGHTGUARDIAN_SEND_REQUEST_BODY` | `false` | Scrubbed if enabled |
| `NIGHTGUARDIAN_SEND_USER_EMAIL` | `false` | |
| `NIGHTGUARDIAN_HOST_TAG` | `true` | Tag every event with `host`, the host of `APP_URL` (see below) |

Config-only (arrays): `ignore_exceptions`, `scrub_keys` (merged with defaults), `header_allowlist`,
`tags` (static tags added to every event), `user_resolver` (callable returning `['id' => …]`).

Test without the network: `NIGHTGUARDIAN_URL` unset → SDK inactive; `nightguardian:status` explains why.

## One app, many installations

A SaaS where every customer runs its own copy of the same app (one folder, one `.env`, one domain each) is the same code
reporting the same bugs from many places. Choose how the collector should see them:

| | **A. One shared token** | **B. One token per installation** |
|---|---|---|
| Collector | one project | one project per installation |
| The same bug in 30 installations | **one issue** (30× the events) | 30 issues, one in each project |
| Where does it happen? | the panel lists the affected **hosts** | the project is the installation |
| Alerts | once per new bug | once per installation |
| Access control | everybody sees everything | give each team or customer only its project |
| Retention, rate limit, tokens | shared | independent |

**Pick A** when you own and operate the app and want to fix bugs once: you care about *what* breaks and *how widely*.
**Pick B** when installations are operated or paid for separately, customers may see their own project, or one noisy
installation must never hide or rate-limit the others.

Every event carries a `host` tag automatically: the host of `APP_URL` (e.g. `yapai.head-office.com.ar`), also on jobs, the
scheduler and logs. It is what lets model A tell the installations apart. Set `NIGHTGUARDIAN_HOST_TAG=false` to turn it
off; a `host` tag you set yourself (in `config/nightguardian.php` → `tags`, or per event) is always respected. Make sure every
installation has its own `APP_URL`.

### Connect an installation

```bash
php artisan nightguardian:setup --url=https://ng.example.com --token=ng_...
```

Without options it asks for the URL and the token (hidden). It checks the token starts with `ng_` and the URL is `https://`
(plain `http://` only warns), writes `NIGHTGUARDIAN_URL` and `NIGHTGUARDIAN_TOKEN` to the `.env` (the rest of the file,
its permissions and its owner are untouched), caches the configuration again if it was cached, and sends a test event
(skip it with `--no-test`). It never prints the token, can be run again safely and exits with an error code if anything fails.

### Many installations on one server

Run it as the user that owns each `.env` so the files keep their owner. Model A (one shared token):

```bash
read -rsp "Project token: " TOKEN; echo
for app in /var/www/customer-*/; do
  echo "== $app"
  (cd "$app" && sudo -u www-data php8.3 artisan nightguardian:setup --url=https://ng.example.com --token="$TOKEN") || echo "FAILED: $app"
done
unset TOKEN
```

Model B (a token per installation, asked one by one):

```bash
for app in /var/www/customer-*/; do
  read -rsp "Token for $(basename "$app"): " TOKEN; echo
  (cd "$app" && sudo -u www-data php8.3 artisan nightguardian:setup --url=https://ng.example.com --token="$TOKEN") || echo "FAILED: $app"
done
unset TOKEN
```

The token is briefly visible in the process list while each command runs; on a shared server prefer typing it when the
command asks for it.
