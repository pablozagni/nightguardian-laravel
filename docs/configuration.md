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

Config-only (arrays): `ignore_exceptions`, `scrub_keys` (merged with defaults), `header_allowlist`,
`tags` (static tags added to every event), `user_resolver` (callable returning `['id' => …]`).

Test without the network: `NIGHTGUARDIAN_URL` unset → SDK inactive; `nightguardian:status` explains why.
