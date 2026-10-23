# Post45 OJS — Post-Launch Monitoring & Alerting Plan

**Scope:** submissions.post45.org, single DigitalOcean droplet (Ubuntu
24.04), single-journal OJS 3.5 install with the post45Editorial +
post45NotionSync + post45DevEmailGuard + pragmaSubmissions + colorPalettes
stack.

**Status:** design; nothing installed yet. Section 4 is the checklist.

**Related:** an older repo-root note,
[`DATADOG-MONITORING-SETUP.md`](../DATADOG-MONITORING-SETUP.md), was
written pre-launch and pre-cutover. Its command-level recipes (agent
install, integration YAML, Synthetics UI walkthrough) are still useful
and this plan cites them, but its scope (two hosts, includes the main
WordPress site) and its version assumptions (PHP 8.1 + PHP-FPM) are out
of date — prod is now PHP 8.4 + Apache mod_php on a single droplet.

---

## 0. Environment facts (verified 2026-09-09)

- **Droplet:** currently 1 vCPU / 1 GB RAM. **Bumping to 2 GB** as part
  of this plan — steady-state was already 267 MB into swap, and the
  Datadog agent needs headroom.
- **Web:** Apache 2 + **mod_php (PHP 8.4)** — no PHP-FPM. Any old-doc
  step that configures `php_fpm.d/` doesn't apply.
- **DB:** MySQL, `ojs_journal` DB, `ojs_user` account (per
  `reference_prod_db_access` memory).
- **Queue:** **no persistent worker.** Jobs drain during each web
  request's PHP shutdown via
  `PKPQueueProvider::register()` →
  `register_shutdown_function` →
  `JobRunner::processJobs()`
  ([lib/pkp/classes/core/PKPQueueProvider.php:112-154](../lib/pkp/classes/core/PKPQueueProvider.php#L112-L154)).
  This is bounded by `queues.job_runner_max_memory` config + max jobs
  + max time. `lib/pkp/tools/jobs.php work` exists as a persistent
  worker mode but we don't use it. **This means the plan monitors
  queue-depth growth, not "worker unit up/down".**
- **Editorial-alert SlackNotifier** already lives in the plugin and
  writes to a Slack webhook stored in plugin settings.

---

## 1. What signals matter

Post45 is a small academic journal, not e-commerce. Performance isn't
critical (nobody's abandoning a checkout at 500 ms), but the site
shouldn't be a *pain*, and editors need to know when something
actually broke. The plan is biased toward **application errors** and
**visibility into bugs**, not APM-flavored latency histograms.

Signals are grouped by what the response looks like:

### Tier 1 — "A user or editor hit a broken thing right now"

Alarm-worthy. If any of these fire, someone is having a bad time.

- **PHP fatals / unhandled exceptions during editorial actions.** The
  Save/Decide/Submit that returns a white screen or generic "internal
  error." Users often don't report these. Biggest existing blind spot.
- **HTTP 5xx responses in Apache logs.** Same class, at the front door.
- **Site down / cert expiry.** External probes.
- **SMTP send-time failures** — the Symfony Mailer transport throws
  when Workspace's relay rejects, auth fails, or the connection times
  out. If nothing tails these, editors' "Sent" toast is a lie.
- **Notion sync errors** — `failure` class in SlackNotifier
  ([plugins/generic/post45NotionSync/classes/notification/SlackNotifier.php](../plugins/generic/post45NotionSync/classes/notification/SlackNotifier.php))
  already covers this. No new tooling; confirm the channel is being
  watched post-cutover.

### Tier 2 — "Something is trending toward breaking"

Alarm-worthy but only at real thresholds. Not paged on transient spikes.

- **Disk free < 1 GB** on `/var/www/ojs-files` (grows monotonically),
  `/var/www/html`, or `/var/log`. Page at 500 MB free.
- **Bandwidth approaching 2 TB/mo cap.** DO overage costs money;
  runaway bot traffic could sneak up on this.
- **Apache or MySQL systemd unit down.** Obvious, but should still
  fire without waiting for the external probe to notice.

### Tier 3 — Dashboards, not alarms

Deploy the metrics, put them on a dashboard, review on cadence. Don't
alarm until we've watched them for two weeks and know what "normal"
looks like.

- **Queue depth** (un-reserved rows in `jobs`).
- **Failed-jobs count** — redundant with SlackNotifier's `failure`
  channel; only alarm-worthy if SlackNotifier itself breaks, which
  needs different tooling to detect than the metric itself.
- **Time since last successful sync** vs. editorial activity —
  interesting if both were correlated, hard to alarm on without
  false positives.
- **`SyncHealthRepository` numbers** — sync throughput, overwrite
  rate. Editorial-facing dashboard material.
- **Memory + swap, CPU load average.** Watched to catch runaway
  processes / attacks, but a single CPU or memory spike on a small
  droplet is normal cron/build noise. Alarming on it burns attention
  for no return.
- **Submissions per week, decision timing.** These live in Notion per
  [[project_ojs_operational_notion_historical]] — not an OJS
  observability concern.

### Sync-outcome anomalies (already wired, not touched)

SlackNotifier handles three classes today:

- `overwrite` — sync clobbered a manual Notion edit
- `failure` — a job threw before Notion accepted the write
- `unknownDecision` — mapper met a decision constant it doesn't know

**No new tooling should duplicate these.** The monitoring stack's job
is to catch cases where the notifier ITSELF isn't running (Slack
outbound blocked, webhook URL rotated, in-request drain wedged) —
which the log-based error monitor and the failed_jobs metric together
approximate.

### Google Drive OAuth token refresh (conditional)

Only relevant if the Notion → Drive integration stays active. If the
refresh fails, Drive writes 401 silently, and Notion sync jobs surface
as `failure` in Slack — but the ROOT cause needs its own signal so we
don't investigate each downstream failure independently. Periodic ping
of Drive's `about.get`, alarm on 401. 10-min cadence. **Drop if the
Drive integration is retired.**

---

## 2. Tooling

**Primary: Datadog** (free tier — up to 5 hosts, custom metrics
included). The 2 GB droplet bump removes the RAM objection; agent
steady-state is ~100 MB.

**Signal → Datadog surface mapping:**

| Signal (from §1) | Datadog surface | Tier |
|---|---|---|
| PHP fatals / unhandled exceptions | Error Tracking via `dd-trace-php` PHP integration | 1 |
| HTTP 5xx | Log-based monitor on Apache access log via agent log tailer | 1 |
| Site down / cert expiry | Synthetics (external probes) | 1 |
| SMTP send-time failures | Log-based monitor on OJS PHP error log (keywords: TransportException, SMTP, Symfony\\Component\\Mailer) | 1 |
| Notion sync `failure` | SlackNotifier (existing plugin path — no Datadog wiring) | 1 |
| Disk free | Agent system metrics | 2 |
| Bandwidth vs. cap | Agent network metrics (`system.net.bytes_*`) | 2 |
| Apache / MySQL down | Agent Apache + MySQL integrations | 2 |
| Queue depth, sync age, editorial activity | Custom metrics from `scripts/post45-metrics.sh` (dashboards only, no alarms) | 3 |
| Memory + swap, CPU | Agent system metrics (dashboards only) | 3 |

**Dropped:**

- **Mailgun webhook / probe.** Post45 moved to Google Workspace SMTP
  (see [[project_mail_via_workspace]]); Workspace has no equivalent
  real-time delivery webhook. SMTP send-time errors are caught via
  log-based monitor instead.
- **Better Uptime, Sentry** — Datadog Synthetics + Error Tracking
  replace them.
- **Cron-driven Slack posts for infra** — Datadog monitors do this
  natively.

**Kept unchanged:**

- SlackNotifier for editorial anomalies. Datadog alarms go to a
  different channel.
- The "What NOT to monitor" list in §5.
- Post-launch tuning discipline in the final step.

---

## 3. Alarm channels

Post45 has one JM, small editorial team, no on-call rotation. Slack is
the single alarm surface. No SMS. No PagerDuty. No email.

| Signal class | Channel | Rationale |
|---|---|---|
| SlackNotifier: overwrite, unknownDecision, failure | Slack `#post45-notion-sync` (existing) | Already wired. No change. |
| Datadog Tier 1 (PHP fatals, 5xx, site down, cert, SMTP errors) | Slack `#site-updates-and-tech-issues` | Also gets GitHub notifications; low-volume enough to consolidate. Split to `#post45-errors` if volume warrants later. |
| Datadog Tier 2 (disk, bandwidth, apache/mysql down) | Slack `#site-updates-and-tech-issues` | Same. |
| Datadog Tier 3 (queue, memory, CPU, sync age) | No channel — dashboards only | Reviewed on cadence, not paged. Alarms added case-by-case after two-week bake-in confirms what "normal" is. |

If Slack itself is down, Datadog queues and re-fires when it returns.

---

## 4. Concrete implementation steps

Tier 1 signals go first — they're what we don't currently have any
visibility on. Infra alarms and dashboards follow.

### ~~Step 0 — Droplet bump~~ ✅ (done 2026-09-10)

Resized 1 GB → 2 GB / 1 vCPU. Steady-state after reboot: ~18% memory,
0 swap in use (expected to settle around 35-45% as caches re-warm).

### ~~Step 1 — Datadog account + Slack integration~~ ✅ (done 2026-09-10)

Account at app.datadoghq.com (US1). API key stored in JM's password
manager. Slack integration wired to `#site-updates-and-tech-issues`
(shared with GitHub notifications).

### Step 2 — Datadog Synthetics (external probes, ~20 min)

Runs from Datadog's infra. Zero on-box footprint. Covers Tier 1
"site down / cert expiry".

1. Datadog UI → **UX Monitoring → Synthetic Tests → New Test → HTTP Test**.
2. Create three tests:
   - `https://submissions.post45.org/` — 5-min interval, expect 200,
     body contains "Post45", response < 3s.
   - `https://submissions.post45.org/login` — 10-min interval,
     expect 200, body contains "username" or "login".
   - `https://submissions.post45.org/about` — 10-min interval, expect 200.
3. Locations: 3 geographically diverse (N. Virginia, Frankfurt, Singapore).
4. Enable HTTPS certificate expiry alerting (14-day warning).
5. Alert condition: "notify if failing from 2+ locations for 2
   consecutive checks" (avoids single-datacenter flakes).
6. Route to `@slack-site-updates-and-tech-issues`.
7. **Verify** with a bogus assertion (`body contains "impossible-string"`)
   on the `/` test and confirming Slack fires within 5-10 min, then
   remove.

### Step 3 — Datadog agent install (45 min)

Covers Tier 2 (disk, bandwidth, apache/mysql up-down) and gives us the
log tailer needed for steps 4-5.

Follows [DATADOG-MONITORING-SETUP.md](../DATADOG-MONITORING-SETUP.md)
§1.2-1.3 verbatim for install + tagging. Deviations:

- **PHP-FPM integration (old doc §4): SKIP.** Prod runs mod_php.
- **Apache integration (old doc §2):** apply as written; `mod_status`
  is trivial. Confirm `/server-status` is `Require local` so it isn't
  publicly exposed.
- **MySQL integration (old doc §3):** apply, but use `ojs_journal` DB
  name (old doc says `ojs`). Create `datadog@localhost` MySQL user
  with the doc's grants — separate from `ojs_user`, reads
  performance_schema + a few named tables.
- **Log collection (old doc §5):**
  - `/var/log/apache2/access.log` + `error.log` — apply.
  - `/var/log/mysql/error.log` — apply.
  - `/var/www/html/cache/error.log` and any `*.log` in that dir
    (OJS's application error log path).

Verify with `sudo datadog-agent status` — Apache + MySQL should both
show "OK". Confirm logs are showing up in Datadog → Logs → Live tail.

### Step 4 — Tier 1 error monitors (log-based, ~30 min)

With logs flowing in from step 3, create three log-based monitors
(Datadog UI → Monitors → New Monitor → Logs).

| Monitor | Query | Threshold | Notification |
|---|---|---|---|
| HTTP 5xx spike | `service:apache @http.status_code:5??` | > 5 events in 5 min | `@slack-site-updates-and-tech-issues` |
| PHP fatal / uncaught exception | `service:apache source:php ("PHP Fatal error" OR "Uncaught")` (Apache error log carries mod_php fatals) | > 0 events | `@slack-site-updates-and-tech-issues` |
| SMTP send failure | `("TransportException" OR "Symfony\\Component\\Mailer" OR "SMTP" AND ("failed" OR "refused" OR "timed out"))` | > 0 events in 5 min | `@slack-site-updates-and-tech-issues` |

Notes:

- The exact log-index field names (`@http.status_code`, `source`,
  etc.) depend on how the agent parses Apache/PHP logs. Adjust after
  looking at 1-2 real log entries in Datadog's Log Explorer.
- The SMTP monitor's keywords are the best guess without seeing a
  real failure log — refine after the first real send failure or a
  deliberate one (bad SMTP creds for 5 min, undo, watch what the log
  actually says).
- Group-by user/session where relevant so the "5 events" threshold
  isn't dominated by one bot hammering a broken URL.

### Step 5 — Datadog Error Tracking / PHP integration (1 hour)

Tier 1 signal for "unhandled exceptions in editorial actions." The
log-based PHP fatal monitor from step 4 catches the loud cases;
Error Tracking groups by stack trace and shows first-seen /
last-seen / occurrence-count.

1. Install `dd-trace-php` via Datadog's official installer:
   `curl -Lo /tmp/datadog-setup.php https://github.com/DataDog/dd-trace-php/releases/latest/download/datadog-setup.php`
   then `sudo php /tmp/datadog-setup.php --php-bin=all --enable-appsec=no`.
   (Verify latest install command in Datadog's PHP APM docs at time of install.)
2. **Enable error tracking only, skip APM tracing.** In
   `/etc/php/8.4/mods-available/ddtrace.ini` (or equivalent):
   ```
   datadog.trace.enabled = 0
   datadog.error_tracking.enabled = 1
   datadog.service = ojs
   datadog.env = production
   ```
3. `sudo systemctl restart apache2`.
4. Datadog UI → Error Tracking → confirm errors are being ingested
   under `service:ojs`.
5. Route Error Tracking alarms → `@slack-site-updates-and-tech-issues`.

Footprint: ~5 MB per PHP process × ~10 Apache workers = ~50 MB.
Comfortable on the 2 GB box.

### Step 6 — Tier 2 infra monitors (15 min, UI-driven)

| Monitor | Metric | Threshold | Notification |
|---|---|---|---|
| Apache down | `apache.can_connect` | == 0 for 3 min | `@slack-site-updates-and-tech-issues` |
| MySQL down | `mysql.can_connect` | == 0 for 3 min | `@slack-site-updates-and-tech-issues` |
| Disk low (ojs-files) | `system.disk.free{device:/dev/vda1}` | < 1 GB warn, < 500 MB alert | `@slack-site-updates-and-tech-issues` |
| Bandwidth monthly | `sum:system.net.bytes_sent{host:*} by {host}.rollup(sum, 2592000)` | > 1.5 TB (75% of 2 TB cap) | `@slack-site-updates-and-tech-issues` |

**Deliberately excluded:** CPU alarms, single-window memory alarms,
queue depth, sync age. These are Tier 3 — dashboards only for the
first two weeks.

### Step 7 — Custom-metrics cron script (Tier 3, dashboards only, 1 hour)

Script lives in the repo at
[`scripts/post45-metrics.sh`](../scripts/post45-metrics.sh) —
version-controlled, deployed via the normal `git pull` on prod.

**Metrics pushed** (all gauges, tagged
`env:production service:ojs host:$HOSTNAME`):

| Metric | Source |
|---|---|
| `post45.queue.jobs.depth` | `SELECT COUNT(*) FROM jobs WHERE reserved_at IS NULL` |
| `post45.queue.failed_jobs.recent` | `SELECT COUNT(*) FROM failed_jobs WHERE failed_at > NOW() - INTERVAL 1 HOUR` |
| `post45.sync.seconds_since_last` | `SELECT UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(MAX(last_synced_at)) FROM post45_notion_sync_state WHERE last_synced_at IS NOT NULL` |
| `post45.editorial.recent_activity` | `SELECT COUNT(*) FROM submissions WHERE date_last_activity > NOW() - INTERVAL 1 HOUR` |
| `post45.storage.ojs_files_bytes` | `du -sb /var/www/ojs-files` |
| `post45.storage.db_bytes` | `SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema='ojs_journal'` |

Deliberately **not** pushed:

- Overwrite count. SlackNotifier's `overwrite` messages aren't persisted
  anywhere queryable — they just POST to Slack. Adding a counter requires
  a plugin code change; defer until we see whether editors edit-and-lose
  in Notion enough to warrant it.

**Deploy on prod:**

```bash
# One-time setup
sudo mkdir -p /etc/post45
sudo tee /etc/post45/metrics.env > /dev/null <<'EOF'
DD_API_KEY=<from Datadog UI>
DD_SITE=datadoghq.com
DB_NAME=ojs_journal
DB_USER=p45_metrics
DB_PASS=<generated>
OJS_FILES_DIR=/var/www/ojs-files
EOF
sudo chmod 600 /etc/post45/metrics.env
sudo chown root:root /etc/post45/metrics.env

# Read-only DB user
sudo mysql <<'EOF'
CREATE USER 'p45_metrics'@'localhost' IDENTIFIED BY '<generated>';
GRANT SELECT ON ojs_journal.jobs TO 'p45_metrics'@'localhost';
GRANT SELECT ON ojs_journal.failed_jobs TO 'p45_metrics'@'localhost';
GRANT SELECT ON ojs_journal.submissions TO 'p45_metrics'@'localhost';
GRANT SELECT ON ojs_journal.post45_notion_sync_state TO 'p45_metrics'@'localhost';
GRANT SELECT ON `information_schema`.* TO 'p45_metrics'@'localhost';
FLUSH PRIVILEGES;
EOF

# Root crontab (already root-owned in repo)
sudo crontab -l | { cat; echo '*/5 * * * * /var/www/html/scripts/post45-metrics.sh'; } | sudo crontab -

# Smoke test
sudo /var/www/html/scripts/post45-metrics.sh && echo OK
# Then look for the metrics in Datadog UI → Metrics → Explorer (post45.*)
```

Failures are logged to syslog via `logger -t post45-metrics`. Tail with
`journalctl -t post45-metrics -f` during the smoke test.

**Do NOT add alarms on these metrics yet.** Two-week bake-in first —
see step 9.

### Step 8 — Verification pass (1-2 hours, active)

Before declaring monitoring "done":

- **Trigger a real 500.** Break a URL momentarily (e.g., visit a made-up
  admin endpoint that fatals), or dump a `throw new \Exception('DD test')`
  behind a debug flag. Confirm the log-based 5xx monitor fires AND
  Error Tracking captures the stack trace.
- **Trigger a real SMTP failure.** Point the OJS mail config at an
  invalid Workspace app password briefly, send a test email, undo.
  Confirm the SMTP log monitor fires and note what the actual log
  line looked like — refine the monitor's regex if the guess in
  step 4 didn't match.
- **Stop apache2 briefly**; confirm apache-down alarm fires within
  5 min; restart. Confirm Synthetics also detects it (should catch
  first — external probes have < 5 min interval).
- **Fill /tmp** with a large file to push disk-free below threshold;
  confirm alarm; clean up.
- **Confirm Synthetics status page** renders and can be shared with
  the editorial team.
- **Confirm alarm throttling** — deliberately trip the same condition
  twice within an hour and confirm only one Slack notification.

### Step 9 — First-two-weeks tuning (ongoing)

Thresholds are opening bids. After 14 days:

- **Was every alarm actionable?** Down-tune anything that fired
  without cause. If a monitor cried wolf twice, its threshold is wrong.
- **Was any incident invisible?** Add a signal for whatever would
  have caught it.
- **Promote Tier 3 metrics that earned it.** By now `post45.queue.*`,
  `post45.sync.seconds_since_last`, etc. have two weeks of baseline
  data. Pick 1-2 that actually correlate with real problems seen in
  the window and turn them into alarms. Leave the rest as dashboards.
- **Update this doc** with tuned thresholds and any new monitors.

---

## 5. What NOT to monitor

Over-alerting burns the JM's attention, which is the scarce resource.
Explicit deny-list:

- **Individual sync events at "normal" level.** SlackNotifier's
  `normalSync` class is off by default for a reason. Don't turn on in
  prod.
- **Notion API rate-limit warnings.** The plugin retries with backoff;
  only sustained 429 storms matter, and those show up as job failures.
- **Editorial workflow events** — "new submission arrived", "decision
  made". Already surface in OJS's own in-app notifications and email.
- **Per-request response times.** APM-flavored latency histograms
  don't add editor-facing value at Post45's scale.
- **User-agent / access patterns / analytics-ish signals.** Separate
  analytics tool for editorial traffic questions.
- **CSRF token failures, 4xx rates broadly.** Individual 4xxs are
  noise; Synthetics' 5xx probing catches the actually-broken class.
- **CPU utilization spikes < 1 minute.** Single-vCPU boxes spike
  constantly during Composer/npm/cron. Only sustained > 2 min matters.
- **Every disk directory.** Only `/var/www/html`, `/var/www/ojs-files`,
  `/var/log`.
- **Queue depth == 0 for extended periods.** DESIRED state.
- **"Queue worker down".** There is no worker. See §0.
- **post45DevEmailGuard status.** Post-cutover it's disabled;
  alarming on off-ness is wrong.
- **CHANGELOG.md staleness or doc freshness.** Human-review concerns.
- **Git working tree state on prod.** Submodule oddities during
  pkp-lib updates aren't an alarm.

---

## Appendix: intentionally-unstructured surfaces

Deferred to a future revision when we understand editorial patterns
better:

- **User-perceived latency SLOs.** No formal SLO; editorial UX
  tolerates 1-2s. Reconsider if the journal grows.
- **Business metrics.** Submissions per week, time-to-decision, review
  duration. All live in Notion per
  [[project_ojs_operational_notion_historical]] and are queried there,
  not from OJS observability.
- **Security event monitoring.** Failed-login rate, admin access,
  sudo events. Separate security-audit pass to design properly.
- **Backup verification.** Own layer; separate backup plan doc.
