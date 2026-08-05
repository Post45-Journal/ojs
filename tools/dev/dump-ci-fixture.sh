#!/bin/bash
#
# Dump a sanitized, trimmed snapshot of ojs_test to
# tests/fixtures/ci-database.sql.gz for the CI integration-tests job.
#
# Why sanitize: the local ojs_test DB is a clone of the dev DB and contains
# real usernames, emails, biographies, etc. The CI fixture is committed to
# git — even in a private repo, we don't ship real people's PII. So we
# clone ojs_test → scratch schema `ojs_test_ci`, run sanitize-ci-fixture.sql
# against the scratch copy to replace identity fields with synthetic values
# (preserving all IDs and FKs), then dump that.
#
# Why trim: the fixture excludes tables that carry no test signal but bloat
# the dump — ROR registry mirror (~23MB uncompressed), sessions, ephemeral
# logs, notification/note bodies that could contain quoted email. Nothing
# under test reads these.
#
# The scratch DB is dropped + recreated on every run. If the test suite
# starts to depend on a currently-excluded table, drop it from EXCLUDES
# and rerun.
#
# CI consumes the fixture via .github/workflows/plugin-tests.yml (integration
# job): `zcat tests/fixtures/ci-database.sql.gz | mysql` restores the schema
# + seed data into the CI MySQL service, then $DATABASEDUMP points at the
# same file for per-test PKP_TEST_ENTIRE_DB restoration.
#
# Local runs continue to use ./database.sql.gz (produced by dump-test-db.sh);
# the sanitize + trim only matters for the committed CI fixture.
#

set -e

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

SRC_DB=ojs_test
SCRATCH_DB=ojs_test_ci
OUT="${ROOT}/tests/fixtures/ci-database.sql.gz"
SANITIZE_SQL="${ROOT}/tools/dev/sanitize-ci-fixture.sql"

# Tables excluded from the CI fixture. All are either:
#   - large external mirror data (rors, ror_settings — ROR ID registry)
#   - large ephemeral session/log noise (sessions, email_log, failed_jobs,
#     event_log, event_log_settings, notifications, notes)
# Nothing under test reads these; excluding them takes the dump from ~37MB
# to <100KB gzipped.
EXCLUDES=(
    rors
    ror_settings
)
# Everything else stays in the fixture. Tables that carry PII bodies but are
# actively written to by tests (notes, notifications, email_log, event_log,
# sessions, failed_jobs) keep their schema; the sanitize step TRUNCATEs
# their data.

if [ ! -f "${SANITIZE_SQL}" ]; then
    echo "ERROR: ${SANITIZE_SQL} missing." >&2
    exit 1
fi

IGNORE_ARGS=()
for t in "${EXCLUDES[@]}"; do
    IGNORE_ARGS+=("--ignore-table=${SCRATCH_DB}.${t}")
done

mkdir -p "$(dirname "${OUT}")"

MYSQL="docker exec -i mysql mysql -uroot -proot_password"
MYSQLDUMP="docker exec mysql mysqldump -uroot -proot_password --no-tablespaces --routines --triggers --events"

echo "1/4 Dropping + recreating scratch schema ${SCRATCH_DB}"
${MYSQL} <<SQL
DROP DATABASE IF EXISTS ${SCRATCH_DB};
CREATE DATABASE ${SCRATCH_DB} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SQL

echo "2/4 Cloning ${SRC_DB} → ${SCRATCH_DB}"
docker exec mysql sh -c "mysqldump --no-tablespaces --routines --triggers --events -uroot -proot_password ${SRC_DB} | mysql -uroot -proot_password ${SCRATCH_DB}"

echo "3/4 Dropping backup_* tables (leftovers from prior test runs — PII) + applying sanitize-ci-fixture.sql to ${SCRATCH_DB}"
BACKUP_TABLES=$(docker exec mysql mysql -uroot -proot_password -N -B -e \
    "SELECT table_name FROM information_schema.tables WHERE table_schema='${SCRATCH_DB}' AND table_name LIKE 'backup\\_%'")
for t in ${BACKUP_TABLES}; do
    ${MYSQL} -e "DROP TABLE IF EXISTS \`${t}\`" ${SCRATCH_DB}
done
${MYSQL} ${SCRATCH_DB} < "${SANITIZE_SQL}"

echo "4/4 Dumping sanitized ${SCRATCH_DB} (trimmed) → ${OUT}"
docker exec mysql sh -c "mysqldump --no-tablespaces --routines --triggers --events -uroot -proot_password ${IGNORE_ARGS[*]} ${SCRATCH_DB}" \
    | gzip -9 > "${OUT}"

ls -lh "${OUT}"
