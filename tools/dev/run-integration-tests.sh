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

# Invoke phpunit directly (not runAllTests.sh) so we can pass --filter etc.
# --group=integration keeps this run to tests marked with #[Group('integration')].
# Reason: an integration test's setUp() calls $plugin->register(), which loads
# the plugin's locale.po globally for the rest of the PHP process. Pure-unit
# tests in the same process that hardcode expected values against the raw
# `##...##` locale-key fallback then fail. Splitting into groups keeps the
# unit + integration tiers cleanly isolated in separate PHPUnit invocations.
# Callers who want to pass their own --filter or --group override can still
# do so via "$@" after these defaults.
php lib/pkp/lib/vendor/phpunit/phpunit/phpunit \
    --configuration lib/pkp/tests/phpunit.xml \
    --testdox \
    --no-coverage \
    --testsuite ApplicationPlugins \
    --group integration \
    "$@"
