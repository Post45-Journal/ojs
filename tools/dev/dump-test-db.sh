#!/bin/bash
#
# Dump the ojs_test schema to ./database.sql.gz for PKP_TEST_ENTIRE_DB
# restoration between tests. Regenerate whenever the seeded test-DB state
# needs to change.
#

set -e

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

DB=ojs_test
OUT="${ROOT}/database.sql.gz"

echo "Dumping ${DB} → ${OUT}"
docker exec mysql sh -c "mysqldump --no-tablespaces --routines --triggers --events -uroot -proot_password ${DB}" | gzip -9 > "${OUT}"
ls -lh "${OUT}"
