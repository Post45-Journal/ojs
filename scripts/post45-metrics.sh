#!/bin/bash
# Push Post45-specific gauges to Datadog.
#
# Runs every 5 minutes via root crontab. Reads DD API key + DB creds
# from /etc/post45/metrics.env (root:root 0600, not committed).
#
# Metrics pushed:
#   post45.queue.jobs.depth              — un-reserved rows in `jobs`
#   post45.queue.failed_jobs.recent      — failed_jobs in last hour
#   post45.sync.seconds_since_last       — age of most-recent successful sync
#   post45.editorial.recent_activity     — submissions touched in last hour
#   post45.storage.ojs_files_bytes       — du of /var/www/ojs-files
#   post45.storage.db_bytes              — sum of table data + index sizes
#
# Failures are logged to syslog via `logger`; the script never blocks
# or retries — a stuck cron invocation is worse than a missing sample.

set -u
umask 077

# --- Config ----------------------------------------------------------

ENV_FILE=/etc/post45/metrics.env
if [[ ! -r "$ENV_FILE" ]]; then
    logger -t post45-metrics "cannot read $ENV_FILE — skipping"
    exit 0
fi
# shellcheck disable=SC1090
source "$ENV_FILE"

: "${DD_API_KEY:?DD_API_KEY must be set in $ENV_FILE}"
: "${DD_SITE:=datadoghq.com}"
: "${DB_NAME:?DB_NAME must be set in $ENV_FILE}"
: "${DB_USER:?DB_USER must be set in $ENV_FILE}"
: "${DB_PASS:?DB_PASS must be set in $ENV_FILE}"
: "${OJS_FILES_DIR:=/var/www/ojs-files}"

TS=$(date +%s)
HOST=$(hostname -s)
COMMON_TAGS='["env:production","service:ojs","host:'"$HOST"'"]'

# --- Helpers ---------------------------------------------------------

# One-shot MySQL — no persistent connection; each call is small.
sql() {
    MYSQL_PWD="$DB_PASS" mysql \
        --defaults-file=/dev/null \
        --user="$DB_USER" \
        --host=127.0.0.1 \
        --database="$DB_NAME" \
        --batch --skip-column-names \
        --execute="$1" 2>/dev/null
}

# Append a gauge to the batch payload. Silently skips empty values —
# a metric with no reading is better than a metric with `null` cast to 0.
SERIES=()
add_gauge() {
    local metric=$1
    local value=$2
    if [[ -z "$value" ]] || ! [[ "$value" =~ ^-?[0-9]+$ ]]; then
        return
    fi
    SERIES+=("{\"metric\":\"$metric\",\"type\":\"gauge\",\"points\":[[$TS,$value]],\"tags\":$COMMON_TAGS}")
}

# --- Collect metrics -------------------------------------------------

# Queue depth: un-reserved jobs waiting in the table. Zero is normal.
add_gauge post45.queue.jobs.depth \
    "$(sql 'SELECT COUNT(*) FROM jobs WHERE reserved_at IS NULL')"

# Failed-jobs in last hour. Any non-zero row also triggered SlackNotifier;
# this metric is the sanity check that SlackNotifier itself is running.
add_gauge post45.queue.failed_jobs.recent \
    "$(sql 'SELECT COUNT(*) FROM failed_jobs WHERE failed_at > NOW() - INTERVAL 1 HOUR')"

# Age (seconds) of the most-recent successful sync. Every row in
# post45_notion_sync_state is a successful push; failures land in
# failed_jobs, not here.
add_gauge post45.sync.seconds_since_last \
    "$(sql 'SELECT UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(MAX(last_synced_at)) FROM post45_notion_sync_state WHERE last_synced_at IS NOT NULL')"

# Editorial activity: submissions touched in the last hour. Paired with
# seconds_since_last, this is the "if editors are working but sync is
# stalled" signal. Column is date_last_activity (per pkp-lib
# submission/DAO.php).
add_gauge post45.editorial.recent_activity \
    "$(sql 'SELECT COUNT(*) FROM submissions WHERE date_last_activity > NOW() - INTERVAL 1 HOUR')"

# ojs-files grows monotonically; catches attachment blooms + orphaned files.
if [[ -d "$OJS_FILES_DIR" ]]; then
    add_gauge post45.storage.ojs_files_bytes \
        "$(du -sb "$OJS_FILES_DIR" 2>/dev/null | awk '{print $1}')"
fi

# Total DB size. Correlates with jobs/failed_jobs pruning + submissions
# growth. Alarms on this are a slow-moving signal — reviewed, not paged.
add_gauge post45.storage.db_bytes \
    "$(sql "SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema='$DB_NAME'")"

# --- Push ------------------------------------------------------------

if [[ ${#SERIES[@]} -eq 0 ]]; then
    logger -t post45-metrics "no metrics collected"
    exit 0
fi

PAYLOAD='{"series":['$(IFS=,; echo "${SERIES[*]}")']}'

RESPONSE=$(curl -sS \
    --max-time 10 \
    -X POST "https://api.$DD_SITE/api/v1/series" \
    -H "Content-Type: application/json" \
    -H "DD-API-KEY: $DD_API_KEY" \
    -d "$PAYLOAD" \
    -w '\n%{http_code}')

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
if [[ "$HTTP_CODE" != "202" ]]; then
    logger -t post45-metrics "Datadog rejected series (HTTP $HTTP_CODE): $(echo "$RESPONSE" | head -n-1 | head -c 300)"
    exit 1
fi

exit 0
