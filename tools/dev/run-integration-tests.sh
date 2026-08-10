#!/bin/bash
#
# Wrapper for the phpunit ApplicationPlugins suite that routes it at the
# ojs_test schema instead of the dev DB.
#
# Why the wrapper: PKPContainer::loadConfiguration() reads
# Config::getVar('database', ...) at Laravel-bootstrap time, so once the OJS
# app object is constructed the DB connection is baked in. The path of least
# resistance is to swap config.inc.php with config.test.inc.php for the run
# and swap back on exit (matching how pkp/pkp-github-actions installs test
# credentials in CI). Refuses to run unless config.test.inc.php targets a
# schema whose name contains "test", so a hand-edit to config.test.inc.php
# can't silently point at the dev DB.
#
# Usage:
#   tools/dev/run-integration-tests.sh                       # all ApplicationPlugins tests
#   tools/dev/run-integration-tests.sh --filter MarkPublished  # narrow
#
# Progress is printed to stderr as each test class starts and finishes,
# followed by a slowest-classes summary — see progress_filter below.
#
# Prereqs (one-time):
#   - config.test.inc.php exists at repo root (copy of config.inc.php with
#     database.name = ojs_test and files_dir = <repo>/tests-files).
#   - ojs_test MySQL schema exists (mysqldump ojs | mysql ojs_test).
#   - Dumps of ojs_test live at ./database.sql.gz (regenerate via
#     tools/dev/dump-test-db.sh — used by PKP_TEST_ENTIRE_DB restoration).
#

set -e

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

if [ ! -f config.test.inc.php ]; then
    echo "ERROR: config.test.inc.php missing at $ROOT" >&2
    echo "  Create it by copying config.inc.php and changing database.name to ojs_test." >&2
    exit 1
fi

if [ ! -f config.inc.php ]; then
    echo "ERROR: config.inc.php missing at $ROOT" >&2
    exit 1
fi

# Safety guard: refuse if config.test.inc.php doesn't target a *_test schema.
if ! grep -qE '^name\s*=\s*"?[A-Za-z0-9_]*test' config.test.inc.php; then
    echo "ERROR: config.test.inc.php database.name does not contain 'test'." >&2
    echo "  Refusing to run integration tests that might target a non-test DB." >&2
    exit 1
fi

# Warn if the DATABASEDUMP fixture is missing (only needed for tests that use
# PKP_TEST_ENTIRE_DB; table-scoped tests don't require it).
if [ ! -f database.sql.gz ]; then
    echo "NOTE: database.sql.gz not found at $ROOT/database.sql.gz." >&2
    echo "  PKP_TEST_ENTIRE_DB restoration will fail without it." >&2
    echo "  Regenerate via: tools/dev/dump-test-db.sh" >&2
fi

# Swap config files for the run, restore on any exit.
mv config.inc.php config.inc.php.dev-backup
cp config.test.inc.php config.inc.php
trap 'mv config.inc.php.dev-backup config.inc.php' EXIT INT TERM

# Live progress.
#
# PHPUnit prints its testdox summary only at the very END of the run, so a suite
# where a single test can take 20 seconds shows nothing but a slowly advancing
# row of dots — indistinguishable from a hang. `--log-events-text` streams
# structured events as they happen; this filter turns them into one line per test
# class ("started" / "finished, took Ns") plus a slowest-classes table at the end,
# so at any moment you can see what is running and afterwards where the time went.
#
# It writes to stderr so piping stdout (e.g. `| grep -v '✔'`) leaves progress
# visible, and phpunit's own output is untouched.
#
# Delivered over a FIFO rather than bash process substitution: PHPUnit's
# EventLogger re-opens the log path with file_put_contents on EVERY event, and a
# `>(...)` path (/dev/fd/N) is only valid for the process that created it — the
# second write fails and the whole run fills with "Failed to open stream"
# warnings. A FIFO has a real, reopenable path. The catch is the mirror image:
# because PHPUnit closes between writes, the reader would see EOF and exit after
# the first event, so this holds a writer descriptor open for the whole run and
# closes it only when phpunit is done.
progress_filter() {
    gawk '
        function now() { return systime() }
        # gawk does not accept a line break before ? or : in a ternary.
        function fmt(secs) {
            if (secs >= 60) { return sprintf("%dm%02ds", int(secs / 60), secs % 60) }
            return sprintf("%ds", secs)
        }
        BEGIN { started = now(); classes = 0 }
        # "Test Suite Started (Fully\\Qualified\\ClassName, N tests)".
        # Requiring a "\\" skips the two outer suites (the config file and the
        # testsuite name); excluding "::" skips the per-data-provider sub-suites
        # PHPUnit nests inside a class, which would otherwise both add noise and
        # overwrite the enclosing class before it finished.
        /^Test Suite Started \(.*\\.*, [0-9]+ tests?\)$/ && $0 !~ /::/ {
            match($0, /\(([^,]+), ([0-9]+) tests?\)/, m)
            n = split(m[1], parts, "\\")
            current = parts[n]
            currentStarted = now()
            printf("  [%6s] %-52s %2d tests\n", fmt(now() - started), current "  ...", m[2]) > "/dev/stderr"
            fflush("/dev/stderr")
            next
        }
        /^Test Suite Finished \(.*\\.*, [0-9]+ tests?\)$/ && $0 !~ /::/ {
            if (current == "") next
            elapsed = now() - currentStarted
            durations[current] = elapsed
            classes++
            printf("  [%6s] %-52s %s\n", fmt(now() - started), current "  done", fmt(elapsed)) > "/dev/stderr"
            fflush("/dev/stderr")
            current = ""
            next
        }
        END {
            if (classes == 0) exit
            printf("\n  Slowest classes (total %s):\n", fmt(now() - started)) > "/dev/stderr"
            # Insertion sort — a handful of classes, and it avoids depending on
            # gawk sort extensions.
            n = 0
            for (c in durations) { order[++n] = c }
            for (i = 2; i <= n; i++) {
                key = order[i]
                for (j = i - 1; j >= 1 && durations[order[j]] < durations[key]; j--) {
                    order[j + 1] = order[j]
                }
                order[j + 1] = key
            }
            for (i = 1; i <= n && i <= 5; i++) {
                printf("    %6s  %s\n", fmt(durations[order[i]]), order[i]) > "/dev/stderr"
            }
        }
    '
}

# Invoke phpunit directly (not runAllTests.sh) so we can pass --filter etc.
# --group=integration keeps this run to tests marked with #[Group('integration')].
# Reason: an integration test's setUp() calls $plugin->register(), which loads
# the plugin's locale.po globally for the rest of the PHP process. Pure-unit
# tests in the same process that hardcode expected values against the raw
# `##...##` locale-key fallback then fail. Splitting into groups keeps the
# unit + integration tiers cleanly isolated in separate PHPUnit invocations.
# Callers who want to pass their own --filter or --group override can still
# do so via "$@" after these defaults.
PROGRESS_DIR="$(mktemp -d)"
PROGRESS_FIFO="$PROGRESS_DIR/events"
mkfifo "$PROGRESS_FIFO"
# `|| cat > /dev/null` is a safety net, not decoration: phpunit BLOCKS on a full
# pipe with no reader, so a filter that dies (a bad awk edit, say) would hang the
# whole run with no output rather than just losing the progress lines.
{ progress_filter || cat > /dev/null; } < "$PROGRESS_FIFO" &
PROGRESS_PID=$!
exec 9>"$PROGRESS_FIFO"   # keep the pipe open across phpunit's per-event reopens

cleanup_progress() {
    exec 9>&-              # last writer closed => the reader sees EOF and prints its summary
    wait "$PROGRESS_PID" 2>/dev/null || true
    rm -rf "$PROGRESS_DIR"
}
trap 'cleanup_progress; mv config.inc.php.dev-backup config.inc.php' EXIT INT TERM

php lib/pkp/lib/vendor/phpunit/phpunit/phpunit \
    --configuration lib/pkp/tests/phpunit.xml \
    --testdox \
    --no-coverage \
    --testsuite ApplicationPlugins \
    --group integration \
    --log-events-text "$PROGRESS_FIFO" \
    "$@"
